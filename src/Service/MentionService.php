<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use StreamEngine\Core\Config;
use StreamEngine\Core\Cryptography;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\MentionRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UserRepository;
use Throwable;

final class MentionService
{
    public const int MAX_MENTIONS = 20;

    /** Existing username contract, with boundaries that reject emails/URLs. */
    private const string TOKEN_PATTERN = '~(?<![A-Za-z0-9_@./\\\\-])@([A-Za-z0-9][A-Za-z0-9_-]{2,29})(?![A-Za-z0-9_-])~';

    private ?NotificationService $notifications = null;
    private ?FeedService $feeds = null;
    private ?MessageRepository $messages = null;
    private ?ParticipantRepository $participants = null;
    private ?UserRepository $users = null;
    private ?TranslationManager $tm = null;
    private ?Config $config = null;

    public function __construct(
        private readonly MentionRepository $mentions,
        private readonly ?UrlGenerator $urls = null,
    ) {
    }

    public function configureAccess(
        FeedService $feeds,
        MessageRepository $messages,
        ParticipantRepository $participants,
        UserRepository $users,
        TranslationManager $tm,
        Config $config,
    ): void {
        $this->feeds = $feeds;
        $this->messages = $messages;
        $this->participants = $participants;
        $this->users = $users;
        $this->tm = $tm;
        $this->config = $config;
    }

    public function setNotificationService(NotificationService $notifications): void
    {
        $this->notifications = $notifications;
    }

    /** @return list<int> newly mentioned user ids */
    public function synchronizeFeed(int $feedId, string $html, int $actorUserId = 0): array
    {
        return $this->synchronize($feedId, null, $html, $actorUserId);
    }

    /** @return list<int> newly mentioned user ids */
    public function synchronizeMessage(int $messageId, string $html, int $actorUserId = 0): array
    {
        return $this->synchronize(null, $messageId, $html, $actorUserId);
    }

    public function validate(string $html): void
    {
        $this->validateTokenCount($this->extractTokens($html));
    }

    public function deactivateMessage(int $messageId): void
    {
        $this->mentions->deactivateMessage($messageId);
    }

    public function renderFeed(int $feedId, string $html): string
    {
        if ($html === '' || ! str_contains($html, '@')) {
            return $html;
        }

        return $this->render($this->mentions->findForFeed($feedId), $html);
    }

    /**
     * @param array<int, string> $htmlByFeedId
     * @return array<int, string>
     */
    public function renderFeeds(array $htmlByFeedId): array
    {
        $withMentionTokens = array_filter(
            $htmlByFeedId,
            static fn (string $html): bool => str_contains($html, '@'),
        );
        if ($withMentionTokens === []) {
            return $htmlByFeedId;
        }

        $mentionsByFeedId = $this->mentions->findForFeeds(array_keys($withMentionTokens));
        foreach ($withMentionTokens as $feedId => $html) {
            $htmlByFeedId[$feedId] = $this->render($mentionsByFeedId[$feedId] ?? [], $html);
        }

        return $htmlByFeedId;
    }

    public function renderMessage(int $messageId, string $html): string
    {
        if ($html === '' || ! str_contains($html, '@')) {
            return $html;
        }

        return $this->render($this->mentions->findForMessage($messageId), $html);
    }

    public function canDeliverFeed(int $feedId, int $recipientUserId): bool
    {
        if ($this->feeds === null
            || $this->users === null
            || ! $this->mentions->isActiveForFeedUser($feedId, $recipientUserId)
        ) {
            return false;
        }

        $recipient = $this->users->findActiveById($recipientUserId);
        if ($recipient === null) {
            return false;
        }

        try {
            $source = $this->feeds->getFeedById($feedId, $recipient);
            $this->rootFeed($source, $recipient);
            return true;
        } catch (ForbiddenException|NotFoundException) {
            return false;
        }
    }

    public function canDeliverMessage(int $messageId, int $recipientUserId): bool
    {
        if ($this->messages === null
            || $this->participants === null
            || $this->users === null
            || ! $this->mentions->isActiveForMessageUser($messageId, $recipientUserId)
            || $this->users->findActiveById($recipientUserId) === null
        ) {
            return false;
        }

        $message = $this->messages->findById($messageId);

        return $message !== null
            && $message['deleted_at'] === null
            && $this->participants->isParticipant((int) $message['conversation_id'], $recipientUserId);
    }

    /** @return array<string,string> lowercase username => original spelling */
    public function extractTokens(string $html): array
    {
        if ($html === '' || ! str_contains($html, '@')) {
            return [];
        }

        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//se-root//text()[not(ancestor::a or ancestor::code or ancestor::pre or ancestor::blockquote)]');
        $tokens = [];

        foreach ($nodes ?: [] as $node) {
            preg_match_all(self::TOKEN_PATTERN, $node->nodeValue ?? '', $matches);
            foreach ($matches[1] ?? [] as $username) {
                $normalized = strtolower($username);
                $tokens[$normalized] ??= $username;
                if (count($tokens) > self::MAX_MENTIONS) {
                    break 2;
                }
            }
        }

        return $tokens;
    }

    /** @return list<int> */
    private function synchronize(?int $feedId, ?int $messageId, string $html, int $actorUserId): array
    {
        $tokens = $this->extractTokens($html);
        $this->validateTokenCount($tokens);
        $existing = $feedId !== null
            ? $this->mentions->findForFeed($feedId)
            : $this->mentions->findForMessage((int) $messageId);
        $existingBySnapshot = [];
        foreach ($existing as $row) {
            $existingBySnapshot[strtolower($row['usernameSnapshot'])] = $row['userId'];
        }

        $desired = [];
        $unresolved = [];
        foreach ($tokens as $normalized => $spelling) {
            if (isset($existingBySnapshot[$normalized])) {
                $desired[$existingBySnapshot[$normalized]] = $spelling;
            } else {
                $unresolved[] = $spelling;
            }
        }

        foreach ($this->mentions->findActiveUsersByUsernames($unresolved) as $normalized => $user) {
            $desired[$user['id']] = $tokens[$normalized] ?? $user['username'];
        }

        $desired = array_slice($desired, 0, self::MAX_MENTIONS, true);
        $newUserIds = array_values(array_diff(array_keys($desired), array_keys($existing)));
        if ($feedId !== null) {
            $this->mentions->reconcileFeed($feedId, $desired);
        } else {
            $this->mentions->reconcileMessage((int) $messageId, $desired);
        }

        if ($newUserIds !== [] && $actorUserId > 0) {
            $this->notify($feedId, $messageId, $newUserIds, $actorUserId);
        }

        return array_map('intval', $newUserIds);
    }

    /** @param array<int, array{usernameSnapshot:string, active:bool, currentUsername:?string}> $mentions */
    private function render(array $mentions, string $html): string
    {
        $resolved = [];
        foreach ($mentions as $row) {
            if ($row['active'] && $row['currentUsername'] !== null) {
                $resolved[strtolower($row['usernameSnapshot'])] = $row['currentUsername'];
            }
        }

        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $nodes = iterator_to_array(
            $xpath->query('//se-root//text()[not(ancestor::a or ancestor::code or ancestor::pre or ancestor::blockquote)]') ?: [],
        );
        foreach ($nodes as $node) {
            $this->linkTextNode($document, $node, $resolved);
        }

        $root = $document->getElementsByTagName('se-root')->item(0);
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $rendered = '';
        foreach ($root->childNodes as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return $rendered;
    }

    /** @param list<int> $recipientUserIds */
    private function notify(?int $feedId, ?int $messageId, array $recipientUserIds, int $actorUserId): void
    {
        if ($this->notifications === null || $this->users === null || $this->tm === null) {
            return;
        }

        $actor = $this->users->findActiveById($actorUserId);
        if ($actor === null) {
            return;
        }

        $contentUrl = $this->contentUrl($feedId, $messageId, $actor);
        if ($contentUrl === null) {
            return;
        }

        $linkedTarget = '<a href="'.htmlspecialchars($contentUrl, ENT_QUOTES).'">'
            .htmlspecialchars($this->tm->trans('notification.mention_target'), ENT_QUOTES).'</a>';
        $text = $this->tm->trans('notification.mention', [
            'author' => htmlspecialchars($actor->getDisplayName(), ENT_QUOTES),
            'content' => $linkedTarget,
        ]);

        foreach (array_values(array_unique(array_map('intval', $recipientUserIds))) as $recipientUserId) {
            $canDeliver = $feedId !== null
                ? $this->canDeliverFeed($feedId, $recipientUserId)
                : $this->canDeliverMessage((int) $messageId, $recipientUserId);
            if ($recipientUserId <= 0 || $recipientUserId === $actorUserId || ! $canDeliver) {
                continue;
            }

            try {
                $this->notifications->notify(new Notification(
                    recipientUserId: $recipientUserId,
                    type: 'user.mention',
                    messengerText: $text,
                    payload: [
                        'contentUrl' => $this->absoluteUrl($contentUrl),
                        'mentionFeedId' => $feedId,
                        'mentionMessageId' => $messageId,
                        'actorUserId' => $actorUserId,
                        'actorName' => $actor->getDisplayName(),
                    ],
                    deduplicationKey: 'user.mention:'
                        .($feedId !== null ? 'feed:'.$feedId : 'message:'.$messageId)
                        .':'.$recipientUserId,
                ));
            } catch (Throwable $e) {
                error_log('Unable to queue mention notification: '.$e->getMessage());
            }
        }
    }

    private function contentUrl(?int $feedId, ?int $messageId, User $actor): ?string
    {
        if ($messageId !== null && $this->messages !== null && $this->urls !== null) {
            $message = $this->messages->findById($messageId);
            if ($message === null) {
                return null;
            }
            $conversation = Cryptography::encodeOpaqueId((int) $message['conversation_id']);
            $messageCode = Cryptography::encodeOpaqueId($messageId);
            $inbox = $this->urls->action('messages.inbox');

            return $inbox !== null && $conversation !== null && $messageCode !== null
                ? $inbox.'#c='.$conversation.'&m='.$messageCode
                : null;
        }

        if ($feedId === null || $this->feeds === null) {
            return null;
        }
        $source = $this->feeds->getFeedById($feedId, $actor);
        $root = $this->rootFeed($source, $actor);
        if ($root->canonicalUrl === null) {
            return null;
        }
        $parameter = $root->type === 'forum-post' ? 'post' : 'comment';
        $separator = str_contains($root->canonicalUrl, '?') ? '&' : '?';

        return $root->canonicalUrl.$separator.$parameter.'='.$source->id.'#'.$parameter.'-'.$source->id;
    }

    private function rootFeed(Feed $source, User $viewer): Feed
    {
        $root = $source;
        $visited = [];
        while ($root->type === 'comment' && $root->parentId !== null && ! isset($visited[$root->id])) {
            $visited[$root->id] = true;
            $root = $this->feeds?->getFeedById($root->parentId, $viewer) ?? $root;
        }

        return $root;
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return rtrim((string) $this->config?->siteUrl(), '/').'/'.ltrim($url, '/');
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><se-root>'.$html.'</se-root>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @param array<string,string> $tokens */
    private function validateTokenCount(array $tokens): void
    {
        if (count($tokens) > self::MAX_MENTIONS) {
            throw new ValidationException('Too many mentions');
        }
    }

    /** @param array<string,string> $resolved snapshot => current username */
    private function linkTextNode(DOMDocument $document, DOMNode $node, array $resolved): void
    {
        $text = $node->nodeValue ?? '';
        if (! preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $node->nodeValue = $this->displayText($text);
            return;
        }

        $fragment = $document->createDocumentFragment();
        $offset = 0;
        foreach ($matches[0] as $index => [$token, $position]) {
            $fragment->appendChild($document->createTextNode(
                $this->displayText(substr($text, $offset, $position - $offset)),
            ));
            $snapshot = strtolower($matches[1][$index][0]);
            $currentUsername = $resolved[$snapshot] ?? null;
            $url = $currentUsername !== null && $this->urls !== null
                ? $this->urls->action('user.show', ['username' => $currentUsername])
                : null;

            if ($url === null) {
                $fragment->appendChild($document->createTextNode($token));
            } else {
                $link = $document->createElement('a');
                $link->setAttribute('class', 'mention');
                $link->setAttribute('href', $url);
                $link->appendChild($document->createTextNode($token));
                $fragment->appendChild($link);
            }
            $offset = $position + strlen($token);
        }
        $fragment->appendChild($document->createTextNode($this->displayText(substr($text, $offset))));
        $node->parentNode?->replaceChild($fragment, $node);
    }

    private function displayText(string $text): string
    {
        return (string) preg_replace('~\\\\(@[A-Za-z0-9][A-Za-z0-9_-]{2,29})~', '$1', $text);
    }
}

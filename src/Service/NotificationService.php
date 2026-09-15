<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use Throwable;

/**
 * Routes application notifications to their configured delivery channels.
 *
 * Resolves per-user channel preferences and creates durable deliveries.
 * All deliveries are queued. "Instant" means the next cron worker pass;
 * neither messenger nor SMTP I/O runs inside the originating HTTP request.
 */
final readonly class NotificationService
{
    private const string DEFAULT_DAILY_TIME = '09:00';
    private const int MESSENGER_BATCH_SIZE = 1000;
    private const int INSTANT_EMAIL_BATCH_SIZE = 1000;
    private const int DAILY_EMAIL_BATCH_SIZE = 1000;

    private TranslationManager $tm;

    private Config $config;

    private const array CONFIGURABLE_TYPES = [
        'friend.request' => [
            'key' => 'friend_request',
            'translationKey' => 'friend_request',
        ],
        'friend.mutual' => [
            'key' => 'friend_mutual',
            'translationKey' => 'friend_mutual',
        ],
        'forum.reply' => [
            'key' => 'forum_reply',
            'translationKey' => 'forum_reply',
        ],
        'content.comment' => [
            'key' => 'content_comment',
            'translationKey' => 'content_comment',
        ],
        'comment.reply' => [
            'key' => 'comment_reply',
            'translationKey' => 'comment_reply',
        ],
        'friend.post' => [
            'key' => 'friend_post',
            'translationKey' => 'friend_post',
        ],
        'community.join_request' => [
            'key' => 'community_join_request',
            'translationKey' => 'community_join_request',
        ],
        'community.join_approved' => [
            'key' => 'community_join_approved',
            'translationKey' => 'community_join_approved',
        ],
        'community.membership_changed' => [
            'key' => 'community_membership_changed',
            'translationKey' => 'community_membership_changed',
        ],
        'community.post' => [
            'key' => 'community_post',
            'translationKey' => 'community_post',
        ],
        'message.unread_digest' => [
            'key' => 'message_unread_digest',
            'translationKey' => 'message_unread_digest',
            'digestOnly' => true,
        ],
    ];

    public function __construct(
        private MessageService $messages,
        private NotificationDeliveryRepository $deliveries,
        private NotificationPreferenceRepository $preferences,
        private UserRepository $users,
        private MailService $mail,
        TranslationManager $translationManager,
        Config $config,
    ) {
        $this->tm = $translationManager;
        $this->config = $config;
    }

    public function notify(Notification $notification): void
    {
        $preferences = $this->preferences->findForUserAndType(
            $notification->recipientUserId,
            $notification->type,
        );
        $now = time();
        $messengerDelivery = $this->messengerDelivery(
            $preferences['messenger']['delivery'] ?? null,
            $notification->type,
        );
        $emailDelivery = $this->emailDelivery($preferences['email']['delivery'] ?? null);

        if ($messengerDelivery === 'instant') {
            $this->deliveries->create(
                $notification,
                channel: 'messenger',
                delivery: 'instant',
                scheduledAt: $now,
            );
        }

        if ($emailDelivery !== 'off') {
            $scheduledAt = $emailDelivery === 'daily'
                ? $this->nextDailyDeliveryAt(
                    $notification->recipientUserId,
                    $this->users->findDigestDeliveryTimeById($notification->recipientUserId),
                    $now,
                )
                : $now;

            $this->deliveries->create(
                $notification,
                channel: 'email',
                delivery: $emailDelivery,
                scheduledAt: $scheduledAt,
            );
        }
    }

    public function processQueue(): void
    {
        $this->queueUnreadMessageDigests();

        foreach ($this->deliveries->claimDue('messenger', 'instant', self::MESSENGER_BATCH_SIZE) as $delivery) {
            $this->sendMessenger($delivery);
        }

        foreach ($this->deliveries->claimDue('email', 'instant', self::INSTANT_EMAIL_BATCH_SIZE) as $delivery) {
            $this->sendEmail([$delivery], $this->instantEmail($delivery));
        }

        $dailyByRecipient = [];
        foreach ($this->deliveries->claimDue('email', 'daily', self::DAILY_EMAIL_BATCH_SIZE) as $delivery) {
            if ($delivery['notificationType'] === 'message.unread_digest') {
                $preference = $this->preferences->findForUserAndType($delivery['recipientUserId'], 'message.unread_digest');
                $count = $this->deliveries->unreadPersonalMessageCount($delivery['recipientUserId']);
                if (($preference['email']['delivery'] ?? 'off') !== 'daily' || $count === 0) {
                    $this->deliveries->cancel($delivery['id']);
                    continue;
                }
                $delivery['messageText'] = $this->tm->trans('notification.unread_messages', ['count' => $count]);
            }
            $dailyByRecipient[$delivery['recipientUserId']][] = $delivery;
        }

        foreach ($dailyByRecipient as $deliveries) {
            $this->sendEmail($deliveries, $this->dailyEmail($deliveries));
        }
    }

    private function queueUnreadMessageDigests(): void
    {
        $afterId = 0;
        while ($ids = $this->preferences->findUnreadDigestRecipients($afterId)) {
            foreach ($ids as $userId) {
                $afterId = $userId;
                if ($this->deliveries->unreadPersonalMessageCount($userId) === 0) {
                    continue;
                }
                $scheduledAt = $this->nextDailyDeliveryAt(
                    $userId,
                    $this->users->findDigestDeliveryTimeById($userId),
                    time(),
                );
                $siteUrl = $this->config->siteUrl();
                $url = $siteUrl !== null ? $siteUrl.'/messages/' : null;
                $this->deliveries->create(
                    new Notification(
                        recipientUserId: $userId,
                        type: 'message.unread_digest',
                        messengerText: $this->tm->trans('notification.unread_messages_title'),
                        payload: ['contentUrl' => $url],
                        deduplicationKey: 'message.unread_digest:'.$scheduledAt,
                    ),
                    channel: 'email',
                    delivery: 'daily',
                    scheduledAt: $scheduledAt,
                );
            }
        }
    }

    public function notifyCommentCreated(Feed $comment, Feed $parent, Feed $root, User $author): void
    {
        $notifiedUserIds = [];
        $contentUrl = $root->canonicalUrl !== null ? $root->canonicalUrl.'#comments' : null;
        $contentTitle = trim((string) $root->title);
        $contentLabel = $contentTitle !== '' ? '«'.$contentTitle.'»' : $this->tm->trans('notification.untitled');
        $linkedContentLabel = $contentUrl !== null
            ? '<a href="'.htmlspecialchars($contentUrl, ENT_QUOTES).'">'.htmlspecialchars($contentLabel, ENT_QUOTES).'</a>'
            : htmlspecialchars($contentLabel, ENT_QUOTES);
        $authorName = $author->getDisplayName();

        if ($parent->type === 'comment' && $parent->ownerId !== $author->id) {
            $recipientUserId = $parent->ownerId;
            $notifiedUserIds[$recipientUserId] = true;

            $this->notify(new Notification(
                recipientUserId: $recipientUserId,
                type: 'comment.reply',
                messengerText: $this->tm->trans('notification.comment_reply', [
                    'author' => htmlspecialchars($authorName, ENT_QUOTES),
                    'content' => $linkedContentLabel,
                ]),
                payload: [
                    'commentId' => $comment->id,
                    'parentCommentId' => $parent->id,
                    'contentId' => $root->id,
                    'contentTitle' => $contentTitle,
                    'contentUrl' => $contentUrl,
                    'actorUserId' => $author->id,
                    'actorName' => $authorName,
                ],
                deduplicationKey: 'comment.reply:'.$comment->id.':'.$recipientUserId,
            ));
        }

        if ($root->type !== 'blog-post'
            || $root->ownerId === $author->id
            || isset($notifiedUserIds[$root->ownerId])) {
            return;
        }

        $recipientUserId = $root->ownerId;
        $this->notify(new Notification(
            recipientUserId: $recipientUserId,
            type: 'content.comment',
            messengerText: $this->tm->trans('notification.content_comment', [
                'author' => htmlspecialchars($authorName, ENT_QUOTES),
                'content' => $linkedContentLabel,
            ]),
            payload: [
                'commentId' => $comment->id,
                'contentId' => $root->id,
                'contentTitle' => $contentTitle,
                'contentUrl' => $contentUrl,
                'actorUserId' => $author->id,
                'actorName' => $authorName,
            ],
            deduplicationKey: 'content.comment:'.$comment->id.':'.$recipientUserId,
        ));
    }

    /** @param list<int> $recipientUserIds */
    public function notifyFriendsAboutPost(Feed $post, User $author, array $recipientUserIds): void
    {
        $title = trim((string) $post->title);
        $label = $title !== '' ? '«'.$title.'»' : $this->tm->trans('notification.untitled');
        $linkedLabel = $post->canonicalUrl !== null
            ? '<a href="'.htmlspecialchars($post->canonicalUrl, ENT_QUOTES).'">'.htmlspecialchars($label, ENT_QUOTES).'</a>'
            : htmlspecialchars($label, ENT_QUOTES);
        $message = $this->tm->trans('notification.friend_post', [
            'author' => htmlspecialchars($author->getDisplayName(), ENT_QUOTES),
            'content' => $linkedLabel,
        ]);

        foreach (array_values(array_unique(array_map('intval', $recipientUserIds))) as $recipientUserId) {
            if ($recipientUserId <= 0 || $recipientUserId === $author->id) {
                continue;
            }

            try {
                $this->notify(new Notification(
                    recipientUserId: $recipientUserId,
                    type: 'friend.post',
                    messengerText: $message,
                    payload: [
                        'contentId' => $post->id,
                        'contentTitle' => $title,
                        'contentUrl' => $post->canonicalUrl,
                        'actorUserId' => $author->id,
                        'actorName' => $author->getDisplayName(),
                    ],
                    deduplicationKey: 'friend.post:'.$post->id.':'.$recipientUserId,
                ));
            } catch (Throwable $e) {
                error_log('Unable to notify user '.$recipientUserId.' about post '.$post->id.': '.$e->getMessage());
            }
        }
    }

    /** @param list<int> $recipientUserIds */
    public function notifyCommunityMembersAboutPost(
        Feed $post,
        Feed $community,
        User $author,
        array $recipientUserIds,
    ): void {
        $title = trim((string) $post->title);
        $label = $title !== '' ? '«'.$title.'»' : $this->tm->trans('notification.untitled');
        $linkedLabel = $post->canonicalUrl !== null
            ? '<a href="'.htmlspecialchars($post->canonicalUrl, ENT_QUOTES).'">'.htmlspecialchars($label, ENT_QUOTES).'</a>'
            : htmlspecialchars($label, ENT_QUOTES);
        $communityTitle = trim((string) $community->title);
        $communityLabel = $communityTitle !== '' ? '«'.$communityTitle.'»' : $this->tm->trans('notification.untitled');
        $message = $this->tm->trans('notification.community_post', [
            'author' => htmlspecialchars($author->getDisplayName(), ENT_QUOTES),
            'community' => htmlspecialchars($communityLabel, ENT_QUOTES),
            'content' => $linkedLabel,
        ]);

        foreach (array_values(array_unique(array_map('intval', $recipientUserIds))) as $recipientUserId) {
            if ($recipientUserId <= 0 || $recipientUserId === $author->id) {
                continue;
            }

            try {
                $this->notify(new Notification(
                    recipientUserId: $recipientUserId,
                    type: 'community.post',
                    messengerText: $message,
                    payload: [
                        'contentId' => $post->id,
                        'contentTitle' => $title,
                        'contentUrl' => $post->canonicalUrl,
                        'communityId' => $community->id,
                        'communityTitle' => $communityTitle,
                        'communityUrl' => $community->canonicalUrl,
                        'actorUserId' => $author->id,
                        'actorName' => $author->getDisplayName(),
                    ],
                    deduplicationKey: 'community.post:'.$post->id.':'.$recipientUserId,
                ));
            } catch (Throwable $e) {
                error_log('Unable to notify user '.$recipientUserId.' about community post '.$post->id.': '.$e->getMessage());
            }
        }
    }

    /**
     * @return array{
     *     items: list<array{
     *         type: string,
     *         key: string,
     *         label: string,
     *         description: string,
     *         messenger: string,
     *         email: string
     *     }>,
     *     digestDeliveryTime: string
     * }
     */
    public function preferenceSettingsForUser(int $userId): array
    {
        $items = [];
        $digestDeliveryTime = $this->users->findDigestDeliveryTimeById($userId);

        foreach (self::CONFIGURABLE_TYPES as $type => $definition) {
            $preference = $this->preferences->findForUserAndType($userId, $type);
            $translationKey = $definition['translationKey'];
            $items[] = [
                'type' => $type,
                'key' => $definition['key'],
                'label' => $this->tm->trans('notification.preference.'.$translationKey.'.label'),
                'description' => $this->tm->trans('notification.preference.'.$translationKey.'.description'),
                'digestOnly' => ! empty($definition['digestOnly']),
                'messenger' => $this->messengerDelivery($preference['messenger']['delivery'] ?? null, $type),
                'email' => $this->emailDelivery($preference['email']['delivery'] ?? null),
            ];
        }

        return [
            'items' => $items,
            'digestDeliveryTime' => substr($digestDeliveryTime ?? self::DEFAULT_DAILY_TIME, 0, 5),
        ];
    }

    /** @param array<string, mixed> $input */
    public function updatePreferenceSettings(int $userId, array $input): void
    {
        $updates = [];
        $digestDeliveryTime = trim((string) ($input['digest_delivery_time'] ?? ''));

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $digestDeliveryTime) !== 1) {
            throw new ValidationException($this->tm->trans('notification.daily_time_invalid'));
        }

        foreach (self::CONFIGURABLE_TYPES as $type => $definition) {
            $key = $definition['key'];
            $messenger = ! empty($definition['digestOnly']) ? 'off' : ($input[$key.'_messenger'] ?? null);
            $email = $input[$key.'_email'] ?? (! empty($definition['digestOnly']) ? 'off' : null);

            if (! in_array($messenger, ['off', 'instant'], true)) {
                throw new ValidationException($this->tm->trans('notification.messenger_mode_invalid'));
            }

            if (! in_array($email, ! empty($definition['digestOnly']) ? ['off', 'daily'] : ['off', 'instant', 'daily'], true)) {
                throw new ValidationException($this->tm->trans('notification.email_mode_invalid'));
            }

            $updates[$type] = [
                'messenger' => $messenger,
                'email' => $email,
            ];
        }

        $this->preferences->saveForUser($userId, $updates, $digestDeliveryTime);
    }

    public function canUnsubscribeFromEmail(string $token): bool
    {
        return $this->preferences->findUserIdByEmailUnsubscribeToken($token) !== null;
    }

    public function unsubscribeFromEmail(string $token): bool
    {
        $userId = $this->preferences->findUserIdByEmailUnsubscribeToken($token);
        if ($userId === null) {
            return false;
        }

        $this->preferences->unsubscribeUserFromEmail($userId);
        $this->deliveries->cancelPendingEmailForUser($userId);

        return true;
    }

    private function messengerDelivery(?string $delivery, ?string $notificationType = null): string
    {
        if ($notificationType === 'message.unread_digest') {
            return 'off';
        }

        if (in_array($delivery, ['off', 'instant'], true)) {
            return $delivery;
        }

        return in_array($notificationType, ['friend.post', 'community.post'], true) ? 'off' : 'instant';
    }

    private function emailDelivery(?string $delivery): string
    {
        return in_array($delivery, ['off', 'instant', 'daily'], true) ? $delivery : 'off';
    }

    private function nextDailyDeliveryAt(int $userId, ?string $deliveryTime, int $now): int
    {
        $deliveryTime = is_string($deliveryTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $deliveryTime)
            ? $deliveryTime
            : self::DEFAULT_DAILY_TIME;
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $deliveryTime)), 3, 0);

        try {
            $timezone = new DateTimeZone($this->users->findTimezoneById($userId) ?? 'UTC');
        } catch (DateInvalidTimeZoneException) {
            $timezone = new DateTimeZone('UTC');
        }

        $localNow = (new DateTimeImmutable('@'.$now))->setTimezone($timezone);
        $scheduled = $localNow->setTime($hour, $minute, $second);

        if ($scheduled <= $localNow) {
            $scheduled = $scheduled->modify('+1 day');
        }

        return $scheduled->getTimestamp();
    }

    /** @param array{id: int, recipientUserId: int, messageText: string} $delivery */
    private function sendMessenger(array $delivery): void
    {
        try {
            $this->messages->notify($delivery['recipientUserId'], $delivery['messageText']);
            $this->deliveries->markSent([$delivery['id']]);
        } catch (Throwable $e) {
            try {
                $this->deliveries->markFailed([$delivery['id']], $e->getMessage());
            } catch (Throwable $markFailedError) {
                error_log('Unable to mark messenger notification as failed: '.$markFailedError->getMessage());
            }
        }
    }

    /**
     * @param non-empty-list<array{id: int, recipientUserId: int, email: string}> $deliveries
     * @param array{subject: string, template: string, context: array} $message
     */
    private function sendEmail(array $deliveries, array $message): void
    {
        $ids = array_column($deliveries, 'id');

        try {
            $this->mail->send(
                $deliveries[0]['email'],
                $message['subject'],
                $message['template'],
                $message['context'],
                $this->emailUnsubscribePath($deliveries[0]['recipientUserId']),
            );
            $this->deliveries->markSent($ids);
        } catch (Throwable $e) {
            try {
                $this->deliveries->markFailed($ids, $e->getMessage());
            } catch (Throwable $markFailedError) {
                error_log('Unable to mark notification email as failed: '.$markFailedError->getMessage());
            }
        }
    }

    /**
     * @param array{notificationType: string, messageText: string, payload: array} $delivery
     * @return array{subject: string, template: string, context: array}
     */
    private function instantEmail(array $delivery): array
    {
        return [
            'subject' => match ($delivery['notificationType']) {
                'system.welcome' => $this->tm->trans('notification.subject.welcome'),
                'friend.request' => $this->tm->trans('notification.subject.friend_request'),
                'friend.mutual' => $this->tm->trans('notification.subject.friend_mutual'),
                'forum.reply' => $this->tm->trans('notification.subject.forum_reply'),
                'content.comment' => $this->tm->trans('notification.subject.content_comment'),
                'comment.reply' => $this->tm->trans('notification.subject.comment_reply'),
                'friend.post' => $this->tm->trans('notification.subject.friend_post'),
                'community.post' => $this->tm->trans('notification.subject.community_post'),
                'community.join_request' => $this->tm->trans('notification.subject.community_join_request'),
                'community.join_approved' => $this->tm->trans('notification.subject.community_join_approved'),
                'community.membership_changed' => $this->tm->trans('notification.subject.community_membership_changed'),
                default => $this->tm->trans('notification.subject.default'),
            },
            'template' => 'notifications/instant',
            'context' => ['notification' => $this->emailItem($delivery)],
        ];
    }

    /**
     * @param non-empty-list<array{notificationType: string, messageText: string, payload: array}> $deliveries
     * @return array{subject: string, template: string, context: array}
     */
    private function dailyEmail(array $deliveries): array
    {
        return [
            'subject' => $this->tm->trans('notification.subject.daily'),
            'template' => 'notifications/digest',
            'context' => [
                'notifications' => array_map($this->emailItem(...), $deliveries),
            ],
        ];
    }

    /**
     * @param array{messageText: string, payload: array} $delivery
     * @return array{text: string, url: ?string}
     */
    private function emailItem(array $delivery): array
    {
        $url = $delivery['payload']['topicUrl']
            ?? $delivery['payload']['contentUrl']
            ?? $delivery['payload']['actorUrl']
            ?? null;

        return [
            'text' => trim(html_entity_decode(strip_tags($delivery['messageText']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'url' => $this->safeEmailUrl($url),
        ];
    }

    private function safeEmailUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private function emailUnsubscribePath(int $userId): string
    {
        $token = $this->preferences->getOrCreateEmailUnsubscribeToken($userId);

        return '/unsubscribe?token='.rawurlencode($token);
    }
}

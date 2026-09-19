<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Feedback;

use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\View\ViewModel;

class FeedbackController extends AbstractController
{
    public static function pageActions(): array
    {
        return [
            'feedback.show' => [
                'label' => 'Feedback form',
                'fields' => [
                    'feedId' => 'optional',
                ],
            ],
        ];
    }

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly FeedService $feedService,
        private readonly TranslationManager $tm,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly Config $config,
        private readonly UrlGenerator $urlGenerator,
    ) {
        parent::__construct($db, $context);
    }

    /**
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        $hintFeed = $page->feedId !== null
            ? $this->feedService->getFeedById($page->feedId, $this->context->user)
            : null;

        $now = time();

        return ViewModel::fromPage(
            $page,
            'modules/feedback/page.twig',
            [
                'title' => $hintFeed?->title ?? $page->pageName,
                'description' => $hintFeed?->description,
                'image' => $hintFeed?->imageUrl,
                'canonical' => $hintFeed?->canonicalUrl,
                'head_ext' => [
                    '<script type="module" src="/assets/js/feedback.js" defer></script>',
                ],
                'hash' => hash_hmac('sha256', (string) $now, $this->config->appSecret()),
                'time' => $now,
                'hintText' => $hintFeed?->content ?? '',
                'success' => $this->context->query->string('success') === '1',
                'successUrl' => $this->urlGenerator->page(
                    $page,
                    query: ['success' => 1],
                    fragment: 'feedbackFormHeader'
                ),
            ]
        );
    }

    /**
     * @throws ValidationException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');
        match ($page->action) {
            'feedback.post' => $this->handleFeedbackPostRequest(),
            default => throw new ValidationException('Unknown action'),
        };
    }

    /**
     * @throws ValidationException
     */
    private function handleFeedbackPostRequest(): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        // A non-JSON body decoded to null, and every read below then hit an
        // offset on null - which the form_hash comparison turned into a
        // TypeError, i.e. a 500 on a public unauthenticated endpoint from a
        // request as simple as an empty POST body.
        $data = is_array($data) ? $data : [];

        // Refuse to run with no secret rather than keying an HMAC with '' and
        // accepting anything a caller invents - same reasoning as
        // APIController::handleCronRequest()'s empty-key guard. A
        // misconfiguration, so it says so instead of pretending to have sent.
        $secret = $this->config->appSecret();

        if ($secret === '') {
            throw new RuntimeException('Feedback form is not configured: APP_SECRET is empty.');
        }

        // honeypot
        if (!empty($data['website']) && strlen($data['website']) > 0) {
            print Formatter::json(["status" => "ok"]);
            return; // bot
        }

        // Checking a form fill time
        $formTime = (int)($data['form_time'] ?? 0);

        if (time() - $formTime < 3) {
            print Formatter::json(["status" => "ok"]);
            return; // Too fast
        }

        $formHash = $data['form_hash'] ?? null;

        // A missing or non-string hash is answered exactly like a mismatched
        // one - silent "ok", nothing sent. Deliberate: it is indistinguishable
        // from a bot that omitted the field, and the three branches here all
        // decline to tell the caller which check it failed. The cost is that a
        // template that stops rendering form_hash would fail silently, but that
        // was already true of the mismatch branch.
        if (! is_string($formHash) || ! hash_equals(
            hash_hmac('sha256', (string) $formTime, $secret),
            $formHash
        )) {
            print Formatter::json(["status" => "ok"]);
            return;
        }

        $name = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $message = trim($data['message'] ?? '');

        if (!$name || !$email || !$message) {
            throw new ValidationException($this->tm->trans('feedback.data_invalid'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException($this->tm->trans('feedback.email_invalid'));
        }

        if (mb_strlen($message) < 30) {
            throw new ValidationException($this->tm->trans('feedback.message_too_short'));
        }
        if (mb_strlen($message) > 2000) {
            throw new ValidationException($this->tm->trans('feedback.message_too_long'));
        }

        $text = $this->tm->trans('feedback.notification', [
            'name' => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'email' => htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'message' => htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);

        foreach ($this->users->findAdministratorIds() as $administratorId) {
            $this->notifications->notify(new Notification(
                recipientUserId: $administratorId,
                type: 'system.feedback',
                messengerText: $text,
                payload: ['name' => $name, 'email' => $email, 'message' => $message],
            ));
        }

        print Formatter::json(["status" => "ok"]);

    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $pageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $pageId,
                parentId: $apiPageId,
                pattern: 'feedback',
                requestMethods: ['POST'],
                action: 'feedback.post',
            )
        );
    }
}

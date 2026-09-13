<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Messages;

use Exception;
use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MessageService;
use StreamEngine\View\ViewModel;
use Throwable;

class MessagesController extends AbstractController
{
    public static function pageActions(): array
    {
        return [
            'messages.inbox' => 'Messages inbox',
        ];
    }

    public function __construct(
        protected PdoDatabase $db,
        protected FeedService $feedService,
        private readonly MessageService $messageService,
        private readonly TranslationManager $tm,
        protected RequestContext $context
    ) {
        parent::__construct($db, $context);
    }
    /**
     * @throws ForbiddenException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        if ($this->context->user->isGuest()) {
            throw new ForbiddenException("You don't have permission to view this page.");
        }
        return ViewModel::fromPage(
            $page,
            'modules/messages/page.twig',
            [
                'head_ext' => [
                    '<script type="module" src="/assets/js/messages.js" defer></script>',
                    '<link rel="stylesheet" href="/assets/css/messages.css">'
                ],
                'description' => $page->pageName,
            ]
        );
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws Throwable
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');
        if ($this->context->user->isGuest()) {
            throw new ForbiddenException("You don't have permission to view this page.");
        }

        match ($page->action) {
            'conversations.list' => $this->handleConversationsRequest(),
            'conversation.show' => $this->handleConversationIdRequest($args),
            'conversation.typing' => $this->handleTypingRequest($args),
            'conversation.messages' => $this->handleMessagesRequest($args),
            'message.mutate' => $this->handleMessageIdRequest($args),
            'conversation.participants' => $this->handleParticipantsRequest($args),
            'conversation.participant.remove' => $this->handleUserIdRequest($args),
            'conversation.read' => $this->handleReadRequest($args),
            default => throw new RuntimeException('Unknown route'),
        };
    }

    /**
     * 405 for a method the handler has no branch for.
     *
     * Every handler below dispatches on REQUEST_METHOD with a chain of `if`s
     * and no `else`, so an unmatched method used to run off the end of the
     * function and return normally - which callApi() takes as success, so the
     * response was an empty body with HTTP 200. The client parses `null`,
     * finds none of the fields it wanted, and reports something unrelated.
     *
     * Unreachable from outside today: handleRequest() 405s anything not in
     * the route's own `requestMethods` before the controller is even built,
     * and every route above declares exactly the methods its handler tests
     * for. So this is defence in depth against those two lists drifting apart
     * - which is a one-line edit in registerApi() away, and would otherwise
     * fail silently.
     */
    private function methodNotAllowed(): ValidationException
    {
        return new ValidationException(
            sprintf(
                'Method %s is not supported by this endpoint.',
                $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ),
            405,
            'method_not_allowed'
        );
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    private function handleConversationsRequest(): void
    {
        $userId = $this->context->user->id;
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo Formatter::json(
                $this->messageService->getUserConversationsWithMeta($userId)
            );
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);

            if (!empty($input['user_id'])) {
                $id = $this->messageService->createOrGetDirect(
                    $userId,
                    (int)$input['user_id']
                );

                echo Formatter::json(['conversation_id' => $id]);
                return;
            }

            if (!empty($input['user_ids'])) {
                $id = $this->messageService->createGroup(
                    $userId,
                    $input['user_ids'],
                    $input['title'] ?? null
                );

                echo Formatter::json(['conversation_id' => $id]);
                return;
            }

            throw new ValidationException('Invalid payload');
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws ForbiddenException
     */
    private function handleConversationIdRequest(array $args): void
    {
        $userId = $this->context->user->id;
        $conversationId = (int)$args['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo Formatter::json(
                $this->messageService->getConversation($conversationId, $userId)
            );

            return;
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleTypingRequest(array $args): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $userId = $this->context->user->id;
        $conversationId = (int)$args['id'];

        $this->messageService->typing($conversationId, $userId);

        echo Formatter::json(['status' => 'ok']);
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     * @throws Throwable
     */
    private function handleMessagesRequest(array $args): void
    {
        $userId = $this->context->user->id;
        $conversationId = (int)$args['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);

            $messageId = $this->messageService->send(
                $conversationId,
                $userId,
                $input['text'] ?? '',
                !empty($input['reply_to_message_id']) ? (int)$input['reply_to_message_id'] : null,
                !empty($input['attachment_upload_id']) ? (int)$input['attachment_upload_id'] : null
            );

            echo Formatter::json(['message_id' => $messageId]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $afterId = $this->context->query->int('after_id');

            echo Formatter::json(
                $this->messageService->getMessagesWithMeta($conversationId, $userId, $afterId)
            );

            return;
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleMessageIdRequest(array $args): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $userId = $this->context->user->id;
        $messageId = (int)$args['message_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            try {
                $this->messageService->delete($messageId, $userId);
            } catch (Exception $e) {
                error_log($e->getMessage());
                // The cause is already in the log above; what the client gets
                // is the same 403 as before, now as a deliberate answer rather
                // than as the blanket mapping of any exception at all.
                throw new ForbiddenException('Unable to delete this message.');
            }
            echo Formatter::json(['status' => 'ok']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            $input = json_decode(file_get_contents('php://input'), true);

            $this->messageService->edit(
                $messageId,
                $userId,
                $input['text'] ?? ''
            );

            echo Formatter::json(['status' => 'ok']);

            return;
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleParticipantsRequest(array $args): void
    {
        $userId = $this->context->user->id;
        $conversationId = (int)$args['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);

            $this->messageService->addParticipants(
                $conversationId,
                $userId,
                $input['user_ids'] ?? []
            );

            echo Formatter::json(['status' => 'ok']);

            return;
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleUserIdRequest(array $args): void
    {
        $actorId = $this->context->user->id;

        $conversationId = (int)$args['id'];
        $targetUserId = (int)$args['user_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $this->messageService->removeParticipant(
                $conversationId,
                $actorId,
                $targetUserId
            );
            echo Formatter::json(['status' => 'ok']);

            return;
        }

        throw $this->methodNotAllowed();
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleReadRequest(array $args): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $userId = $this->context->user->id;
        $conversationId = (int)$args['id'];

        $input = json_decode(file_get_contents('php://input'), true);

        $messageId = (int)($input['message_id'] ?? 0);

        $this->messageService->markAsRead($conversationId, $userId, $messageId);

        echo Formatter::json(['status' => 'ok']);
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $conversationPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $conversationPageId,
                parentId: $apiPageId,
                pattern: 'conversations',
                requestMethods: ['GET','POST'],
                action: 'conversations.list',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $conversationIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $conversationIdPageId,
                parentId: $conversationPageId,
                pattern: '{id}',
                requestMethods: ['GET'],
                action: 'conversation.show',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $typingPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $typingPageId,
                parentId: $conversationIdPageId,
                pattern: 'typing',
                requestMethods: ['POST'],
                action: 'conversation.typing',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );

        $messagesPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $messagesPageId,
                parentId: $conversationIdPageId,
                pattern: 'messages',
                requestMethods: ['GET', 'POST'],
                action: 'conversation.messages',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $messageIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $messageIdPageId,
                parentId: $messagesPageId,
                pattern: '{message_id}',
                requestMethods: ['DELETE','PATCH'],
                action: 'message.mutate',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $participantsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $participantsPageId,
                parentId: $conversationIdPageId,
                pattern: 'participants',
                requestMethods: ['POST'],
                action: 'conversation.participants',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $participantIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $participantIdPageId,
                parentId: $participantsPageId,
                pattern: '{user_id}',
                requestMethods: ['DELETE'],
                action: 'conversation.participant.remove',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
        $conversationReadPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $conversationReadPageId,
                parentId: $conversationIdPageId,
                pattern: 'read',
                requestMethods: ['POST'],
                action: 'conversation.read',
                accessRule: AccessService::ACCESS_AUTHENTICATED
            )
        );
    }
}

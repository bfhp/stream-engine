<?php

declare(strict_types=1);

namespace Tests\Modules\Messages;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Messages\MessagesController;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MessageService;
use Throwable;

final class MessagesControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_COOKIE['csrfToken'],
        );
    }

    private function makeModule(?User $user = null): MessagesController
    {
        return new MessagesController(
            $this->createStub(PdoDatabase::class),
            $this->createStub(FeedService::class),
            // MessageService is `final`, so it can't be createStub()'d -
            // built via reflection without its own constructor instead.
            // Safe here since no test is meant to complete a messageService
            // call: registerApi() is static, show() throws for a guest first,
            // and the callApi() tests are all about the CSRF check that sits
            // in front of the work. The read-path test leans on this
            // deliberately - see testCallApiAllowsGetWithoutCsrfToken().
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new TranslationManager('ru', 'en'),
            new RequestContext(
                $user ?? new User(id: 1, email: 'user@example.com', role: AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            )
        );
    }

    /**
     * @param list<string> $requestMethods
     */
    private function makeApiPage(string $action, array $requestMethods): Page
    {
        return Page::api(
            id: 20,
            parentId: 10,
            pattern: 'stub',
            requestMethods: $requestMethods,
            action: $action,
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    public function testRegisterApiAddsPagesWithExplicitActions(): void
    {
        $apiV1 = new Page(
            id: 10,
            parentId: 1,
            pattern: 'v1',
            pageName: 'API v1',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'api.v1.index',
        );
        $tree = new PageTree([$apiV1]);

        MessagesController::registerApi(10, $tree);

        $conversationPage = $tree->findChildren(10)[0];
        $this->assertSame('conversations', $conversationPage->pattern);
        $this->assertSame('conversations.list', $conversationPage->action);

        $conversationChildren = $tree->findChildren($conversationPage->id);
        $this->assertCount(1, $conversationChildren);
        $conversationIdPage = $conversationChildren[0];
        $this->assertSame('conversation.show', $conversationIdPage->action);

        $nestedByPattern = [];
        foreach ($tree->findChildren($conversationIdPage->id) as $page) {
            $nestedByPattern[$page->pattern] = $page;
        }

        $this->assertSame('conversation.typing', $nestedByPattern['typing']->action);
        $this->assertSame('conversation.messages', $nestedByPattern['messages']->action);
        $this->assertSame('conversation.participants', $nestedByPattern['participants']->action);
        $this->assertSame('conversation.read', $nestedByPattern['read']->action);

        $messageIdPage = $tree->findChildren($nestedByPattern['messages']->id)[0];
        $this->assertSame('message.mutate', $messageIdPage->action);

        $participantIdPage = $tree->findChildren($nestedByPattern['participants']->id)[0];
        $this->assertSame('conversation.participant.remove', $participantIdPage->action);
    }

    public function testShowRejectsGuestUser(): void
    {
        $module = $this->makeModule(new User(id: 0, email: '', role: AccessService::ROLE_USER));
        $page = new Page(
            id: 1,
            parentId: null,
            pattern: 'messages',
            pageName: 'Messages',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
            action: 'messages.inbox',
        );

        $this->expectException(ForbiddenException::class);

        $module->show($page);
    }

    /**
     * The whole controller once shipped without a single
     * Security::verifyCsrf() call, so a cross-site POST carrying the
     * visitor's session cookie could send, edit and delete their private
     * messages. callApi() now gates every non-GET request centrally.
     */
    /**
     * Every mutating action, one case each. The checks live in the handlers
     * rather than in callApi(), so this provider is what stops a ninth
     * mutating action from shipping without one - add the action here and the
     * missing call fails the suite.
     *
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function mutatingActionProvider(): array
    {
        return [
            'create conversation' => ['conversations.list', 'POST', []],
            'typing indicator' => ['conversation.typing', 'POST', ['id' => '5']],
            'send message' => ['conversation.messages', 'POST', ['id' => '5']],
            'delete message' => ['message.mutate', 'DELETE', ['id' => '5', 'message_id' => '9']],
            'edit message' => ['message.mutate', 'PATCH', ['id' => '5', 'message_id' => '9']],
            'add participants' => ['conversation.participants', 'POST', ['id' => '5']],
            'remove participant' => [
                'conversation.participant.remove',
                'DELETE',
                ['id' => '5', 'user_id' => '7'],
            ],
            'mark as read' => ['conversation.read', 'POST', ['id' => '5']],
        ];
    }

    /**
     * @param array<string, string> $args
     */
    #[DataProvider('mutatingActionProvider')]
    public function testCallApiRejectsMutationWithoutCsrfToken(
        string $action,
        string $method,
        array $args,
    ): void {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = $method;
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        // A ValidationException rather than anything else is the assertion:
        // messageService is a constructor-less reflection instance, so had the
        // handler got past verifyCsrf() it would have died with an Error on an
        // uninitialized property instead. Nothing reads php://input either -
        // the check comes before every json_decode().
        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage($action, [$method]), $args);
    }

    /**
     * @param array<string, string> $args
     */
    #[DataProvider('mutatingActionProvider')]
    public function testCallApiRejectsMutationWithMismatchedCsrfToken(
        string $action,
        string $method,
        array $args,
    ): void {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = $method;
        $_COOKIE['csrfToken'] = str_repeat('a', 64);
        // Same length as the real thing, so this fails on hash_equals() rather
        // than on the emptiness check above it.
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);

        $this->expectException(ValidationException::class);

        $module->callApi($this->makeApiPage($action, [$method]), $args);
    }

    /**
     * The reads have to be checked too: requiring a token on a GET would break
     * the messenger's polling and the global unread badge, so the two
     * GET/POST handlers must only demand one on the POST branch.
     */
    #[DataProvider('readActionProvider')]
    public function testCallApiAllowsGetWithoutCsrfToken(string $action): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        ob_start();

        try {
            $module->callApi($this->makeApiPage($action, ['GET']), ['id' => '5']);
            $reachedHandlerBody = true;
        } catch (ValidationException) {
            $reachedHandlerBody = false;
        } catch (Throwable) {
            // Past the check, the read runs against the constructor-less
            // MessageService and dies on an uninitialized property. Getting
            // that far is the point - it's exactly what the mutating cases
            // above must not do.
            $reachedHandlerBody = true;
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($reachedHandlerBody, 'A GET must not require a CSRF token');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readActionProvider(): array
    {
        return [
            // GET/POST handler: only its POST branch may demand a token.
            'list conversations' => ['conversations.list'],
            'read messages' => ['conversation.messages'],
            // GET-only handlers, which should have no check at all.
            'show conversation' => ['conversation.show'],
        ];
    }

    /* ===============================
       Methods no handler branches on
    =============================== */

    /**
     * Every handler dispatches with a chain of `if ($_SERVER['REQUEST_METHOD']
     * === …)` and no `else`, so a method none of them matched used to run off
     * the end of the function and return - which callApi() reads as success.
     * The response was an **empty body with HTTP 200**: the client parses
     * `null`, finds none of the fields it wanted, and reports something
     * unrelated, or nothing.
     *
     * The method for each case is one the route does not declare, so it is
     * unreachable from outside - handleRequest() 405s first. That is the
     * point: the guard exists for the day those two lists drift apart, and
     * without it the drift is silent.
     *
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function unsupportedMethodProvider(): array
    {
        return [
            'conversations, PUT' => ['conversations.list', 'PUT', []],
            'conversations, DELETE' => ['conversations.list', 'DELETE', []],
            'show conversation, POST' => ['conversation.show', 'POST', ['id' => '5']],
            'messages, DELETE' => ['conversation.messages', 'DELETE', ['id' => '5']],
            'mutate message, POST' => ['message.mutate', 'POST', ['id' => '5', 'message_id' => '9']],
            'mutate message, GET' => ['message.mutate', 'GET', ['id' => '5', 'message_id' => '9']],
            'participants, GET' => ['conversation.participants', 'GET', ['id' => '5']],
            'remove participant, GET' => [
                'conversation.participant.remove',
                'GET',
                ['id' => '5', 'user_id' => '7'],
            ],
        ];
    }

    /**
     * @param array<string, string> $args
     */
    #[DataProvider('unsupportedMethodProvider')]
    public function testAnUnsupportedMethodIsAFourOhFiveRatherThanAnEmptyTwoHundred(
        string $action,
        string $method,
        array $args,
    ): void {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = $method;

        // A matching pair, so the handlers that verify CSRF before dispatching
        // on the method (message.mutate does) get past that check and reach
        // the fall-through this is about.
        $token = str_repeat('a', 64);
        $_COOKIE['csrfToken'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        ob_start();

        try {
            $module->callApi($this->makeApiPage($action, [$method]), $args);
            $thrown = null;
        } catch (ValidationException $e) {
            $thrown = $e;
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertNotNull($thrown, 'An unhandled method must not return normally');
        $this->assertSame(405, $thrown->getHttpCode());
        $this->assertSame('method_not_allowed', $thrown->getCodeName());
        $this->assertStringContainsString($method, $thrown->getMessage());
        // And nothing was written before the throw - a partial body would be
        // sent alongside the error and break the client's parse.
        $this->assertSame('', $output);
    }

    /**
     * The `default` arm of callApi()'s match. An action that is in the page
     * tree but not in the match is a wiring mistake in registerApi(), so it
     * stays a RuntimeException - which Core\ApiErrorResponse now turns into a
     * logged 500 rather than the 403 it used to be.
     */
    public function testAnUnknownActionIsABugRatherThanAnAnswer(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown route');

        $module->callApi($this->makeApiPage('conversation.archive', ['GET']), []);
    }

    /**
     * Not a ValidationException, which is the distinction ApiErrorResponse
     * reads: a wiring mistake must not be able to present itself to the client
     * as a deliberate answer with a status of its choosing.
     */
    public function testAnUnknownActionIsNotAValidationException(): void
    {
        $module = $this->makeModule();

        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $module->callApi($this->makeApiPage('conversation.archive', ['GET']), []);
            $this->fail('An unknown action must throw');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e);
        }
    }

    public function testCallApiRejectsGuestBeforeCheckingCsrf(): void
    {
        $module = $this->makeModule(new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_COOKIE['csrfToken'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        // Order matters for the message the caller gets: a guest should be
        // told they're not allowed here, not handed a CSRF error about a
        // token they were never issued.
        $this->expectException(ForbiddenException::class);

        $module->callApi($this->makeApiPage('conversation.messages', ['POST']), ['id' => '5']);
    }
}

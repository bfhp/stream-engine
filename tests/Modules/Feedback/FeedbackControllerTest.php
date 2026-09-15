<?php

declare(strict_types=1);

namespace Tests\Modules\Feedback;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Feedback\FeedbackController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\PhpInputStreamMock;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class FeedbackControllerTest extends TestCase
{
    private const SECRET = 'test-secret';

    private string $appSecret = self::SECRET;
    private array $deliveries = [];
    private array $administratorIds = [7, 12];

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_COOKIE['csrfToken'],
        );

        // Belt to post()'s braces, for a future test that registers the mock
        // directly. Harmless because restore() is idempotent - it used to raise
        // a PHP notice per test when called twice, which is why it now tracks
        // whether the wrapper is actually installed.
        PhpInputStreamMock::restore();

        parent::tearDown();
    }

    private function makeModule(?FeedService $feedService = null): FeedbackController
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn(array_map(static fn (int $id): array => ['id' => $id], $this->administratorIds));
        $users = new UserRepository($db);
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            $this->deliveries[] = $params;
            return 0;
        });
        $notifications = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            $users,
            $this->createStub(MailService::class),
            translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
            config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
        );

        return new FeedbackController(
            $this->createStub(PdoDatabase::class),
            new RequestContext(
                new User(id: 0, email: '', role: AccessService::ROLE_USER),
                new DateTimeZone('UTC'),
                QueryParams::fromGlobals()
            ),
            $feedService ?? $this->createStub(FeedService::class),
            new TranslationManager('ru', 'en'),
            $users,
            $notifications,
            new Config(['APP_SECRET' => $this->appSecret]),
            new UrlGenerator(
                new PageTree([$this->pageRoot()]),
                new FakeFeedRepository([]),
                new ArrayCache(),
            ),
        );
    }

    private function pageRoot(): Page
    {
        return new Page(
            id: 1,
            parentId: null,
            pattern: '',
            pageName: 'Root',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }

    public function testSuccessUrlFollowsTheConfiguredFeedbackPagePattern(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn(new Feed(
            id: 5,
            parentId: null,
            ownerId: 1,
            type: 'article',
            slug: null,
            title: 'Contact us',
            description: null,
            imageUrl: null,
            content: 'Hint',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: null,
            relevance: null,
            canonicalUrl: '/write-to-us/',
        ));
        $page = new Page(
            id: 20,
            parentId: 1,
            pattern: 'write-to-us',
            pageName: 'Feedback',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: 5,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'feedback.show',
        );

        $view = $this->makeModule($feedService)->show($page);

        $this->assertSame(
            '/write-to-us/?success=1#feedbackFormHeader',
            $view->data['successUrl']
        );
    }

    private function makePage(): Page
    {
        return Page::api(
            id: 50,
            parentId: 10,
            pattern: 'feedback',
            requestMethods: ['POST'],
            action: 'feedback.post',
        );
    }

    /** Runs the endpoint against a raw body and returns what it printed. */
    private function post(string $body): string
    {
        PhpInputStreamMock::register($body);

        ob_start();

        try {
            $this->makeModule()->callApi($this->makePage(), []);
        } finally {
            $output = ob_get_clean();
            PhpInputStreamMock::restore();
        }

        return $output;
    }

    /**
     * A payload that is correctly signed but whose content the ladder rejects -
     * a tripwire for the anti-bot checks. `$overrides` breaks whichever
     * anti-bot check the test is about.
     */
    private function signedButInvalidPayload(array $overrides = []): string
    {
        $formTime = time() - 30; // old enough to clear the 3-second guard

        return json_encode(array_merge([
            'form_time' => $formTime,
            'form_hash' => hash_hmac('sha256', (string) $formTime, self::SECRET),
            'full_name' => 'Аня',
            'email' => 'anya@example.com',
            'website' => '',
            'message' => '', // the tripwire
        ], $overrides));
    }

    private function assertReturnedEarly(string $output): void
    {
        $this->assertSame(['status' => 'ok'], json_decode($output, true));
        $this->assertSame([], $this->deliveries);
    }

    public function testValidFeedbackNotifiesEveryAdministratorAndEscapesHtml(): void
    {
        $message = '<script>alert("test")</script> Сообщение администратору.';
        $output = $this->post($this->signedButInvalidPayload([
            'full_name' => '  Аня <b>Тест</b>  ',
            'message' => $message,
        ]));

        self::assertSame(['status' => 'ok'], json_decode($output, true));
        self::assertSame([7, 12], array_column($this->deliveries, 0));
        foreach ($this->deliveries as $delivery) {
            self::assertSame('system.feedback', $delivery[1]);
            self::assertSame('messenger', $delivery[2]);
            self::assertSame('instant', $delivery[3]);
            self::assertSame(['name' => 'Аня <b>Тест</b>', 'email' => 'anya@example.com', 'message' => $message], json_decode($delivery[4], true));
            self::assertStringContainsString('Аня &lt;b&gt;Тест&lt;/b&gt;', $delivery[5]);
            self::assertStringContainsString('anya@example.com', $delivery[5]);
            self::assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), $delivery[5]);
        }
    }

    public function testValidFeedbackWithNoAdministrators(): void
    {
        $this->administratorIds = [];
        $this->assertReturnedEarly($this->post($this->signedButInvalidPayload([
            'message' => str_repeat('Текст сообщения ', 3),
        ])));
    }

    /**
     * The regression: an empty body decoded to null, every read hit an offset on
     * null, and the form_hash comparison turned that into a TypeError.
     */
    public function testAnEmptyBodyIsRefusedWithoutCrashing(): void
    {
        $this->assertReturnedEarly($this->post(''));
    }

    public function testANonJsonBodyIsRefusedWithoutCrashing(): void
    {
        $this->assertReturnedEarly($this->post('not json at all'));
    }

    public function testAMissingFormHashIsTreatedLikeAMismatchedOne(): void
    {
        $decoded = json_decode($this->signedButInvalidPayload(), true);
        unset($decoded['form_hash']);

        $this->assertReturnedEarly($this->post(json_encode($decoded)));
    }

    public function testANonStringFormHashIsRefused(): void
    {
        $this->assertReturnedEarly($this->post($this->signedButInvalidPayload(['form_hash' => ['x']])));
    }

    public function testAMismatchedFormHashIsRefused(): void
    {
        $this->assertReturnedEarly(
            $this->post($this->signedButInvalidPayload(['form_hash' => str_repeat('a', 64)]))
        );
    }

    public function testTheHoneypotFieldIsRefused(): void
    {
        $this->assertReturnedEarly(
            $this->post($this->signedButInvalidPayload(['website' => 'http://spam.example']))
        );
    }

    public function testASubmissionFasterThanThreeSecondsIsRefused(): void
    {
        $formTime = time();

        $this->assertReturnedEarly($this->post($this->signedButInvalidPayload([
            'form_time' => $formTime,
            'form_hash' => hash_hmac('sha256', (string) $formTime, self::SECRET),
        ])));
    }

    /**
     * The other side of the tripwire, and what keeps the tests above honest: a
     * correctly signed submission that clears every anti-bot check *does* reach
     * the validation ladder. Without this, a mechanism that rejected everything
     * would pass the whole file.
     */
    public function testACorrectlySignedSubmissionReachesTheValidationLadder(): void
    {
        $this->expectException(ValidationException::class);

        $this->post($this->signedButInvalidPayload());
    }

    /**
     * An unset secret would key the HMAC with '' and accept whatever a caller
     * invented, so it refuses to run instead - same reasoning as
     * APIController::handleCronRequest()'s empty-key guard. Loudly, because it is
     * a misconfiguration rather than a bot.
     */
    public function testAnUnconfiguredSecretIsRefusedLoudly(): void
    {
        $this->appSecret = '';

        // The message matters here: the test above also expects a
        // ValidationException, so without pinning which one this would pass on
        // the ladder's rejection and prove nothing about the secret.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Форма обратной связи не настроена');

        $this->post($this->signedButInvalidPayload());
    }
}

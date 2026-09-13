<?php

declare(strict_types=1);

namespace Tests\Core;

use Error;
use Exception;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\ApiErrorResponse;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use Throwable;
use TypeError;

/**
 * The one decision an API route makes when its handler threw.
 *
 * It used to be two catch arms in StreamEngine::handleRequest(), and the
 * second one answered **403 for everything that was not a ValidationException**
 * - so a null dereference in a handler reached the browser as "you don't have
 * permission" and reached the log as nothing at all. Extracted here because
 * handleRequest() is 160 lines of superglobals, headers and echo, and this
 * rule is the part of it that is worth being sure about.
 */
final class ApiErrorResponseTest extends TestCase
{
    /* ===============================
       Deliberate answers pass through
    =============================== */

    /**
     * @return array<string, array{ValidationException, int, string}>
     */
    public static function deliberateProvider(): array
    {
        return [
            'plain validation error' => [
                new ValidationException('Опишите ваш сон более подробно.'),
                400,
                'bad_request',
            ],
            'not found' => [
                new NotFoundException('Book not found'),
                404,
                'not_found',
            ],
            'forbidden' => [
                new ForbiddenException('Unable to delete this message.'),
                403,
                'forbidden',
            ],
            'rate limited' => [
                new ValidationException('Слишком часто.', 429, 'too_many_requests'),
                429,
                'too_many_requests',
            ],
            'method not allowed' => [
                new ValidationException('Method PUT is not supported.', 405, 'method_not_allowed'),
                405,
                'method_not_allowed',
            ],
        ];
    }

    #[DataProvider('deliberateProvider')]
    public function testAValidationExceptionKeepsItsOwnStatusAndMessage(
        ValidationException $e,
        int $status,
        string $codeName
    ): void {
        $error = ApiErrorResponse::forThrowable($e);

        $this->assertSame($status, $error->status);
        $this->assertSame($e->getMessage(), $error->message);
        $this->assertSame($codeName, $error->codeName);
    }

    /**
     * NotFoundException and ForbiddenException are ValidationExceptions - they
     * were rewritten to be, precisely so this arm would catch them. Pinned
     * because if either ever went back to extending Exception the only visible
     * symptom would be every "not found" answering 500.
     */
    public function testTheTwoNamedExceptionsTakeTheDeliberatePath(): void
    {
        foreach ([new NotFoundException('нет'), new ForbiddenException('нельзя')] as $e) {
            $this->assertFalse(
                ApiErrorResponse::forThrowable($e)->isBug,
                $e::class.' must be an answer, not a bug'
            );
        }
    }

    public function testADeliberateAnswerIsNotLogged(): void
    {
        $this->assertFalse(ApiErrorResponse::forThrowable(new NotFoundException('нет'))->isBug);
    }

    /**
     * The message of a deliberate answer is written for the client, so it goes
     * out verbatim in dev and in production alike.
     */
    public function testTheDebugFlagDoesNotChangeADeliberateAnswer(): void
    {
        $e = new ValidationException('Опишите ваш сон более подробно.');

        $this->assertEquals(
            ApiErrorResponse::forThrowable($e, false),
            ApiErrorResponse::forThrowable($e, true)
        );
    }

    /* ===============================
       Everything else is a bug
    =============================== */

    /**
     * @return array<string, array{Throwable}>
     */
    public static function bugProvider(): array
    {
        return [
            // What handlers actually throw for wiring mistakes.
            'unknown route' => [new RuntimeException('Unknown route')],
            'bad argument' => [new InvalidArgumentException('Unknown dependency: Foo')],
            'bare exception' => [new Exception('No controller resolves page action')],
            // The database going away mid-request.
            'database' => [new PDOException('SQLSTATE[HY000] [2002] Connection refused')],
            // The two that the old `catch (Exception)` did not catch at all,
            // so they escaped as fatals and answered a fetch() expecting JSON
            // with an HTML error page.
            'type error' => [new TypeError('getFeedById(): Argument #1 must be of type int, null given')],
            'plain error' => [new Error('Call to a member function nick() on null')],
        ];
    }

    #[DataProvider('bugProvider')]
    public function testAnythingElseIsAFiveHundred(Throwable $e): void
    {
        $error = ApiErrorResponse::forThrowable($e);

        // 500, not the 403 this used to be. A bug reported as a permission
        // problem sends whoever is debugging it to look at roles and ACLs.
        $this->assertSame(500, $error->status);
        $this->assertTrue($error->isBug);
        $this->assertSame('internal_error', $error->codeName);
    }

    #[DataProvider('bugProvider')]
    public function testABugSaysNothingAboutItselfInProduction(Throwable $e): void
    {
        $error = ApiErrorResponse::forThrowable($e, false);

        // Exception messages are written for a log: they carry table names,
        // file paths, and sometimes the value that broke. None of that is an
        // answer to an anonymous request.
        $this->assertSame(ApiErrorResponse::GENERIC_MESSAGE, $error->message);
        $this->assertStringNotContainsString($e->getMessage(), $error->message);
    }

    public function testInDevTheBugSaysWhatItWas(): void
    {
        $error = ApiErrorResponse::forThrowable(
            new TypeError('Argument #1 must be of type int, null given'),
            true
        );

        $this->assertStringContainsString('TypeError', $error->message);
        $this->assertStringContainsString('must be of type int', $error->message);
    }

    /**
     * A subclass of Exception that happens to carry a `getHttpCode()` is still
     * a bug: the check is `instanceof ValidationException`, not duck typing.
     * Recorded because the tempting "if it has a status, use it" version would
     * let any library exception choose its own response code.
     */
    public function testTheCheckIsTheTypeRatherThanTheShape(): void
    {
        $lookalike = new class ('boom') extends RuntimeException {
            public function getHttpCode(): int
            {
                return 418;
            }
        };

        $this->assertSame(500, ApiErrorResponse::forThrowable($lookalike)->status);
    }

    /* ===============================
       The body
    =============================== */

    public function testTheBodyCarriesTheMessageUnderTheKeyTheClientReads(): void
    {
        // Every caller in assets-src reads `error` off the parsed body -
        // app.ts's api() throws the whole payload and getApiErrorMessage()
        // digs the message out of it.
        $body = ApiErrorResponse::forThrowable(new NotFoundException('Book not found'))->body();

        $this->assertSame('Book not found', $body['error']);
    }

    public function testTheBodyAlsoCarriesTheMachineReadableCode(): void
    {
        // ValidationException has had getCodeName() since it was written and
        // nothing ever sent it; the response was message-only.
        $body = ApiErrorResponse::forThrowable(new ForbiddenException('нельзя'))->body();

        $this->assertSame('forbidden', $body['code']);
    }

    public function testTheBodyIsEncodableAsJsonWithoutEscaping(): void
    {
        $body = ApiErrorResponse::forThrowable(new ValidationException('Слишком часто.'))->body();

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);
        $this->assertJson($json);
        $this->assertSame($body, json_decode($json, true));
    }

    /* ===============================
       The log line
    =============================== */

    public function testTheLogLineCarriesEnoughToFindTheThrow(): void
    {
        $e = new RuntimeException('Unknown route');

        $line = ApiErrorResponse::logLine($e, 'POST', '/api/v1/conversations/5/messages');

        $this->assertStringContainsString('POST', $line);
        $this->assertStringContainsString('/api/v1/conversations/5/messages', $line);
        $this->assertStringContainsString(RuntimeException::class, $line);
        $this->assertStringContainsString('Unknown route', $line);
        // Where it was thrown - this file.
        $this->assertStringContainsString(basename(__FILE__), $line);
    }

    public function testTheLogLineIsOneLine(): void
    {
        // error_log() writes it as-is; a multi-line entry breaks whatever
        // reads the log by line.
        $line = ApiErrorResponse::logLine(new RuntimeException('boom'), 'GET', '/api/v1/feeds');

        $this->assertStringNotContainsString("\n", $line);
    }
}

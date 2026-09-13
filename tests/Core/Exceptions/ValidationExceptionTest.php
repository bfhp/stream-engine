<?php

declare(strict_types=1);

namespace Tests\Core\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;

/**
 * Twenty lines of production code that decide the HTTP status of every API
 * error in the app: StreamEngine's handler reads getHttpCode() and
 * getCodeName() off whatever was thrown and writes them into the response.
 *
 * The defaults are the load-bearing part. Almost every throw site in the
 * codebase passes a message only, so "a validation failure is a 400 called
 * bad_request" is not a fallback that rarely fires - it is the answer for the
 * large majority of errors the API returns.
 */
final class ValidationExceptionTest extends TestCase
{
    public function testAMessageOnlyFailureIsABadRequest(): void
    {
        $e = new ValidationException('Укажите дату рождения.');

        $this->assertSame(400, $e->getHttpCode());
        $this->assertSame('bad_request', $e->getCodeName());
        $this->assertSame('Укажите дату рождения.', $e->getMessage());
    }

    #[DataProvider('customCodeProvider')]
    public function testAnExplicitCodeAndNameRoundTrip(int $httpCode, string $codeName): void
    {
        $e = new ValidationException('nope', $httpCode, $codeName);

        $this->assertSame($httpCode, $e->getHttpCode());
        $this->assertSame($codeName, $e->getCodeName());
    }

    /** @return array<string, array{int, string}> */
    public static function customCodeProvider(): array
    {
        return [
            'not found' => [404, 'not_found'],
            'conflict' => [409, 'conflict'],
            'too many requests' => [429, 'rate_limited'],
            'unprocessable' => [422, 'unprocessable'],
        ];
    }

    /**
     * The code lives in its own property rather than Exception::$code, and
     * that separation is deliberate: `getCode()` stays 0 while `getHttpCode()`
     * carries the status. A handler that reached for the inherited getCode()
     * would emit `http_response_code(0)`.
     */
    public function testTheHttpCodeIsNotTheInheritedExceptionCode(): void
    {
        $e = new ValidationException('nope', 404, 'not_found');

        $this->assertSame(0, $e->getCode());
        $this->assertSame(404, $e->getHttpCode());
    }

    /**
     * The subclasses are what make `catch (ValidationException)` a single
     * handler for the whole family, and each carries its own status without
     * the throw site having to remember it.
     */
    public function testTheSubclassesCarryTheirOwnStatusAndStayCatchable(): void
    {
        $notFound = new NotFoundException('Pair not found.');
        $forbidden = new ForbiddenException('Not permitted');

        $this->assertInstanceOf(ValidationException::class, $notFound);
        $this->assertInstanceOf(ValidationException::class, $forbidden);

        $this->assertSame(404, $notFound->getHttpCode());
        $this->assertSame('not_found', $notFound->getCodeName());

        $this->assertSame(403, $forbidden->getHttpCode());
        $this->assertSame('forbidden', $forbidden->getCodeName());
    }
}

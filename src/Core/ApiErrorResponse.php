<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use StreamEngine\Core\Exceptions\ValidationException;
use Throwable;

/**
 * What an API route answers when the handler threw.
 *
 * This exists because the decision used to be two `catch` arms inside
 * `StreamEngine::handleRequest()`, and the second one mapped *everything*
 * that was not a ValidationException to **403**. So a null dereference, a
 * bad SQL statement or a missing service came back to the browser as a
 * permission problem - and went nowhere at all in the log. Two costs, both
 * paid repeatedly: the client showed "access denied" for bugs, and the only
 * record that a bug had happened was the user saying so.
 *
 * The rule now:
 *
 * - a ValidationException (which NotFoundException and ForbiddenException
 *   both are) is a deliberate answer - its own status and message go
 *   straight through, because the handler chose them for the client;
 * - anything else is a bug - 500, and the caller logs it.
 *
 * `Throwable`, not `Exception`: a TypeError from a handler is exactly the
 * case being described, and the old `catch (Exception)` did not catch it at
 * all - it escaped as a fatal, so the response was an HTML error page in the
 * middle of a fetch() expecting JSON.
 *
 * The message of a bug is not shown to the client outside dev. An exception
 * message is written for whoever is reading the log - it carries table
 * names, file paths and occasionally values - and none of that belongs in a
 * response to an anonymous request.
 */
final class ApiErrorResponse
{
    /**
     * What a 500 says out loud. Deliberately incurious: the detail is in the
     * log, and this is the string a visitor sees.
     */
    public const string GENERIC_MESSAGE = 'Internal server error.';

    private function __construct(
        public readonly int $status,
        public readonly string $message,
        public readonly string $codeName,
        /** Whether this is our fault, i.e. whether the caller should log it. */
        public readonly bool $isBug,
    ) {
    }

    public static function forThrowable(
        Throwable $e,
        bool $debug = false,
        string $genericMessage = self::GENERIC_MESSAGE,
    ): self {
        if ($e instanceof ValidationException) {
            return new self(
                $e->getHttpCode(),
                $e->getMessage(),
                $e->getCodeName(),
                false
            );
        }

        return new self(
            500,
            $debug
                ? sprintf('%s: %s', $e::class, $e->getMessage())
                : $genericMessage,
            'internal_error',
            true
        );
    }

    /**
     * The JSON body. `error` is what every caller in assets-src reads;
     * `code` is the stable machine-readable half, which ValidationException
     * has always carried and nothing has ever sent.
     *
     * @return array{error: string, code: string}
     */
    public function body(): array
    {
        return [
            'error' => $this->message,
            'code' => $this->codeName,
        ];
    }

    /**
     * One line for `error_log()`, with the things needed to find the throw
     * again: the class, the message, and where it came from.
     */
    public static function logLine(Throwable $e, string $method, string $path): string
    {
        return sprintf(
            'API %s %s failed: %s: %s in %s:%d',
            $method,
            $path,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }
}

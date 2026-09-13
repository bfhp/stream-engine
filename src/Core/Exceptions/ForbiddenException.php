<?php

declare(strict_types=1);

namespace StreamEngine\Core\Exceptions;

/**
 * Extends ValidationException so the API path can answer with the right status
 * and a machine-readable code name; see NotFoundException for why.
 *
 * 403 was already what the blanket `catch (Exception)` in handleRequest()
 * produced, so the status was unchanged at the time - what this adds is
 * `forbidden` as the code name instead of the generic one, and a single family
 * for Core\ApiErrorResponse to recognise. Since that blanket arm now answers
 * 500, extending ValidationException is what keeps a genuine 403 a 403.
 */
class ForbiddenException extends ValidationException
{
    public function __construct(
        string $message,
    ) {
        parent::__construct($message, 403, 'forbidden');
    }
}

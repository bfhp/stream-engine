<?php

declare(strict_types=1);

namespace StreamEngine\Core\Exceptions;

/**
 * Extends ValidationException so the API path can answer with the right status.
 *
 * Core\ApiErrorResponse treats a ValidationException as the handler's own
 * answer and uses its getHttpCode(); anything else is a bug and answers 500
 * (it was a blanket 403 when this was written). While this extended Exception
 * directly, every "not found" thrown from an API endpoint came back as
 * *Forbidden* - the wrong status, and a misleading one, since it implies the
 * caller lacks permission for something that does not exist.
 *
 * The HTML path is unaffected: it catches Exception and renders the 404 page,
 * which this still is.
 */
class NotFoundException extends ValidationException
{
    public function __construct(
        string $message,
    ) {
        parent::__construct($message, 404, 'not_found');
    }
}

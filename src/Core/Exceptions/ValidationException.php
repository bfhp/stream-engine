<?php

declare(strict_types=1);

namespace StreamEngine\Core\Exceptions;

use Exception;

class ValidationException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $httpCode = 400,
        private readonly string $codeName = 'bad_request'
    ) {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getCodeName(): string
    {
        return $this->codeName;
    }
}

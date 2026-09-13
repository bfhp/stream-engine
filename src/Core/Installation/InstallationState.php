<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class InstallationState
{
    public const string STATUS_INSTALLING = 'installing';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_READY = 'ready';

    private const array STATUSES = [
        self::STATUS_INSTALLING,
        self::STATUS_FAILED,
        self::STATUS_READY,
    ];

    public function __construct(
        public string $status,
        public string $release,
        public string $stage,
        public string $startedAt,
        public ?string $completedAt = null,
        public ?string $errorCode = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid installation status "%s".', $status));
        }

        if (trim($release) === '') {
            throw new InvalidArgumentException('Installation release must not be empty.');
        }

        if (trim($stage) === '') {
            throw new InvalidArgumentException('Installation stage must not be empty.');
        }

        if ($status === self::STATUS_READY && $completedAt === null) {
            throw new InvalidArgumentException('A ready installation must have a completion time.');
        }

        if ($status !== self::STATUS_FAILED && $errorCode !== null) {
            throw new InvalidArgumentException('Only a failed installation may have an error code.');
        }
    }

    public static function start(string $release, string $stage = 'preflight'): self
    {
        return new self(
            status: self::STATUS_INSTALLING,
            release: $release,
            stage: $stage,
            startedAt: self::now(),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['status', 'release', 'stage', 'started_at'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('Installation state field "%s" must be a string.', $key));
            }
        }

        foreach (['completed_at', 'error_code'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('Installation state field "%s" must be a string or null.', $key));
            }
        }

        return new self(
            status: $data['status'],
            release: $data['release'],
            stage: $data['stage'],
            startedAt: $data['started_at'],
            completedAt: $data['completed_at'] ?? null,
            errorCode: $data['error_code'] ?? null,
        );
    }

    public function advanceTo(string $stage): self
    {
        return new self(
            status: self::STATUS_INSTALLING,
            release: $this->release,
            stage: $stage,
            startedAt: $this->startedAt,
        );
    }

    public function fail(string $errorCode): self
    {
        if (trim($errorCode) === '') {
            throw new InvalidArgumentException('Installation error code must not be empty.');
        }

        return new self(
            status: self::STATUS_FAILED,
            release: $this->release,
            stage: $this->stage,
            startedAt: $this->startedAt,
            errorCode: $errorCode,
        );
    }

    public function complete(): self
    {
        return new self(
            status: self::STATUS_READY,
            release: $this->release,
            stage: 'ready',
            startedAt: $this->startedAt,
            completedAt: self::now(),
        );
    }

    /** @return array{status:string,release:string,stage:string,started_at:string,completed_at:?string,error_code:?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'release' => $this->release,
            'stage' => $this->stage,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'error_code' => $this->errorCode,
        ];
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

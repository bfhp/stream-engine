<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

/**
 * A channel-independent notification request.
 *
 * messengerText keeps today's already-rendered message path working while
 * payload preserves the semantic data future email and digest renderers need.
 */
final readonly class Notification
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public int $recipientUserId,
        public string $type,
        public string $messengerText,
        public array $payload = [],
        public ?string $deduplicationKey = null,
    ) {
    }
}

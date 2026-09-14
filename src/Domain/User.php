<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

use StreamEngine\Service\AccessService;

final class User
{
    /**
     * The site's reserved "system" account - a fixed id, public profile,
     * but an unusable password so nobody can actually log into it. Seeded by
     * the initial installer rather than by the schema migration.
     *
     * Lives here (Domain, not any one Service) because it's a fact about a
     * user, not about messaging specifically - MessageService uses it
     * to send notifications "from" this account. Anything that needs "the
     * reserved system user's id" depends on this constant directly, rather
     * than reaching into whichever Service happened to declare it first.
     */
    public const int SYSTEM_USER_ID = 1;

    public readonly bool $isGuest;

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $role = AccessService::ROLE_USER,
        public readonly string $nick = '',
        public readonly string $username = '',
        public readonly string $avatarUrl = '',
        public readonly string $bio = '',
        public readonly string $homepage = '',
        public readonly string $gender = '',
        public readonly ?string $birthDate = null,
        public readonly string $signature = '',
        public readonly bool $showGenderPublicly = false,
        public readonly bool $showBirthDatePublicly = false,
        public readonly bool $showHomepagePublicly = false,
        public readonly bool $hidePresence = false,
        public readonly bool $showHiddenProfileToFriends = false,
        public readonly ?int $createdAt = null,
        public readonly string $timezone = 'UTC',
    ) {
        if (! in_array($role, AccessService::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid user role: '.$role);
        }
        $this->isGuest = $this->isGuest();
    }

    public function isGuest(): bool
    {
        return $this->id === 0;
    }

    public function isAdmin(): bool
    {
        return !$this->isGuest() && $this->role === AccessService::ROLE_ADMIN;
    }

    public function withAvatarUrl(string $avatarUrl): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            role: $this->role,
            nick: $this->nick,
            username: $this->username,
            avatarUrl: $avatarUrl,
            bio: $this->bio,
            homepage: $this->homepage,
            gender: $this->gender,
            birthDate: $this->birthDate,
            signature: $this->signature,
            showGenderPublicly: $this->showGenderPublicly,
            showBirthDatePublicly: $this->showBirthDatePublicly,
            showHomepagePublicly: $this->showHomepagePublicly,
            hidePresence: $this->hidePresence,
            showHiddenProfileToFriends: $this->showHiddenProfileToFriends,
            createdAt: $this->createdAt,
            timezone: $this->timezone,
        );
    }

    public function getDisplayName(): string
    {
        $nick = trim($this->nick);

        return $nick !== '' ? $nick : '#'.$this->id;
    }
}

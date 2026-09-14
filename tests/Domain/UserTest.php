<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;

final class UserTest extends TestCase
{
    #[DataProvider('identityProvider')]
    public function testGuestnessAndAdminRightsFollowIdentity(int $id, string $role, bool $isGuest, bool $isAdmin): void
    {
        $user = new User($id, '', $role);
        $this->assertSame($isGuest, $user->isGuest());
        $this->assertSame($isGuest, $user->isGuest);
        $this->assertSame($isAdmin, $user->isAdmin());
        $this->assertSame($role, $user->role);
    }

    public function testUnknownRoleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User(42, '', 'unknown');
    }

    public static function identityProvider(): array
    {
        return [
            'guest' => [0, AccessService::ROLE_USER, true, false],
            'guest with admin role cannot gain access' => [0, AccessService::ROLE_ADMIN, true, false],
            'registered' => [42, AccessService::ROLE_USER, false, false],
            'moderator' => [42, AccessService::ROLE_MODERATOR, false, false],
            'admin' => [42, AccessService::ROLE_ADMIN, false, true],
            'system account' => [User::SYSTEM_USER_ID, AccessService::ROLE_USER, false, false],
        ];
    }

    /* ===============================
       getDisplayName
    =============================== */

    public function testTheNickIsUsedWhenItIsSet(): void
    {
        $this->assertSame('Аноним', (new User(42, 'a@b.c', AccessService::ROLE_USER, 'Аноним'))->getDisplayName());
    }

    /**
     * The fallback has to be *something*, because the name is rendered next to
     * every post and comment: a user who never set a nick would otherwise
     * appear as an empty span.
     */
    #[DataProvider('emptyNickProvider')]
    public function testAnEmptyNickFallsBackToTheId(string $nick): void
    {
        $this->assertSame('#42', (new User(42, 'a@b.c', AccessService::ROLE_USER, $nick))->getDisplayName());
    }

    /** @return array<string, array{string}> */
    public static function emptyNickProvider(): array
    {
        return [
            'never set' => [''],
            // Trimmed before the check, so a nick made only of whitespace is
            // treated as unset rather than rendered as a blank name.
            'spaces' => ['   '],
            'a tab and a newline' => ["\t\n"],
        ];
    }

    public function testSurroundingWhitespaceIsTrimmedOffARealNick(): void
    {
        $this->assertSame('Аноним', (new User(42, 'a@b.c', AccessService::ROLE_USER, '  Аноним  '))->getDisplayName());
    }

    /**
     * The nick is not escaped here - it is data, and every template escapes on
     * output. Pinned so nobody "helpfully" adds htmlspecialchars() in the
     * Domain object and produces double-escaped names everywhere.
     */
    public function testTheNickIsNotEscapedByTheDomainObject(): void
    {
        $this->assertSame(
            '<b>bold</b>',
            (new User(42, 'a@b.c', AccessService::ROLE_USER, '<b>bold</b>'))->getDisplayName()
        );
    }

    public function testWithAvatarUrlReturnsACopyAndPreservesTheUser(): void
    {
        $user = new User(
            id: 42,
            email: 'user@example.com',
            role: AccessService::ROLE_MODERATOR,
            nick: 'Аноним',
            username: 'anonymous',
            avatarUrl: '',
            bio: 'Bio',
            homepage: 'https://example.com',
            gender: 'other',
            birthDate: '2000-01-02',
            signature: 'Signature',
            showGenderPublicly: true,
            showBirthDatePublicly: true,
            showHomepagePublicly: true,
            hidePresence: true,
            showHiddenProfileToFriends: true,
            createdAt: 1_700_000_000,
            timezone: 'Europe/Nicosia',
        );

        $copy = $user->withAvatarUrl('/assets/img/default-avatar.svg');
        $expected = get_object_vars($user);
        $expected['avatarUrl'] = '/assets/img/default-avatar.svg';

        $this->assertNotSame($user, $copy);
        $this->assertSame('', $user->avatarUrl);
        $this->assertSame($expected, get_object_vars($copy));
    }

    /* ===============================
       The system account
    =============================== */

    /**
     * A fixed id used for notifications "from" the site, seeded by
     * initial installer. Services depend on the
     * constant rather than on a literal, so its value is part of the schema.
     */
    public function testTheSystemUserIdMatchesTheSeededRow(): void
    {
        $this->assertSame(1, User::SYSTEM_USER_ID);
    }

    /* ===============================
       Defaults
    =============================== */

    public function testTheOptionalProfileFieldsDefaultToEmptyRatherThanNull(): void
    {
        $user = new User(42, 'a@b.c', AccessService::ROLE_USER);

        // Templates concatenate these; a null would be a deprecation on 8.1+
        // and an empty render anyway.
        $this->assertSame('', $user->nick);
        $this->assertSame('', $user->username);
        $this->assertSame('', $user->avatarUrl);
        $this->assertSame('', $user->bio);
        $this->assertSame('', $user->homepage);
        $this->assertSame('', $user->gender);
        $this->assertSame('', $user->signature);

        // These two are genuinely absent rather than empty: birthDate is
        // optional, and createdAt is only populated by the public-profile
        // lookups, so null distinguishes "not asked for" from "zero".
        $this->assertNull($user->birthDate);
        $this->assertNull($user->createdAt);
    }
}

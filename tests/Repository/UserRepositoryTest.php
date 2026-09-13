<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;

final class UserRepositoryTest extends TestCase
{
    public function testFindAdministratorIdsUsesAdminRole(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchAll')->with($this->logicalAnd(
            $this->stringContains('WHERE u.role = \'admin\''),
        ))->willReturn([['id' => '7'], ['id' => '12']]);

        self::assertSame([7, 12], (new UserRepository($db))->findAdministratorIds());
    }

    public function testFindByIdReturnsUserWhenFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->logicalAnd(
                $this->stringContains('FROM users u'),
                    $this->stringContains('WHERE u.id = ?')
            ), [42])
            ->willReturn([
                'id' => 42,
                'email' => 'user@example.com',
                'timezone' => 'Europe/Nicosia',
                'role' => 'user',
                'nick' => 'Nicky',
                'username' => 'nicky42',
                'bio' => 'Hello',
                'signature' => 'Sig',
                'homepage' => 'https://example.com',
                'gender' => 'm',
                'birth_date' => '2000-01-01',
                'avatar_url' => '/avatars/42.webp',
            ]);

        $repository = new UserRepository($db);
        $user = $repository->findById(42);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(42, $user->id);
        $this->assertSame('user@example.com', $user->email);
        $this->assertSame(AccessService::ROLE_USER, $user->role);
        $this->assertSame('Nicky', $user->nick);
        $this->assertSame('nicky42', $user->username);
        $this->assertSame('Hello', $user->bio);
        $this->assertSame('Sig', $user->signature);
        $this->assertSame('https://example.com', $user->homepage);
        $this->assertSame('m', $user->gender);
        $this->assertSame('2000-01-01', $user->birthDate);
        $this->assertSame('/avatars/42.webp', $user->avatarUrl);
        $this->assertSame('Europe/Nicosia', $user->timezone);
    }

    public function testUpdateTimezonePersistsItForUser(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE users SET timezone = ? WHERE id = ?'),
                ['Europe/Nicosia', 42]
            )
            ->willReturn(1);

        (new UserRepository($db))->updateTimezone(42, 'Europe/Nicosia');
    }

    public function testFindTimezoneByIdReturnsStoredTimezone(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT timezone FROM users WHERE id = ?', [42])
            ->willReturn(['timezone' => 'Europe/Nicosia']);

        $this->assertSame('Europe/Nicosia', (new UserRepository($db))->findTimezoneById(42));
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->anything(), [404])
            ->willReturn(null);

        $repository = new UserRepository($db);

        $this->assertNull($repository->findById(404));
    }

    public function testFindByUsernameReturnsUserWhenFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->logicalAnd(
                $this->stringContains('FROM users u'),
                $this->stringContains('WHERE u.username = ? AND u.is_active = 1')
            ), ['nicky42'])
            ->willReturn([
                'id' => 42,
                'email' => 'user@example.com',
                'role' => 'user',
                'nick' => 'Nicky',
                'username' => 'nicky42',
                'bio' => '',
                'signature' => '',
                'homepage' => '',
                'gender' => '',
                'birth_date' => null,
                'avatar_url' => '',
            ]);

        $repository = new UserRepository($db);
        $user = $repository->findByUsername('nicky42');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(42, $user->id);
        $this->assertSame('nicky42', $user->username);
    }

    public function testFindByUsernameReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->anything(), ['missing'])
            ->willReturn(null);

        $repository = new UserRepository($db);

        $this->assertNull($repository->findByUsername('missing'));
    }

    public function testExistsUsernameReturnsTrueWhenRowFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('SELECT 1 FROM users WHERE username = ? LIMIT 1'),
                ['nicky42']
            )
            ->willReturn(['1' => 1]);

        $repository = new UserRepository($db);

        $this->assertTrue($repository->existsUsername('nicky42'));
    }

    public function testExistsUsernameReturnsFalseWhenNoRowFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->anything(), ['free'])
            ->willReturn(null);

        $repository = new UserRepository($db);

        $this->assertFalse($repository->existsUsername('free'));
    }

    public function testSetUsernameExecutesUpdate(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE users SET username = ? WHERE id = ?'),
                ['nicky42', 5]
            );

        $repository = new UserRepository($db);

        $repository->setUsername(5, 'nicky42');
    }

    public function testFindWithoutUsernameReturnsLegacyRows(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('WHERE username IS NULL'))
            ->willReturn([
                ['id' => 3, 'nick' => 'Old User'],
            ]);

        $repository = new UserRepository($db);

        $this->assertSame(
            [['id' => 3, 'nick' => 'Old User']],
            $repository->findWithoutUsername()
        );
    }

    public function testFindByEmailReturnsRowWhenFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT u.id, u.email, u.password_hash, u.role, u.is_active'),
                $this->stringContains('WHERE u.email = ?')
            ), ['user@example.com'])
            ->willReturn([
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => 'hash',
                'role' => 'user',
                'is_active' => 1,
            ]);

        $repository = new UserRepository($db);

        $this->assertSame(
            [
                'id' => 7,
                'email' => 'user@example.com',
                'password_hash' => 'hash',
                'role' => 'user',
                'is_active' => 1,
            ],
            $repository->findByEmail('user@example.com')
        );
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->anything(), ['missing@example.com'])
            ->willReturn(null);

        $repository = new UserRepository($db);

        $this->assertNull($repository->findByEmail('missing@example.com'));
    }

    public function testCreateInsertsUserAndReturnsNewId(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->logicalOr(
                    $this->logicalAnd(
                        $this->stringContains('INSERT INTO users (email, password_hash, role, created_at)'),
                        $this->stringContains('VALUES (?, ?, ?, UNIX_TIMESTAMP())')
                    ),
                    $this->stringContains('UPDATE users SET username = ?')
                ),
                $this->callback(static function (array $params): bool {
                    return $params === ['user@example.com', 'hashed-password', 'admin']
                        || $params === ['user99', 99];
                })
            )
            ->willReturn(1);
        $db
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn(99);

        $repository = new UserRepository($db);

        $this->assertSame(99, $repository->create('user@example.com', 'hashed-password', AccessService::ROLE_ADMIN));
    }

    public function testCreateUsesDefaultRoleWhenNotProvided(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params): bool {
                    return $params === ['user@example.com', 'hashed-password', 'user']
                        || $params === ['user1', 1];
                })
            )
            ->willReturn(1);
        $db->method('lastInsertId')->willReturn(1);

        $repository = new UserRepository($db);

        $repository->create('user@example.com', 'hashed-password');
    }

    public function testExistsByEmailReturnsTrueWhenRowFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('SELECT 1 FROM users WHERE email = ? LIMIT 1'),
                ['user@example.com']
            )
            ->willReturn(['1' => 1]);

        $repository = new UserRepository($db);

        $this->assertTrue($repository->existsByEmail('user@example.com'));
    }

    public function testExistsByEmailReturnsFalseWhenNoRowFound(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->anything(), ['missing@example.com'])
            ->willReturn(null);

        $repository = new UserRepository($db);

        $this->assertFalse($repository->existsByEmail('missing@example.com'));
    }

    public function testUpdateProfilePersonalExecutesUpdateWithAllFields(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE users'),
                    $this->stringContains('SET nick = ?, bio = ?, homepage = ?, gender = ?, birth_date = ?, avatar_url = ?'),
                    $this->stringContains('WHERE id = ?')
                ),
                ['Nicky', 'Bio text', 'https://example.com', 'f', '1999-12-31', '/avatars/5.webp', 5]
            )
            ->willReturn(1);

        $repository = new UserRepository($db);

        $repository->updateProfilePersonal(
            5,
            'Nicky',
            'Bio text',
            'https://example.com',
            'f',
            '1999-12-31',
            '/avatars/5.webp'
        );
    }

    public function testUpdateProfilePersonalAllowsNullBirthDate(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                ['Nicky', '', '', '', null, '', 8]
            )
            ->willReturn(1);

        $repository = new UserRepository($db);

        $repository->updateProfilePersonal(8, 'Nicky', '', '', '', null, '');
    }

    public function testUpdateProfileForumSignatureExecutesUpdate(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE users'),
                    $this->stringContains('SET signature = ?'),
                    $this->stringContains('WHERE id = ?')
                ),
                ['New signature', 12]
            )
            ->willReturn(1);

        $repository = new UserRepository($db);

        $repository->updateProfileForumSignature(12, 'New signature');
    }

    public function testUpdateProfilePrivacyExecutesUpdate(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE users'),
                    $this->stringContains('show_gender_publicly = ?'),
                    $this->stringContains('show_hidden_profile_to_friends = ?'),
                    $this->stringContains('WHERE id = ?')
                ),
                [1, 0, 1, 1, 0, 12]
            )
            ->willReturn(1);

        $repository = new UserRepository($db);

        $repository->updateProfilePrivacy(12, true, false, true, true, false);
    }

    public function testFindPublicUsersClampsLimitBelowMinimumToOne(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 1 OFFSET 0'), [])
            ->willReturn([]);

        $repository = new UserRepository($db);

        $repository->findPublicUsers(0, 0);
    }

    public function testFindPublicUsersClampsLimitAboveMaximumToOneHundred(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 100 OFFSET 0'), [])
            ->willReturn([]);

        $repository = new UserRepository($db);

        $repository->findPublicUsers(500, 0);
    }

    public function testFindPublicUsersKeepsLimitWithinRangeUnchanged(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 50 OFFSET 0'), [])
            ->willReturn([]);

        $repository = new UserRepository($db);

        $repository->findPublicUsers(50, 0);
    }

    public function testFindPublicUsersClampsNegativeOffsetToZero(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 20 OFFSET 0'), [])
            ->willReturn([]);

        $repository = new UserRepository($db);

        $repository->findPublicUsers(20, -10);
    }

    public function testFindPublicUsersKeepsOffsetWithinRangeUnchanged(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 20 OFFSET 30'), [])
            ->willReturn([]);

        $repository = new UserRepository($db);

        $repository->findPublicUsers(20, 30);
    }

    public function testFindPublicUsersReturnsActiveUsersForList(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->logicalAnd(
                $this->stringContains('WHERE u.is_active = 1'),
                $this->stringContains('AND u.nick LIKE ?'),
                $this->stringContains('ORDER BY u.nick ASC, u.id ASC'),
                $this->stringContains('LIMIT 20 OFFSET 5')
            ), ['Мар%'])
            ->willReturn([
                [
                    'id' => 11,
                    'nick' => 'Мария',
                    'username' => 'maria_k',
                    'avatar_url' => '/uploads/avatars/maria.webp',
                    'created_at' => 1778167990,
                ],
                [
                    'id' => 10,
                    'nick' => '',
                    'username' => '',
                    'avatar_url' => '',
                    'created_at' => 1778160000,
                ],
            ]);

        $repository = new UserRepository($db);

        $this->assertSame(
            [
                [
                    'id' => 11,
                    'displayName' => 'Мария',
                    'username' => 'maria_k',
                    'avatarUrl' => '/uploads/avatars/maria.webp',
                    'createdAt' => 1778167990,
                ],
                [
                    'id' => 10,
                    'displayName' => '#10',
                    'username' => null,
                    'avatarUrl' => '',
                    'createdAt' => 1778160000,
                ],
            ],
            $repository->findPublicUsers(20, 5, 'Мар', 'name', 'asc')
        );
    }

    public function testCountPublicUsersAppliesNameFilter(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->logicalAnd(
                $this->stringContains('COUNT(*) AS total'),
                $this->stringContains('AND u.nick LIKE ?')
            ), ['ал%'])
            ->willReturn(['total' => 3]);

        $repository = new UserRepository($db);

        $this->assertSame(3, $repository->countPublicUsers('ал'));
    }
}

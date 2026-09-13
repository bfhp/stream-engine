<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;

final readonly class UserRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /** @return list<int> */
    public function findAdministratorIds(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id FROM users u WHERE u.role = 'admin'"
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Uses index: PRIMARY(id)
     * Uses index: PRIMARY(id) on roles join.
     */
    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne(
            "
            SELECT u.id, u.email, u.timezone, u.role, u.nick, u.username, u.bio, u.signature, u.homepage, u.gender, u.birth_date, u.avatar_url,
                   u.show_gender_publicly, u.show_birth_date_publicly, u.show_homepage_publicly, u.hide_presence, u.show_hidden_profile_to_friends
            FROM users u
            WHERE u.id = ?
            ",
            [$id]
        );

        if (!$row) {
            return null;
        }

        return new User(
            id: (int)$row['id'],
            email: $row['email'],
            role: $row['role'],
            nick: $row['nick'] ?? '',
            username: $row['username'] ?? '',
            avatarUrl: $row['avatar_url'] ?? '',
            bio: $row['bio'] ?? '',
            homepage: $row['homepage'] ?? '',
            gender: $row['gender'] ?? '',
            birthDate: $row['birth_date'] ?? null,
            signature: $row['signature'] ?? '',
            showGenderPublicly: (bool) ($row['show_gender_publicly'] ?? false),
            showBirthDatePublicly: (bool) ($row['show_birth_date_publicly'] ?? false),
            showHomepagePublicly: (bool) ($row['show_homepage_publicly'] ?? false),
            hidePresence: (bool) ($row['hide_presence'] ?? false),
            showHiddenProfileToFriends: (bool) ($row['show_hidden_profile_to_friends'] ?? false),
            timezone: $row['timezone'] ?? 'UTC',
        );
    }

    /**
     * Public profile lookup by the URL-safe handle (not the free-text,
     * possibly-unicode `nick`).
     *
     * Uses index: users_username_unique (username)
     * Uses index: PRIMARY(id) on roles join.
     */
    public function findByUsername(string $username): ?User
    {
        $row = $this->db->fetchOne(
            "
            SELECT u.id, u.email, u.timezone, u.role, u.nick, u.username, u.bio, u.signature, u.homepage, u.gender, u.birth_date, u.avatar_url,
                   u.show_gender_publicly, u.show_birth_date_publicly, u.show_homepage_publicly, u.hide_presence, u.show_hidden_profile_to_friends,
                   u.created_at
            FROM users u
            WHERE u.username = ? AND u.is_active = 1
            LIMIT 1
            ",
            [$username]
        );

        if (!$row) {
            return null;
        }

        return new User(
            id: (int)$row['id'],
            email: $row['email'],
            role: $row['role'],
            nick: $row['nick'] ?? '',
            username: $row['username'] ?? '',
            avatarUrl: $row['avatar_url'] ?? '',
            bio: $row['bio'] ?? '',
            homepage: $row['homepage'] ?? '',
            gender: $row['gender'] ?? '',
            birthDate: $row['birth_date'] ?? null,
            signature: $row['signature'] ?? '',
            showGenderPublicly: (bool) ($row['show_gender_publicly'] ?? false),
            showBirthDatePublicly: (bool) ($row['show_birth_date_publicly'] ?? false),
            showHomepagePublicly: (bool) ($row['show_homepage_publicly'] ?? false),
            hidePresence: (bool) ($row['hide_presence'] ?? false),
            showHiddenProfileToFriends: (bool) ($row['show_hidden_profile_to_friends'] ?? false),
            createdAt: isset($row['created_at']) ? (int) $row['created_at'] : null,
            timezone: $row['timezone'] ?? 'UTC',
        );
    }

    /**
     * Public profile lookup by id (e.g. a blog post's owner_id) - same
     * is_active filter as findByUsername(), unlike the plain findById()
     * above which is used for internal/session lookups that shouldn't
     * silently drop deactivated accounts.
     *
     * Uses index: PRIMARY(id)
     * Uses index: PRIMARY(id) on roles join.
     */
    public function findActiveById(int $id): ?User
    {
        $row = $this->db->fetchOne(
            "
            SELECT u.id, u.email, u.timezone, u.role, u.nick, u.username, u.bio, u.signature, u.homepage, u.gender, u.birth_date, u.avatar_url,
                   u.show_gender_publicly, u.show_birth_date_publicly, u.show_homepage_publicly, u.hide_presence, u.show_hidden_profile_to_friends,
                   u.created_at
            FROM users u
            WHERE u.id = ? AND u.is_active = 1
            ",
            [$id]
        );

        if (!$row) {
            return null;
        }

        return new User(
            id: (int)$row['id'],
            email: $row['email'],
            role: $row['role'],
            nick: $row['nick'] ?? '',
            username: $row['username'] ?? '',
            avatarUrl: $row['avatar_url'] ?? '',
            bio: $row['bio'] ?? '',
            homepage: $row['homepage'] ?? '',
            gender: $row['gender'] ?? '',
            birthDate: $row['birth_date'] ?? null,
            signature: $row['signature'] ?? '',
            showGenderPublicly: (bool) ($row['show_gender_publicly'] ?? false),
            showBirthDatePublicly: (bool) ($row['show_birth_date_publicly'] ?? false),
            showHomepagePublicly: (bool) ($row['show_homepage_publicly'] ?? false),
            hidePresence: (bool) ($row['hide_presence'] ?? false),
            showHiddenProfileToFriends: (bool) ($row['show_hidden_profile_to_friends'] ?? false),
            createdAt: isset($row['created_at']) ? (int) $row['created_at'] : null,
            timezone: $row['timezone'] ?? 'UTC',
        );
    }

    /**
     * Uses index: email (email)
     * Uses index: PRIMARY(id) on roles join.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            "
            SELECT u.id, u.email, u.password_hash, u.role, u.is_active
            FROM users u
            WHERE u.email = ?
            ",
            [$email]
        );
    }

    /**
     * Uses index: users_active_created_id_index (is_active, created_at, id) for registration date sorting.
     * Uses index: users_active_nick_id_index (is_active, nick, id) for name sorting and prefix filtering.
     *
     * @return list<array{id:int, displayName:string, username:?string, avatarUrl:string, createdAt:int}>
     */
    public function findPublicUsers(
        int $limit = 20,
        int $offset = 0,
        string $query = '',
        string $sort = 'registered',
        string $direction = 'desc',
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        [$where, $params] = $this->publicUsersWhere($query);
        $orderBy = $this->publicUsersOrderBy($sort, $direction);

        $rows = $this->db->fetchAll(
            "
            SELECT u.id, u.nick, u.username, u.avatar_url, u.created_at
            FROM users u
            $where
            $orderBy
            LIMIT $limit OFFSET $offset
            ",
            $params
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'displayName' => trim((string) ($row['nick'] ?? '')) !== ''
                    ? trim((string) $row['nick'])
                    : '#'.(int) $row['id'],
                'username' => trim((string) ($row['username'] ?? '')) !== ''
                    ? trim((string) $row['username'])
                    : null,
                'avatarUrl' => (string) ($row['avatar_url'] ?? ''),
                'createdAt' => (int) $row['created_at'],
            ],
            $rows
        );
    }

    /**
     * Uses index: users_active_created_id_index (is_active, created_at, id) for unfiltered active users count.
     * Uses index: users_active_nick_id_index (is_active, nick, id) for prefix-filtered active users count.
     */
    public function countPublicUsers(string $query = ''): int
    {
        [$where, $params] = $this->publicUsersWhere($query);

        $row = $this->db->fetchOne(
            "
            SELECT COUNT(*) AS total
            FROM users u
            $where
            ",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array{string, list<string>}
     */
    private function publicUsersWhere(string $query): array
    {
        $where = 'WHERE u.is_active = 1';
        $params = [];
        $query = trim($query);

        if ($query !== '') {
            $where .= "\nAND u.nick LIKE ?";
            $params[] = $query.'%';
        }

        return [$where, $params];
    }

    private function publicUsersOrderBy(string $sort, string $direction): string
    {
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return match ($sort) {
            'name' => "ORDER BY u.nick $direction, u.id $direction",
            default => "ORDER BY u.created_at $direction, u.id $direction",
        };
    }

    /**
     * The public username can't be chosen at registration (there's no nick
     * yet at this point), so every new user gets a plain `user<id>` handle
     * assigned right after the insert. It's an alphanumeric, unique-by-
     * construction placeholder - never colliding, since ids never repeat.
     */
    public function create(
        string $email,
        string $passwordHash,
        string $role = AccessService::ROLE_USER
    ): int {
        $this->db->execute(
            "
            INSERT INTO users (email, password_hash, role, created_at)
            VALUES (?, ?, ?, UNIX_TIMESTAMP())
            ",
            [$email, $passwordHash, $role]
        );

        $id = $this->db->lastInsertId();

        $this->db->execute(
            "UPDATE users SET username = ? WHERE id = ?",
            ['user'.$id, $id]
        );

        return $id;
    }

    /**
     * Uses index: email (email)
     */
    public function existsByEmail(string $email): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM users WHERE email = ? LIMIT 1",
            [$email]
        );
    }

    /**
     * Used by the username backfill to check candidate slugs for collisions.
     *
     * Uses index: users_username_unique (username)
     */
    public function existsUsername(string $username): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM users WHERE username = ? LIMIT 1",
            [$username]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function setUsername(int $id, string $username): void
    {
        $this->db->execute(
            "UPDATE users SET username = ? WHERE id = ?",
            [$username, $id]
        );
    }

    /**
     * Persists the browser-resolved IANA timezone for server-side work that
     * has no request cookie, such as notification digest cron jobs.
     *
     * Uses index: PRIMARY(id)
     */
    public function updateTimezone(int $id, string $timezone): void
    {
        $this->db->execute(
            "UPDATE users SET timezone = ? WHERE id = ?",
            [$timezone, $id]
        );
    }

    public function findDigestDeliveryTimeById(int $id): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT digest_delivery_time FROM users WHERE id = ?',
            [$id],
        );

        return $row['digest_delivery_time'] ?? null;
    }

    /**
     * Lightweight timezone lookup for background notification scheduling.
     *
     * Uses index: PRIMARY(id)
     */
    public function findTimezoneById(int $id): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT timezone FROM users WHERE id = ?',
            [$id]
        );

        return $row !== null ? (string) $row['timezone'] : null;
    }

    /**
     * Legacy rows created before the username column existed.
     *
     * @return list<array{id:int, nick:string}>
     */
    public function findWithoutUsername(): array
    {
        return $this->db->fetchAll(
            "SELECT id, nick FROM users WHERE username IS NULL ORDER BY id"
        );
    }

    /**
     * Bulk-resolves display info for a set of user ids, e.g. for enriching
     * conversation/message lists with sender names and avatars in one query.
     *
     * createdAt is included alongside the original displayName/avatarUrl pair
     * - a generic "when did this user join" fact (e.g. Forums.topic-view's
     * "Member since ..." per-post stat via Formatter::monthYear()), not
     * specific to any one caller, so it's added here rather than duplicated
     * into a module-only query.
     *
     * signature is here for the same reason: Forums.topic-view shows each
     * author's forum signature under their posts, and this is already the
     * batched per-author lookup that page does. Already-purified HTML, since
     * every write into that column goes through
     * Service\UserService::sanitizeSignature(). Deliberately not folded into
     * findActiveByIds() instead: that one hides deactivated accounts, which
     * would also blank out the post count and join date this callsite needs
     * for them.
     *
     * Uses index: PRIMARY(id)
     *
     * @param list<int> $ids
     * @return array<int, array{id:int, displayName:string, avatarUrl:string, createdAt:int, signature:string, hidePresence:bool}>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $result = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "SELECT id, nick, avatar_url, created_at, signature, hide_presence FROM users WHERE id IN ($placeholders)",
                $chunk
            );

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $result[$id] = [
                    'id' => $id,
                    'displayName' => trim((string) ($row['nick'] ?? '')) !== ''
                        ? trim((string) $row['nick'])
                        : '#'.$id,
                    'avatarUrl' => (string) ($row['avatar_url'] ?? ''),
                    'createdAt' => (int) ($row['created_at'] ?? 0),
                    'signature' => (string) ($row['signature'] ?? ''),
                    'hidePresence' => (bool) ($row['hide_presence'] ?? false),
                ];
            }
        }

        return $result;
    }

    /**
     * Bulk-resolves active users by id, full public profile shape (incl.
     * username, needed to build per-author post URLs) - e.g. the community
     * feed's per-post author byline, batched to avoid one findActiveById()
     * query per post. Same is_active visibility rule as findActiveById();
     * deactivated accounts (or ids that don't exist) are simply absent from
     * the result, same graceful-degradation contract as findByIds() above.
     *
     * Uses index: PRIMARY(id)
     *
     * @param list<int> $ids
     * @return array<int, User> keyed by id
     */
    public function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $result = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "
                SELECT u.id, u.email, u.timezone, u.role, u.nick, u.username, u.bio, u.signature, u.homepage, u.gender, u.birth_date, u.avatar_url,
                       u.show_gender_publicly, u.show_birth_date_publicly, u.show_homepage_publicly, u.hide_presence, u.show_hidden_profile_to_friends,
                       u.created_at
                FROM users u
                WHERE u.id IN ($placeholders) AND u.is_active = 1
                ",
                $chunk
            );

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $result[$id] = new User(
                    id: $id,
                    email: $row['email'],
                    role: $row['role'],
                    nick: $row['nick'] ?? '',
                    username: $row['username'] ?? '',
                    avatarUrl: $row['avatar_url'] ?? '',
                    bio: $row['bio'] ?? '',
                    homepage: $row['homepage'] ?? '',
                    gender: $row['gender'] ?? '',
                    birthDate: $row['birth_date'] ?? null,
                    signature: $row['signature'] ?? '',
                    showGenderPublicly: (bool) ($row['show_gender_publicly'] ?? false),
                    showBirthDatePublicly: (bool) ($row['show_birth_date_publicly'] ?? false),
                    showHomepagePublicly: (bool) ($row['show_homepage_publicly'] ?? false),
                    hidePresence: (bool) ($row['hide_presence'] ?? false),
                    showHiddenProfileToFriends: (bool) ($row['show_hidden_profile_to_friends'] ?? false),
                    createdAt: isset($row['created_at']) ? (int) $row['created_at'] : null,
                    timezone: $row['timezone'] ?? 'UTC',
                );
            }
        }

        return $result;
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function updateProfilePersonal(
        int $id,
        string $nick,
        string $bio,
        string $homepage,
        string $gender,
        ?string $birthDate,
        string $avatarUrl
    ): void {
        $this->db->execute(
            "
            UPDATE users
            SET nick = ?, bio = ?, homepage = ?, gender = ?, birth_date = ?, avatar_url = ?
            WHERE id = ?
            ",
            [$nick, $bio, $homepage, $gender, $birthDate, $avatarUrl, $id]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function updateProfileForumSignature(int $id, string $signature): void
    {
        $this->db->execute(
            "
            UPDATE users
            SET signature = ?
            WHERE id = ?
            ",
            [$signature, $id]
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function updateProfilePrivacy(
        int $id,
        bool $showGenderPublicly,
        bool $showBirthDatePublicly,
        bool $showHomepagePublicly,
        bool $hidePresence,
        bool $showHiddenProfileToFriends
    ): void {
        $this->db->execute(
            "
            UPDATE users
            SET show_gender_publicly = ?,
                show_birth_date_publicly = ?,
                show_homepage_publicly = ?,
                hide_presence = ?,
                show_hidden_profile_to_friends = ?
            WHERE id = ?
            ",
            [
                $showGenderPublicly ? 1 : 0,
                $showBirthDatePublicly ? 1 : 0,
                $showHomepagePublicly ? 1 : 0,
                $hidePresence ? 1 : 0,
                $showHiddenProfileToFriends ? 1 : 0,
                $id,
            ]
        );
    }
}

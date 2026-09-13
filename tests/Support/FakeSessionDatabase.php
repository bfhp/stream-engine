<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use StreamEngine\Core\PdoDatabase;

/**
 * In-memory stand-in for PdoDatabase covering the queries UserService's
 * presence methods make: UserSessionRepository's member/guest/bot lookups,
 * its guest and bot writes, and UserRepository::findActiveByIds().
 *
 * Separate from FakePdoDatabase (which is `final`, and models the messenger's
 * feeds/memberships/conversations tables instead) for the same reason that
 * one exists at all: it lets the presence tests in UserServiceTest exercise
 * the *real* repositories rather than stubbing them out, so the service's
 * ordering and filtering rules are tested against the shapes those
 * repositories actually return.
 *
 * Queries are routed by matching a distinctive substring of each known SQL,
 * not by parsing SQL - same deliberate limitation FakePdoDatabase documents.
 */
final class FakeSessionDatabase extends PdoDatabase
{
    /** @var list<array{userId: ?int, botName: ?string, tokenHash: ?string, lastUsedAt: int}> */
    private array $sessions = [];

    /**
     * Every execute() this fake was asked to run, in order, as
     * [sql, params] - the write half of presence (createGuest/
     * recordBotVisit/touchIfStale) has nothing to read back, so tests
     * assert on what was issued instead.
     *
     * @var list<array{0: string, 1: array}>
     */
    public array $executed = [];

    /** @var array<int, array{nick: string, username: string, active: bool}> */
    private array $users = [];

    public function __construct()
    {
    }

    /**
     * Seeds one session row. $secondsAgo is how stale its last_used_at is,
     * which is the only thing presence cares about; a user with several
     * sessions (phone + laptop) is seeded by calling this more than once
     * with the same id, exactly as the real table would hold it.
     */
    public function addSession(int $userId, int $secondsAgo): void
    {
        $this->sessions[] = [
            'userId' => $userId,
            'botName' => null,
            'tokenHash' => null,
            'lastUsedAt' => time() - $secondsAgo,
        ];
    }

    /**
     * An anonymous visitor's row: no user_id, no bot_name. $tokenHash is
     * only needed by tests that then look the row up through findValid()
     * (i.e. the "returning visitor gets touched" path).
     */
    public function addGuestSession(int $secondsAgo, ?string $tokenHash = null): void
    {
        $this->sessions[] = [
            'userId' => null,
            'botName' => null,
            'tokenHash' => $tokenHash,
            'lastUsedAt' => time() - $secondsAgo,
        ];
    }

    /**
     * A crawler's row - one per bot, same as UserSessionRepository::
     * recordBotVisit() maintains in the real table.
     */
    public function addBotSession(string $botName, int $secondsAgo): void
    {
        $this->sessions[] = [
            'userId' => null,
            'botName' => $botName,
            'tokenHash' => null,
            'lastUsedAt' => time() - $secondsAgo,
        ];
    }

    public function addUser(int $id, string $nick, string $username = '', bool $active = true): void
    {
        $this->users[$id] = ['nick' => $nick, 'username' => $username, 'active' => $active];
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        // UserSessionRepository::findOnlineUserIds()
        if (str_contains($sql, 'FROM user_sessions') && str_contains($sql, 'GROUP BY user_id')) {
            $threshold = (int) $params[0];
            $lastSeen = [];

            foreach ($this->sessions as $session) {
                // Mirrors the real query's `AND user_id IS NOT NULL`: guest
                // and bot rows live in this table too and must not collapse
                // into a phantom member.
                if ($session['userId'] === null) {
                    continue;
                }

                if (! $this->isFresh($session['lastUsedAt'], $threshold)) {
                    continue;
                }

                $id = $session['userId'];
                $lastSeen[$id] = max($lastSeen[$id] ?? 0, $session['lastUsedAt']);
            }

            arsort($lastSeen);

            return array_map(
                static fn (int $id, int $seen): array => ['user_id' => $id, 'last_seen_at' => $seen],
                array_keys($lastSeen),
                array_values($lastSeen)
            );
        }

        // UserSessionRepository::getOnlineUserIds() - candidate ids first,
        // threshold last, matching its own parameter order.
        if (str_contains($sql, 'FROM user_sessions') && str_contains($sql, 'SELECT DISTINCT user_id')) {
            $threshold = (int) array_pop($params);
            $candidates = array_map('intval', $params);
            $online = [];

            foreach ($this->sessions as $session) {
                if ($session['userId'] === null
                    || ! in_array($session['userId'], $candidates, true)) {
                    continue;
                }

                if ($this->isFresh($session['lastUsedAt'], $threshold)) {
                    $online[$session['userId']] = true;
                }
            }

            return array_map(
                static fn (int $id): array => ['user_id' => $id],
                array_keys($online)
            );
        }

        // UserSessionRepository::findOnlineBotNames()
        if (str_contains($sql, 'FROM user_sessions') && str_contains($sql, 'GROUP BY bot_name')) {
            $threshold = (int) $params[0];
            $lastSeen = [];

            foreach ($this->sessions as $session) {
                if ($session['botName'] === null || ! $this->isFresh($session['lastUsedAt'], $threshold)) {
                    continue;
                }

                $name = $session['botName'];
                $lastSeen[$name] = max($lastSeen[$name] ?? 0, $session['lastUsedAt']);
            }

            arsort($lastSeen);

            return array_map(
                static fn (string $name, int $seen): array => ['bot_name' => $name, 'last_seen_at' => $seen],
                array_keys($lastSeen),
                array_values($lastSeen)
            );
        }

        // UserSessionRepository::countOnlineGuests()
        if (str_contains($sql, 'FROM user_sessions') && str_contains($sql, 'COUNT(*) AS total')) {
            $threshold = (int) $params[0];
            $total = 0;

            foreach ($this->sessions as $session) {
                if ($session['userId'] === null
                    && $session['botName'] === null
                    && $this->isFresh($session['lastUsedAt'], $threshold)) {
                    $total++;
                }
            }

            return [['total' => $total]];
        }

        // UserSessionRepository::findValid() - the guest half of
        // recordGuestPresence() looks its own row up by cookie hash.
        if (str_contains($sql, 'FROM user_sessions') && str_contains($sql, 'WHERE token_hash = ?')) {
            foreach ($this->sessions as $index => $session) {
                if ($session['tokenHash'] !== null && $session['tokenHash'] === $params[0]) {
                    return [['id' => $index + 1, 'user_id' => $session['userId']]];
                }
            }

            return [];
        }

        // UserRepository::findActiveByIds()
        if (str_contains($sql, 'FROM users u') && str_contains($sql, 'u.id IN (')) {
            $rows = [];

            foreach (array_map('intval', $params) as $id) {
                $user = $this->users[$id] ?? null;

                if ($user === null || ! $user['active']) {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'email' => 'user'.$id.'@example.com',
                    'role' => 'user',
                    'nick' => $user['nick'],
                    'username' => $user['username'],
                    'bio' => '',
                    'signature' => '',
                    'homepage' => '',
                    'gender' => '',
                    'birth_date' => null,
                    'avatar_url' => '',
                    'created_at' => 1700000000,
                ];
            }

            // Deliberately not in $params order: the real query's rows come
            // back in whatever order the database chooses, and
            // UserService::onlineMembers() is supposed to re-impose its own
            // ordering.
            // Reversing here is what makes that assertion meaningful.
            return array_reverse($rows);
        }

        // Deliberately loud rather than an empty result: if the SQL in
        // UserSessionRepository/UserRepository is ever reworded, a silent []
        // here would leave every presence test still passing while quietly
        // asserting "nobody is online" - the exact failure mode a fake like
        // this is supposed to make impossible.
        throw new RuntimeException('FakeSessionDatabase: unrouted SQL: '.$sql);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->fetchAll($sql, $params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executed[] = [$sql, $params];

        return 1;
    }

    /**
     * True if some execute() call's SQL contained $needle - the assertion
     * every presence *write* test makes ("was a guest row inserted?",
     * "was it only touched?").
     */
    public function didExecute(string $needle): bool
    {
        foreach ($this->executed as [$sql]) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isFresh(int $lastUsedAt, int $thresholdSeconds): bool
    {
        return $lastUsedAt > time() - $thresholdSeconds;
    }
}

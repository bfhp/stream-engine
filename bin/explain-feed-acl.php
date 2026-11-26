<?php

declare(strict_types=1);

/*
 * Query plans of the ACL-filtered feed reads on production-shaped data.
 *
 * Creates a disposable database (as bin/build-install-schema.php does),
 * installs resources/install/schema.sql and the migrations newer than it
 * (the snapshot is rebuilt at release), seeds 330,000 feeds of which
 * 309,000 are library pages, runs each FeedRepository case for a guest and
 * for a member, and for every query it sent prints EXPLAIN, the server's
 * own analysis (ANALYZE FORMAT=JSON on MariaDB, EXPLAIN ANALYZE on MySQL)
 * and the median time. The plans are checked against the methods'
 * `Uses index` annotations: no full table scan, every ACL subquery on a
 * primary key, and the declared index - index-only where declared so - for
 * the typed scan. Exits 1 when a check fails.
 *
 *   php bin/explain-feed-acl.php [--report=plans.md] [--json=plans.json]
 *       [--content-bytes=1000] [--keep]
 *   php bin/explain-feed-acl.php --compare a.json b.json ...
 *
 * Connects with DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD (.env or the
 * environment); the user needs CREATE/DROP DATABASE for
 * stream_engine_schema_build_% databases. The database is dropped at the
 * end unless --keep is given.
 */

use StreamEngine\Core\Config;
use StreamEngine\Core\Installation\DisposableDatabase;
use StreamEngine\Core\Installation\SchemaSnapshot;
use StreamEngine\Core\Migrations\SqlDialect;
use StreamEngine\Core\Migrations\SqlScript;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();

const SEED_TIME = 1700000000;
const SEED_STEP = 37;
const TYPED_INDEX = 'feeds_type_created_at_id_index';

try {
    $arguments = array_slice($argv, 1);
    if (($arguments[0] ?? null) === '--compare') {
        $files = array_slice($arguments, 1);
        if ($files === []) {
            throw new RuntimeException('--compare needs the JSON reports to compare.');
        }
        echo compareReports($files);
        exit(0);
    }

    $options = getopt('', ['report:', 'json:', 'content-bytes:', 'keep']);
    if ($options === false) {
        throw new RuntimeException('Could not parse command-line options.');
    }
    $contentBytes = max(0, (int) ($options['content-bytes'] ?? 1000));
    $keep = array_key_exists('keep', $options);

    $env = array_merge(getenv(), $_ENV);
    $config = new Config($env);
    $temporaryDatabase = DisposableDatabase::create($config);

    try {
        $db = new PdoDatabase(new Config([
            'APP_ENV' => 'test',
            'DB_HOST' => $config->dbHost(),
            'DB_PORT' => $config->dbPort(),
            'DB_NAME' => $temporaryDatabase->name(),
            'DB_USERNAME' => $config->dbUserName(),
            'DB_PASSWORD' => $config->dbPassword(),
        ]), logQueries: true);
        $pdo = $db->getPdo();
        $dialect = SqlDialect::forPdo($pdo);
        $server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        progress("Server $server, database {$temporaryDatabase->name()}");
        $applied = installSchema($pdo, $dialect, dirname(__DIR__));
        progress('Schema of the release, then '.($applied === [] ? 'no newer migrations' : 'migrations '.implode(', ', $applied)));
        $seedSeconds = seed($pdo, $contentBytes);
        progress(sprintf('Seeded in %.1f s', $seedSeconds));

        $results = runCases(new FeedRepository($db), $db, $pdo, $dialect);
        $report = [
            'server' => $server,
            'mysql' => $dialect->isMySql(),
            'content_bytes' => $contentBytes,
            'counts' => $pdo->query('SELECT type, COUNT(*) AS feeds FROM feeds GROUP BY type ORDER BY type')->fetchAll(PDO::FETCH_KEY_PAIR),
            'cases' => $results,
        ];

        if (isset($options['json'])) {
            file_put_contents((string) $options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }
        $markdown = markdownReport($report);
        if (isset($options['report'])) {
            file_put_contents((string) $options['report'], $markdown);
        } else {
            echo $markdown;
        }

        $failures = 0;
        foreach ($results as $case) {
            foreach ($case['queries'] as $query) {
                $failures += count($query['failures']);
            }
        }
        progress($failures === 0 ? 'All plans match their annotations.' : "$failures plan check(s) failed.");
        $exitCode = $failures === 0 ? 0 : 1;
    } finally {
        if ($keep) {
            progress("Kept database {$temporaryDatabase->name()}");
        } else {
            $temporaryDatabase->drop();
        }
    }

    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    if ($e->getPrevious() !== null) {
        fwrite(STDERR, $e->getPrevious()->getMessage().PHP_EOL);
    }
    exit(2);
}

function progress(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}

/**
 * The release snapshot, then every migration it does not include, in order:
 * the plans of the code as it is, not as it was released.
 *
 * @return list<string> the migrations applied over the snapshot
 */
function installSchema(PDO $pdo, SqlDialect $dialect, string $root): array
{
    $snapshot = SchemaSnapshot::load($root.'/resources/install/manifest.json');
    $run = static function (string $file) use ($pdo, $dialect): void {
        foreach (SqlScript::statements((string) file_get_contents($file)) as $statement) {
            $pdo->exec($dialect->translate($statement));
        }
    };
    $run($snapshot->schemaFile);

    $included = array_column($snapshot->migrations, 'version');
    $applied = [];
    $files = glob($root.'/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (! in_array($version, $included, true)) {
            $run($file);
            $applied[] = $version;
        }
    }

    // Only MATCH() reads them, and keeping them would make seeding minutes
    // longer; no case here uses full-text search.
    $pdo->exec('ALTER TABLE feeds DROP INDEX feeds_fulltext_title_content, DROP INDEX feeds_fulltext_title');

    return $applied;
}

/**
 * The shape of the production data the annotations were measured on:
 * 330,000 feeds, 309,000 of them library pages under 1,800 books; a tenth
 * of the pages in a community container; a few members-only and private
 * feeds of every type; blogs, communities, posts, comments and a gallery.
 * Ids follow creation time, newest last.
 */
function seed(PDO $pdo, int $contentBytes): float
{
    $start = microtime(true);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('SET UNIQUE_CHECKS = 0');

    $pdo->exec('CREATE TABLE seed_digits (d INT UNSIGNED NOT NULL PRIMARY KEY)');
    $pdo->exec('INSERT INTO seed_digits VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE seed_seq (n INT UNSIGNED NOT NULL PRIMARY KEY)');
    $pdo->exec('INSERT INTO seed_seq
        SELECT a.d + 10 * b.d + 100 * c.d + 1000 * d.d + 10000 * e.d + 100000 * f.d
        FROM seed_digits a, seed_digits b, seed_digits c, seed_digits d, seed_digits e, seed_digits f
        WHERE f.d < 4');

    $pdo->exec("INSERT INTO membership_roles (id, name, role_level) VALUES
        (1, 'subscriber', 0), (2, 'member', 1), (3, 'moderator', 2), (4, 'owner', 3)");
    $pdo->exec("INSERT INTO users (id, email, password_hash, created_at, nick, username)
        SELECT n, CONCAT('u', n, '@example.test'), 'x', 1, CONCAT('User ', n), CONCAT('u', n)
        FROM seed_seq WHERE n BETWEEN 1 AND 2000");

    $content = $contentBytes > 0 ? 'REPEAT(\'x\', '.$contentBytes.')' : 'NULL';
    $visibility = static fn (string $members, string $private): string => "CASE WHEN $members THEN 'members' WHEN $private THEN 'private' ELSE 'public' END";
    $groups = [
        // [type, first id, last id, parent, container, container type, visibility, owner]
        ['community', 1, 200, 'NULL', 'n', "'community'", $visibility('n % 20 BETWEEN 15 AND 17', 'n % 20 >= 18'), '1 + n % 2000'],
        ['blog', 201, 1200, 'NULL', 'n', "'blog'", $visibility('n % 20 = 18', 'n % 20 = 19'), '1 + n % 2000'],
        ['lib-book', 1201, 3000, 'NULL', 'NULL', 'NULL', "'public'", '1 + n % 50'],
        ['lib-page', 3001, 312000, '1201 + n % 1800', 'CASE WHEN n % 10 = 0 THEN 1 + n % 200 END', "CASE WHEN n % 10 = 0 THEN 'community' END", $visibility('n % 100 = 0', 'n % 100 = 1'), '1 + n % 50'],
        ['blog-post', 312001, 324000, '201 + n % 1000', '201 + n % 1000', "'blog'", $visibility('n % 20 = 18', 'n % 20 = 19'), '1 + n % 2000'],
        ['comment', 324001, 327000, '312001 + n % 12000', 'NULL', 'NULL', "'public'", '1 + n % 2000'],
        ['comment', 327001, 328000, '324001 + n % 3000', 'NULL', 'NULL', "'public'", '1 + n % 2000'],
        ['gallery-album', 328001, 328200, 'CASE WHEN n % 2 = 0 THEN 1 + n % 200 ELSE 201 + n % 1000 END', 'CASE WHEN n % 2 = 0 THEN 1 + n % 200 ELSE 201 + n % 1000 END', "CASE WHEN n % 2 = 0 THEN 'community' ELSE 'blog' END", "'public'", '1 + n % 2000'],
        ['gallery-photo', 328201, 330000, '328001 + n % 200', 'NULL', 'NULL', $visibility('n % 50 = 0', 'n % 50 = 1'), '1 + n % 2000'],
    ];
    foreach ($groups as [$type, $first, $last, $parent, $container, $containerType, $visible, $owner]) {
        $pdo->exec("INSERT INTO feeds (id, parent_id, type, container_id, container_type, owner_id, slug, title,
                description, content, views, created_at, updated_at, visibility, position, rating_sum, rating_count, rating_avg)
            SELECT n, $parent, '$type', $container, $containerType, $owner, CONCAT('f', n), CONCAT('Feed ', n),
                NULL, $content, n % 1000, ".SEED_TIME.' + n * '.SEED_STEP.', '.SEED_TIME.' + n * '.SEED_STEP.", $visible, n,
                4 * (n % 4), n % 4, CASE WHEN n % 4 = 0 THEN 0 ELSE 4 END
            FROM seed_seq WHERE n BETWEEN $first AND $last");
    }

    $pdo->exec("INSERT INTO feed_metadata (feed_id, name, content, created_at, updated_at)
        SELECT n, 'gallery.state', CASE WHEN n % 10 = 0 THEN 'processing' ELSE 'ready' END, 1, 1
        FROM seed_seq WHERE n BETWEEN 328201 AND 330000");

    // The member of the cases (user 2): every fourth community, every
    // eighth as a moderator, every fiftieth blog. Others: 20,000 random
    // memberships.
    $pdo->exec('INSERT INTO memberships (container_id, user_id, joined_at, membership_role_id)
        SELECT n, 2, 1, CASE WHEN n % 8 = 0 THEN 3 ELSE 2 END FROM seed_seq WHERE n BETWEEN 1 AND 200 AND n % 4 = 0
        UNION ALL
        SELECT n, 2, 1, 2 FROM seed_seq WHERE n BETWEEN 201 AND 1200 AND n % 50 = 0');
    $pdo->exec('INSERT IGNORE INTO memberships (container_id, user_id, joined_at, membership_role_id)
        SELECT 1 + n % 1200, 3 + (n * 7) % 1998, 1, 1 + n % 4 FROM seed_seq WHERE n BETWEEN 1 AND 20000');

    $pdo->exec('DROP TABLE seed_seq, seed_digits');
    $pdo->exec('SET UNIQUE_CHECKS = 1');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (['users', 'membership_roles', 'memberships', 'feeds', 'feed_metadata'] as $table) {
        $pdo->query("ANALYZE TABLE $table")->fetchAll();
    }

    return microtime(true) - $start;
}

/**
 * Each case: what it calls, and what its annotations promise for the scan
 * of the typed feeds - the index ('key', or any of 'keys') and whether it
 * is index-only ('covering'). Every query of a case gets the same check;
 * the joins back to full rows go by PRIMARY and are checked as such.
 *
 * @return list<array<string, mixed>>
 */
function cases(): array
{
    $guest = new User(id: 0, email: '');
    $member = new User(id: 2, email: 'u2@example.test');
    $deepCursor = (object) ['createdAt' => SEED_TIME + 20000 * SEED_STEP, 'id' => 20000];
    $typed = ['key' => TYPED_INDEX, 'covering' => true];
    $cases = [
        ['countAllByType(lib-page)', static fn (FeedRepository $r) => $r->countAllByType('lib-page'),
            ['keys' => ['feeds_type_rating_avg_id_index', TYPED_INDEX], 'covering' => true]],
        ['findPublicByTypePage(lib-page, 30000, 0)', static fn (FeedRepository $r) => $r->findPublicByTypePage('lib-page', 30000, 0), $typed],
        ['findPublicByTypePage(lib-page, 30000, 270000)', static fn (FeedRepository $r) => $r->findPublicByTypePage('lib-page', 30000, 270000), $typed],
    ];
    foreach (['guest' => $guest, 'member' => $member] as $who => $user) {
        array_push(
            $cases,
            ["countByType(lib-page) as $who", static fn (FeedRepository $r) => $r->countByType('lib-page', $user), $typed],
            ["findByType(lib-page, 20, 0) as $who", static fn (FeedRepository $r) => $r->findByType('lib-page', $user, 20, 0), $typed],
            ["findByType(lib-page, 20, 280000) as $who", static fn (FeedRepository $r) => $r->findByType('lib-page', $user, 20, 280000), $typed],
            ["findByTypePage(lib-page, 20, 0) as $who", static fn (FeedRepository $r) => $r->findByTypePage('lib-page', $user, 20, 0), $typed],
            ["findByTypePage(lib-page, 20, 280000) as $who", static fn (FeedRepository $r) => $r->findByTypePage('lib-page', $user, 20, 280000), $typed],
            ["findByTypeAfter(lib-page, 20, deep cursor) as $who", static fn (FeedRepository $r) => $r->findByTypeAfter('lib-page', $user, 20, $deepCursor), ['key' => TYPED_INDEX, 'covering' => false]],
            ["countByType(blog-post) as $who", static fn (FeedRepository $r) => $r->countByType('blog-post', $user), $typed],
            ["findByTypePage(blog-post, 20, 0) as $who", static fn (FeedRepository $r) => $r->findByTypePage('blog-post', $user, 20, 0), $typed],
            ["countByType(comment) as $who", static fn (FeedRepository $r) => $r->countByType('comment', $user), $typed],
            ["findByType(comment, 20, 0) as $who", static fn (FeedRepository $r) => $r->findByType('comment', $user, 20, 0), $typed],
            ["countByType(gallery-photo) as $who", static fn (FeedRepository $r) => $r->countByType('gallery-photo', $user), $typed],
            ["findByType(gallery-photo, 20, 0) as $who", static fn (FeedRepository $r) => $r->findByType('gallery-photo', $user, 20, 0), $typed],
            ["findByIds(20 mixed ids) as $who", static fn (FeedRepository $r) => $r->findByIds([1, 150, 201, 1199, 1201, 3001, 3010, 3100, 200000, 311990, 312001, 312018, 312019, 324001, 327500, 328001, 328201, 328210, 328250, 329999], $user), ['key' => 'PRIMARY', 'covering' => false]],
        );
    }

    return $cases;
}

/**
 * @return list<array<string, mixed>>
 */
function runCases(FeedRepository $repository, PdoDatabase $db, PDO $pdo, SqlDialect $dialect): array
{
    $results = [];
    foreach (cases() as [$name, $call, $expect]) {
        progress($name);
        // A warm-up run, then the median of three - unless the warm-up is
        // already slow (a bad plan): then it is the time.
        $logged = count($db->getQueryLog());
        $start = microtime(true);
        $value = $call($repository);
        $warmUp = (microtime(true) - $start) * 1000;
        $queries = array_slice($db->getQueryLog(), $logged);
        $times = [$warmUp, $warmUp, $warmUp];
        if ($warmUp < 5000) {
            $times = [];
            for ($run = 0; $run < 3; $run++) {
                $start = microtime(true);
                $value = $call($repository);
                $times[] = (microtime(true) - $start) * 1000;
            }
            sort($times);
        }

        $explained = [];
        foreach ($queries as $query) {
            $plan = explain($pdo, $query['query'], $query['params']);
            $explained[] = [
                'sql' => trim((string) preg_replace('/[ \t]+$/m', '', $query['query'])),
                'params' => $query['params'],
                'plan' => $plan,
                'signature' => signature($plan),
                'failures' => checkPlan($plan, $expect),
                'analysis' => analyze($pdo, $dialect, $query['query'], $query['params']),
            ];
        }
        $results[] = [
            'case' => $name,
            'result' => describeResult($value),
            'median_ms' => round($times[1], 1),
            'expect' => $expect,
            'queries' => $explained,
        ];
    }

    return $results;
}

function describeResult(mixed $value): string
{
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_array($value) && array_key_exists('items', $value)) {
        return count($value['items']).' rows, total '.$value['total'];
    }

    return (is_array($value) ? count($value) : 0).' rows';
}

/**
 * @param list<mixed> $params
 */
function prepared(PDO $pdo, string $sql, array $params): PDOStatement
{
    // EXPLAIN and ANALYZE are not preparable everywhere: inline the values,
    // typed as the repository bound them.
    $statement = $pdo->prepare($sql, [PDO::ATTR_EMULATE_PREPARES => true]);
    foreach (array_values($params) as $i => $param) {
        $statement->bindValue($i + 1, $param, is_int($param) ? PDO::PARAM_INT : (is_null($param) ? PDO::PARAM_NULL : PDO::PARAM_STR));
    }
    $statement->execute();

    return $statement;
}

/**
 * EXPLAIN rows, reduced to the columns both servers print.
 *
 * @param list<mixed> $params
 * @return list<array<string, mixed>>
 */
function explain(PDO $pdo, string $sql, array $params): array
{
    $rows = prepared($pdo, 'EXPLAIN '.$sql, $params)->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'select_type' => $row['select_type'],
        'table' => $row['table'],
        'type' => $row['type'],
        'key' => $row['key'],
        'ref' => $row['ref'],
        'rows' => $row['rows'],
        'Extra' => $row['Extra'],
    ], $rows);
}

/**
 * @param list<mixed> $params
 */
function analyze(PDO $pdo, SqlDialect $dialect, string $sql, array $params): string
{
    $prefix = $dialect->isMySql() ? 'EXPLAIN ANALYZE ' : 'ANALYZE FORMAT=JSON ';
    try {
        return (string) prepared($pdo, $prefix.$sql, $params)->fetchColumn();
    } catch (PDOException $e) {
        return 'Not available: '.$e->getMessage();
    }
}

function isCovering(?string $extra): bool
{
    return preg_match('/(^|;\s*)Using index($|;)/', (string) $extra) === 1;
}

function isRealTable(?string $table): bool
{
    return $table !== null && $table !== '' && ! str_starts_with($table, '<');
}

/**
 * The access paths, without row estimates: what must match across servers.
 *
 * @param list<array<string, mixed>> $plan
 * @return list<string>
 */
function signature(array $plan): array
{
    return array_map(static fn (array $row): string => sprintf(
        '%s %s %s %s%s',
        $row['select_type'],
        $row['table'] ?? '-',
        $row['type'] ?? '-',
        $row['key'] ?? '-',
        isCovering($row['Extra']) ? ' (index only)' : '',
    ), $plan);
}

/**
 * @param list<array<string, mixed>> $plan
 * @param array<string, mixed> $expect
 * @return list<string>
 */
function checkPlan(array $plan, array $expect): array
{
    $failures = [];
    $keys = $expect['keys'] ?? [$expect['key']];
    $driver = null;
    foreach ($plan as $row) {
        $table = $row['table'];
        if (! isRealTable($table)) {
            continue;
        }
        if ($row['type'] === 'ALL') {
            $failures[] = "full table scan of $table ({$row['select_type']})";
        }
        if ($row['type'] === 'index' && $row['key'] !== null) {
            $failures[] = "full index scan of $table by {$row['key']} ({$row['select_type']})";
        }
        $isSubquery = str_contains((string) $row['select_type'], 'SUBQUERY');
        if ($isSubquery && $row['type'] !== null && $row['key'] !== 'PRIMARY') {
            $failures[] = "ACL lookup on $table uses ".($row['key'] ?? 'no index').' instead of PRIMARY';
        }
        if ($isSubquery && ! in_array($row['type'], [null, 'eq_ref', 'const', 'system', 'ref'], true)) {
            $failures[] = "ACL lookup on $table is {$row['type']}, not a primary key lookup";
        }
        if (! $isSubquery && $driver === null && in_array($table, ['f', 'feeds'], true) && in_array($row['key'], $keys, true)) {
            $driver = $row;
        }
    }
    if ($driver === null) {
        $failures[] = 'no scan of the feeds uses '.implode(' or ', $keys);
    } elseif (($expect['covering'] ?? false) && ! isCovering($driver['Extra'])) {
        $failures[] = "{$driver['key']} is not index-only (Extra: {$driver['Extra']})";
    }

    return $failures;
}

/**
 * @param array<string, mixed> $report
 */
function markdownReport(array $report): string
{
    $out = "## Feed ACL plans on {$report['server']}\n\n";
    $out .= 'Seeded: '.implode(', ', array_map(
        static fn (string $type, int|string $count): string => "$type $count",
        array_keys($report['counts']),
        $report['counts'],
    ))."; content {$report['content_bytes']} bytes per feed.\n\n";
    $out .= "| Case | Result | Median | Checks |\n| --- | --- | ---: | --- |\n";
    foreach ($report['cases'] as $case) {
        $failures = array_merge(...array_map(static fn (array $q): array => $q['failures'], $case['queries']));
        $out .= sprintf(
            "| `%s` | %s | %.1f ms | %s |\n",
            $case['case'],
            $case['result'],
            $case['median_ms'],
            $failures === [] ? 'ok' : 'FAIL: '.str_replace('|', '\\|', implode('; ', array_unique($failures))),
        );
    }
    foreach ($report['cases'] as $case) {
        $out .= "\n### `{$case['case']}`\n";
        foreach ($case['queries'] as $i => $query) {
            $out .= "\nQuery ".($i + 1)."\n\n| id | select_type | table | type | key | ref | rows | Extra |\n| --- | --- | --- | --- | --- | --- | ---: | --- |\n";
            foreach ($query['plan'] as $row) {
                $out .= '| '.implode(' | ', array_map(
                    static fn (mixed $v): string => str_replace('|', '\\|', (string) ($v ?? '')),
                    $row,
                ))." |\n";
            }
            $out .= "\n<details><summary>SQL and analysis</summary>\n\n```sql\n{$query['sql']}\n```\n\nParameters: `"
                .json_encode($query['params'])."`\n\n```\n{$query['analysis']}\n```\n\n</details>\n";
        }
    }

    return $out;
}

/**
 * Access paths of every case side by side: a row per case and query step
 * whose path differs between the reports, and the median times.
 *
 * @param list<string> $files
 */
function compareReports(array $files): string
{
    $reports = array_map(static function (string $file): array {
        $report = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $report['byCase'] = array_column($report['cases'], null, 'case');

        return $report;
    }, $files);
    $servers = array_map(static fn (array $r): string => $r['server'], $reports);
    $header = static fn (string $first): string => "| $first | ".implode(' | ', $servers)." |\n| --- |".str_repeat(' --- |', count($servers))."\n";

    $out = "## Feed ACL plans compared\n\n### Median times\n\n".$header('Case');
    foreach ($reports[0]['cases'] as $case) {
        $cells = array_map(static function (array $r) use ($case): string {
            $other = $r['byCase'][$case['case']] ?? null;
            if ($other === null) {
                return 'missing';
            }
            $failed = array_filter($other['queries'], static fn (array $q): bool => $q['failures'] !== []);

            return sprintf('%.1f ms%s', $other['median_ms'], $failed === [] ? '' : ' FAIL');
        }, $reports);
        $out .= "| `{$case['case']}` | ".implode(' | ', $cells)." |\n";
    }

    $out .= "\n### Access paths that differ\n\n";
    $differences = '';
    foreach ($reports[0]['cases'] as $case) {
        $signatures = array_map(
            static fn (array $r): array => array_map(
                static fn (array $q): array => $q['signature'],
                ($r['byCase'][$case['case']] ?? ['queries' => []])['queries'],
            ),
            $reports,
        );
        if (count(array_unique(array_map('serialize', $signatures))) === 1) {
            continue;
        }
        $differences .= "\n#### `{$case['case']}`\n\n".$header('Step');
        $steps = max(array_map(static fn (array $s): int => count($s), $signatures));
        for ($q = 0; $q < $steps; $q++) {
            $lines = max(array_map(static fn (array $s): int => count($s[$q] ?? []), $signatures));
            for ($l = 0; $l < $lines; $l++) {
                $cells = array_map(static fn (array $s): string => '`'.($s[$q][$l] ?? '').'`', $signatures);
                if (count(array_unique($cells)) > 1) {
                    $differences .= '| query '.($q + 1).' step '.($l + 1).' | '.implode(' | ', $cells)." |\n";
                }
            }
        }
    }

    return $out.($differences === '' ? "None: every case has the same access paths on every server.\n" : $differences);
}

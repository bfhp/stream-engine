<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$wikiDirectory = $projectRoot.'/docs/wiki';
$wikiBaseUrl = 'https://github.com/bfhp/stream-engine/wiki/';
$administratorPages = [
    'Administrator-Requirements.md',
    'Administrator-Installation.md',
    'Administrator-Configuration.md',
    'Administrator-Content-and-Routing.md',
    'Administrator-Users-and-Permissions.md',
    'Administrator-Appearance-and-Files.md',
    'Administrator-Scheduler.md',
    'Administrator-Routine-Operations.md',
    'Administrator-Backups-and-Restore.md',
    'Administrator-Upgrades-and-Rollback.md',
    'Administrator-Troubleshooting.md',
    'Administrator-Uninstallation.md',
];
$requiredPages = [
    'Home.md',
    '_Sidebar.md',
    'Administrator-Guide.md',
    ...$administratorPages,
];

if (! is_dir($wikiDirectory)) {
    fail('Documentation source directory docs/wiki does not exist.');
}

$files = glob($wikiDirectory.'/*.md');
if ($files === false || $files === []) {
    fail('Documentation source directory docs/wiki contains no Markdown pages.');
}

sort($files, SORT_STRING);
$pages = [];
$anchors = [];
$caseInsensitiveNames = [];
foreach ($files as $file) {
    $name = basename($file);
    if (preg_match('/\A(?:Home|_Sidebar|[A-Z][A-Za-z0-9-]*)\.md\z/', $name) !== 1) {
        fail(sprintf('Wiki page name "%s" is not a stable ASCII page name.', $name));
    }

    $folded = strtolower($name);
    if (isset($caseInsensitiveNames[$folded])) {
        fail(sprintf(
            'Wiki page names "%s" and "%s" differ only by case.',
            $caseInsensitiveNames[$folded],
            $name,
        ));
    }
    $caseInsensitiveNames[$folded] = $name;

    $contents = file_get_contents($file);
    if ($contents === false) {
        fail(sprintf('Could not read docs/wiki/%s.', $name));
    }
    if (preg_match('/\A# [^\r\n]+(?:\r?\n|\z)/', $contents) !== 1 && $name !== '_Sidebar.md') {
        fail(sprintf('Wiki page "%s" must start with one level-one heading.', $name));
    }

    $pages[$name] = $contents;
    $anchors[$name] = markdownAnchors($contents);
}

foreach ($requiredPages as $requiredPage) {
    if (! isset($pages[$requiredPage])) {
        fail(sprintf('Required Wiki page "%s" is missing.', $requiredPage));
    }
}

foreach ($administratorPages as $administratorPage) {
    $wikiPage = substr($administratorPage, 0, -3);
    $canonicalUrl = $wikiBaseUrl.$wikiPage;
    foreach (['Home.md', '_Sidebar.md', 'Administrator-Guide.md'] as $navigationPage) {
        if (! str_contains($pages[$navigationPage], $canonicalUrl)) {
            fail(sprintf(
                'Administrator page "%s" is missing from "%s".',
                $administratorPage,
                $navigationPage,
            ));
        }
    }

    if (! str_contains($pages[$administratorPage], $wikiBaseUrl.'Administrator-Guide')) {
        fail(sprintf(
            'Administrator page "%s" does not link back to Administrator-Guide.',
            $administratorPage,
        ));
    }
}

foreach ($administratorPages as $index => $administratorPage) {
    $previousPage = $index === 0 ? 'Administrator-Guide.md' : $administratorPages[$index - 1];
    $previousUrl = $wikiBaseUrl.substr($previousPage, 0, -3);
    if (! str_contains($pages[$administratorPage], $previousUrl)) {
        fail(sprintf(
            'Administrator page "%s" does not link to previous page "%s".',
            $administratorPage,
            $previousPage,
        ));
    }

    $nextPage = $administratorPages[$index + 1] ?? null;
    if ($nextPage !== null) {
        $nextUrl = $wikiBaseUrl.substr($nextPage, 0, -3);
        if (! str_contains($pages[$administratorPage], $nextUrl)) {
            fail(sprintf(
                'Administrator page "%s" does not link to next page "%s".',
                $administratorPage,
                $nextPage,
            ));
        }
    }
}

$links = [];
foreach ($pages as $name => $contents) {
    $links[$name] = [];
    preg_match_all('/(?<!!)\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);
    foreach ($matches[1] as $destination) {
        $destination = trim($destination);
        if ($destination === '') {
            continue;
        }

        if (str_starts_with($destination, '#')) {
            assertAnchorExists($name, $name, substr($destination, 1), $destination, $anchors);

            continue;
        }

        if (str_starts_with($destination, $wikiBaseUrl)) {
            $wikiTarget = rawurldecode(substr($destination, strlen($wikiBaseUrl)));
            [$page, $fragment] = array_pad(explode('#', $wikiTarget, 2), 2, null);
            if (preg_match('/\A(?:Home|_Sidebar|[A-Z][A-Za-z0-9-]*)\z/', $page) !== 1) {
                fail(sprintf('Wiki page "%s" has invalid Wiki link "%s".', $name, $destination));
            }

            $target = $page.'.md';
            if (! isset($pages[$target])) {
                fail(sprintf('Wiki page "%s" links to missing page "%s".', $name, $page));
            }
            if ($fragment !== null && $fragment !== '') {
                assertAnchorExists($name, $target, $fragment, $destination, $anchors);
            }
            $links[$name][] = $target;

            continue;
        }

        if (preg_match('/\A(?:https?:|mailto:)/i', $destination) === 1) {
            continue;
        }

        if (preg_match('/\.md(?:#|\z)/', $destination) === 1) {
            fail(sprintf(
                'Wiki page "%s" has source-style link "%s"; use the canonical GitHub Wiki URL.',
                $name,
                $destination,
            ));
        }

        fail(sprintf('Wiki page "%s" has unsupported relative link "%s".', $name, $destination));
    }
}

$reachable = [];
$pending = ['Home.md', '_Sidebar.md'];
while ($pending !== []) {
    $page = array_pop($pending);
    if (isset($reachable[$page])) {
        continue;
    }
    $reachable[$page] = true;
    foreach ($links[$page] ?? [] as $target) {
        if (! isset($reachable[$target])) {
            $pending[] = $target;
        }
    }
}

foreach (array_keys($pages) as $page) {
    if (! isset($reachable[$page])) {
        fail(sprintf('Wiki page "%s" is not reachable from Home.md or _Sidebar.md.', $page));
    }
}

fwrite(STDOUT, sprintf("Validated %d Wiki pages.\n", count($pages)));

/** @return array<string, true> */
function markdownAnchors(string $contents): array
{
    $anchors = [];
    $occurrences = [];
    $inFence = false;

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $inFence = ! $inFence;

            continue;
        }
        if ($inFence || preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $matches) !== 1) {
            continue;
        }

        $base = githubHeadingSlug($matches[1]);
        if ($base === '') {
            continue;
        }

        $occurrence = $occurrences[$base] ?? 0;
        $slug = $occurrence === 0 ? $base : $base.'-'.$occurrence;
        $occurrences[$base] = $occurrence + 1;
        $anchors[$slug] = true;
    }

    return $anchors;
}

function githubHeadingSlug(string $heading): string
{
    $heading = preg_replace('/!?\[([^\]]+)\]\([^)]+\)/u', '$1', $heading) ?? $heading;
    $heading = str_replace('`', '', strip_tags(html_entity_decode($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $heading = function_exists('mb_strtolower') ? mb_strtolower($heading, 'UTF-8') : strtolower($heading);
    $heading = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', $heading) ?? '';

    return preg_replace('/\s/u', '-', trim($heading)) ?? '';
}

/** @param array<string, array<string, true>> $anchors */
function assertAnchorExists(
    string $source,
    string $target,
    string $fragment,
    string $destination,
    array $anchors,
): void {
    $fragment = rawurldecode($fragment);
    if (! isset($anchors[$target][$fragment])) {
        fail(sprintf(
            'Wiki page "%s" links to missing heading "%s" in "%s" through "%s".',
            $source,
            $fragment,
            $target,
            $destination,
        ));
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$wikiDirectory = $projectRoot.'/docs/wiki';
$requiredPages = [
    'Home.md',
    '_Sidebar.md',
    'Administrator-Guide.md',
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
}

foreach ($requiredPages as $requiredPage) {
    if (! isset($pages[$requiredPage])) {
        fail(sprintf('Required Wiki page "%s" is missing.', $requiredPage));
    }
}

$links = [];
foreach ($pages as $name => $contents) {
    $links[$name] = [];
    preg_match_all('/(?<!!)\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);
    foreach ($matches[1] as $destination) {
        $destination = trim($destination);
        if ($destination === ''
            || str_starts_with($destination, '#')
            || preg_match('/\A(?:https?:|mailto:)/i', $destination) === 1) {
            continue;
        }

        $target = rawurldecode(explode('#', $destination, 2)[0]);
        if (str_contains($target, '/') || ! str_ends_with($target, '.md')) {
            fail(sprintf(
                'Wiki page "%s" has non-portable internal link "%s"; link to a flat .md page name.',
                $name,
                $destination,
            ));
        }
        if (! isset($pages[$target])) {
            fail(sprintf('Wiki page "%s" links to missing page "%s".', $name, $target));
        }

        $links[$name][] = $target;
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

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

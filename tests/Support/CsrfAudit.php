<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Source scanner used by CsrfCoverageTest (composer audit:csrf).
 *
 * Scans source files without bootstrapping the app. A clean result only means
 * a verifyCsrf call was found, not that CSRF protection works at runtime.
 * Calls are followed up to depth 4; collaborator methods are loosely resolved
 * by name across the tree. Inline switch checks also count as covered.
 * Undispatched actions are reported as PLACEHOLDER, not as missing checks.
 *
 * This deliberately preserves the original source scanner's heuristics:
 * braces in strings/comments and checks in unrelated branches can over-report
 * coverage. Runtime security is covered by the controller and Security tests.
 */
final class CsrfAudit
{
    private const MUTATING = ['POST', 'PATCH', 'PUT', 'DELETE'];
    private const MAX_DEPTH = 4;
    private const CALL = '/\$\w+->(\w+)\(/';
    private const PAGE_API = '/Page::api\((.*?)\)\s*\);/s';
    private const PAGE_NEW = '/new Page\((.*?)\n\s*\)\s*\);/s';
    private const ACTION = "/action:\s*'([^']+)'/";
    private const METHODS = '/requestMethods:\s*\[(.*?)\]/s';
    private const MATCH_ARM = "/'([a-z0-9_.\-]+)'\s*=>\s*[$]\w+->(\w+)\(/i";
    private const CASE_ARM = "/case\s*'([a-z0-9_.\-]+)':(.*?)(?=\n\s*case |\n\s*default)/si";

    /** Extract method bodies by brace matching, as in the original scanner. */
    private static function methodBodies(array $sources): array
    {
        $bodies = [];
        foreach ($sources as $path => $src) {
            preg_match_all('/function\s+(\w+)\s*\(/', $src, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $start = strpos($src, '{', $match[0][1] + strlen($match[0][0]));
                if ($start === false) {
                    continue;
                }

                $depth = 0;
                $length = strlen($src);
                for ($i = $start; $i < $length; $i++) {
                    if ($src[$i] === '{') {
                        $depth++;
                    } elseif ($src[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $bodies[$path][$match[1][0]] = substr($src, $start, $i - $start);
            }
        }

        return $bodies;
    }

    private static function verifies(array $bodies, string $path, string $method, int $depth = 0, array &$seen = []): bool
    {
        if ($depth > self::MAX_DEPTH || isset($seen[$path][$method])) {
            return false;
        }
        $seen[$path][$method] = true;
        $body = $bodies[$path][$method] ?? null;

        if ($body === null) {
            // A collaborator's method: fall back to the name across the tree.
            foreach ($bodies as $methods) {
                if (isset($methods[$method]) && str_contains($methods[$method], 'verifyCsrf')) {
                    return true;
                }
            }

            return false;
        }
        if (str_contains($body, 'verifyCsrf')) {
            return true;
        }

        preg_match_all(self::CALL, $body, $calls);
        foreach (array_unique($calls[1]) as $callee) {
            if (self::verifies($bodies, $path, $callee, $depth + 1, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** Map actions to handler methods and inline case bodies. */
    private static function dispatchMap(array $sources): array
    {
        $handlers = $inline = [];
        foreach ($sources as $path => $src) {
            preg_match_all(self::MATCH_ARM, $src, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $action, $method]) {
                $handlers[$action][] = [$path, $method];
            }
            preg_match_all(self::CASE_ARM, $src, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $action, $block]) {
                $inline[$action][] = $block;
                preg_match_all(self::CALL, $block, $calls);
                foreach ($calls[1] as $method) {
                    $handlers[$action][] = [$path, $method];
                }
            }
        }

        return [$handlers, $inline];
    }

    public static function status(array $row): string
    {
        if ($row['covered']) {
            return 'ok';
        }

        return $row['handlers'] === null ? 'PLACEHOLDER' : 'MISSING';
    }

    /** @param list<string> $directories */
    public static function scan(string $root, array $directories = ['src', 'modules']): array
    {
        $sources = [];
        foreach ($directories as $directory) {
            $directory = $root.'/'.$directory;
            if (!is_dir($directory)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
            ));
            foreach ($files as $file) {
                $path = $file->getPathname();
                // Python glob ignores hidden files and directories by default.
                if (!$file->isFile() || $file->getExtension() !== 'php' || preg_match('~(^|/)\.~', substr($path, strlen($root) + 1))) {
                    continue;
                }
                $src = file_get_contents($path);
                if ($src === false) {
                    throw new RuntimeException("Cannot read source: $path");
                }
                $sources[$path] = $src;
            }
        }
        if ($sources === []) {
            throw new RuntimeException('No sources found in: '.implode(', ', $directories));
        }
        ksort($sources, SORT_STRING);

        $bodies = self::methodBodies($sources);
        [$handlers, $inline] = self::dispatchMap($sources);
        $rows = [];
        foreach ($sources as $src) {
            preg_match_all(self::PAGE_API, $src, $apiPages);
            preg_match_all(self::PAGE_NEW, $src, $newPages);
            foreach (array_merge($apiPages[1], $newPages[1]) as $args) {
                if (!preg_match(self::ACTION, $args, $action)) {
                    continue;
                }
                preg_match(self::METHODS, $args, $declared);
                preg_match_all("/'([A-Z]+)'/", $declared[1] ?? '', $methods);
                $mutating = array_values(array_intersect(array_unique($methods[1]), self::MUTATING));
                sort($mutating, SORT_STRING);
                if ($mutating === []) {
                    continue;
                }

                $action = $action[1];
                $targets = $handlers[$action] ?? [];
                $covered = false;
                foreach ($inline[$action] ?? [] as $block) {
                    if (str_contains($block, 'verifyCsrf')) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    foreach ($targets as [$path, $method]) {
                        if (self::verifies($bodies, $path, $method) || self::verifies($bodies, $path, 'callApi')) {
                            $covered = true;
                            break;
                        }
                    }
                }
                $rows[$action] = [
                    'methods' => implode(',', $mutating),
                    'handlers' => implode(',', array_unique(array_column($targets, 1))) ?: null,
                    'covered' => $covered,
                ];
            }
        }

        return $rows;
    }
}

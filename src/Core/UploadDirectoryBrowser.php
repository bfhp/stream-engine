<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use FilesystemIterator;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Read-only, directory-at-a-time view of the local uploads storage.
 *
 * Paths in the public contract are always relative to the configured uploads
 * root. Symlinks are deliberately omitted: following them would make the
 * configured root cease to be a security boundary.
 */
final readonly class UploadDirectoryBrowser
{
    public function __construct(private string $basePath)
    {
    }

    /**
     * @return array{path: string, files: list<array<string, mixed>>, folderChain: list<array<string, mixed>>}|null
     */
    public function browse(string $path): ?array
    {
        $path = $this->normalizePath($path);
        $root = realpath($this->basePath);

        if ($root === false) {
            return null;
        }

        $directory = realpath($path === '' ? $root : $root.'/'.$path);
        if ($directory === false || ! is_dir($directory) || ! $this->isInsideRoot($directory, $root)) {
            return null;
        }

        $files = [];
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return null;
        }

        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! mb_check_encoding($entry->getFilename(), 'UTF-8')) {
                continue;
            }

            $entryPath = ltrim($path.'/'.$entry->getFilename(), '/');
            $isDirectory = $entry->isDir();
            if (! $isDirectory && ! $entry->isFile()) {
                continue;
            }
            $modifiedAt = $entry->getMTime();
            $file = [
                'id' => $entryPath,
                'path' => $entryPath,
                'name' => $entry->getFilename(),
                'isDir' => $isDirectory,
                'modifiedAt' => $modifiedAt > 0 ? $modifiedAt : null,
            ];

            if ($isDirectory) {
                $file['childrenCount'] = $this->countVisibleChildren($entry->getPathname());
            } else {
                $size = $entry->getSize();
                $file['size'] = $size >= 0 ? $size : 0;
                $file['extension'] = pathinfo($entry->getFilename(), PATHINFO_EXTENSION);
            }

            $files[] = $file;
        }

        usort($files, static function (array $left, array $right): int {
            $directoryOrder = (int) $right['isDir'] <=> (int) $left['isDir'];

            return $directoryOrder !== 0
                ? $directoryOrder
                : strnatcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $this->payload($path, $files, explode('/', $path));
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid uploads path');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid uploads path');
            }
        }

        return implode('/', $segments);
    }

    private function isInsideRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function countVisibleChildren(string $directory): int
    {
        $count = 0;

        try {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (! $entry->isLink() && mb_check_encoding($entry->getFilename(), 'UTF-8')) {
                    $count++;
                }
            }
        } catch (UnexpectedValueException) {
            return 0;
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<string> $segments
     * @return array{path: string, files: list<array<string, mixed>>, folderChain: list<array<string, mixed>>}
     */
    private function payload(string $path, array $files, array $segments): array
    {
        $folderChain = [[
            'id' => '__uploads_root__',
            'path' => '',
            'name' => 'uploads',
            'isDir' => true,
        ]];

        if ($path !== '') {
            $currentPath = '';
            foreach ($segments as $segment) {
                $currentPath = ltrim($currentPath.'/'.$segment, '/');
                $folderChain[] = [
                    'id' => $currentPath,
                    'path' => $currentPath,
                    'name' => $segment,
                    'isDir' => true,
                ];
            }
        }

        return compact('path', 'files', 'folderChain');
    }
}

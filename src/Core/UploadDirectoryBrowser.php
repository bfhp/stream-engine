<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use FilesystemIterator;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Filesystem operations backing the administrator file browser.
 *
 * Paths in the public contract are always relative to the configured uploads
 * root. Symlinks are deliberately omitted: following them would make the
 * configured root cease to be a security boundary.
 */
final readonly class UploadDirectoryBrowser
{
    public const string INVALID_PATH = 'admin.error.invalid_uploads_path';
    public const string INVALID_DIRECTORY_NAME = 'admin.error.invalid_directory_name';
    public const string DIRECTORY_EXISTS = 'admin.error.directory_exists';
    public const string DIRECTORY_CREATE_FAILED = 'admin.error.directory_create_failed';
    public const string INVALID_ENTRY_NAME = 'admin.error.invalid_file_browser_name';
    public const string ENTRY_NOT_FOUND = 'admin.error.file_browser_entry_not_found';
    public const string INVALID_MOVE = 'admin.error.invalid_file_browser_move';
    public const string MOVE_FAILED = 'admin.error.file_browser_move_failed';

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

    /**
     * @return array{path: string, files: list<array<string, mixed>>, folderChain: list<array<string, mixed>>}|null
     */
    public function createDirectory(string $path, string $name): ?array
    {
        $path = $this->normalizePath($path);
        $name = $this->normalizeDirectoryName($name, $path);
        $root = realpath($this->basePath);

        if ($root === false) {
            return null;
        }

        $directory = realpath($path === '' ? $root : $root.'/'.$path);
        if ($directory === false || ! is_dir($directory) || ! $this->isInsideRoot($directory, $root)) {
            return null;
        }

        $target = $directory.'/'.$name;
        if (file_exists($target) || is_link($target)) {
            throw new InvalidArgumentException(self::DIRECTORY_EXISTS);
        }
        if (! @mkdir($target, 0755)) {
            throw new InvalidArgumentException(self::DIRECTORY_CREATE_FAILED);
        }

        return $this->browse($path);
    }

    /**
     * @return array{
     *     payload: array{path: string, files: list<array<string, mixed>>, folderChain: list<array<string, mixed>>},
     *     from: string,
     *     to: string,
     *     isDir: bool
     * }|null
     */
    public function relocate(string $sourcePath, string $destinationPath, string $name): ?array
    {
        $sourcePath = $this->normalizePath($sourcePath);
        $destinationPath = $this->normalizePath($destinationPath);
        $name = $this->normalizeEntryName($name, $destinationPath);
        if ($sourcePath === '') {
            throw new InvalidArgumentException(self::INVALID_MOVE);
        }

        $root = realpath($this->basePath);
        if ($root === false) {
            return null;
        }

        $sourceCandidate = $root.'/'.$sourcePath;
        $source = realpath($sourceCandidate);
        $destination = realpath($destinationPath === '' ? $root : $root.'/'.$destinationPath);
        if ($source === false || is_link($sourceCandidate) || ! $this->isInsideRoot($source, $root)) {
            throw new InvalidArgumentException(self::ENTRY_NOT_FOUND);
        }
        if ($destination === false || ! is_dir($destination) || ! $this->isInsideRoot($destination, $root)) {
            return null;
        }

        $isDirectory = is_dir($source);
        if (! $isDirectory && ! is_file($source)) {
            throw new InvalidArgumentException(self::ENTRY_NOT_FOUND);
        }
        if ($isDirectory && $this->isInsideRoot($destination, $source)) {
            throw new InvalidArgumentException(self::INVALID_MOVE);
        }

        $target = $destination.'/'.$name;
        $targetPath = ltrim($destinationPath.'/'.$name, '/');
        if ($target === $source) {
            $payload = $this->browse($this->parentPath($sourcePath));

            return $payload === null ? null : [
                'payload' => $payload,
                'from' => $sourcePath,
                'to' => $targetPath,
                'isDir' => $isDirectory,
            ];
        }
        if (file_exists($target) || is_link($target)) {
            throw new InvalidArgumentException(self::DIRECTORY_EXISTS);
        }
        if (! @rename($source, $target)) {
            throw new InvalidArgumentException(self::MOVE_FAILED);
        }

        $payload = $this->browse($this->parentPath($sourcePath));
        if ($payload === null) {
            @rename($target, $source);

            return null;
        }

        return [
            'payload' => $payload,
            'from' => $sourcePath,
            'to' => $targetPath,
            'isDir' => $isDirectory,
        ];
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw new InvalidArgumentException(self::INVALID_PATH);
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(self::INVALID_PATH);
            }
        }

        return implode('/', $segments);
    }

    private function normalizeDirectoryName(string $name, string $path): string
    {
        $name = trim($name);
        $relativePath = ltrim($path.'/'.$name, '/');
        if ($name === '' || $name === '.' || $name === '..' || ! mb_check_encoding($name, 'UTF-8')
            || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
            || strlen($name) > 255 || strlen($relativePath) > 255) {
            throw new InvalidArgumentException(self::INVALID_DIRECTORY_NAME);
        }

        return $name;
    }

    private function normalizeEntryName(string $name, string $path): string
    {
        $name = trim($name);
        $relativePath = ltrim($path.'/'.$name, '/');
        if ($name === '' || $name === '.' || $name === '..' || ! mb_check_encoding($name, 'UTF-8')
            || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
            || strlen($name) > 255 || strlen($relativePath) > 255) {
            throw new InvalidArgumentException(self::INVALID_ENTRY_NAME);
        }

        return $name;
    }

    private function parentPath(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? '' : $parent;
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

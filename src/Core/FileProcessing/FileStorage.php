<?php

declare(strict_types=1);

namespace StreamEngine\Core\FileProcessing;

use Random\RandomException;
use StreamEngine\Core\Exceptions\ValidationException;

class FileStorage
{
    private const array MIME_EXT_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    public function storeUserFile(int $userId, string $tmpFile, string $mime): array
    {
        $ext = $this->getExtension($mime);

        $name = bin2hex(random_bytes(16)).'.'.$ext;

        $dir = $this->basePath.'/'.$userId;

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir.'/'.$name;

        rename($tmpFile, $path);

        chmod($path, 0644);

        return [
            'path' => "$userId/$name",
            'size' => filesize($path),
        ];
    }

    /**
     * Store an administrator-supplied file in an existing directory while
     * keeping a human-readable name. The canonical extension follows the
     * detected (and possibly processed) MIME type, not the client filename.
     *
     * @return array{path:string, size:int}
     * @throws ValidationException
     */
    public function storeNamedFile(string $directory, string $tmpFile, string $mime, string $originalName): array
    {
        $directory = $this->normalizedDirectory($directory);
        $root = realpath($this->basePath);
        $expectedDirectory = $root === false || $directory === '' ? $root : $root.'/'.$directory;
        $targetDirectory = $root === false
            ? false
            : realpath($directory === '' ? $root : $root.'/'.$directory);

        if ($root === false || $targetDirectory === false || ! is_dir($targetDirectory)
            || $targetDirectory !== $expectedDirectory
            || ($targetDirectory !== $root
                && ! str_starts_with($targetDirectory, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
            throw new ValidationException('Invalid destination directory');
        }

        $name = $this->normalizedFilename($originalName, $this->getExtension($mime));
        $relativePath = ltrim($directory.'/'.$name, '/');
        if (strlen($name) > 255 || strlen($relativePath) > 255) {
            throw new ValidationException('Invalid file name');
        }
        $target = $targetDirectory.'/'.$name;
        $reservation = @fopen($target, 'x');
        if ($reservation === false) {
            throw new ValidationException('File already exists');
        }
        fclose($reservation);

        if (! @rename($tmpFile, $target)) {
            @unlink($target);
            throw new ValidationException('Could not store file');
        }

        chmod($target, 0644);
        $size = filesize($target);

        return [
            'path' => $relativePath,
            'size' => $size === false ? 0 : $size,
        ];
    }

    /**
     * Deletes one database-tracked file without allowing a stored path to
     * escape the uploads root. Missing files count as already deleted.
     */
    public function deleteStoredFile(string $relativePath): bool
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            return false;
        }

        $segments = explode('/', $relativePath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return false;
        }

        $root = realpath($this->basePath);
        if ($root === false) {
            return false;
        }

        $candidate = $root.'/'.$relativePath;
        if (! file_exists($candidate) && ! is_link($candidate)) {
            return true;
        }

        $parent = realpath(dirname($candidate));
        if ($parent === false
            || ($parent !== $root && ! str_starts_with($parent, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
            return false;
        }

        $path = $parent.'/'.basename($relativePath);
        if (! is_file($path) && ! is_link($path)) {
            return false;
        }

        if (! @unlink($path)) {
            return false;
        }

        if ($parent !== $root) {
            @rmdir($parent);
        }

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function getExtension(string $mime): string
    {
        if (! isset(self::MIME_EXT_MAP[$mime])) {
            throw new ValidationException("Unsupported mime: $mime");
        }

        return self::MIME_EXT_MAP[$mime];
    }

    /** @throws ValidationException */
    private function normalizedFilename(string $originalName, string $extension): string
    {
        $name = trim($originalName);
        if ($name === '' || $name === '.' || $name === '..' || ! mb_check_encoding($name, 'UTF-8')
            || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new ValidationException('Invalid file name');
        }

        $dot = strrpos($name, '.');
        $stem = rtrim($dot === false || $dot === 0 ? $name : substr($name, 0, $dot), " .");
        if ($stem === '' || $stem === '.' || $stem === '..') {
            throw new ValidationException('Invalid file name');
        }

        return $stem.'.'.$extension;
    }

    /** @throws ValidationException */
    private function normalizedDirectory(string $directory): string
    {
        if ($directory === '') {
            return '';
        }

        if (str_contains($directory, "\0") || str_contains($directory, '\\') || str_starts_with($directory, '/')) {
            throw new ValidationException('Invalid destination directory');
        }

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ValidationException('Invalid destination directory');
            }
        }

        return $directory;
    }
}

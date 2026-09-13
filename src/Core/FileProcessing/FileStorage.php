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
     * @throws ValidationException
     */
    private function getExtension(string $mime): string
    {
        if (! isset(self::MIME_EXT_MAP[$mime])) {
            throw new ValidationException("Unsupported mime: $mime");
        }

        return self::MIME_EXT_MAP[$mime];
    }
}

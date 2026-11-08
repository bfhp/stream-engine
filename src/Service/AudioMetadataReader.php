<?php

declare(strict_types=1);

namespace StreamEngine\Service;

/** Extracted only during upload; playback never reopens the media file. */
class AudioMetadataReader
{
    /** @return array{title: ?string, artist: ?string} */
    public function read(string $path, string $mime): array
    {
        $empty = ['title' => null, 'artist' => null];
        if (!str_starts_with($mime, 'audio/')) {
            return $empty;
        }

        try {
            $reader = new \getID3();
            $reader->encoding = 'UTF-8';
            $reader->option_save_attachments = false;
            $info = $reader->analyze($path);
            $reader->CopyTagsToComments($info);

            return [
                'title' => $this->text($info['comments']['title'] ?? []),
                'artist' => $this->text($info['comments']['artist'] ?? []),
            ];
        } catch (\Exception) {
            // Broken or unsupported tags must not prevent an otherwise valid upload.
            return $empty;
        }
    }

    private function text(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            $value = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '');
            if ($value !== '') {
                return mb_substr($value, 0, 1000);
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Service\AudioMetadataReader;

final class AudioMetadataReaderTest extends TestCase
{
    public function testReadsId3AndHandlesUntaggedAndBrokenFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audio-tags-');
        try {
            // ID3v2.4 UTF-8 text frames; no audio decoding is needed to read tags.
            $frames = '';
            foreach (['TIT2' => 'Песня', 'TPE1' => 'Исполнитель'] as $id => $text) {
                $payload = "\x03".$text;
                $frames .= $id.pack('N', strlen($payload))."\0\0".$payload;
            }
            $size = strlen($frames);
            file_put_contents($path, "ID3\x04\0\0".pack('C4', ($size >> 21) & 127, ($size >> 14) & 127, ($size >> 7) & 127, $size & 127).$frames.str_repeat("\xff\xfb\x90\x00".str_repeat("\0", 413), 4));
            $reader = new AudioMetadataReader();
            self::assertSame(['title' => 'Песня', 'artist' => 'Исполнитель'], $reader->read($path, 'audio/mpeg'));
            file_put_contents($path, 'broken or untagged');
            self::assertSame(['title' => null, 'artist' => null], $reader->read($path, 'audio/mpeg'));
            self::assertSame(['title' => null, 'artist' => null], $reader->read($path, 'image/jpeg'));
        } finally {
            unlink($path);
        }
    }
}

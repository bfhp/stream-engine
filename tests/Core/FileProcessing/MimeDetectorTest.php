<?php

declare(strict_types=1);

namespace Tests\Core\FileProcessing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Service\UploadService;

/**
 * Eight lines of production code, and the first thing
 * UploadService::uploadForUser() calls on an uploaded temp file. Its answer is
 * the only thing validateFile() checks the allow-list against, so this is the
 * whole of "what may be uploaded".
 *
 * The point of the fixtures below is that they are written by their *magic
 * bytes* and named to contradict them. A detector that trusted the extension
 * would pass every one of these tests except the last, which is the one that
 * matters.
 */
final class MimeDetectorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mime-detector-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @return array<string, string> name => magic bytes */
    private static function fixtures(): array
    {
        return [
            // SOI + APP0/JFIF + EOI.
            'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            // The 8-byte PNG signature is the whole of the identification.
            'png' => "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR",
            'gif' => "GIF89a\x01\x00\x01\x00\x00\x00\x00;",
            // RIFF container with a WEBP form type at offset 8.
            'webp' => "RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x00",
            // ID3v2.3 tag followed by an MPEG audio frame sync.
            'mp3' => "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x00",
            'pdf' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        ];
    }

    /**
     * Every type the uploader accepts is recognised from its content while
     * carrying the *wrong* extension, so nothing in the allow-list depends on
     * the filename a browser happened to send.
     */
    #[DataProvider('allowedTypeProvider')]
    public function testAnAllowedTypeIsRecognisedDespiteAMisleadingName(
        string $fixture,
        string $expected
    ): void {
        $path = $this->write('holiday.txt', self::fixtures()[$fixture]);

        $this->assertSame($expected, (new MimeDetector())->detect($path));
    }

    /** @return array<string, array{string, string}> */
    public static function allowedTypeProvider(): array
    {
        return [
            'jpeg' => ['jpeg', 'image/jpeg'],
            'png' => ['png', 'image/png'],
            'gif' => ['gif', 'image/gif'],
            'webp' => ['webp', 'image/webp'],
            'mp3' => ['mp3', 'audio/mpeg'],
            'pdf' => ['pdf', 'application/pdf'],
        ];
    }

    /**
     * The one that carries the security. A `.jpg` whose content is PHP has to
     * come back as something the allow-list rejects - if the detector went by
     * extension, this file would be accepted, stored under the uploads root and
     * then be one webserver misconfiguration away from executing.
     */
    public function testAPhpScriptNamedAsAnImageIsNotDetectedAsAnImage(): void
    {
        $path = $this->write('avatar.jpg', "<?php echo shell_exec(\$_GET['c']); ?>\n");

        $mime = (new MimeDetector())->detect($path);

        $this->assertSame('text/x-php', $mime);
        $this->assertNotSame('image/jpeg', $mime);
        $this->assertNotContains($mime, self::allowedMimeTypes());
    }

    /**
     * Same shape, no PHP tag - a plain text file wearing an image extension is
     * still not an image.
     */
    public function testPlainTextNamedAsAnImageIsRejectedToo(): void
    {
        $path = $this->write('avatar.png', str_repeat("just some words\n", 20));

        $this->assertNotContains((new MimeDetector())->detect($path), self::allowedMimeTypes());
    }

    /* ===============================
       The failure that used to be a 500
    =============================== */

    /**
     * finfo::file() warns and returns false for a path it cannot open, and the
     * method is declared `: string` - so this was a TypeError escaping
     * uploadForUser(), a 500 rather than a refused upload.
     */
    #[DataProvider('unreadableProvider')]
    public function testAnUnreadablePathIsReportedAsUnknownRatherThanFatal(string $which): void
    {
        $path = match ($which) {
            'missing' => $this->dir.'/never-written.jpg',
            'directory' => $this->dir,
        };

        $this->assertSame(MimeDetector::UNKNOWN, (new MimeDetector())->detect($path));
    }

    /** @return array<string, array{string}> */
    public static function unreadableProvider(): array
    {
        return [
            // The realistic one: a temp file already swept between the move and
            // the sniff.
            'a path that does not exist' => ['missing'],
            'a directory' => ['directory'],
        ];
    }

    /**
     * An empty file is readable, so it reaches finfo - which calls it
     * `application/x-empty`, not the unknown fallback. Either way it is off the
     * allow-list; asserted so the distinction between "could not read" and
     * "read, and it was nothing" stays visible.
     */
    public function testAnEmptyFileIsNotTheUnreadableFallback(): void
    {
        $path = $this->write('empty.jpg', '');

        $mime = (new MimeDetector())->detect($path);

        $this->assertNotContains($mime, self::allowedMimeTypes());
    }

    /**
     * The fallback has to stay off the allow-list, or "we could not read this"
     * would silently become "accepted".
     */
    public function testTheUnknownFallbackIsNotAnAcceptedUploadType(): void
    {
        $this->assertNotContains(MimeDetector::UNKNOWN, self::allowedMimeTypes());
    }

    /** @return list<string> UploadService::ALLOWED_MIME */
    private static function allowedMimeTypes(): array
    {
        /** @var list<string> $allowed */
        $allowed = (new ReflectionClass(UploadService::class))->getConstant('ALLOWED_MIME');

        // If the constant is ever renamed this reads as an empty list and every
        // assertNotContains above passes vacuously, so check it is really there.
        self::assertNotEmpty($allowed);

        return $allowed;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\UploadDirectoryBrowser;

final class UploadDirectoryBrowserTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/uploads-browser-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root.'/2/nested', 0700, true));
        self::assertNotFalse(file_put_contents($this->root.'/2/photo one.webp', 'image'));
        self::assertNotFalse(file_put_contents($this->root.'/10.txt', 'ten'));
        self::assertNotFalse(file_put_contents($this->root.'/2.txt', 'two'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testBrowsesOneDirectoryAtATimeWithStableMetadata(): void
    {
        $browser = new UploadDirectoryBrowser($this->root);

        $root = $browser->browse('');
        self::assertNotNull($root);
        self::assertSame('', $root['path']);
        self::assertSame(['2', '2.txt', '10.txt'], array_column($root['files'], 'name'));
        self::assertTrue($root['files'][0]['isDir']);
        self::assertSame(2, $root['files'][0]['childrenCount']);
        self::assertSame('__uploads_root__', $root['folderChain'][0]['id']);

        $child = $browser->browse('2');
        self::assertNotNull($child);
        self::assertSame('2', $child['path']);
        self::assertSame(['uploads', '2'], array_column($child['folderChain'], 'name'));
        self::assertSame(['nested', 'photo one.webp'], array_column($child['files'], 'name'));
        self::assertSame('webp', $child['files'][1]['extension']);
        self::assertSame(5, $child['files'][1]['size']);
    }

    #[DataProvider('invalidPathProvider')]
    public function testRejectsPathsThatCanEscapeOrChangeMeaning(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UploadDirectoryBrowser($this->root))->browse($path);
    }

    /** @return array<string, array{string}> */
    public static function invalidPathProvider(): array
    {
        return [
            'parent' => ['../outside'],
            'embedded parent' => ['2/../outside'],
            'current directory' => ['2/./nested'],
            'absolute' => ['/etc'],
            'backslash' => ['2\\nested'],
            'empty segment' => ['2//nested'],
            'nul' => ["2\0nested"],
        ];
    }

    public function testMissingRootAndMissingChildAreNotFound(): void
    {
        $browser = new UploadDirectoryBrowser($this->root.'/missing');

        self::assertNull($browser->browse(''));
        self::assertNull($browser->browse('child'));
    }

    public function testSymlinksAreNotExposed(): void
    {
        $outside = sys_get_temp_dir().'/uploads-outside-'.bin2hex(random_bytes(8));
        self::assertNotFalse(file_put_contents($outside, 'secret'));
        self::assertTrue(symlink($outside, $this->root.'/outside-link'));

        try {
            $payload = (new UploadDirectoryBrowser($this->root))->browse('');
            self::assertNotNull($payload);
            self::assertNotContains('outside-link', array_column($payload['files'], 'name'));
        } finally {
            unlink($outside);
        }
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}

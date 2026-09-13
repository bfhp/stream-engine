<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\ImageProcessor;

final class ImageProcessorTest extends TestCase
{
    private string $tmpDir;

    private array $filesToDelete = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/stream-engine-image-processor-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function testIsImageRecognizesImageMimeTypes(): void
    {
        $processor = new ImageProcessor($this->tmpDir);

        $this->assertTrue($processor->isImage('image/png'));
        $this->assertTrue($processor->isImage('image/webp'));
        $this->assertFalse($processor->isImage('audio/mpeg'));
        $this->assertFalse($processor->isImage('application/json'));
    }

    public function testProcessRejectsInvalidImage(): void
    {
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makeTextFile('not-an-image');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid image');

        $processor->process($file, 'image/png');
    }

    public function testProcessConvertsImageAndReturnsWebpFile(): void
    {
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makePngImage(40, 20);

        [$output, $mime] = $processor->process($file, 'image/png');
        $this->filesToDelete[] = $output;

        $imageInfo = getimagesize($output);

        $this->assertSame('image/webp', $mime);
        $this->assertFileExists($output);
        $this->assertNotFalse($imageInfo);
        $this->assertSame(40, $imageInfo[0]);
        $this->assertSame(20, $imageInfo[1]);
        $this->assertSame('image/webp', $imageInfo['mime']);
    }

    public function testProcessConvertsPaletteImageToWebp(): void
    {
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makePalettePngImage(40, 20);

        [$output, $mime] = $processor->process($file, 'image/png');
        $this->filesToDelete[] = $output;

        $imageInfo = getimagesize($output);

        $this->assertSame('image/webp', $mime);
        $this->assertNotFalse($imageInfo);
        $this->assertSame(40, $imageInfo[0]);
        $this->assertSame(20, $imageInfo[1]);
        $this->assertSame('image/webp', $imageInfo['mime']);
    }

    public function testProcessNeverConvertsGifToWebp(): void
    {
        // Regression guard: GD's imagecreatefromstring() only ever reads an
        // animated GIF's first frame, so running one through the
        // webp/palette pipeline would silently destroy the animation. GIFs
        // must be passed through completely untouched instead.
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makeGifImage(40, 20);
        $originalBytes = file_get_contents($file);

        [$output, $mime] = $processor->process($file, 'image/gif');

        $this->assertSame('image/gif', $mime);
        $this->assertSame($file, $output);
        $this->assertSame($originalBytes, file_get_contents($output));
    }

    public function testProcessAvatarCropsAndResizesToSquareAvatar(): void
    {
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makePngImage(640, 320);

        [$output, $mime] = $processor->processAvatar($file, 'image/png');
        $this->filesToDelete[] = $output;

        $imageInfo = getimagesize($output);

        $this->assertSame('image/webp', $mime);
        $this->assertFileExists($output);
        $this->assertNotFalse($imageInfo);
        $this->assertSame(512, $imageInfo[0]);
        $this->assertSame(512, $imageInfo[1]);
        $this->assertSame('image/webp', $imageInfo['mime']);
    }

    public function testProcessAvatarCentersCropHorizontallyForLandscapeImage(): void
    {
        // 300x100 landscape image, three vertical stripes: red | green | blue.
        // side = min(300,100) = 100, srcX = floor((300-100)/2) = 100, srcY = 0,
        // so the crop region [100,200) x [0,100) is exactly the green stripe.
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makeStripedPngImage(300, 100, true);

        [$output, ] = $processor->processAvatar($file, 'image/png');
        $this->filesToDelete[] = $output;

        $this->assertCenterPixelColor($output, 0, 200, 0);
    }

    public function testProcessAvatarCentersCropVerticallyForPortraitImage(): void
    {
        // 100x300 portrait image, three horizontal stripes: red | green | blue.
        // side = min(100,300) = 100, srcX = 0, srcY = floor((300-100)/2) = 100,
        // so the crop region [0,100) x [100,200) is exactly the green stripe.
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makeStripedPngImage(100, 300, false);

        [$output, ] = $processor->processAvatar($file, 'image/png');
        $this->filesToDelete[] = $output;

        $this->assertCenterPixelColor($output, 0, 200, 0);
    }

    public function testProcessAvatarNeverConvertsGifToWebp(): void
    {
        // Regression guard for the actual bug report: uploading a GIF
        // avatar was silently turned into a static WebP, because
        // processAvatar() (unlike process()) had no GIF short-circuit at
        // all - it always ran the file through imagecreatefromstring()
        // (first frame only) + imagewebp(). Same fix and same contract as
        // testProcessNeverConvertsGifToWebp() above, just for the avatar
        // pipeline: pass GIFs through completely untouched, uncropped.
        $processor = new ImageProcessor($this->tmpDir);
        $file = $this->makeGifImage(40, 20);
        $originalBytes = file_get_contents($file);

        [$output, $mime] = $processor->processAvatar($file, 'image/gif');

        $this->assertSame('image/gif', $mime);
        $this->assertSame($file, $output);
        $this->assertSame($originalBytes, file_get_contents($output));
    }

    private function assertCenterPixelColor(string $webpFile, int $red, int $green, int $blue): void
    {
        $image = imagecreatefromwebp($webpFile);
        $this->assertNotFalse($image);

        $center = imagecolorsforindex($image, imagecolorat($image, 256, 256));

        // Lossy webp compression (quality 85) can shift channel values slightly.
        $this->assertEqualsWithDelta($red, $center['red'], 20);
        $this->assertEqualsWithDelta($green, $center['green'], 20);
        $this->assertEqualsWithDelta($blue, $center['blue'], 20);
    }

    /**
     * Creates an image split into three equal same-size stripes (red, green, blue)
     * along the given axis, to make crop centering verifiable via pixel sampling.
     */
    private function makeStripedPngImage(int $width, int $height, bool $vertical): string
    {
        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 200, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);

        if ($vertical) {
            $third = intdiv($width, 3);
            imagefilledrectangle($image, 0, 0, $third - 1, $height, $red);
            imagefilledrectangle($image, $third, 0, 2 * $third - 1, $height, $green);
            imagefilledrectangle($image, 2 * $third, 0, $width, $height, $blue);
        } else {
            $third = intdiv($height, 3);
            imagefilledrectangle($image, 0, 0, $width, $third - 1, $red);
            imagefilledrectangle($image, 0, $third, $width, 2 * $third - 1, $green);
            imagefilledrectangle($image, 0, 2 * $third, $width, $height, $blue);
        }

        $file = tempnam(sys_get_temp_dir(), 'img-stripe-');
        imagepng($image, $file);

        $this->filesToDelete[] = $file;

        return $file;
    }

    private function makeTextFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'img-text-');
        file_put_contents($file, $contents);
        $this->filesToDelete[] = $file;

        return $file;
    }

    private function makePngImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 20, 120, 220);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $file = tempnam(sys_get_temp_dir(), 'img-src-');
        imagepng($image, $file);

        $this->filesToDelete[] = $file;

        return $file;
    }

    private function makeGifImage(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        $background = imagecolorallocate($image, 20, 120, 220);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $file = tempnam(sys_get_temp_dir(), 'img-gif-');
        imagegif($image, $file);

        $this->filesToDelete[] = $file;

        return $file;
    }

    private function makePalettePngImage(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        $background = imagecolorallocate($image, 20, 120, 220);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $file = tempnam(sys_get_temp_dir(), 'img-palette-');
        imagepng($image, $file);

        $this->filesToDelete[] = $file;

        return $file;
    }
}

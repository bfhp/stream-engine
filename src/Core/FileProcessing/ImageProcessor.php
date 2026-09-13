<?php

declare(strict_types=1);

namespace StreamEngine\Core\FileProcessing;

use GdImage;
use StreamEngine\Core\Exceptions\ValidationException;

readonly class ImageProcessor
{
    private const int AVATAR_SIZE = 512;

    public function __construct(private string $tmpPath)
    {
    }

    public function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /**
     * @throws ValidationException
     */
    public function process(string $tmpFile, string $mime): array
    {
        // GIF is never re-encoded, to WebP or anything else. GD only ever
        // reads the first frame of an animated GIF (imagecreatefromstring
        // has no concept of multiple frames), so running one through this
        // pipeline would silently flatten an animation into a single still
        // frame. Passing the original bytes through untouched is the only
        // way to keep the animation intact.
        if ($mime === 'image/gif') {
            return [$tmpFile, $mime];
        }

        $image = @imagecreatefromstring(file_get_contents($tmpFile));

        if (! $image) {
            throw new ValidationException('Invalid image');
        }

        $output = tempnam($this->tmpPath, 'img');

        if (function_exists('imagewebp')) {
            $image = $this->trueColorImage($image);

            if (! imagewebp($image, $output, 85)) {
                throw new ValidationException('Image conversion failed');
            }

            $mime = 'image/webp';
        } elseif (function_exists('imagepng')) {
            imagepng($image, $output, 8);
            $mime = 'image/png';
        } else {
            throw new ValidationException('Image conversion is not supported on this server');
        }

        return [$output, $mime];
    }

    private function trueColorImage(GdImage $image): GdImage
    {
        if (imageistruecolor($image)) {
            return $image;
        }

        if (function_exists('imagepalettetotruecolor') && imagepalettetotruecolor($image)) {
            imagealphablending($image, true);
            imagesavealpha($image, true);

            return $image;
        }

        $trueColor = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagealphablending($trueColor, false);
        imagesavealpha($trueColor, true);
        imagecopy($trueColor, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $trueColor;
    }

    /**
     * @throws ValidationException
     */
    public function processAvatar(string $tmpFile, string $mime): array
    {
        // Same rule as process(): GD's imagecreatefromstring() below would
        // only ever read a GIF's first frame, so cropping/re-encoding one
        // through this pipeline would silently destroy the animation.
        // Passing it through untouched means an animated GIF avatar keeps
        // its original (uncropped) dimensions instead of being forced into
        // a 512x512 square - a worthwhile trade-off to keep it animated.
        if ($mime === 'image/gif') {
            return [$tmpFile, $mime];
        }

        $source = @imagecreatefromstring(file_get_contents($tmpFile));

        if (! $source) {
            throw new ValidationException('Invalid image');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = (int) floor(($width - $side) / 2);
        $srcY = (int) floor(($height - $side) / 2);

        $avatar = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);

        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        $transparent = imagecolorallocatealpha($avatar, 0, 0, 0, 127);
        imagefilledrectangle($avatar, 0, 0, self::AVATAR_SIZE, self::AVATAR_SIZE, $transparent);

        imagecopyresampled(
            $avatar,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::AVATAR_SIZE,
            self::AVATAR_SIZE,
            $side,
            $side
        );

        $output = tempnam($this->tmpPath, 'avatar');

        if (function_exists('imagewebp')) {
            imagewebp($avatar, $output, 85);
            $mime = 'image/webp';
        } elseif (function_exists('imagepng')) {
            imagepng($avatar, $output, 8);
            $mime = 'image/png';
        } else {
            throw new ValidationException('Image conversion is not supported on this server');
        }

        return [$output, $mime];
    }
}

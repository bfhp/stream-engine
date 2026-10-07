<?php

declare(strict_types=1);

namespace StreamEngine\Core\FileProcessing;

use GdImage;
use StreamEngine\Core\Exceptions\ValidationException;

readonly class ImageProcessor
{
    private const int AVATAR_SIZE = 512;
    private const array SITE_ICON_RASTER_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(private string $tmpPath)
    {
    }

    public function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public function supportsSvg(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        if (! class_exists(\Imagick::class)) {
            return $supported = false;
        }

        try {
            if (\Imagick::queryFormats('SVG') === []) {
                return $supported = false;
            }
            $probe = new \Imagick();
            $probe->readImageBlob('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>');
            $probe->setImageFormat('png');
            $supported = $probe->getImageBlob() !== '';
            $probe->clear();

            return $supported;
        } catch (\Throwable) {
            return $supported = false;
        }
    }

    /**
     * @return array{ico:string, png:string, svg:?string}
     * @throws ValidationException
     */
    public function processSiteIcon(string $file, string $mime): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new ValidationException('Invalid image');
        }

        $svg = null;
        if ($mime === 'image/svg+xml') {
            if (! $this->supportsSvg()) {
                throw new ValidationException('SVG conversion is not supported on this server');
            }
            $this->validateSvg($source);
            $raster = $this->rasterizeSvg($source);
            $svg = $source;
        } elseif (in_array($mime, self::SITE_ICON_RASTER_MIME, true)) {
            $raster = @imagecreatefromstring($source);
            if (! $raster) {
                throw new ValidationException('Invalid image');
            }
            if ($this->supportsSvg()) {
                $svg = $this->rasterSvg($source, $mime);
            }
        } else {
            throw new ValidationException('Unsupported image type');
        }

        return [
            'ico' => $this->ico($raster),
            'png' => $this->squarePng($raster, 180),
            'svg' => $svg,
        ];
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

    /** @throws ValidationException */
    private function rasterizeSvg(string $svg): GdImage
    {
        try {
            $image = new \Imagick();
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->setResolution(192, 192);
            $image->readImageBlob($svg);
            $image->setIteratorIndex(0);
            $image->setImageFormat('png32');
            $image->thumbnailImage(512, 512, true, true);
            $png = $image->getImageBlob();
            $image->clear();
        } catch (\Throwable) {
            throw new ValidationException('Invalid SVG image');
        }

        $raster = @imagecreatefromstring($png);
        if (! $raster) {
            throw new ValidationException('Invalid SVG image');
        }

        return $raster;
    }

    /** @throws ValidationException */
    private function validateSvg(string $svg): void
    {
        if (stripos($svg, '<!DOCTYPE') !== false) {
            throw new ValidationException('Invalid SVG image');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement?->localName !== 'svg') {
            throw new ValidationException('Invalid SVG image');
        }

        $forbiddenElements = ['script', 'foreignObject', 'iframe', 'object', 'embed'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array($element->localName, $forbiddenElements, true)) {
                throw new ValidationException('Unsafe SVG image');
            }
            if ($element->localName === 'style'
                && preg_match('/(?:javascript\s*:|@import|url\s*\(\s*[\'\"]?(?!#))/i', $element->textContent) === 1) {
                throw new ValidationException('Unsafe SVG image');
            }
            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on')
                    || preg_match('/(?:javascript\s*:|@import)/i', $value) === 1
                    || (($name === 'href' || $name === 'src')
                        && $value !== '' && ! str_starts_with($value, '#')
                        && preg_match('~\Adata:image/(?:png|jpeg|gif|webp);base64,~i', $value) !== 1)
                    || preg_match('/url\s*\(\s*[\'\"]?(?!#)/i', $value) === 1) {
                    throw new ValidationException('Unsafe SVG image');
                }
            }
        }
    }

    private function rasterSvg(string $source, string $mime): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'.
            '<image width="512" height="512" preserveAspectRatio="xMidYMid meet" href="data:'.
            $mime.';base64,'.base64_encode($source).'"/></svg>';
    }

    /** @throws ValidationException */
    private function squarePng(GdImage $source, int $size): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled(
            $canvas,
            $source,
            (int) floor(($size - $targetWidth) / 2),
            (int) floor(($size - $targetHeight) / 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        ob_start();
        $written = imagepng($canvas, null, 8);
        $png = ob_get_clean();
        if (! $written || ! is_string($png)) {
            throw new ValidationException('Image conversion failed');
        }

        return $png;
    }

    /** @throws ValidationException */
    private function ico(GdImage $source): string
    {
        $images = [];
        foreach ([16, 32, 48] as $size) {
            $images[$size] = $this->squarePng($source, $size);
        }

        $header = pack('vvv', 0, 1, count($images));
        $entries = '';
        $data = '';
        $offset = 6 + (16 * count($images));
        foreach ($images as $size => $png) {
            $length = strlen($png);
            $entries .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $length, $offset);
            $data .= $png;
            $offset += $length;
        }

        return $header.$entries.$data;
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

<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use JsonException;
use RuntimeException;

final class ReleaseVersion
{
    public static function fromPackageJson(string $file): string
    {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read package metadata "%s".', $file));
        }

        try {
            $package = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Package metadata "%s" is not valid JSON.', $file), 0, $e);
        }

        $version = is_array($package) ? ($package['version'] ?? null) : null;
        if (! is_string($version) || trim($version) === '') {
            throw new RuntimeException(sprintf('Package metadata "%s" has no release version.', $file));
        }

        return trim($version);
    }
}

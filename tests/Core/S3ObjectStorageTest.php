<?php

declare(strict_types=1);

namespace Tests\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StreamEngine\Core\Config;
use StreamEngine\Core\ObjectStorage\S3ObjectStorage;

final class S3ObjectStorageTest extends TestCase
{
    private function makeStorage(array $overrides = []): S3ObjectStorage
    {
        return new S3ObjectStorage(new Config(array_merge([
            'OBJECT_STORAGE_ENDPOINT' => 'http://minio:9000',
            'OBJECT_STORAGE_BUCKET' => 'stream-engine',
            'OBJECT_STORAGE_REGION' => 'us-east-1',
            'OBJECT_STORAGE_ACCESS_KEY' => 'minio',
            'OBJECT_STORAGE_SECRET_KEY' => 'minio-secret',
        ], $overrides)));
    }

    public function testPublicUrlUsesConfiguredPublicBaseUrl(): void
    {
        $storage = $this->makeStorage([
            'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'https://cdn.example.test/content',
        ]);

        $this->assertSame(
            'https://cdn.example.test/content/library/covers/book%201.webp',
            $storage->publicUrl('library/covers/book 1.webp')
        );
    }

    public function testPublicUrlFallsBackToBucketEndpoint(): void
    {
        $storage = $this->makeStorage([
            'OBJECT_STORAGE_ENDPOINT' => 'http://localhost:9000/',
        ]);

        $this->assertSame(
            'http://localhost:9000/stream-engine/library/covers/cover.webp',
            $storage->publicUrl('library/covers/cover.webp')
        );
    }

    public function testPublicUrlRejectsUnsafeKeys(): void
    {
        $storage = $this->makeStorage();

        $this->expectException(InvalidArgumentException::class);

        $storage->publicUrl('../secret.webp');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'empty key' => ['', 'Object storage key is required'],
            'leading slash' => ['/library/covers/cover.webp', 'Object storage key must be a relative slash-separated path'],
            'backslash' => ['library\\covers\\cover.webp', 'Object storage key must be a relative slash-separated path'],
            'empty segment' => ['library//cover.webp', 'Object storage key contains an invalid path segment'],
            'dot segment' => ['library/./cover.webp', 'Object storage key contains an invalid path segment'],
            'control character' => ["library/cover\x01.webp", 'Object storage key contains control characters'],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function testPublicUrlRejectsInvalidKeys(string $key, string $expectedMessage): void
    {
        $storage = $this->makeStorage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $storage->publicUrl($key);
    }

    public function testPutRejectsInvalidMetadataNameBeforeSendingRequest(): void
    {
        $storage = $this->makeStorage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid object storage metadata name: -bad-name');

        $storage->put('library/covers/cover.webp', 'contents', metadata: ['-bad-name' => 'value']);
    }

    public function testHostReturnsHostAndPortFromEndpoint(): void
    {
        $storage = $this->makeStorage(['OBJECT_STORAGE_ENDPOINT' => 'http://minio:9000']);

        $host = (new ReflectionMethod(S3ObjectStorage::class, 'host'))->invoke($storage);

        $this->assertSame('minio:9000', $host);
    }

    public function testHostRejectsEndpointWithoutHost(): void
    {
        $storage = $this->makeStorage(['OBJECT_STORAGE_ENDPOINT' => '/just/a/path']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid S3 endpoint: /just/a/path');

        (new ReflectionMethod(S3ObjectStorage::class, 'host'))->invoke($storage);
    }
}

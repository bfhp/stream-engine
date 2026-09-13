<?php

declare(strict_types=1);

namespace StreamEngine\Core\ObjectStorage;

use InvalidArgumentException;
use RuntimeException;
use StreamEngine\Core\Config;

final readonly class S3ObjectStorage
{
    private const string SERVICE = 's3';

    public function __construct(
        private Config $config,
    ) {
    }

    public function put(
        string $key,
        string $contents,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): string {
        $key = $this->normalizeKey($key);
        $headers = [
            'content-type' => $contentType,
        ];

        foreach ($metadata as $name => $value) {
            $headers['x-amz-meta-'.$this->normalizeMetadataName($name)] = trim((string) $value);
        }

        $this->request('PUT', $key, $contents, $headers);

        return $this->publicUrl($key);
    }

    public function putFile(
        string $key,
        string $filePath,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): string {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException('Object storage source file is not readable: '.$filePath);
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new RuntimeException('Failed to read object storage source file: '.$filePath);
        }

        return $this->put($key, $contents, $contentType, $metadata);
    }

    public function exists(string $key): bool
    {
        $key = $this->normalizeKey($key);
        $response = $this->request('HEAD', $key, '', [], [200, 404]);

        return $response['status'] === 200;
    }

    public function delete(string $key): void
    {
        $key = $this->normalizeKey($key);
        $this->request('DELETE', $key, '', [], [200, 204, 404]);
    }

    public function publicUrl(string $key): string
    {
        $key = $this->normalizeKey($key);
        $baseUrl = rtrim($this->config->objectStoragePublicBaseUrl() ?: $this->bucketEndpoint(), '/');

        return $baseUrl.'/'.$this->encodeKey($key);
    }

    /**
     * @param array<string, string> $headers
     * @param int[] $acceptedStatuses
     * @return array{status: int, body: string}
     */
    private function request(
        string $method,
        string $key,
        string $body = '',
        array $headers = [],
        array $acceptedStatuses = [200, 201, 204]
    ): array {
        $url = $this->objectUrl($key);
        $payloadHash = hash('sha256', $body);
        $now = gmdate('Ymd\THis\Z');

        $headers = $this->signedHeaders($method, $key, $payloadHash, $now, $headers);
        $curlHeaders = [];

        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name.': '.$value;
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Failed to initialize object storage request');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($method !== 'HEAD') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException('Object storage request failed: '.$error);
        }

        if (! in_array($status, $acceptedStatuses, true)) {
            throw new RuntimeException(sprintf(
                'Object storage request failed with HTTP %d: %s',
                $status,
                $responseBody
            ));
        }

        return [
            'status' => $status,
            'body' => (string) $responseBody,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $key, string $payloadHash, string $now, array $headers): array
    {
        $headers = array_change_key_case($headers);
        $headers['host'] = $this->host();
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $now;
        ksort($headers);

        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim((string) $value)."\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [
            $method,
            '/'.$this->config->objectStorageBucket().'/'.$this->encodeKey($key),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $date = substr($now, 0, 8);
        $scope = $date.'/'.$this->config->objectStorageRegion().'/'.self::SERVICE.'/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        $headers['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->config->objectStorageAccessKey(),
            $scope,
            $signedHeaders,
            $signature
        );

        return $headers;
    }

    private function signingKey(string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$this->config->objectStorageSecretKey(), true);
        $dateRegionKey = hash_hmac('sha256', $this->config->objectStorageRegion(), $dateKey, true);
        $dateRegionServiceKey = hash_hmac('sha256', self::SERVICE, $dateRegionKey, true);

        return hash_hmac('sha256', 'aws4_request', $dateRegionServiceKey, true);
    }

    private function objectUrl(string $key): string
    {
        return $this->bucketEndpoint().'/'.$this->encodeKey($key);
    }

    private function bucketEndpoint(): string
    {
        return rtrim($this->config->objectStorageEndpoint(), '/').'/'.$this->config->objectStorageBucket();
    }

    private function host(): string
    {
        $endpoint = $this->config->objectStorageEndpoint();
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Invalid S3 endpoint: '.$endpoint);
        }

        $host = $parts['host'];

        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        return $host;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('Object storage key is required');
        }

        if (str_starts_with($key, '/') || str_contains($key, '\\')) {
            throw new InvalidArgumentException('Object storage key must be a relative slash-separated path');
        }

        $segments = explode('/', $key);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Object storage key contains an invalid path segment');
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new InvalidArgumentException('Object storage key contains control characters');
            }
        }

        return $key;
    }

    private function normalizeMetadataName(string $name): string
    {
        $name = strtolower(trim($name));

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException('Invalid object storage metadata name: '.$name);
        }

        return $name;
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}

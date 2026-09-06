<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class SdkObjectStorageAdapter implements ObjectStorageAdapterInterface
{
    public function __construct(private readonly object $client, private readonly string $bucket = '')
    {
    }

    public function put(string $key, string $localPath): string
    {
        if (!is_file($localPath)) {
            throw new MarketException('SDK object source file does not exist.');
        }
        if (!method_exists($this->client, 'putObject')) {
            throw new MarketException('SDK client does not support putObject.');
        }
        $result = $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($key, '/'),
            'SourceFile' => $localPath,
        ]);

        return is_array($result) && isset($result['ObjectURL']) ? (string) $result['ObjectURL'] : ltrim($key, '/');
    }

    public function get(string $key, string $targetPath): string
    {
        if (!method_exists($this->client, 'getObject')) {
            throw new MarketException('SDK client does not support getObject.');
        }
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }
        $result = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($key, '/'),
            'SaveAs' => $targetPath,
        ]);
        if (!is_file($targetPath) && is_array($result) && isset($result['Body'])) {
            file_put_contents($targetPath, (string) $result['Body']);
        }
        if (!is_file($targetPath)) {
            throw new MarketException('SDK object could not be fetched.');
        }

        return $targetPath;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function presignUpload(string $key, int $ttlSeconds = 300, array $options = []): array
    {
        if (!method_exists($this->client, 'createPresignedUpload')) {
            throw new MarketException('SDK client does not support createPresignedUpload.');
        }
        $result = $this->client->createPresignedUpload([
            'Bucket' => $this->bucket,
            'Key' => ltrim($key, '/'),
            'TtlSeconds' => max(1, $ttlSeconds),
            'ContentType' => (string) ($options['content_type'] ?? 'application/octet-stream'),
            'MaxBytes' => (int) ($options['max_bytes'] ?? 0),
        ]);
        if (!is_array($result) || !isset($result['url'])) {
            throw new MarketException('SDK client returned an invalid presigned upload payload.');
        }

        return [
            'method' => (string) ($result['method'] ?? 'PUT'),
            'url' => (string) $result['url'],
            'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            'key' => ltrim($key, '/'),
            'expires_at' => (string) ($result['expires_at'] ?? gmdate('c', time() + max(1, $ttlSeconds))),
            'max_bytes' => (int) ($options['max_bytes'] ?? 0),
        ];
    }
}

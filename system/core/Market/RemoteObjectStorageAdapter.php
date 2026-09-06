<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class RemoteObjectStorageAdapter implements ObjectStorageAdapterInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $baseUri,
        private readonly array $headers = [],
    ) {
    }

    public function put(string $key, string $localPath): string
    {
        if (!is_file($localPath)) {
            throw new MarketException('Remote object source file does not exist.');
        }
        $uri = rtrim($this->baseUri, '/') . '/' . ltrim($key, '/');
        if (str_starts_with($uri, 'file://')) {
            $target = substr($uri, 7);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy($localPath, $target);

            return $uri;
        }

        throw new MarketException('Remote object storage upload requires a signed upload URL adapter.');
    }

    public function get(string $key, string $targetPath): string
    {
        $uri = rtrim($this->baseUri, '/') . '/' . ltrim($key, '/');
        $context = null;
        if ($this->headers !== []) {
            $lines = [];
            foreach ($this->headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $context = stream_context_create(['http' => ['header' => implode("\r\n", $lines)]]);
        }
        $contents = @file_get_contents($uri, false, $context);
        if ($contents === false) {
            throw new MarketException('Remote object could not be fetched.');
        }
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }
        file_put_contents($targetPath, $contents);

        return $targetPath;
    }

    public function signedUrl(string $key, int $ttlSeconds = 300): string
    {
        $uri = rtrim($this->baseUri, '/') . '/' . ltrim($key, '/');
        if (str_starts_with($uri, 'file://')) {
            return $uri;
        }
        $expires = time() + max(1, $ttlSeconds);
        $secret = $this->headers['X-Signature-Secret'] ?? '';
        $signature = hash_hmac('sha256', $key . '|' . $expires, $secret);
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri . $separator . 'expires=' . $expires . '&signature=' . rawurlencode($signature);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function presignUpload(string $key, int $ttlSeconds = 300, array $options = []): array
    {
        $uri = rtrim($this->baseUri, '/') . '/' . ltrim($key, '/');
        if (str_starts_with($uri, 'file://')) {
            return [
                'method' => 'PUT',
                'url' => $uri,
                'headers' => [],
                'key' => ltrim($key, '/'),
                'expires_at' => gmdate('c', time() + max(1, $ttlSeconds)),
                'max_bytes' => (int) ($options['max_bytes'] ?? 0),
            ];
        }

        $expires = time() + max(1, $ttlSeconds);
        $secret = $this->headers['X-Signature-Secret'] ?? '';
        $contentType = (string) ($options['content_type'] ?? 'application/octet-stream');
        $maxBytes = (int) ($options['max_bytes'] ?? 0);
        $signature = hash_hmac('sha256', 'PUT|' . $key . '|' . $expires . '|' . $contentType . '|' . $maxBytes, $secret);
        $separator = str_contains($uri, '?') ? '&' : '?';

        return [
            'method' => 'PUT',
            'url' => $uri . $separator . 'expires=' . $expires . '&signature=' . rawurlencode($signature),
            'headers' => ['Content-Type' => $contentType],
            'key' => ltrim($key, '/'),
            'expires_at' => gmdate('c', $expires),
            'max_bytes' => $maxBytes,
        ];
    }
}

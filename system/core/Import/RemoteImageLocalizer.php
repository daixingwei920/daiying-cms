<?php

declare(strict_types=1);

namespace Cms\Core\Import;

use Cms\Core\Media\MediaLibrary;

final class RemoteImageLocalizer
{
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ImportException('Remote media URL must be http or https.');
        }

        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new ImportException('Unable to resolve remote media host.');
        }

        if ($this->isPrivateIp($ip)) {
            throw new ImportException('Remote media host resolves to a private or reserved IP.');
        }
    }

    /** @param null|callable(string):array{body:string,content_type?:string,final_url?:string} $fetcher */
    public function localize(string $url, MediaLibrary $media, string $tmpDir, int $maxBytes = 5242880, ?callable $fetcher = null): int
    {
        $this->assertSafeUrl($url);
        if ($maxBytes <= 0 || $maxBytes > 52428800) {
            throw new ImportException('Remote media size limit is invalid.');
        }
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) {
            throw new ImportException('Unable to prepare remote media staging directory.');
        }

        $result = $fetcher !== null ? $fetcher($url) : $this->fetch($url, $maxBytes);
        $body = (string) ($result['body'] ?? '');
        $contentType = $this->canonicalContentType((string) ($result['content_type'] ?? ''));
        $finalUrl = (string) ($result['final_url'] ?? $url);
        if ($finalUrl !== $url) {
            $this->assertSafeUrl($finalUrl);
        }
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new ImportException('Remote media payload is empty or too large.');
        }
        if ($contentType !== '' && !isset(self::IMAGE_EXTENSIONS[$contentType])) {
            throw new ImportException('Remote media is not a supported image.');
        }

        $extension = $this->extensionFor($finalUrl, $contentType);
        $name = $this->filenameFor($finalUrl, $extension);
        $tmp = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'remote-image-' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new ImportException('Unable to stage remote media.');
        }

        try {
            return $media->registerLocalFile($tmp, $name);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** @return array{body:string,content_type?:string,final_url?:string} */
    private function fetch(string $url, int $maxBytes): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: image/avif,image/webp,image/png,image/jpeg,image/gif\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if (!is_string($body)) {
            throw new ImportException('Unable to download remote media.');
        }

        $contentType = '';
        foreach (($http_response_header ?? []) as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
                break;
            }
        }

        return ['body' => $body, 'content_type' => $contentType, 'final_url' => $url];
    }

    private function canonicalContentType(string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        return isset(self::IMAGE_EXTENSIONS[$contentType]) ? $contentType : $contentType;
    }

    private function extensionFor(string $url, string $contentType): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return self::IMAGE_EXTENSIONS[$contentType] ?? 'jpg';
    }

    private function filenameFor(string $url, string $extension): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $name = basename($path);
        if ($name === '' || $name === '.' || !str_contains($name, '.')) {
            return 'remote-image.' . $extension;
        }

        return $name;
    }
}

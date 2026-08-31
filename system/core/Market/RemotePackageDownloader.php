<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class RemotePackageDownloader
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $targetDir,
        private readonly array $allowedHosts = [],
        private readonly ?DownloadProgressRepository $progress = null,
    ) {
    }

    public function download(string $url, string $expectedSha256 = ''): string
    {
        $downloadId = hash('sha256', $url . microtime(true));
        $this->progress?->record($downloadId, 'Started', ['url' => $url]);
        $parts = parse_url($url);
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $isLocalHttp = $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (!in_array($scheme, ['https', 'file'], true) && !$isLocalHttp) {
            throw new MarketException('Package download URL must be https or file.');
        }
        if ($scheme === 'https' && $this->allowedHosts !== [] && !in_array($host, $this->allowedHosts, true)) {
            throw new MarketException('Package download host is not allowed.');
        }

        if (!is_dir($this->targetDir)) {
            mkdir($this->targetDir, 0755, true);
        }

        $target = $this->targetDir . '/remote-' . bin2hex(random_bytes(8)) . '.zip';
        $context = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 0]]);
        $content = @file_get_contents($url, false, $context);
        if (!is_string($content)) {
            $this->progress?->record($downloadId, 'Failed', ['url' => $url, 'error' => 'read_failed']);
            throw new MarketException('Unable to download package.');
        }
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, hash('sha256', $content))) {
            $this->progress?->record($downloadId, 'Failed', ['url' => $url, 'error' => 'hash_mismatch']);
            throw new MarketException('Downloaded package hash mismatch.');
        }
        file_put_contents($target, $content);
        $this->progress?->record($downloadId, 'Completed', [
            'url' => $url,
            'path' => $target,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        return $target;
    }
}

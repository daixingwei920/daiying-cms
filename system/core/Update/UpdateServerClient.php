<?php

declare(strict_types=1);

namespace Cms\Core\Update;

final class UpdateServerClient
{
    /** @var callable|null */
    private $transport;

    public function __construct(
        private readonly string $serverUrl,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /** @return array<string,mixed> */
    public function latest(string $currentVersion, string $channel = 'stable', string $siteId = ''): array
    {
        $base = rtrim($this->serverUrl, '/');
        if ($base === '') {
            throw new UpdateException('Core update server URL is not configured.');
        }
        if (!str_starts_with($base, 'https://') && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
            throw new UpdateException('Core update server URL must use HTTPS.');
        }

        $url = $base . '/latest?' . http_build_query([
            'current_version' => $currentVersion,
            'channel' => $channel,
            'site_id' => $siteId,
        ]);
        $decoded = json_decode($this->get($url), true);
        if (!is_array($decoded)) {
            throw new UpdateException('Core update server response is invalid.');
        }
        if ((string) ($decoded['package_url'] ?? '') === '' || (string) ($decoded['version'] ?? '') === '') {
            throw new UpdateException('Core update server response is missing package metadata.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $manifest @return array{path:string,sha256:string,size_bytes:int} */
    public function downloadPackage(array $manifest, string $incomingDir): array
    {
        $url = (string) ($manifest['package_url'] ?? '');
        if ($url === '') {
            throw new UpdateException('Core update package URL is missing.');
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name)) {
            throw new UpdateException('Core update package filename is unsafe.');
        }
        if (!is_dir($incomingDir) && !mkdir($incomingDir, 0755, true) && !is_dir($incomingDir)) {
            throw new UpdateException('Core update incoming directory is not writable.');
        }

        $body = $this->get($url);
        $hash = hash('sha256', $body);
        $expected = (string) ($manifest['package_sha256'] ?? '');
        if ($expected !== '' && !hash_equals($expected, $hash)) {
            throw new UpdateException('Core update package download hash mismatch.');
        }

        $path = rtrim($incomingDir, '/') . '/' . $name;
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new UpdateException('Core update package could not be saved.');
        }

        return ['path' => $path, 'sha256' => $hash, 'size_bytes' => strlen($body)];
    }

    private function get(string $url): string
    {
        if ($this->transport !== null) {
            $body = ($this->transport)($url);
            if (is_string($body)) {
                return $body;
            }
            throw new UpdateException('Core update server transport returned invalid data.');
        }

        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20]]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            throw new UpdateException('Core update server request failed.');
        }

        return $body;
    }
}

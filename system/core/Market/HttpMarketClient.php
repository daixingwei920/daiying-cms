<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class HttpMarketClient implements MarketApiClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $siteToken = '',
        private readonly string $cacheDir = '',
        private readonly string $channel = 'stable',
        private readonly string $coreVersion = '',
        private readonly int $cacheTtlSeconds = 300,
    ) {
    }

    public function search(string $type, string $query = '', bool $forceRefresh = false): array
    {
        if ($this->baseUrl === '') {
            throw new MarketException('Market server URL is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/api/market/search?' . http_build_query([
            'type' => $type,
            'q' => $query,
            'channel' => $this->channel,
            'core_version' => $this->coreVersion,
            'php_version' => PHP_VERSION,
        ]);
        $payload = $this->requestJson($url, $forceRefresh);
        if (isset($payload['error']) && (string) $payload['error'] !== '') {
            throw new MarketException('Market API error: ' . (string) $payload['error']);
        }
        $items = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = MarketItem::fromArray($item);
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function detail(string $marketId, string $version = '', bool $forceRefresh = false): array
    {
        if ($this->baseUrl === '') {
            throw new MarketException('Market server URL is not configured.');
        }
        $url = rtrim($this->baseUrl, '/') . '/api/market/version?' . http_build_query([
            'market_id' => $marketId,
            'version' => $version,
            'channel' => $this->channel,
            'core_version' => $this->coreVersion,
            'php_version' => PHP_VERSION,
        ]);
        $payload = $this->requestJson($url, $forceRefresh);
        if (isset($payload['error']) && (string) $payload['error'] !== '') {
            throw new MarketException('Market detail error: ' . (string) $payload['error']);
        }

        return $payload;
    }

    public function authorizeInstall(string $marketId, string $siteId, string $licenseKey = ''): InstallAuthorization
    {
        if ($this->baseUrl === '') {
            throw new MarketException('Market server URL is not configured.');
        }

        $params = ['market_id' => $marketId, 'site_id' => $siteId];
        if ($licenseKey !== '') {
            $params['license_key'] = $licenseKey;
        }
        $url = rtrim($this->baseUrl, '/') . '/api/market/install-authorizations?' . http_build_query($params);
        return InstallAuthorization::fromArray($this->requestJson($url, true));
    }

    /** @return array<string,mixed> */
    public function activateLicense(string $productId, string $licenseKey, string $siteId, string $siteUrl = ''): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/market/license-activations';
        return $this->postJson($url, [
            'product_id' => $productId,
            'license_key' => $licenseKey,
            'site_id' => $siteId,
            'cms_version' => $this->coreVersion,
            'site_url' => $siteUrl,
            'domain' => (string) (parse_url($siteUrl, PHP_URL_HOST) ?: ''),
        ]);
    }

    /** @return array<string,mixed> */
    public function authorizePaidUpdate(string $productId, string $version, string $installedVersion, string $siteId, string $licenseKey): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/market/paid-update-authorizations';
        return $this->postJson($url, [
            'product_id' => $productId,
            'version' => $version,
            'installed_version' => $installedVersion,
            'site_id' => $siteId,
            'license_key' => $licenseKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function diagnostics(bool $forceRefresh = false): array
    {
        if ($this->baseUrl === '') {
            return ['api_status' => 'not_configured', 'http_status' => 0, 'cache_status' => 'disabled'];
        }
        $url = rtrim($this->baseUrl, '/') . '/api/market/search?' . http_build_query([
            'type' => 'plugin',
            'channel' => $this->channel,
            'core_version' => $this->coreVersion,
            'php_version' => PHP_VERSION,
        ]);
        $cache = $this->cacheRead($url);
        try {
            $result = $this->requestJsonWithMeta($url, $forceRefresh);
            return [
                'api_status' => 'ok',
                'http_status' => $result['http_status'],
                'last_sync_at' => $result['fetched_at'],
                'last_item_count' => count(is_array($result['payload']['items'] ?? null) ? $result['payload']['items'] : []),
                'cache_status' => $result['cache_hit'] ? 'hit' : ($cache !== null ? 'refreshed' : 'miss'),
                'cache_path' => $this->cacheDir,
                'channel' => $this->channel,
                'api_version' => (string) ($result['payload']['api_version'] ?? 'unknown'),
            ];
        } catch (\Throwable $exception) {
            return [
                'api_status' => 'error',
                'http_status' => 0,
                'last_sync_at' => is_array($cache) ? (string) ($cache['fetched_at'] ?? '') : '',
                'last_item_count' => is_array($cache) ? count(is_array($cache['payload']['items'] ?? null) ? $cache['payload']['items'] : []) : 0,
                'cache_status' => is_array($cache) ? 'stale' : 'miss',
                'cache_path' => $this->cacheDir,
                'channel' => $this->channel,
                'api_version' => 'unknown',
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function clearCache(): void
    {
        if ($this->cacheDir === '' || !is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url, bool $forceRefresh = false): array
    {
        return $this->requestJsonWithMeta($url, $forceRefresh)['payload'];
    }

    /** @param array<string,string> $fields @return array<string,mixed> */
    private function postJson(string $url, array $fields): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'header' => trim(($this->siteToken !== '' ? 'Authorization: Bearer ' . $this->siteToken . "\r\n" : '') . "Content-Type: application/x-www-form-urlencoded\r\nCache-Control: no-cache"),
                'content' => http_build_query($fields),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $httpStatus = $this->httpStatus($http_response_header ?? []);
        $decoded = json_decode(is_string($body) ? $body : '', true);
        if (!is_array($decoded)) {
            throw new MarketException('Market API response is invalid.');
        }
        if ($httpStatus >= 400 || isset($decoded['error'])) {
            throw new MarketException((string) ($decoded['error'] ?? ('Market API returned HTTP ' . $httpStatus . '.')));
        }

        return $decoded;
    }

    /** @return array{payload:array<string,mixed>,http_status:int,fetched_at:string,cache_hit:bool} */
    private function requestJsonWithMeta(string $url, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && ($cached = $this->cacheRead($url)) !== null && time() - (int) ($cached['fetched_unix'] ?? 0) <= $this->cacheTtlSeconds) {
            return [
                'payload' => is_array($cached['payload'] ?? null) ? $cached['payload'] : [],
                'http_status' => (int) ($cached['http_status'] ?? 0),
                'fetched_at' => (string) ($cached['fetched_at'] ?? ''),
                'cache_hit' => true,
            ];
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => trim(($this->siteToken !== '' ? 'Authorization: Bearer ' . $this->siteToken . "\r\n" : '') . 'Cache-Control: no-cache'),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $httpStatus = $this->httpStatus($http_response_header ?? []);
        $decoded = json_decode(is_string($body) ? $body : '', true);
        if (!is_array($decoded)) {
            throw new MarketException('Market API response is invalid.');
        }
        if ($httpStatus >= 400) {
            throw new MarketException('Market API returned HTTP ' . $httpStatus . '.');
        }
        $fetchedAt = gmdate('c');
        $this->cacheWrite($url, $decoded, $httpStatus, $fetchedAt);

        return ['payload' => $decoded, 'http_status' => $httpStatus, 'fetched_at' => $fetchedAt, 'cache_hit' => false];
    }

    /** @return array<string,mixed>|null */
    private function cacheRead(string $url): ?array
    {
        $file = $this->cacheFile($url);
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private function cacheWrite(string $url, array $payload, int $httpStatus, string $fetchedAt): void
    {
        $file = $this->cacheFile($url);
        if ($file === '') {
            return;
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode([
            'url' => $url,
            'payload' => $payload,
            'http_status' => $httpStatus,
            'fetched_at' => $fetchedAt,
            'fetched_unix' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function cacheFile(string $url): string
    {
        if ($this->cacheDir === '') {
            return '';
        }

        return rtrim($this->cacheDir, '/') . '/' . sha1($url) . '.json';
    }

    /** @param list<string> $headers */
    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class HttpMarketClient implements MarketApiClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $siteToken = '',
    ) {
    }

    public function search(string $type, string $query = ''): array
    {
        if ($this->baseUrl === '') {
            throw new MarketException('Market server URL is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/api/market/search?type=' . rawurlencode($type) . '&q=' . rawurlencode($query);
        $payload = $this->requestJson($url);
        $items = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = MarketItem::fromArray($item);
            }
        }

        return $items;
    }

    public function authorizeInstall(string $marketId, string $siteId): InstallAuthorization
    {
        if ($this->baseUrl === '') {
            throw new MarketException('Market server URL is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/api/market/install-authorizations?market_id=' . rawurlencode($marketId) . '&site_id=' . rawurlencode($siteId);
        return InstallAuthorization::fromArray($this->requestJson($url));
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => $this->siteToken !== '' ? 'Authorization: Bearer ' . $this->siteToken : '',
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $decoded = json_decode(is_string($body) ? $body : '', true);
        if (!is_array($decoded)) {
            throw new MarketException('Market API response is invalid.');
        }

        return $decoded;
    }
}

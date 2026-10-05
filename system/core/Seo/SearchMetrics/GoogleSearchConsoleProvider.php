<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

final class GoogleSearchConsoleProvider implements SearchMetricsProviderInterface
{
    /** @param null|callable(string,string,array<string,string>,array<string,mixed>): array{status:int,body:string} $httpClient */
    public function __construct(
        private readonly GoogleSearchConsoleConnectionRepository $connections,
        private readonly mixed $httpClient = null,
    ) {
    }

    public function engine(): string
    {
        return 'google';
    }

    public function capabilities(): array
    {
        return ['search_analytics' => true, 'url_inspection' => false];
    }

    public function connectionStatus(): array
    {
        $status = $this->connections->status();
        return ['ok' => $status['ok'], 'status' => $status['status'], 'message' => $status['message']];
    }

    public function sync(SearchMetricsSyncRequest $request): SearchMetricsSyncResult
    {
        $config = $this->connections->config();
        $status = $this->connections->status();
        if (!$status['ok']) {
            return new SearchMetricsSyncResult(false, (string) $status['status'], (string) $status['message']);
        }

        $token = $this->accessToken((string) ($config['client_id'] ?? ''));
        $rows = [];
        $startRow = 0;
        $rowLimit = max(1, min(25000, $request->rowLimit));
        do {
            $payload = [
                'startDate' => $request->periodStart,
                'endDate' => $request->periodEnd,
                'dimensions' => ['query', 'page', 'date'],
                'rowLimit' => $rowLimit,
                'startRow' => $startRow,
                'type' => 'web',
                'dataState' => 'final',
            ];
            $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($request->siteUrl) . '/searchAnalytics/query';
            $response = $this->request('POST', $url, ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], $payload);
            $decoded = $this->decodeResponse($response, 'Google Search Analytics query failed.');
            $items = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $keys = is_array($item['keys'] ?? null) ? array_values($item['keys']) : [];
                $keyword = trim((string) ($keys[0] ?? ''));
                $urlPath = trim((string) ($keys[1] ?? ''));
                $date = trim((string) ($keys[2] ?? ''));
                if ($keyword === '' || $urlPath === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    continue;
                }
                $rows[] = [
                    'keyword' => $keyword,
                    'url_path' => $urlPath,
                    'search_engine' => 'google',
                    'impressions' => (string) (int) ($item['impressions'] ?? 0),
                    'clicks' => (string) (int) ($item['clicks'] ?? 0),
                    'ctr' => isset($item['ctr']) ? (string) $item['ctr'] : '',
                    'average_position' => isset($item['position']) ? (string) $item['position'] : '',
                    'period_start' => $date,
                    'period_end' => $date,
                ];
            }
            $startRow += $rowLimit;
        } while (count($items) === $rowLimit);

        return new SearchMetricsSyncResult(true, 'synced', 'Google Search Console 搜索表现数据已同步。', $rows, ['row_count' => count($rows)]);
    }

    /** @return array{ok:bool,status:string,message:string,refresh_token:string} */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->connections->config();
        $response = $this->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], [
            'code' => $code,
            'client_id' => (string) ($config['client_id'] ?? ''),
            'client_secret' => $this->connections->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $decoded = $this->decodeResponse($response, 'Google OAuth code exchange failed.');
        $refresh = (string) ($decoded['refresh_token'] ?? '');
        if ($refresh === '') {
            return ['ok' => false, 'status' => 'auth_error', 'message' => 'Google did not return a refresh token.', 'refresh_token' => ''];
        }
        return ['ok' => true, 'status' => 'connected', 'message' => 'Google OAuth connected.', 'refresh_token' => $refresh];
    }

    private function accessToken(string $clientId): string
    {
        $response = $this->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], [
            'client_id' => $clientId,
            'client_secret' => $this->connections->clientSecret(),
            'refresh_token' => $this->connections->refreshToken(),
            'grant_type' => 'refresh_token',
        ]);
        $decoded = $this->decodeResponse($response, 'Google OAuth token refresh failed.');
        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new SearchMetricsException('Google OAuth access token is missing.');
        }
        return $token;
    }

    /** @param array<string,string> $headers @param array<string,mixed> $body @return array{status:int,body:string} */
    private function request(string $method, string $url, array $headers, array $body): array
    {
        if (is_callable($this->httpClient)) {
            return ($this->httpClient)($method, $url, $headers, $body);
        }
        $encoded = ($headers['Content-Type'] ?? '') === 'application/x-www-form-urlencoded'
            ? http_build_query($body)
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => is_string($encoded) ? $encoded : '',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $bodyText = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $line, $m) === 1) {
                $status = (int) $m[1];
                break;
            }
        }

        return ['status' => $status, 'body' => is_string($bodyText) ? $bodyText : ''];
    }

    /** @param array{status:int,body:string} $response @return array<string,mixed> */
    private function decodeResponse(array $response, string $fallback): array
    {
        $decoded = json_decode((string) $response['body'], true);
        if ((int) $response['status'] < 200 || (int) $response['status'] >= 300 || !is_array($decoded)) {
            throw new SearchMetricsException($fallback . ' HTTP ' . (int) $response['status']);
        }
        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'Google API error') : 'Google API error';
            throw new SearchMetricsException($message);
        }
        return $decoded;
    }
}

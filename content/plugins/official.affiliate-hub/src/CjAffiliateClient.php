<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

final class CjAffiliateRateLimitException extends \RuntimeException
{
}

interface CjHttpTransportInterface
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
    public function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = 20): array;
}

final class CjCurlHttpTransport implements CjHttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = 20): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize CJ HTTP client.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => max(3, min(60, $timeout)),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                unset($curl);
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            throw new \RuntimeException('CJ request failed: ' . ($error !== '' ? $error : 'network error'));
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw];
    }
}

final class CjAffiliateClient
{
    private const ADS_ENDPOINT = 'https://ads.api.cj.com/query';
    private const LINK_SEARCH_ENDPOINT = 'https://link-search.api.cj.com/v2/link-search';
    private const ALLOWED_HOSTS = ['ads.api.cj.com', 'link-search.api.cj.com'];

    public function __construct(private readonly CjHttpTransportInterface $transport = new CjCurlHttpTransport())
    {
    }

    /** @param array<string,mixed> $config @return array{ok:bool,message:string} */
    public function testConnection(array $config): array
    {
        $token = $this->token($config);
        $companyId = $this->companyId($config);
        $query = 'query { productFeeds(companyId: "' . $this->gql($companyId) . '") { count totalCount resultList { advertiserId advertiserName feedName productCount } } }';
        $data = $this->graphql($query, $token, (int) ($config['timeout'] ?? 20));
        $feeds = $data['data']['productFeeds'] ?? null;
        if (!is_array($feeds)) {
            return ['ok' => false, 'message' => 'CJ 返回内容缺少 productFeeds。'];
        }

        return ['ok' => true, 'message' => 'CJ 连接成功，读取到 ' . (int) ($feeds['count'] ?? 0) . ' 个 feed 摘要。'];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $query @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function searchProducts(array $config, array $query): array
    {
        $token = $this->token($config);
        $companyId = $this->companyId($config);
        $pid = trim((string) ($config['website_id'] ?? $config['pid'] ?? ''));
        if ($pid === '') {
            throw new \InvalidArgumentException('CJ Website ID / PID is required.');
        }
        $limit = max(1, min(100, (int) ($query['limit'] ?? 25)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $keywords = trim((string) ($query['keywords'] ?? ''));
        $partnerIds = $this->partnerIds($query['advertiser_ids'] ?? $config['advertiser_ids'] ?? '');
        $args = ['companyId: "' . $this->gql($companyId) . '"', 'limit: ' . $limit, 'offset: ' . ($offset + 1)];
        if ($keywords !== '') {
            $args[] = 'keywords: "' . $this->gql($keywords) . '"';
        }
        if ($partnerIds !== []) {
            $args[] = 'partnerIds: [' . implode(', ', array_map(fn (string $id): string => '"' . $this->gql($id) . '"', $partnerIds)) . ']';
        }
        $gql = 'query { products(' . implode(', ', $args) . ') { totalCount count limit resultList { advertiserId advertiserName catalogId id title description brand sku imageLink availability price { amount currency } linkCode(pid: "' . $this->gql($pid) . '") { clickUrl } } } }';
        $data = $this->graphql($gql, $token, (int) ($config['timeout'] ?? 20));
        $products = $data['data']['products'] ?? null;
        if (!is_array($products)) {
            return ['items' => [], 'next_cursor' => null];
        }
        $items = [];
        foreach (($products['resultList'] ?? []) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeProduct($row);
            }
        }
        $count = (int) ($products['count'] ?? count($items));
        $total = (int) ($products['totalCount'] ?? 0);
        $nextOffset = $offset + $count;

        return [
            'items' => $items,
            'next_cursor' => $count > 0 && ($total === 0 || $nextOffset < $total) ? (string) $nextOffset : null,
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $query @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function searchLinks(array $config, array $query): array
    {
        $token = $this->token($config);
        $params = [
            'website-id' => trim((string) ($config['website_id'] ?? $config['pid'] ?? '')),
            'keywords' => trim((string) ($query['keywords'] ?? '')),
            'advertiser-ids' => trim((string) ($query['advertiser_ids'] ?? $config['advertiser_ids'] ?? 'joined')),
        ];
        $params = array_filter($params, static fn (string $value): bool => $value !== '');
        $url = self::LINK_SEARCH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('GET', $url, [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/xml, text/xml, application/json',
        ], '', (int) ($config['timeout'] ?? 20));
        if (($response['headers']['content-type'] ?? '') !== '' && str_contains(strtolower($response['headers']['content-type']), 'json')) {
            $decoded = json_decode($response['body'], true);
            return ['items' => is_array($decoded['links'] ?? null) ? $decoded['links'] : [], 'next_cursor' => null];
        }

        return ['items' => $this->normalizeLinksXml($response['body']), 'next_cursor' => null];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeProduct(array $row): array
    {
        $price = is_array($row['price'] ?? null) ? $row['price'] : [];
        $link = is_array($row['linkCode'] ?? null) ? $row['linkCode'] : [];
        $clickUrl = $this->string($link['clickUrl'] ?? '');
        $destinationUrl = $this->destinationFromClick($clickUrl);

        return [
            'provider_id' => 'affiliate.cj',
            'external_product_id' => $this->string($row['id'] ?? ''),
            'external_parent_id' => $this->string($row['catalogId'] ?? ''),
            'advertiser_external_id' => $this->string($row['advertiserId'] ?? ''),
            'advertiser_name' => $this->string($row['advertiserName'] ?? ''),
            'name' => html_entity_decode($this->string($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode(strip_tags($this->string($row['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'brand' => $this->string($row['brand'] ?? ''),
            'sku' => $this->string($row['sku'] ?? ''),
            'image_url' => $this->string($row['imageLink'] ?? ''),
            'price_current' => $this->string($price['amount'] ?? ''),
            'currency' => strtoupper($this->string($price['currency'] ?? '')),
            'availability' => $this->string($row['availability'] ?? 'unknown') ?: 'unknown',
            'destination_url' => $destinationUrl,
            'affiliate_url' => $clickUrl,
            'source_payload' => $row,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeLinksXml(string $xml): array
    {
        $parsed = @simplexml_load_string($xml);
        if (!$parsed instanceof \SimpleXMLElement) {
            return [];
        }
        $items = [];
        foreach ($parsed->xpath('//*[local-name()="link"]') ?: [] as $link) {
            $items[] = [
                'link_id' => (string) ($link->{'link-id'} ?? $link->linkId ?? ''),
                'name' => (string) ($link->{'link-name'} ?? $link->name ?? ''),
                'advertiser_name' => (string) ($link->{'advertiser-name'} ?? ''),
                'click_url' => (string) ($link->{'clickUrl'} ?? $link->{'click-url'} ?? ''),
            ];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function graphql(string $query, string $token, int $timeout): array
    {
        $response = $this->request('POST', self::ADS_ENDPOINT, [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['query' => $query], JSON_UNESCAPED_SLASHES), $timeout);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('CJ returned a non-JSON GraphQL response.');
        }
        if (isset($decoded['errors'])) {
            throw new \RuntimeException('CJ GraphQL error: ' . $this->redactedMessage(json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE)));
        }

        return $decoded;
    }

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
    private function request(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $this->assertAllowedUrl($url);
        $response = $this->transport->request($method, $url, $headers, $body, $timeout);
        $status = (int) ($response['status'] ?? 0);
        if ($status === 429) {
            throw new CjAffiliateRateLimitException('CJ rate limit reached.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('CJ request failed with HTTP ' . $status . '.');
        }

        return $response;
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException('CJ endpoint is not allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('CJ endpoint is not allowed.');
        }
    }

    /** @param array<string,mixed> $config */
    private function token(array $config): string
    {
        $token = trim((string) ($config['personal_access_token'] ?? $config['token'] ?? ''));
        if ($token === '' || strlen($token) < 20 || preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            throw new \InvalidArgumentException('CJ Personal Access Token is required.');
        }
        return $token;
    }

    /** @param array<string,mixed> $config */
    private function companyId(array $config): string
    {
        $companyId = trim((string) ($config['company_id'] ?? ''));
        if ($companyId === '' || !preg_match('/^[A-Za-z0-9_-]{2,64}$/', $companyId)) {
            throw new \InvalidArgumentException('CJ Company ID is required.');
        }
        return $companyId;
    }

    /** @return list<string> */
    private function partnerIds(mixed $value): array
    {
        $ids = [];
        foreach (preg_split('/[,\s]+/', trim((string) $value)) ?: [] as $id) {
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function destinationFromClick(string $clickUrl): string
    {
        $query = parse_url($clickUrl, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $url = $params['url'] ?? '';
            if (is_string($url) && str_starts_with($url, 'https://')) {
                return $url;
            }
        }
        return $clickUrl;
    }

    private function gql(string $value): string
    {
        return addcslashes($value, "\\\"\n\r\t");
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function redactedMessage(string|false $message): string
    {
        $message = is_string($message) ? $message : 'unknown';
        return preg_replace('/(Authorization|Bearer|token|personal_access_token|api[_-]?key|secret)[^,\]\s]*/i', '$1=[redacted]', $message) ?: 'CJ error';
    }
}

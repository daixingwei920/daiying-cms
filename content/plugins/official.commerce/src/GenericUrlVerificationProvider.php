<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GenericUrlVerificationProvider implements CommerceVerificationProviderInterface
{
    /** @var callable(string,array<string,mixed>):array<string,mixed>|null */
    private $fetcher;

    /** @param callable(string,array<string,mixed>):array<string,mixed>|null $fetcher */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    public function providerId(): string
    {
        return 'official.commerce.verifier.url';
    }

    public function verifySource(array $product, array $context = []): array
    {
        $sourceUrl = trim((string) ($product['source_url'] ?? ''));
        if ($sourceUrl === '') {
            return $this->result('not_provided', '', [], [], '商品未填写来源 URL。');
        }

        try {
            $this->assertSafeUrl($sourceUrl);
            $response = $this->fetch($sourceUrl, $context);
            $finalUrl = (string) ($response['final_url'] ?? $sourceUrl);
            $this->assertSafeUrl($finalUrl);
            $status = (int) ($response['http_status'] ?? 0);
            $body = (string) ($response['body'] ?? '');
            $contentType = (string) ($response['content_type'] ?? '');
            if ($status < 200 || $status >= 300 || $body === '') {
                return $this->result('failed', $sourceUrl, [
                    'URL可访问性' => '不可访问',
                    '来源域名' => $this->host($sourceUrl),
                    '最终跳转域名' => $finalUrl !== '' ? $this->host($finalUrl) : '未核验',
                    '页面标题' => '未核验',
                    '品牌' => '未核验',
                    '型号' => '未核验',
                    '价格' => '未核验',
                    '来源' => $sourceUrl,
                ], $this->evidence($sourceUrl, $response), '来源 URL 暂不可访问或返回空页面。');
            }

            $facts = $this->extractFacts($body, $contentType, $sourceUrl, $finalUrl);
            return $this->result('pending', $sourceUrl, $facts, $this->evidence($sourceUrl, $response), 'URL 基础核验已完成；未进行正品判断。');
        } catch (Throwable $exception) {
            return $this->result('failed', $sourceUrl, [
                'URL可访问性' => '核验失败',
                '来源域名' => $sourceUrl !== '' ? $this->host($sourceUrl) : '未核验',
                '最终跳转域名' => '未核验',
                '页面标题' => '未核验',
                '品牌' => '未核验',
                '型号' => '未核验',
                '价格' => '未核验',
                '来源' => $sourceUrl,
            ], [
                'requested_url' => $sourceUrl,
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'error_class' => $exception::class,
            ], 'URL 核验 Provider 暂不可用：' . $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function fetch(string $url, array $context): array
    {
        $fetcher = $this->fetcher;
        if ($fetcher !== null) {
            return $fetcher($url, $context);
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('当前 PHP 缺少 cURL。');
        }

        $current = $url;
        $redirects = 0;
        $maxRedirects = max(0, min((int) ($context['max_redirects'] ?? 5), 8));
        do {
            $this->assertSafeUrl($current);
            $ch = curl_init($current);
            if ($ch === false) {
                throw new RuntimeException('无法初始化 HTTP 请求。');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => max(1, min((int) ($context['connect_timeout'] ?? 5), 15)),
                CURLOPT_TIMEOUT => max(3, min((int) ($context['timeout'] ?? 12), 30)),
                CURLOPT_USERAGENT => 'DaiyingCommerceUrlVerifier/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_RANGE => '0-1048575',
            ]);
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if (!is_string($raw)) {
                throw new RuntimeException($error !== '' ? $error : 'HTTP 请求失败。');
            }
            $headers = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize, 1048576);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->locationHeader($headers);
                if ($location === '') {
                    break;
                }
                $current = $this->resolveUrl($current, $location);
                $redirects++;
                continue;
            }

            return [
                'requested_url' => $url,
                'final_url' => $current,
                'http_status' => $status,
                'content_type' => $contentType,
                'redirect_count' => $redirects,
                'body' => $body,
            ];
        } while ($redirects <= $maxRedirects);

        throw new RuntimeException('来源 URL 跳转次数过多。');
    }

    /** @return array<string,string> */
    private function extractFacts(string $body, string $contentType, string $sourceUrl, string $finalUrl): array
    {
        $jsonProduct = $this->jsonLdProduct($body);
        $title = $this->pageTitle($body);
        $brand = $this->scalarFact($jsonProduct['brand'] ?? null);
        $model = $this->scalarFact($jsonProduct['model'] ?? $jsonProduct['mpn'] ?? null);
        $offers = is_array($jsonProduct['offers'] ?? null) ? $jsonProduct['offers'] : [];
        if (array_is_list($offers)) {
            $offers = is_array($offers[0] ?? null) ? $offers[0] : [];
        }
        $price = $this->scalarFact($offers['price'] ?? $jsonProduct['price'] ?? null);
        $currency = $this->scalarFact($offers['priceCurrency'] ?? $jsonProduct['priceCurrency'] ?? null);
        $specs = $this->additionalProperties($jsonProduct);

        return [
            'URL可访问性' => '可访问',
            '来源域名' => $this->host($sourceUrl),
            '最终跳转域名' => $this->host($finalUrl),
            '最终URL' => $finalUrl,
            'Content-Type' => $contentType !== '' ? $contentType : '未核验',
            '页面标题' => $title !== '' ? $title : '未核验',
            '品牌' => $brand !== '' ? $brand : '未核验',
            '型号' => $model !== '' ? $model : '未核验',
            '价格' => $price !== '' ? $price : '未核验',
            '币种' => $currency !== '' ? strtoupper($currency) : '未核验',
            '规格' => $specs !== '' ? $specs : '未核验',
            '来源' => $sourceUrl,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonLdProduct(string $body): array
    {
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $matches)) {
            return [];
        }
        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode(trim((string) $json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $product = $this->findProductNode($decoded);
            if ($product !== []) {
                return $product;
            }
        }

        return [];
    }

    /** @return array<string,mixed> */
    private function findProductNode(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $item) {
            if (is_string($item) && strtolower($item) === 'product') {
                return $node;
            }
        }
        foreach (['@graph', 'itemListElement'] as $key) {
            if (is_array($node[$key] ?? null)) {
                foreach ($node[$key] as $child) {
                    $found = $this->findProductNode($child);
                    if ($found !== []) {
                        return $found;
                    }
                }
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = $this->findProductNode($child);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    private function pageTitle(string $body): string
    {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $match)) {
            return '';
        }

        return $this->clean(html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 191);
    }

    private function scalarFact(mixed $value): string
    {
        if (is_array($value)) {
            if (isset($value['name'])) {
                return $this->scalarFact($value['name']);
            }
            return '';
        }
        if ($value === null || is_bool($value)) {
            return '';
        }

        return $this->clean((string) $value, 191);
    }

    /** @param array<string,mixed> $product */
    private function additionalProperties(array $product): string
    {
        $props = $product['additionalProperty'] ?? [];
        if (!is_array($props)) {
            return '';
        }
        $items = [];
        foreach ($props as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $name = $this->scalarFact($prop['name'] ?? '');
            $value = $this->scalarFact($prop['value'] ?? '');
            if ($name !== '' && $value !== '') {
                $items[] = $name . ': ' . $value;
            }
        }

        return $this->clean(implode('; ', $items), 1000);
    }

    /** @return array<string,mixed> */
    private function evidence(string $sourceUrl, array $response): array
    {
        return [
            'requested_url' => (string) ($response['requested_url'] ?? $sourceUrl),
            'final_url' => (string) ($response['final_url'] ?? ''),
            'http_status' => (int) ($response['http_status'] ?? 0),
            'content_type' => (string) ($response['content_type'] ?? ''),
            'response_length' => strlen((string) ($response['body'] ?? '')),
            'redirect_count' => (int) ($response['redirect_count'] ?? 0),
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'provider_scope' => 'url_accessibility_and_structured_page_facts_only',
        ];
    }

    /** @param array<string,mixed> $facts @param array<string,mixed> $rawEvidence @return array<string,mixed> */
    private function result(string $status, string $sourceUrl, array $facts, array $rawEvidence, string $failureReason): array
    {
        return [
            'status' => $status,
            'source_url' => $sourceUrl,
            'checked_facts' => $facts,
            'raw_evidence' => $rawEvidence,
            'failure_reason' => $failureReason,
            'provider' => $this->providerId(),
            'record_type' => 'provider_result',
        ];
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('URL 格式无效。');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('来源 URL 仅允许 http/https。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('来源 URL 不允许包含账号信息。');
        }
        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('来源 URL Host 无效。');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new InvalidArgumentException('来源 URL 不允许使用非默认端口。');
        }
        if ($this->isBlockedIp($host)) {
            throw new InvalidArgumentException('来源 URL 指向内网或保留地址。');
        }
    }

    private function host(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) ? $this->normalizeHost((string) ($parts['host'] ?? '')) : '';
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));
        if ($host === '') {
            return '';
        }
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }

        return $host;
    }

    private function isBlockedIp(string $host): bool
    {
        $ip = trim($host, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function locationHeader(string $headers): string
    {
        if (!preg_match_all('/^Location:\s*(.+)$/im', $headers, $matches)) {
            return '';
        }
        $locations = $matches[1] ?? [];
        return trim((string) end($locations));
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            throw new InvalidArgumentException('跳转地址无法解析。');
        }
        if (str_starts_with($location, '//')) {
            return (string) $baseParts['scheme'] . ':' . $location;
        }
        $root = (string) $baseParts['scheme'] . '://' . (string) $baseParts['host'] . (isset($baseParts['port']) ? ':' . (string) $baseParts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        $path = (string) ($baseParts['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return $root . $dir . $location;
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }
}

<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TaobaoVerificationProvider implements CommerceVerificationProviderInterface
{
    private const HOSTS = [
        'taobao.com',
        'tmall.com',
        'tmall.hk',
        'tb.cn',
    ];

    /** @var callable(string,array<string,mixed>):array<string,mixed>|null */
    private $fetcher;

    /** @param callable(string,array<string,mixed>):array<string,mixed>|null $fetcher */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    public function providerId(): string
    {
        return 'official.commerce.verifier.taobao';
    }

    public function verifySource(array $product, array $context = []): array
    {
        $sourceUrl = trim((string) ($product['source_url'] ?? ''));
        if ($sourceUrl === '') {
            return $this->result('not_provided', '', [], [], '商品未填写淘宝/天猫来源 URL。');
        }

        try {
            $this->assertTaobaoUrl($sourceUrl);
            $sourceItemId = $this->itemId($sourceUrl);
            $response = $this->fetch($sourceUrl, $context);
            $finalUrl = (string) ($response['final_url'] ?? $sourceUrl);
            $this->assertTaobaoUrl($finalUrl);
            $finalItemId = $this->itemId($finalUrl);
            $status = (int) ($response['http_status'] ?? 0);
            $body = (string) ($response['body'] ?? '');
            if ($status < 200 || $status >= 300 || $body === '') {
                return $this->result('failed', $sourceUrl, $this->baseFacts($sourceUrl, $finalUrl, $sourceItemId, $finalItemId) + [
                    '当前页面状态' => '不可访问',
                    '商品标题' => '未核验',
                    '品牌' => '未核验',
                    '型号' => '未核验',
                    '价格' => '未核验',
                    '币种' => '未核验',
                    '规格' => '未核验',
                    '图片信息' => '未核验',
                ], $this->evidence($sourceUrl, $response), '淘宝/天猫来源页面暂不可访问。');
            }

            $pageState = $this->pageState($body, $finalUrl);
            $extracted = $pageState === '正常商品页面' ? $this->extractFacts($body) : [
                '商品标题' => '未核验',
                '品牌' => '未核验',
                '型号' => '未核验',
                '价格' => '未核验',
                '币种' => '未核验',
                '规格' => '未核验',
                '图片信息' => '未核验',
            ];
            $facts = $this->baseFacts($sourceUrl, $finalUrl, $sourceItemId, $finalItemId) + ['当前页面状态' => $pageState] + $extracted + $this->comparisonFacts($product, $extracted);

            return $this->result('pending', $sourceUrl, $facts, $this->evidence($sourceUrl, $response), '淘宝/天猫来源事实核验已完成；未进行正品判断。');
        } catch (Throwable $exception) {
            return $this->result('failed', $sourceUrl, [
                '淘宝域名是否合法' => '不合法或未核验',
                '商品ID' => $sourceUrl !== '' ? ($this->itemId($sourceUrl) ?: '未核验') : '未核验',
                '当前页面状态' => '核验失败',
                '商品标题' => '未核验',
                '品牌' => '未核验',
                '型号' => '未核验',
                '价格' => '未核验',
                '币种' => '未核验',
                '规格' => '未核验',
                '图片信息' => '未核验',
                '来源' => $sourceUrl,
            ], [
                'requested_url' => $sourceUrl,
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'error_class' => $exception::class,
            ], 'Taobao Provider 暂不可用：' . $exception->getMessage());
        }
    }

    /** @return array<string,string> */
    private function baseFacts(string $sourceUrl, string $finalUrl, string $sourceItemId, string $finalItemId): array
    {
        return [
            '淘宝域名是否合法' => '合法',
            '来源域名' => $this->host($sourceUrl),
            '最终跳转域名' => $this->host($finalUrl),
            '商品ID' => $finalItemId !== '' ? $finalItemId : ($sourceItemId !== '' ? $sourceItemId : '未核验'),
            '来源商品ID' => $sourceItemId !== '' ? $sourceItemId : '未核验',
            '最终商品ID' => $finalItemId !== '' ? $finalItemId : '未核验',
            '来源' => $sourceUrl,
        ];
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
            $this->assertTaobaoUrl($current);
            $ch = curl_init($current);
            if ($ch === false) {
                throw new RuntimeException('无法初始化 HTTP 请求。');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => max(1, min((int) ($context['connect_timeout'] ?? 5), 15)),
                CURLOPT_TIMEOUT => max(3, min((int) ($context['timeout'] ?? 12), 30)),
                CURLOPT_USERAGENT => 'DaiyingCommerceTaobaoVerifier/1.0',
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

        throw new RuntimeException('淘宝/天猫来源 URL 跳转次数过多。');
    }

    /** @return array<string,string> */
    private function extractFacts(string $body): array
    {
        $jsonProduct = $this->jsonLdProduct($body);
        $title = $this->firstNonEmpty(
            $this->metaContent($body, 'og:title'),
            $this->scalarFact($jsonProduct['name'] ?? null),
            $this->regexFact($body, '/"title"\s*:\s*"([^"]{2,191})"/u'),
            $this->pageTitle($body)
        );
        $brand = $this->firstNonEmpty(
            $this->detailFact($body, ['品牌', 'Brand']),
            $this->regexFact($body, '/"brand"\s*:\s*"([^"]{1,191})"/u'),
            $this->scalarFact($jsonProduct['brand'] ?? null)
        );
        $model = $this->firstNonEmpty(
            $this->detailFact($body, ['型号', '货号', 'Model']),
            $this->regexFact($body, '/"model"\s*:\s*"([^"]{1,191})"/u'),
            $this->scalarFact($jsonProduct['model'] ?? $jsonProduct['mpn'] ?? null)
        );
        $offers = is_array($jsonProduct['offers'] ?? null) ? $jsonProduct['offers'] : [];
        if (array_is_list($offers)) {
            $offers = is_array($offers[0] ?? null) ? $offers[0] : [];
        }
        $price = $this->firstNonEmpty(
            $this->regexFact($body, '/"(?:price|priceText|salePrice|reservePrice)"\s*:\s*"?(¥?\d+(?:\.\d{1,2})?)"?/u'),
            $this->scalarFact($offers['price'] ?? $jsonProduct['price'] ?? null)
        );
        $currency = $this->scalarFact($offers['priceCurrency'] ?? $jsonProduct['priceCurrency'] ?? null);
        $image = $this->firstNonEmpty(
            $this->metaContent($body, 'og:image'),
            $this->regexFact($body, '/"(?:picUrl|image|mainPic)"\s*:\s*"([^"]{10,1024})"/u'),
            $this->scalarFact($jsonProduct['image'] ?? null)
        );
        $specs = $this->specs($body, $jsonProduct);

        return [
            '商品标题' => $title !== '' ? $title : '未核验',
            '页面标题' => $this->pageTitle($body) ?: '未核验',
            '品牌' => $brand !== '' ? $brand : '未核验',
            '型号' => $model !== '' ? $model : '未核验',
            '价格' => $price !== '' ? $price : '未核验',
            '币种' => $currency !== '' ? strtoupper($currency) : ($price !== '' ? 'CNY' : '未核验'),
            '规格' => $specs !== '' ? $specs : '未核验',
            '图片信息' => $image !== '' ? $this->normalizeImageUrl($image) : '未核验',
        ];
    }

    private function pageState(string $body, string $finalUrl): string
    {
        $host = $this->host($finalUrl);
        $text = strtolower($this->clean(strip_tags($body), 4000));
        if (str_starts_with($host, 'login.') || str_contains($finalUrl, '/member/login') || str_contains($finalUrl, 'login.taobao.com')) {
            return '登录页';
        }
        foreach (['请登录', '亲，请登录', 'login', 'alilogin'] as $needle) {
            if (str_contains($text, strtolower($needle))) {
                return '登录页';
            }
        }
        foreach (['验证码', '安全验证', '滑块', 'sec.taobao.com', 'x5sec', 'punish', '访问受限', '访问被拒绝', '风控', 'verify'] as $needle) {
            if (str_contains($text, strtolower($needle))) {
                return '验证码/风控页面';
            }
        }
        foreach (['宝贝不存在', '商品不存在', '已下架', '页面不存在', 'not found'] as $needle) {
            if (str_contains($text, strtolower($needle))) {
                return '商品不存在/已下架';
            }
        }
        if ($this->metaContent($body, 'og:title') !== '' || $this->jsonLdProduct($body) !== [] || str_contains($body, 'itemId')) {
            return '正常商品页面';
        }

        return '未识别页面';
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

    /** @param list<string> $labels */
    private function detailFact(string $body, array $labels): string
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            foreach ([
                '/<li[^>]*>\s*' . $quoted . '\s*[:：]\s*(.*?)<\/li>/isu',
                '/<span[^>]*>\s*' . $quoted . '\s*[:：]?\s*<\/span>\s*<span[^>]*>(.*?)<\/span>/isu',
                '/<td[^>]*>\s*' . $quoted . '\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/isu',
            ] as $pattern) {
                if (preg_match($pattern, $body, $match)) {
                    return $this->clean(html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 191);
                }
            }
        }

        return '';
    }

    private function specs(string $body, array $jsonProduct): string
    {
        $items = [];
        foreach (['颜色', '尺码', '材质', '适用季节', '风格', 'Color', 'Size', 'Material'] as $label) {
            $value = $this->detailFact($body, [$label]);
            if ($value !== '') {
                $items[] = $label . ': ' . $value;
            }
        }
        $props = $jsonProduct['additionalProperty'] ?? [];
        if (is_array($props)) {
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
        }

        return $this->clean(implode('; ', array_values(array_unique($items))), 1000);
    }

    /** @param array<string,mixed> $product @param array<string,string> $facts @return array<string,string> */
    private function comparisonFacts(array $product, array $facts): array
    {
        return [
            '商品标题对比' => $this->compareValue((string) ($product['name'] ?? ''), $facts['商品标题'] ?? ''),
            '品牌对比' => $this->compareValue((string) ($product['brand'] ?? ''), $facts['品牌'] ?? ''),
            '型号对比' => $this->compareValue((string) ($product['model'] ?? ''), $facts['型号'] ?? ''),
            '价格对比' => $this->comparePrice($product, $facts),
            '图片对比' => $this->compareImage($product, $facts),
        ];
    }

    private function compareValue(string $local, string $remote): string
    {
        if ($local === '' || $remote === '' || $remote === '未核验') {
            return '未核验';
        }

        return $this->normalizeComparable($local) === $this->normalizeComparable($remote) ? '一致' : '存在差异';
    }

    /** @param array<string,mixed> $product @param array<string,string> $facts */
    private function comparePrice(array $product, array $facts): string
    {
        $remote = (string) ($facts['价格'] ?? '');
        if ($remote === '' || $remote === '未核验') {
            return '未核验';
        }
        $currency = strtoupper((string) ($product['currency'] ?? ''));
        $remoteCurrency = strtoupper((string) ($facts['币种'] ?? ''));
        if ($remoteCurrency !== '' && $remoteCurrency !== '未核验' && $currency !== '' && $remoteCurrency !== $currency) {
            return '存在差异';
        }
        $number = preg_replace('/[^0-9.]/', '', $remote) ?? '';
        if ($number === '') {
            return '未核验';
        }
        $remoteMinor = (int) round(((float) $number) * 100);

        return $remoteMinor === (int) ($product['price_minor'] ?? -1) ? '一致' : '存在差异';
    }

    /** @param array<string,mixed> $product @param array<string,string> $facts */
    private function compareImage(array $product, array $facts): string
    {
        $remote = (string) ($facts['图片信息'] ?? '');
        if ($remote === '' || $remote === '未核验') {
            return '未核验';
        }
        $local = (string) ($product['primary_media_url'] ?? $product['primary_image_url'] ?? '');
        if ($local === '') {
            return '未核验';
        }

        return $this->normalizeComparable($local) === $this->normalizeComparable($remote) ? '一致' : '存在差异';
    }

    private function regexFact(string $body, string $pattern): string
    {
        if (!preg_match($pattern, $body, $match)) {
            return '';
        }

        return $this->clean(stripcslashes(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 1024);
    }

    private function metaContent(string $body, string $property): string
    {
        if (!preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $body, $match)) {
            return '';
        }

        return $this->clean(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 1024);
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
            if (array_is_list($value)) {
                return $this->scalarFact($value[0] ?? null);
            }
            if (isset($value['name'])) {
                return $this->scalarFact($value['name']);
            }
            if (isset($value['url'])) {
                return $this->scalarFact($value['url']);
            }
            return '';
        }
        if ($value === null || is_bool($value)) {
            return '';
        }

        return $this->clean((string) $value, 191);
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }

    private function assertTaobaoUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('淘宝 URL 格式无效。');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('淘宝 URL 仅允许 http/https。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('淘宝 URL 不允许包含账号信息。');
        }
        $host = $this->host($url);
        if (!$this->isTaobaoHost($host)) {
            throw new InvalidArgumentException('来源 URL 不是受支持的淘宝/天猫官方域名。');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new InvalidArgumentException('淘宝 URL 不允许使用非默认端口。');
        }
    }

    private function isTaobaoHost(string $host): bool
    {
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function itemId(string $url): string
    {
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);
        $id = preg_replace('/\D+/', '', (string) ($query['id'] ?? $query['itemId'] ?? '')) ?? '';
        if ($id !== '') {
            return substr($id, 0, 32);
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (preg_match('/\/i(\d{6,32})\.htm/i', $path, $match)) {
            return (string) $match[1];
        }

        return '';
    }

    private function host(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }

        return $host;
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

    /** @param array<string,mixed> $response @return array<string,mixed> */
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
            'provider_scope' => 'taobao_source_page_facts_only',
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

    private function normalizeComparable(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', '', $value) ?? ''));
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }
}

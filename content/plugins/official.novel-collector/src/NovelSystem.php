<?php

declare(strict_types=1);

namespace Official\NovelCollector;

final class SecurityException extends \RuntimeException
{
}

final class ContentQualityException extends \RuntimeException
{
}

final class SafeHttpClient
{
    private const BLOCKED_HOSTS = ['localhost', 'localhost.localdomain'];
    private const METADATA_IPS = ['169.254.169.254', '100.100.100.200'];

    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBytes = 2097152,
        private readonly int $maxRedirects = 3
    ) {
    }

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new SecurityException('Only http and https URLs are allowed.');
        }
        if (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            throw new SecurityException('Localhost URLs are blocked.');
        }
        $records = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if ($records === false || $records === []) {
            throw new SecurityException('Host cannot be resolved safely.');
        }
        foreach ($records as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new SecurityException('Private, loopback, link-local, multicast, and metadata endpoints are blocked.');
            }
        }
    }

    public function get(string $url, array $extraHeaders = []): array
    {
        $this->assertSafeUrl($url);
        $requested = $url;
        $current = $url;
        for ($redirect = 0; $redirect <= $this->maxRedirects; $redirect++) {
            $this->assertSafeUrl($current);
            $headerLines = [
                'User-Agent: DaiyingNovelCollectorLocalDev/0.1',
                'Accept: text/html,text/plain,application/json,*/*',
            ];
            foreach ($extraHeaders as $name => $value) {
                $headerLines[] = preg_replace('/[^A-Za-z0-9-]/', '', (string) $name) . ': ' . str_replace(["\r", "\n"], '', (string) $value);
            }
            $fallback = $this->curlGet($current, $headerLines);
            if ($fallback !== null) {
                $body = $fallback['body'];
                $headers = $fallback['headers'];
                $status = $fallback['status'];
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => $this->timeoutSeconds,
                        'ignore_errors' => true,
                        'follow_location' => 0,
                        'header' => implode("\r\n", $headerLines) . "\r\n",
                    ],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $stream = @fopen($current, 'rb', false, $context);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('HTTP request failed.');
                }
                $body = stream_get_contents($stream, $this->maxBytes + 1);
                fclose($stream);
                $headers = $http_response_header ?? [];
                $status = $this->statusCode($headers);
            }
            if (strlen((string) $body) > $this->maxBytes) {
                throw new SecurityException('Response size limit exceeded.');
            }
            $location = $this->locationHeader($headers);
            if ($status >= 300 && $status < 400 && $location !== null) {
                if ($redirect === $this->maxRedirects) {
                    throw new SecurityException('Redirect limit exceeded.');
                }
                $current = $this->resolveUrl($current, $location);
                continue;
            }
            return [
                'url' => $current,
                'requested_url' => $requested,
                'final_url' => $current,
                'status' => $status,
                'http_status' => $status,
                'headers' => $headers,
                'content_type' => $this->contentType($headers),
                'response_length' => strlen((string) $body),
                'redirect_count' => $redirect,
                'body' => (string) $body,
            ];
        }
        throw new SecurityException('Redirect limit exceeded.');
    }

    private function curlGet(string $url, array $headerLines): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $handle = curl_init($url);
        if (!is_resource($handle) && !$handle instanceof \CurlHandle) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            if (PHP_VERSION_ID < 80500) {
                curl_close($handle);
            }
            return null;
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($handle);
        }
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = preg_split('/\r\n|\n|\r/', trim($headerText)) ?: [];
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function isBlockedIp(string $ip): bool
    {
        if (in_array($ip, self::METADATA_IPS, true)) {
            return true;
        }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function locationHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Location:') === 0) {
                return trim(substr((string) $header, 9));
            }
        }
        return null;
    }

    private function contentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Content-Type:') === 0) {
                return trim(substr((string) $header, 13));
            }
        }
        return '';
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        return $scheme . '://' . $host . rtrim(dirname($path), '/') . '/' . $location;
    }
}

final class CatalogUrlDiscoverer
{
    public static function discover(string $url, int $max, callable $fetch): array
    {
        $max = max(1, $max);
        $queue = [$url];
        $visited = [];
        $found = [];
        $pageLimit = min(20, max(3, (int) ceil($max / 15) + 2));

        while ($queue !== [] && count($visited) < $pageLimit && count($found) < $max) {
            $currentUrl = array_shift($queue);
            if (!is_string($currentUrl) || $currentUrl === '' || isset($visited[$currentUrl])) {
                continue;
            }
            $visited[$currentUrl] = true;
            try {
                $res = $fetch($currentUrl);
            } catch (\Throwable) {
                if (!BqgSpaAdapter::isPotentialSpaHost($currentUrl)) {
                    continue;
                }
                $res = ['url' => $currentUrl, 'status' => 0, 'headers' => [], 'body' => ''];
            }
            $baseUrl = (string) ($res['url'] ?? $currentUrl);
            $pageFound = self::discoverFromPage($baseUrl, (string) ($res['body'] ?? ''), $max - count($found), $fetch, $queue);
            foreach ($pageFound as $item) {
                $found[(string) $item['url']] = $item;
                if (count($found) >= $max) {
                    break 2;
                }
            }
        }

        return array_values($found);
    }

    private static function discoverFromPage(string $baseUrl, string $body, int $remaining, callable $fetch, array &$queue): array
    {
        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts)) {
            $baseParts = [];
        }
        $baseScheme = strtolower((string) ($baseParts['scheme'] ?? 'https'));
        $baseHost = self::normalizeHost((string) ($baseParts['host'] ?? ''));
        $basePort = self::normalizePort($baseScheme, isset($baseParts['port']) ? (int) $baseParts['port'] : null);
        if ($baseHost === '') {
            return [];
        }

        $found = self::discoverBqgSpa($baseUrl, $body, $remaining, $fetch);
        if (count($found) >= $remaining) {
            return $found;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1');
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = html_entity_decode((string) $node->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = self::resolveUrl($href, $baseUrl);
            if ($candidate === '') {
                continue;
            }
            $parts = parse_url($candidate);
            if (!is_array($parts)) {
                continue;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = self::normalizeHost((string) ($parts['host'] ?? ''));
            $port = self::normalizePort($scheme, isset($parts['port']) ? (int) $parts['port'] : null);
            $path = (string) ($parts['path'] ?? '/');
            if ($host !== $baseHost || $port !== $basePort) {
                continue;
            }
            if (self::isLikelyListingPath($path, $node->textContent)) {
                $queue[] = $candidate;
            }
            if (!self::isLikelyCatalogPath($path, (string) ($parts['fragment'] ?? ''))) {
                continue;
            }
            $title = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($title === '') {
                $title = trim((string) $node->getAttribute('title'));
            }
            $found[$candidate] = [
                'url' => $candidate,
                'title' => $title,
            ];
            if (count($found) >= $remaining) {
                break;
            }
        }
        return array_values($found);
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower(rtrim($ascii, '.'));
            }
        }
        return $host;
    }

    public static function normalizePort(string $scheme, ?int $port): int
    {
        if ($port !== null) {
            return $port;
        }
        return strtolower($scheme) === 'http' ? 80 : 443;
    }

    public static function resolveUrl(string $href, string $baseUrl): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('~^(?:javascript|mailto|tel|data):~i', $href)) {
            return '';
        }
        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts)) {
            return '';
        }
        $baseScheme = strtolower((string) ($baseParts['scheme'] ?? 'https'));
        $baseHost = (string) ($baseParts['host'] ?? '');
        if ($baseHost === '') {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $href = $baseScheme . ':' . $href;
        } elseif (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)) {
            $basePath = (string) ($baseParts['path'] ?? '/');
            if (str_starts_with($href, '/')) {
                $path = $href;
            } else {
                $path = rtrim(dirname($basePath), '/\\') . '/' . $href;
            }
            $href = $baseScheme . '://' . $baseHost . $path;
        }

        $parts = parse_url($href);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = (string) ($parts['host'] ?? '');
        $path = self::normalizePath((string) ($parts['path'] ?? '/'));
        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . (string) $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . (string) $parts['fragment'];
        }
        return $url;
    }

    private static function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments) . (str_ends_with($path, '/') && $segments !== [] ? '/' : '');
    }

    private static function discoverBqgSpa(string $baseUrl, string $body, int $remaining, callable $fetch): array
    {
        if (!BqgSpaAdapter::looksLikeSite($baseUrl, $body) && !BqgSpaAdapter::isPotentialSpaHost($baseUrl)) {
            return [];
        }
        $found = [];
        $apiUrls = [
            BqgSpaAdapter::centralApiUrl('index', ['sort' => 'index']),
            BqgSpaAdapter::plainApiUrl($baseUrl, 'index', ['sort' => 'index']),
        ];
        foreach (['xuanhuan', 'wuxia', 'dushi', 'lishi', 'wangyou', 'kehuan', 'mm', 'finish', 'top'] as $sort) {
            $apiUrls[] = BqgSpaAdapter::centralApiUrl('sort', ['sort' => $sort]);
            $apiUrls[] = BqgSpaAdapter::plainApiUrl($baseUrl, 'sort', ['sort' => $sort]);
        }
        foreach ($apiUrls as $apiUrl) {
            if (count($found) >= $remaining) {
                break;
            }
            try {
                $res = $fetch($apiUrl);
            } catch (\Throwable) {
                continue;
            }
            $json = json_decode((string) ($res['body'] ?? ''), true);
            if (!is_array($json)) {
                continue;
            }
            foreach (BqgSpaAdapter::bookRowsFromPayload($json) as $book) {
                $id = (int) ($book['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $catalogUrl = BqgSpaAdapter::publicCatalogUrl($baseUrl, $id);
                $found[$catalogUrl] = [
                    'url' => $catalogUrl,
                    'title' => trim((string) ($book['title'] ?? '')),
                    'author' => trim((string) ($book['author'] ?? '')),
                    'cover_url' => BqgSpaAdapter::coverUrl($baseUrl, $id),
                ];
                if (count($found) >= $remaining) {
                    break 2;
                }
            }
        }
        return array_values($found);
    }

    private static function isLikelyCatalogPath(string $path, string $fragment = ''): bool
    {
        return preg_match('~(?:/n/[^/?#]+/list\.html|/(?:book|novel|xiaoshuo|xs|b)/[^/?#]+/?$|/(?:book|novel|xiaoshuo|xs|b)/\d+/?$|/read/\d+/?$)~i', $path) === 1
            || preg_match('~^/?book/\d+/?$~i', ltrim($fragment, '#/')) === 1;
    }

    private static function isLikelyListingPath(string $path, string $label): bool
    {
        $label = trim(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('~(?:下一页|下页|尾页|更多|>|»|›)~iu', $label)) {
            return true;
        }
        if (preg_match('~^/?(?:xuanhuan|wuxia|dushi|lishi|wangyou|kehuan|mm|finish|top|sort)(?:/|\b)~i', ltrim($path, '/'))) {
            return true;
        }
        return preg_match('~/(?:\d+|index_\d+|list_\d+)\.html$~i', $path) === 1;
    }
}

final class BqgSpaAdapter
{
    private const API_HOST = 'https://apibi.cc';
    private const CATEGORIES = ['xuanhuan', 'wuxia', 'dushi', 'lishi', 'wangyou', 'kehuan', 'mm', 'finish', 'top'];

    public static function looksLikeSite(string $url, string $body = ''): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        return (str_contains($host, 'bqg') && (str_contains($body, '/#/book') || str_contains($body, '/api/index') || str_contains($body, '/js/common.js')))
            || str_contains($body, 'function url_book(id)')
            || str_contains($body, '/api/sort?sort=');
    }

    public static function isPotentialSpaHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?? '');
        return (str_contains($host, 'bqg') && str_ends_with($host, '.xyz')) || preg_match('~(?:^|/)book/\d+(?:/|$)~i', $fragment) === 1;
    }

    public static function bookIdFromUrl(string $url): ?int
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?? '');
        foreach ([$fragment, $path] as $value) {
            if (preg_match('~(?:^|/)book/(\d+)(?:/|$)~i', $value, $m)) {
                return max(1, (int) $m[1]);
            }
        }
        return null;
    }

    public static function plainApiUrl(string $baseUrl, string $endpoint, array $query): string
    {
        $parts = parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        return $scheme . '://' . $host . '/api/' . rawurlencode($endpoint) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function centralApiUrl(string $endpoint, array $query): string
    {
        return self::API_HOST . '/api/' . rawurlencode($endpoint) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function encryptedApiUrl(string $endpoint, array $payload): string
    {
        $code = md5('book@token.html');
        $iv = substr($code, 0, 16);
        $key = substr($code, 16);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }
        $cipher = openssl_encrypt($json, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipher)) {
            $cipher = '';
        }
        return self::API_HOST . '/api/' . rawurlencode($endpoint) . '?token=' . rawurlencode(base64_encode($cipher));
    }

    public static function publicCatalogUrl(string $baseUrl, int $bookId): string
    {
        $parts = parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        return $scheme . '://' . $host . '/#/book/' . $bookId . '/';
    }

    public static function coverUrl(string $baseUrl, int $bookId): string
    {
        $parts = parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        return $scheme . '://' . $host . '/bookimg/' . intdiv($bookId, 1000) . '/' . $bookId . '.jpg';
    }

    public static function bookRowsFromPayload(array $payload): array
    {
        $rows = [];
        $walk = static function ($value) use (&$walk, &$rows): void {
            if (!is_array($value)) {
                return;
            }
            if (isset($value['id'], $value['title'])) {
                $rows[] = $value;
                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($payload);
        return $rows;
    }

    public static function catalogFromApi(string $catalogUrl, array $book, array $bookList): array
    {
        $bookId = (int) ($book['dirid'] ?? $book['id'] ?? self::bookIdFromUrl($catalogUrl) ?? 0);
        $chapters = [];
        foreach (($bookList['list'] ?? []) as $index => $title) {
            $chapterId = $index + 1;
            $chapters[] = [
                'title' => trim((string) $title),
                'url' => self::encryptedApiUrl('chapter', ['id' => $bookId, 'chapterid' => $chapterId]),
                'source_book_id' => (string) $bookId,
                'source_chapter_id' => $bookId . ':' . $chapterId,
                'sort_order' => $chapterId,
            ];
        }
        return [
            'title' => trim((string) ($book['title'] ?? '')),
            'author' => trim(preg_replace('/\s+/u', ' ', (string) ($book['author'] ?? '')) ?? ''),
            'description' => trim((string) ($book['intro'] ?? '')),
            'cover_url' => $bookId > 0 ? self::coverUrl($catalogUrl, $bookId) : '',
            'status' => str_contains((string) ($book['full'] ?? ''), '完') ? 'completed' : 'serializing',
            'chapters' => $chapters,
            'confidence' => count($chapters) >= 3 ? 0.96 : 0.45,
            'strategy' => 'adapter_bqg_spa_api',
        ];
    }
}

final class HtmlSanitizer
{
    public function clean(string $html, array $rules = []): array
    {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1');
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'nav', 'noscript'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (($rules['remove_selectors'] ?? []) as $selector) {
            foreach ($this->cssToNodes($xpath, (string) $selector) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $text = html_entity_decode(trim((string) ($body?->textContent ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (($rules['remove_regex'] ?? []) as $regex) {
            $text = preg_replace((string) $regex, '', $text) ?? $text;
        }
        foreach (($rules['replace_rules'] ?? []) as $from => $to) {
            $text = str_replace((string) $from, (string) $to, $text);
        }
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{1,}/u', $text) ?: [])));
        $safeHtml = implode("\n", array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', $paragraphs));
        return ['html' => $safeHtml, 'plaintext' => implode("\n", $paragraphs), 'hash' => hash('sha256', implode("\n", $paragraphs))];
    }

    private function cssToNodes(\DOMXPath $xpath, string $selector): array
    {
        if ($selector === '') {
            return [];
        }
        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            return iterator_to_array($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]") ?: new \EmptyIterator());
        }
        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);
            return iterator_to_array($xpath->query("//*[@id='$id']") ?: new \EmptyIterator());
        }
        return iterator_to_array($xpath->query('//' . preg_replace('/[^a-z0-9_-]/i', '', $selector)) ?: new \EmptyIterator());
    }
}

final class ContentQualityAnalyzer
{
    /** @return array<string,mixed> */
    public function analyze(array $clean, array $response = [], array $chapter = []): array
    {
        $plain = trim((string) ($clean['plaintext'] ?? ''));
        $html = (string) ($clean['html'] ?? '');
        $normalized = self::normalizeText($plain);
        $length = mb_strlen($plain);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R+/u', $plain) ?: [])));
        $paragraphCount = count($paragraphs);
        $urlLikeCount = preg_match_all('/(?:https?:\/\/|www\.|[a-z0-9][a-z0-9-]{1,24}\s*(?:[.。·点•⊕◎○◆◇♟♜♀¤Θ]|\\s)+\s*(?:com|cc|net|org|xyz|cn|de|vip))/iu', $plain);
        $linkCount = preg_match_all('/<a\b|href\s*=/iu', $html);
        $imageCount = preg_match_all('/<img\b/iu', $html);
        $visibleLetters = preg_match_all('/[\p{L}\p{N}]/u', $plain);
        $cjkLetters = preg_match_all('/\p{Han}/u', $plain);
        $letterRatio = $length > 0 ? $visibleLetters / max(1, $length) : 0.0;
        $cjkRatio = $length > 0 ? $cjkLetters / max(1, $length) : 0.0;
        $noiseHits = preg_match_all('/验证码|访问(?:过于)?频繁|请登录|点击继续|最新网址|备用网址|广告|推广|扫码|APP下载|安全验证|人机验证|Cloudflare|Just a moment|cf-chl|成人|博彩|棋牌|AV在线|自拍偷拍|无码|加群|QQ|微信|联系(?:方式)?/iu', $plain);
        $honeypotHits = preg_match_all('/大学阿拉伯语专业|全班一共\s*[０-９0-9]+\s*人|另\s*[０-９0-9]+\s*个男生|委琐不堪|典型东北大汉/iu', $plain);
        $title = self::normalizeTitle((string) ($chapter['title'] ?? ''));
        $titleRelated = $title === '' || str_contains($normalized, $title);
        $score = 100;
        $reasons = [];
        if ($length < 100) {
            $score -= 70;
            $reasons[] = 'too_short';
        } elseif ($length < 200) {
            $score -= 35;
            $reasons[] = 'short_text';
        }
        if ($paragraphCount < 2 && $length > 500) {
            $score -= 20;
            $reasons[] = 'low_paragraph_count';
        }
        if ($letterRatio < 0.35 && $length > 200) {
            $score -= 35;
            $reasons[] = 'low_text_ratio';
        }
        if ($urlLikeCount >= 4) {
            $score -= min(45, $urlLikeCount * 6);
            $reasons[] = 'high_domain_density';
        }
        if ($linkCount > 0 && $length > 0 && ($linkCount / max(1, $paragraphCount)) > 0.35) {
            $score -= 30;
            $reasons[] = 'high_link_density';
        }
        if ($imageCount >= 3 && $paragraphCount < 4) {
            $score -= 25;
            $reasons[] = 'image_heavy';
        }
        if ($noiseHits >= 3) {
            $score -= min(45, $noiseHits * 8);
            $reasons[] = 'error_or_promotion_terms';
        }
        if ($honeypotHits >= 2) {
            $score -= 80;
            $reasons[] = 'known_honeypot_promo_template';
        }
        if (!$titleRelated && $length < 800 && $cjkRatio > 0.2) {
            $score -= 10;
            $reasons[] = 'weak_title_relation';
        }
        $status = (int) ($response['http_status'] ?? $response['status'] ?? 0);
        if ($status > 0 && ($status < 200 || $status >= 300)) {
            $score -= 80;
            $reasons[] = 'bad_http_status_' . $status;
        }
        $requested = (string) ($response['requested_url'] ?? '');
        $final = (string) ($response['final_url'] ?? $response['url'] ?? '');
        if ($requested !== '' && $final !== '' && self::looksLikeUnexpectedLanding($requested, $final)) {
            $score -= 70;
            $reasons[] = 'unexpected_final_url';
        }
        $quality = $score >= 70 ? 'ok' : ($score >= 45 ? 'suspicious' : 'failed');
        return [
            'quality' => $quality,
            'score' => max(0, $score),
            'reasons' => array_values(array_unique($reasons)),
            'length' => $length,
            'paragraph_count' => $paragraphCount,
            'url_like_count' => $urlLikeCount,
            'link_count' => $linkCount,
            'image_count' => $imageCount,
            'letter_ratio' => round($letterRatio, 3),
            'cjk_ratio' => round($cjkRatio, 3),
            'fingerprint' => self::fingerprint($plain),
        ];
    }

    public function assertAcceptable(array $clean, array $response = [], array $chapter = []): array
    {
        $quality = $this->analyze($clean, $response, $chapter);
        if (($quality['quality'] ?? '') !== 'ok') {
            throw new ContentQualityException('content_quality_' . (string) $quality['quality'] . ': ' . implode(',', (array) ($quality['reasons'] ?? [])));
        }
        return $quality;
    }

    public static function fingerprint(string $text): string
    {
        return hash('sha256', self::normalizeText($text));
    }

    public static function similarity(string $a, string $b): float
    {
        $a = self::normalizeText($a);
        $b = self::normalizeText($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $len = max(mb_strlen($a), mb_strlen($b));
        $prefix = 0;
        $limit = min(mb_strlen($a), mb_strlen($b), 2000);
        for ($i = 0; $i < $limit; $i++) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) {
                break;
            }
            $prefix++;
        }
        return $prefix / max(1, min($len, 2000));
    }

    private static function normalizeText(string $text): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $text) ?? $text;
    }

    private static function normalizeTitle(string $title): string
    {
        $title = preg_replace('/^第\s*[\p{Han}\d零〇两百千万十]+\s*[章节回卷集部篇]\s*/u', '', $title) ?? $title;
        $title = preg_replace('/[^\p{Han}\p{L}\p{N}]+/u', '', $title) ?? $title;
        return mb_strtolower($title, 'UTF-8');
    }

    private static function looksLikeUnexpectedLanding(string $requested, string $final): bool
    {
        $req = parse_url($requested);
        $fin = parse_url($final);
        if (!is_array($req) || !is_array($fin)) {
            return false;
        }
        $reqPath = trim((string) ($req['path'] ?? ''), '/');
        $finPath = trim((string) ($fin['path'] ?? ''), '/');
        if ($reqPath !== '' && $finPath === '') {
            return true;
        }
        return strtolower((string) ($req['host'] ?? '')) !== strtolower((string) ($fin['host'] ?? '')) && $finPath === '';
    }
}

final class NovelAutoDetector
{
    public function detect(string $catalogUrl, string $html, array $manualRules = []): array
    {
        $host = strtolower((string) (parse_url($catalogUrl, PHP_URL_HOST) ?? ''));
        if (str_ends_with($host, 'quanben.io')) {
            return $this->detectQuanben($catalogUrl, $html, $manualRules);
        }
        $title = $this->match($html, '/<h1[^>]*>(.*?)<\/h1>/isu') ?: $this->match($html, '/<title[^>]*>(.*?)<\/title>/isu');
        $author = $this->match($html, '/(?:作者|author)[\s:：<\/span>]*([^<\n]+)/iu') ?: '佚名';
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER);
        $chapters = [];
        foreach ($matches as $i => $m) {
            $chapterTitle = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            if (!preg_match('/(?:第\s*[一二三四五六七八九十百千万\d零〇两]+\s*章|Chapter\s*\d+|正文|序章|终章|完本感言)/iu', $chapterTitle)) {
                continue;
            }
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $chapters[] = [
                'title' => $chapterTitle,
                'url' => $this->absoluteUrl($catalogUrl, $href),
                'source_chapter_id' => trim($href, '/'),
                'sort_order' => count($chapters) + 1,
            ];
        }
        $confidence = 0.20;
        $confidence += $title ? 0.20 : 0;
        $confidence += $author !== '佚名' ? 0.15 : 0;
        $confidence += count($chapters) >= 3 ? 0.35 : (count($chapters) * 0.08);
        $confidence += $manualRules !== [] ? 0.10 : 0;
        return [
            'title' => $this->cleanText((string) $title),
            'author' => $this->cleanText($author),
            'description' => $this->cleanText($this->match($html, '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)/isu') ?: ''),
            'cover_url' => $this->extractCoverUrl($catalogUrl, $html),
            'status' => str_contains($html, '完结') ? 'completed' : 'serializing',
            'chapters' => $chapters,
            'confidence' => min(0.99, round($confidence, 3)),
            'strategy' => $manualRules === [] ? 'auto_detector' : 'manual_css_xpath_rules',
        ];
    }

    private function detectQuanben(string $catalogUrl, string $html, array $manualRules): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $title = $this->nodeText($xpath, '//*[@itemprop="name" or @itemprop="headline"][1]') ?: $this->match($html, '/<h1[^>]*>(.*?)<\/h1>/isu');
        $author = $this->nodeText($xpath, '//*[@itemprop="author"][1]') ?: '佚名';
        $description = $this->nodeText($xpath, '//*[@itemprop="description"][1]') ?: $this->match($html, '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)/isu') ?: '';
        $statusText = $this->nodeText($xpath, '//*[contains(text(),"状态")]/span[1]') ?: $html;
        $chapters = $this->parseQuanbenChapters($catalogUrl, $html);
        $fullListUrl = $this->quanbenFullListUrl($catalogUrl, $html);
        $confidence = 0.30 + ($title ? 0.20 : 0) + ($author !== '佚名' ? 0.15 : 0) + (count($chapters) >= 3 ? 0.35 : count($chapters) * 0.08);
        return [
            'title' => $this->cleanText((string) $title),
            'author' => $this->cleanText($author),
            'description' => $this->cleanText($description),
            'cover_url' => $this->extractCoverUrl($catalogUrl, $html),
            'status' => str_contains($statusText, '完结') ? 'completed' : 'serializing',
            'chapters' => $chapters,
            'confidence' => min(0.99, round($confidence, 3)),
            'strategy' => $manualRules === [] ? 'adapter_quanben' : 'adapter_quanben_with_manual_rules',
            'full_list_url' => $fullListUrl,
            'notes' => $fullListUrl !== null ? ['quanben.io collapsed list detected; full list endpoint is available without executing source JS.'] : [],
        ];
    }

    public function expandQuanbenJsonp(string $catalogUrl, string $jsonp, array $existingChapters): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+\((.*)\)\s*;?$/s', trim($jsonp), $m)) {
            return $existingChapters;
        }
        $payload = json_decode($m[1], true);
        if (!is_array($payload) || !is_string($payload['content'] ?? null)) {
            return $existingChapters;
        }
        $all = $existingChapters;
        foreach ($this->parseQuanbenChapters($catalogUrl, (string) $payload['content']) as $chapter) {
            $all[] = $chapter;
        }
        $deduped = [];
        foreach ($all as $chapter) {
            $deduped[(string) $chapter['url']] = $chapter;
        }
        $chapters = array_values($deduped);
        usort($chapters, static function (array $a, array $b): int {
            preg_match('/\/(\d+)\.html$/', (string) $a['url'], $ma);
            preg_match('/\/(\d+)\.html$/', (string) $b['url'], $mb);
            return ((int) ($ma[1] ?? PHP_INT_MAX)) <=> ((int) ($mb[1] ?? PHP_INT_MAX));
        });
        foreach ($chapters as $i => &$chapter) {
            $chapter['sort_order'] = $i + 1;
        }
        unset($chapter);
        return $chapters;
    }

    private function parseQuanbenChapters(string $catalogUrl, string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $chapters = [];
        foreach ($xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " list3 ")]//a[@href]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $chapterTitle = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if (!preg_match('/(?:第\s*\d+\s*章|完本感言)/u', $chapterTitle)) {
                continue;
            }
            $href = (string) $node->getAttribute('href');
            $chapters[] = [
                'title' => $chapterTitle,
                'url' => $this->absoluteUrl($catalogUrl, $href),
                'source_book_id' => (string) (parse_url($catalogUrl, PHP_URL_PATH) ?: ''),
                'source_chapter_id' => trim($href, '/'),
                'sort_order' => count($chapters) + 1,
            ];
        }
        return $chapters;
    }

    private function quanbenFullListUrl(string $catalogUrl, string $html): ?string
    {
        if (!preg_match('/load_more\([\'"](\d+)[\'"]\)/', $html, $book) || !preg_match('/var\s+callback\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $html, $callback)) {
            return null;
        }
        $base = $this->absoluteUrl($catalogUrl, '/index.php');
        return $base . '?c=book&a=list.jsonp&callback=' . rawurlencode($callback[1]) . '&book_id=' . rawurlencode($book[1]) . '&b=' . rawurlencode($this->quanbenEncode($callback[1]));
    }

    private function quanbenEncode(string $value): string
    {
        $chars = 'PXhw7UT1B0a9kQDKZsjIASmOezxYG4CHo5Jyfg2b8FLpEvRr3WtVnlqMidu6cN';
        $out = '';
        for ($i = 0; $i < strlen($value); $i++) {
            $pos = strpos($chars, $value[$i]);
            $code = $pos === false ? $value[$i] : $chars[($pos + 3) % strlen($chars)];
            $out .= $chars[1] . $code . $chars[2];
        }
        return $out;
    }

    public function preflight(array $chapters, callable $fetchChapter): array
    {
        if (count($chapters) < 3) {
            return ['pass' => false, 'errors' => ['At least three chapters are required for preflight.']];
        }
        $qualityAnalyzer = new ContentQualityAnalyzer();
        $positions = [0, intdiv(count($chapters), 2), count($chapters) - 1];
        $seen = [];
        $errors = [];
        $samples = [];
        foreach ($positions as $position) {
            $chapter = $chapters[$position];
            $fetched = $fetchChapter($chapter['url']);
            if (is_array($fetched)) {
                $clean = is_array($fetched['clean'] ?? null) ? $fetched['clean'] : [];
                $response = is_array($fetched['response'] ?? null) ? $fetched['response'] : [];
                $content = (string) ($clean['plaintext'] ?? $fetched['plaintext'] ?? '');
                if ($clean === []) {
                    $clean = ['html' => '<p>' . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 'plaintext' => $content, 'hash' => ContentQualityAnalyzer::fingerprint($content)];
                }
            } else {
                $content = (string) $fetched;
                $response = [];
                $clean = ['html' => '<p>' . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 'plaintext' => $content, 'hash' => ContentQualityAnalyzer::fingerprint($content)];
            }
            $plain = trim(strip_tags($content));
            $quality = $qualityAnalyzer->analyze($clean, $response, $chapter);
            $hash = (string) ($quality['fingerprint'] ?? ContentQualityAnalyzer::fingerprint($plain));
            $samples[] = [
                'title' => $chapter['title'],
                'url' => $chapter['url'],
                'requested_url' => (string) ($response['requested_url'] ?? $chapter['url']),
                'final_url' => (string) ($response['final_url'] ?? $response['url'] ?? ''),
                'http_status' => (int) ($response['http_status'] ?? $response['status'] ?? 0),
                'content_type' => (string) ($response['content_type'] ?? ''),
                'response_length' => (int) ($response['response_length'] ?? 0),
                'redirect_count' => (int) ($response['redirect_count'] ?? 0),
                'length' => mb_strlen($plain),
                'hash' => $hash,
                'quality' => (string) ($quality['quality'] ?? 'unknown'),
                'quality_score' => (int) ($quality['score'] ?? 0),
                'quality_reasons' => $quality['reasons'] ?? [],
            ];
            if (mb_strlen($plain) < 200) {
                $errors[] = 'Chapter body is abnormally short: ' . $chapter['title'];
            }
            if (preg_match('/登录|验证码|404|not found|forbidden/i', $plain)) {
                $errors[] = 'Chapter looks like an error or access-control page: ' . $chapter['title'];
            }
            if (($quality['quality'] ?? '') !== 'ok') {
                $errors[] = 'Chapter content quality failed: ' . $chapter['title'] . ' (' . implode(',', (array) ($quality['reasons'] ?? [])) . ')';
            }
            if (isset($seen[$hash])) {
                $errors[] = 'Duplicate chapter body detected.';
            }
            $seen[$hash] = true;
        }
        return ['pass' => $errors === [], 'errors' => $errors, 'samples' => $samples];
    }

    public function extractChapterBody(string $chapterUrl, string $html): string
    {
        $json = json_decode($html, true);
        if (is_array($json) && is_string($json['txt'] ?? null)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/u', (string) $json['txt']) ?: [])));
            return implode("\n", array_map(static fn (string $line): string => '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', $lines));
        }
        $host = strtolower((string) (parse_url($chapterUrl, PHP_URL_HOST) ?? ''));
        if (str_ends_with($host, 'quanben.io')) {
            $body = $this->extractByXpath($html, '//*[@id="content" or contains(concat(" ", normalize-space(@class), " "), " content ")]');
            if ($body !== '') {
                return $body;
            }
        }
        $body = $this->extractBestChapterNode($html);
        return $body !== '' ? $body : $html;
    }

    private function extractBestChapterNode(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'nav', 'header', 'footer', 'aside', 'noscript'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $query = '//article | //*[@itemprop="articleBody"] | //*[contains(translate(concat(" ", @id, " ", @class, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "content") or contains(translate(concat(" ", @id, " ", @class, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "chapter") or contains(translate(concat(" ", @id, " ", @class, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "article") or contains(translate(concat(" ", @id, " ", @class, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "read")]';
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($xpath->query($query) ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            $length = mb_strlen($text);
            if ($length < 80) {
                continue;
            }
            $marker = strtolower((string) $node->getAttribute('id') . ' ' . (string) $node->getAttribute('class'));
            $score = min(5000, $length);
            $score += preg_match('/content|chapter|article|read|正文|章节/u', $marker) ? 1200 : 0;
            $score += min(800, (preg_match_all('/<p\b|<br\b/iu', $dom->saveHTML($node) ?: '') ?: 0) * 80);
            $score -= preg_match('/advert|ads|recommend|banner|footer|header|sidebar|popup|download|promotion|comment|nav/i', $marker) ? 3000 : 0;
            $score -= (preg_match_all('/<a\b/iu', $dom->saveHTML($node) ?: '') ?: 0) * 120;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }
        if (!$best instanceof \DOMNode) {
            return '';
        }
        $out = '';
        foreach ($best->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private function extractByXpath(string $html, string $query): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $node = $xpath->query($query)?->item(0);
        if (!$node instanceof \DOMNode) {
            return '';
        }
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private function match(string $html, string $pattern): ?string
    {
        return preg_match($pattern, $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : null;
    }

    private function extractCoverUrl(string $catalogUrl, string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($html, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $queries = [
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content',
            '//link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"image_src")]/@href',
            '//*[@itemprop="image"]/@content',
            '//*[@itemprop="image"]/@src',
            '//img[contains(translate(concat(" ", @class, " ", @id, " ", @alt, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " cover ")]/@src',
            '//img[contains(translate(concat(" ", @class, " ", @id, " ", @alt, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " bookimg ")]/@src',
            '//img[contains(translate(concat(" ", @class, " ", @id, " ", @alt, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " book-cover ")]/@src',
            '//img[contains(translate(concat(" ", @class, " ", @id, " ", @alt, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " 封面 ")]/@src',
        ];
        foreach ($queries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $candidate = trim(html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($candidate === '') {
                    continue;
                }
                $url = $this->absoluteUrl($catalogUrl, $candidate);
                $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
                $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
                if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (preg_match('/(?:^|\s)content\s*=\s*["\']([^"\']+)["\']/iu', $value, $m)) {
            $value = (string) $m[1];
        }
        return trim($value, " \t\n\r\0\x0B\"'");
    }

    private function nodeText(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);
        return trim(preg_replace('/\s+/u', ' ', (string) ($node?->textContent ?? '')) ?? '');
    }

    private function absoluteUrl(string $base, string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (parse_url($href, PHP_URL_SCHEME)) {
            return $href;
        }
        $parts = parse_url($base);
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $href;
        }
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (str_starts_with($href, '/') ? $href : rtrim(dirname((string) ($parts['path'] ?? '/')), '/') . '/' . $href);
    }
}

final class TxtImportExport
{
    public function splitTxt(string $txt): array
    {
        $txt = mb_convert_encoding($txt, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1');
        $pattern = '/^(?P<title>\s*(?:第[一二三四五六七八九十百千万\d零〇两]+[章卷]|正文\s*第[一二三四五六七八九十百千万\d零〇两]+章|Chapter\s+\d+).*?)$/imu';
        preg_match_all($pattern, $txt, $matches, PREG_OFFSET_CAPTURE);
        $chapters = [];
        for ($i = 0; $i < count($matches['title']); $i++) {
            $title = trim($matches['title'][$i][0]);
            $start = $matches['title'][$i][1] + strlen($matches['title'][$i][0]);
            $end = $matches['title'][$i + 1][1] ?? strlen($txt);
            $body = trim(substr($txt, $start, $end - $start));
            if ($body !== '') {
                $chapters[] = ['title' => $title, 'content' => $body, 'sort_order' => count($chapters) + 1];
            }
        }
        return $chapters;
    }

    public function exportTxt(array $novel, array $chapters): string
    {
        $out = [(string) ($novel['title'] ?? 'Untitled'), '作者：' . (string) ($novel['author'] ?? '佚名'), ''];
        foreach ($chapters as $chapter) {
            $out[] = (string) $chapter['title'];
            $out[] = trim((string) ($chapter['content_plaintext'] ?? strip_tags((string) ($chapter['content'] ?? ''))));
            $out[] = '';
        }
        return implode("\n", $out);
    }

    public function cacheKey(array $novel, array $chapters): string
    {
        return hash('sha256', json_encode([$novel['id'] ?? null, $novel['updated_at'] ?? null, array_column($chapters, 'content_hash')], JSON_UNESCAPED_UNICODE));
    }
}

final class QueueManager
{
    private const STATUSES = ['pending', 'running', 'paused', 'failed', 'completed', 'cancelled'];

    public function transition(string $current, string $next): string
    {
        if (!in_array($current, self::STATUSES, true) || !in_array($next, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid queue status.');
        }
        if ($current === 'cancelled' || $current === 'completed') {
            return $current;
        }
        return $next;
    }

    public function incrementalPlan(array $remoteChapters, array $localChapters, int $revisionWindow = 10): array
    {
        $localBySource = [];
        foreach ($localChapters as $chapter) {
            $localBySource[(string) ($chapter['source_chapter_id'] ?? $chapter['source_url'] ?? $chapter['slug'])] = $chapter;
        }
        $new = [];
        $recheck = [];
        foreach ($remoteChapters as $chapter) {
            $key = (string) ($chapter['source_chapter_id'] ?? $chapter['url'] ?? $chapter['title']);
            if (!isset($localBySource[$key])) {
                $new[] = $chapter;
            }
        }
        $recent = array_slice($remoteChapters, -$revisionWindow);
        foreach ($recent as $chapter) {
            $recheck[] = $chapter;
        }
        return ['new' => $new, 'recheck_recent' => $recheck, 'revision_window' => $revisionWindow, 'delete_missing' => false];
    }
}

final class NovelRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function saveNovel(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $authorId = $this->saveAuthor((string) ($data['author'] ?? '佚名'));
        $slug = $this->slug((string) $data['title']);
        $sourceUrl = (string) ($data['catalog_url'] ?? $data['source_url'] ?? '');
        $sourceHash = $sourceUrl !== '' ? hash('sha256', $sourceUrl) : null;
        $coverUrl = (string) ($data['cover_url'] ?? $data['cover'] ?? '');
        $hasCoverUrl = $this->hasColumn('novels', 'cover_url');
        $novelId = $this->findNovelId($slug, $sourceHash);
        if ($novelId > 0) {
            $coverSql = $hasCoverUrl ? ', cover_url = COALESCE(NULLIF(?, \'\'), cover_url)' : '';
            $stmt = $this->pdo->prepare('UPDATE novels SET title = ?, original_title = ?, author_id = ?, description = ?, status = ?, language = ?, visibility = ?, source_url = COALESCE(source_url, ?), source_url_hash = COALESCE(source_url_hash, ?)' . $coverSql . ', updated_at = ?, published_at = COALESCE(published_at, ?) WHERE id = ?');
            $params = [(string) $data['title'], $data['original_title'] ?? null, $authorId, $data['description'] ?? '', $data['status'] ?? 'serializing', $data['language'] ?? 'zh-CN', 'public', $sourceUrl !== '' ? $sourceUrl : null, $sourceHash];
            if ($hasCoverUrl) {
                $params[] = $coverUrl;
            }
            array_push($params, $now, $now, $novelId);
            $stmt->execute($params);
            $this->ensureDefaultVolume($novelId);
            return $novelId;
        }
        $columns = 'uuid,title,slug,original_title,author_id,description,source_url,source_url_hash,status,language,visibility,created_at,updated_at,published_at';
        $placeholders = '?,?,?,?,?,?,?,?,?,?,?,?,?,?';
        $params = [$this->uuid(), (string) $data['title'], $slug, $data['original_title'] ?? null, $authorId, $data['description'] ?? '', $sourceUrl !== '' ? $sourceUrl : null, $sourceHash, $data['status'] ?? 'serializing', $data['language'] ?? 'zh-CN', 'public', $now, $now, $now];
        if ($hasCoverUrl) {
            $columns .= ',cover_url';
            $placeholders .= ',?';
            $params[] = $coverUrl !== '' ? $coverUrl : null;
        }
        $stmt = $this->pdo->prepare('INSERT INTO novels (' . $columns . ') VALUES (' . $placeholders . ')');
        $stmt->execute($params);
        $novelId = (int) $this->pdo->lastInsertId();
        $this->ensureDefaultVolume($novelId);
        return $novelId;
    }

    public function saveAuthor(string $name): int
    {
        $slug = $this->slug($name);
        $stmt = $this->pdo->prepare('SELECT id FROM novel_authors WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO novel_authors (uuid,name,slug,bio,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([$this->uuid(), $name, $slug, '', $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function saveChapter(int $novelId, array $chapter, array $clean): int
    {
        $volumeId = $this->ensureDefaultVolume($novelId);
        $now = gmdate('Y-m-d H:i:s');
        $title = (string) ($chapter['title'] ?? ('第' . (int) ($chapter['sort_order'] ?? 0) . '章'));
        $sortOrder = (int) ($chapter['sort_order'] ?? 0);
        $slug = $this->slug($title) . '-' . $sortOrder;
        $sourceChapterId = (string) ($chapter['source_chapter_id'] ?? ($chapter['url'] ?? $slug));
        $stmt = $this->pdo->prepare('SELECT id FROM novel_chapters WHERE novel_id = ? AND source_chapter_id = ? LIMIT 1');
        $stmt->execute([$novelId, $sourceChapterId]);
        $chapterId = (int) ($stmt->fetchColumn() ?: 0);
        if ($chapterId <= 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM novel_chapters WHERE novel_id = ? AND slug = ? LIMIT 1');
            $stmt->execute([$novelId, $slug]);
            $chapterId = (int) ($stmt->fetchColumn() ?: 0);
        }
        if ($chapterId > 0) {
            $stmt = $this->pdo->prepare('UPDATE novel_chapters SET volume_id = ?, title = ?, chapter_number = ?, sort_order = ?, content = ?, content_plaintext = ?, source_url = ?, source_chapter_id = ?, content_hash = ?, source_content_hash = ?, word_count = ?, collected_at = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$volumeId, $title, $chapter['chapter_number'] ?? null, $sortOrder, $clean['html'], $clean['plaintext'], $chapter['url'] ?? null, $sourceChapterId, $clean['hash'], $clean['hash'], mb_strlen($clean['plaintext']), $now, $now, $chapterId]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO novel_chapters (uuid,novel_id,volume_id,title,slug,chapter_number,sort_order,content,content_plaintext,source_url,source_chapter_id,content_hash,source_content_hash,word_count,published_at,collected_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$this->uuid(), $novelId, $volumeId, $title, $slug, $chapter['chapter_number'] ?? null, $sortOrder, $clean['html'], $clean['plaintext'], $chapter['url'] ?? null, $sourceChapterId, $clean['hash'], $clean['hash'], mb_strlen($clean['plaintext']), $now, $now, $now]);
            $chapterId = (int) $this->pdo->lastInsertId();
        }
        $this->refreshNovelStats($novelId, $chapterId, $title, $now);
        return $chapterId;
    }

    public function hasDuplicateChapterContent(int $novelId, string $contentHash, int $sortOrder, string $sourceChapterId = ''): bool
    {
        if ($novelId <= 0 || $contentHash === '' || !$this->hasTable('novel_chapters')) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id, sort_order, source_chapter_id FROM novel_chapters WHERE novel_id = ? AND content_hash = ? LIMIT 3');
        $stmt->execute([$novelId, $contentHash]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingSort = (int) ($row['sort_order'] ?? 0);
            $existingSource = (string) ($row['source_chapter_id'] ?? '');
            if ($existingSort !== $sortOrder || ($sourceChapterId !== '' && $existingSource !== '' && $existingSource !== $sourceChapterId)) {
                return true;
            }
        }
        return false;
    }

    public function refreshNovelStats(int $novelId, int $latestChapterId, string $latestChapterTitle, string $now): void
    {
        $this->pdo->prepare('UPDATE novels SET chapter_count = (SELECT COUNT(*) FROM novel_chapters WHERE novel_id = ?), word_count = (SELECT COALESCE(SUM(word_count),0) FROM novel_chapters WHERE novel_id = ?), latest_chapter_id = ?, latest_chapter_title = ?, latest_chapter_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$novelId, $novelId, $latestChapterId, $latestChapterTitle, $now, $now, $novelId]);
        $this->pdo->prepare('UPDATE novel_export_cache SET is_stale = 1 WHERE novel_id = ?')->execute([$novelId]);
    }

    /** @return list<array<string,mixed>> */
    public function publicNovels(int $limit = 100): array
    {
        if (!$this->hasTable('novels')) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $coverSelect = $this->hasColumn('novels', 'cover_url') ? ', n.cover_url' : ', NULL AS cover_url';
        $sql = 'SELECT n.id, n.title, n.slug, n.description, n.status, n.word_count, n.chapter_count, n.latest_chapter_title, n.latest_chapter_at, n.updated_at, n.published_at' . $coverSelect . ', a.name AS author
            FROM novels n
            LEFT JOIN novel_authors a ON a.id = n.author_id
            WHERE n.visibility = ? AND n.chapter_count > 0
            ORDER BY COALESCE(n.latest_chapter_at, n.updated_at, n.published_at) DESC, n.id DESC
            LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['public']);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $this->publicNovelRow($row);
        }
        return $rows;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function publicSections(int $limit = 30): array
    {
        $items = $this->publicNovels(max($limit, 100));
        $latest = $items;
        $new = $items;
        usort($new, static fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? $b['updated_at'] ?? ''), (string) ($a['published_at'] ?? $a['updated_at'] ?? '')));
        $ranking = $items;
        usort($ranking, static fn (array $a, array $b): int => ((int) ($b['word_count'] ?? 0) <=> (int) ($a['word_count'] ?? 0)) ?: ((int) ($b['chapter_count'] ?? 0) <=> (int) ($a['chapter_count'] ?? 0)));
        $completed = array_values(array_filter($items, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['completed', 'complete', 'finished', '完结', '完本'], true)));

        return [
            'recommended' => array_slice($ranking, 0, $limit),
            'latest' => array_slice($latest, 0, $limit),
            'new' => array_slice($new, 0, $limit),
            'completed' => array_slice($completed, 0, $limit),
            'ranking' => array_slice($ranking, 0, $limit),
        ];
    }

    public function publicNovel(int $id): ?array
    {
        if ($id <= 0 || !$this->hasTable('novels')) {
            return null;
        }
        $coverSelect = $this->hasColumn('novels', 'cover_url') ? ', n.cover_url' : ', NULL AS cover_url';
        $stmt = $this->pdo->prepare('SELECT n.id, n.title, n.slug, n.description, n.status, n.word_count, n.chapter_count, n.latest_chapter_title, n.latest_chapter_at, n.updated_at, n.published_at' . $coverSelect . ', a.name AS author
            FROM novels n
            LEFT JOIN novel_authors a ON a.id = n.author_id
            WHERE n.id = ? AND n.visibility = ?
            LIMIT 1');
        $stmt->execute([$id, 'public']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicNovelRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function publicChapterIndex(int $novelId): array
    {
        if ($novelId <= 0 || !$this->hasTable('novel_chapters')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, novel_id, title, slug, chapter_number, sort_order, source_url, content_hash, word_count, published_at, collected_at, updated_at
            FROM novel_chapters
            WHERE novel_id = ?
            ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$novelId]);
        return array_values(array_map([$this, 'publicChapterRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []));
    }

    /** @return list<int> */
    public function publicChapterSorts(int $novelId): array
    {
        if ($novelId <= 0 || !$this->hasTable('novel_chapters')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT sort_order FROM novel_chapters WHERE novel_id = ? AND sort_order > 0 ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$novelId]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
    }

    /** @return list<array<string,mixed>> */
    public function publicChapters(int $novelId): array
    {
        if ($novelId <= 0 || !$this->hasTable('novel_chapters')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, novel_id, title, slug, chapter_number, sort_order, source_url, content, content_plaintext, content_hash, word_count, published_at, collected_at, updated_at
            FROM novel_chapters
            WHERE novel_id = ?
            ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$novelId]);
        return array_values(array_map([$this, 'publicChapterRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []));
    }

    public function publicChapter(int $novelId, int $sortOrder): ?array
    {
        if ($novelId <= 0 || !$this->hasTable('novel_chapters')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, novel_id, title, slug, chapter_number, sort_order, source_url, content, content_plaintext, content_hash, word_count, published_at, collected_at, updated_at
            FROM novel_chapters
            WHERE novel_id = ? AND sort_order = ?
            LIMIT 1');
        $stmt->execute([$novelId, $sortOrder]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicChapterRow($row) : null;
    }

    private function findNovelId(string $slug, ?string $sourceHash): int
    {
        if ($sourceHash !== null && $this->hasColumn('novels', 'source_url_hash')) {
            $stmt = $this->pdo->prepare('SELECT id FROM novels WHERE source_url_hash = ? LIMIT 1');
            $stmt->execute([$sourceHash]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        $stmt = $this->pdo->prepare('SELECT id FROM novels WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : 0;
    }

    private function ensureDefaultVolume(int $novelId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM novel_volumes WHERE novel_id = ? ORDER BY sort_order LIMIT 1');
        $stmt->execute([$novelId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO novel_volumes (novel_id,title,description,sort_order) VALUES (?,?,?,?)')->execute([$novelId, '默认卷', '', 1]);
        return (int) $this->pdo->lastInsertId();
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
                $stmt->execute([$table]);
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicNovelRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $row['id'] = $id;
        $row['formal_novel_id'] = $id;
        $row['job_id'] = 'formal_' . $id;
        $row['author'] = (string) ($row['author'] ?? '佚名');
        $row['url'] = '/novels/book?job_id=formal_' . rawurlencode((string) $id);
        $row['category'] = '小说';
        $row['cover'] = (string) ($row['cover_url'] ?? $row['cover'] ?? '');
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicChapterRow(array $row): array
    {
        $novelId = (int) ($row['novel_id'] ?? 0);
        $sort = max(1, (int) ($row['sort_order'] ?? $row['chapter_number'] ?? 1));
        $row['novel_id'] = $novelId;
        $row['job_id'] = 'formal_' . $novelId;
        $row['novel_key'] = 'formal_' . $novelId;
        $row['sort_order'] = $sort;
        $row['url'] = '/novels/chapter?job_id=formal_' . rawurlencode((string) $novelId) . '&chapter=' . rawurlencode((string) $sort);
        return $row;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $value) ?? '', '-'));
        return $slug !== '' ? mb_substr($slug, 0, 160) : 'item-' . substr(hash('sha256', $value), 0, 12);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

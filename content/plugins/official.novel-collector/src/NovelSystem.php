<?php

declare(strict_types=1);

namespace Official\NovelCollector;

final class SecurityException extends \RuntimeException
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
            if (strlen((string) $body) > $this->maxBytes) {
                throw new SecurityException('Response size limit exceeded.');
            }
            $status = $this->statusCode($headers);
            $location = $this->locationHeader($headers);
            if ($status >= 300 && $status < 400 && $location !== null) {
                if ($redirect === $this->maxRedirects) {
                    throw new SecurityException('Redirect limit exceeded.');
                }
                $current = $this->resolveUrl($current, $location);
                continue;
            }
            return ['url' => $current, 'status' => $status, 'headers' => $headers, 'body' => (string) $body];
        }
        throw new SecurityException('Redirect limit exceeded.');
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
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER);
        $chapters = [];
        foreach ($matches as $i => $m) {
            $chapterTitle = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
            if (!preg_match('/(?:第\s*[一二三四五六七八九十百千万\d零〇两]+\s*章|Chapter\s*\d+|正文|序章|终章|完本感言)/iu', $chapterTitle)) {
                continue;
            }
            $chapters[] = ['title' => $chapterTitle, 'url' => $this->absoluteUrl($catalogUrl, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')), 'sort_order' => count($chapters) + 1];
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
        $positions = [0, intdiv(count($chapters), 2), count($chapters) - 1];
        $seen = [];
        $errors = [];
        $samples = [];
        foreach ($positions as $position) {
            $chapter = $chapters[$position];
            $content = (string) $fetchChapter($chapter['url']);
            $plain = trim(strip_tags($content));
            $hash = hash('sha256', mb_substr($plain, 0, 2000));
            $samples[] = ['title' => $chapter['title'], 'url' => $chapter['url'], 'length' => mb_strlen($plain), 'hash' => $hash];
            if (mb_strlen($plain) < 200) {
                $errors[] = 'Chapter body is abnormally short: ' . $chapter['title'];
            }
            if (preg_match('/登录|验证码|404|not found|forbidden/i', $plain)) {
                $errors[] = 'Chapter looks like an error or access-control page: ' . $chapter['title'];
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
        $host = strtolower((string) (parse_url($chapterUrl, PHP_URL_HOST) ?? ''));
        if (str_ends_with($host, 'quanben.io')) {
            $body = $this->extractByXpath($html, '//*[@id="content" or contains(concat(" ", normalize-space(@class), " "), " content ")]');
            if ($body !== '') {
                return $body;
            }
        }
        $body = $this->extractByXpath($html, '//article | //*[@itemprop="articleBody"] | //*[@id="chaptercontent" or @id="chapter-content" or @id="content"]');
        return $body !== '' ? $body : $html;
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
        if (parse_url($href, PHP_URL_SCHEME)) {
            return $href;
        }
        $parts = parse_url($base);
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
        $novelId = $this->findNovelId($slug, $sourceHash);
        if ($novelId > 0) {
            $stmt = $this->pdo->prepare('UPDATE novels SET title = ?, original_title = ?, author_id = ?, description = ?, status = ?, language = ?, visibility = ?, source_url = COALESCE(source_url, ?), source_url_hash = COALESCE(source_url_hash, ?), updated_at = ?, published_at = COALESCE(published_at, ?) WHERE id = ?');
            $stmt->execute([(string) $data['title'], $data['original_title'] ?? null, $authorId, $data['description'] ?? '', $data['status'] ?? 'serializing', $data['language'] ?? 'zh-CN', 'public', $sourceUrl !== '' ? $sourceUrl : null, $sourceHash, $now, $now, $novelId]);
            $this->ensureDefaultVolume($novelId);
            return $novelId;
        }
        $stmt = $this->pdo->prepare('INSERT INTO novels (uuid,title,slug,original_title,author_id,description,source_url,source_url_hash,status,language,visibility,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$this->uuid(), (string) $data['title'], $slug, $data['original_title'] ?? null, $authorId, $data['description'] ?? '', $sourceUrl !== '' ? $sourceUrl : null, $sourceHash, $data['status'] ?? 'serializing', $data['language'] ?? 'zh-CN', 'public', $now, $now, $now]);
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

    public function refreshNovelStats(int $novelId, int $latestChapterId, string $latestChapterTitle, string $now): void
    {
        $this->pdo->prepare('UPDATE novels SET chapter_count = (SELECT COUNT(*) FROM novel_chapters WHERE novel_id = ?), word_count = (SELECT COALESCE(SUM(word_count),0) FROM novel_chapters WHERE novel_id = ?), latest_chapter_id = ?, latest_chapter_title = ?, latest_chapter_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$novelId, $novelId, $latestChapterId, $latestChapterTitle, $now, $now, $novelId]);
        $this->pdo->prepare('UPDATE novel_export_cache SET is_stale = 1 WHERE novel_id = ?')->execute([$novelId]);
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

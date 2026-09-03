<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/content/plugins/official.novel-collector/src/NovelSystem.php';

use Official\NovelCollector\CatalogUrlDiscoverer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$html = <<<'HTML'
<!doctype html>
<a href="/book/123/">一号书</a>
<a href="novel/abc/">二号书</a>
<a href="//example.com/n/third/list.html">三号书</a>
<a href="https://EXAMPLE.com./xs/456/">四号书</a>
<a href ="/book/321/">空格等号书</a>
<a href="https://evil.test/book/999/">跨域书</a>
<a href="https://example.com:444/book/998/">异端口书</a>
<a href="javascript:alert(1)">脚本链接</a>
<a href="/article/777/">文章页</a>
HTML;

$found = CatalogUrlDiscoverer::discover('https://Example.com./category/page.html', 50, static fn (string $url): array => [
    'url' => $url,
    'status' => 200,
    'headers' => [],
    'body' => $html,
]);

$urls = array_map(static fn (array $item): string => (string) $item['url'], $found);
$joined = implode("\n", $urls);

$assert(count($found) === 5, 'Catalog discoverer should keep exactly five same-origin catalog links.');
$assert(str_contains($joined, 'https://Example.com./book/123/'), 'Root-relative catalog link should resolve.');
$assert(str_contains($joined, 'https://Example.com./category/novel/abc/'), 'Relative catalog link should resolve.');
$assert(str_contains($joined, 'https://example.com/n/third/list.html'), 'Protocol-relative same-origin catalog link should resolve.');
$assert(str_contains($joined, 'https://EXAMPLE.com./xs/456/'), 'Uppercase/trailing-dot host should normalize for allow checks.');
$assert(str_contains($joined, 'https://Example.com./book/321/'), 'Whitespace around href equals should still resolve.');
$assert(!str_contains($joined, 'evil.test'), 'Cross-domain catalog-looking links must be rejected.');
$assert(!str_contains($joined, ':444'), 'Same-host different-port links must be rejected.');
$assert(!str_contains($joined, 'javascript:'), 'Script URLs must be rejected.');
$assert(!str_contains($joined, '/article/777/'), 'Non-catalog paths must be rejected.');

$limited = CatalogUrlDiscoverer::discover('https://example.com/category/page.html', 2, static fn (): array => [
    'url' => 'https://example.com/category/page.html',
    'status' => 200,
    'headers' => [],
    'body' => $html,
]);
$assert(count($limited) === 2, 'Catalog discoverer should honor the max limit.');

$pages = [
    'https://example.com/xuanhuan/' => '<a href="/book/1/">一号书</a><a href="/book/2/">二号书</a><div class="page"><a href="/xuanhuan/2.html">下一页</a></div>',
    'https://example.com/xuanhuan/2.html' => '<a href="/book/3/">三号书</a><a href="/book/4/">四号书</a>',
];
$visited = [];
$paged = CatalogUrlDiscoverer::discover('https://example.com/xuanhuan/', 4, static function (string $url) use ($pages, &$visited): array {
    $visited[] = $url;
    return [
        'url' => $url,
        'status' => 200,
        'headers' => [],
        'body' => $pages[$url] ?? '',
    ];
});
$pagedUrls = implode("\n", array_map(static fn (array $item): string => (string) $item['url'], $paged));
$assert(count($paged) === 4, 'Catalog discoverer should follow same-origin listing pagination.');
$assert(in_array('https://example.com/xuanhuan/2.html', $visited, true), 'Pagination URL should be fetched.');
$assert(str_contains($pagedUrls, 'https://example.com/book/4/'), 'Book links from later pages should be included.');

echo "novel_collector_catalog_discoverer: PASS\n";

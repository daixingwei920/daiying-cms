<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/content/plugins/official.novel-collector/src/NovelSystem.php';

use Official\NovelCollector\BqgSpaAdapter;
use Official\NovelCollector\CatalogUrlDiscoverer;
use Official\NovelCollector\HtmlSanitizer;
use Official\NovelCollector\NovelAutoDetector;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tokenUrl = BqgSpaAdapter::encryptedApiUrl('chapter', ['id' => 14204, 'chapterid' => 1]);
$assert(
    $tokenUrl === 'https://apibi.cc/api/chapter?token=su5HiOSueOlxbta1tBDVlqdGy%2B08GU5lSdWRd%2B97rkY%3D',
    'BQG SPA chapter token should match the browser script.'
);

$catalog = BqgSpaAdapter::catalogFromApi(
    'https://www.bqg204.xyz/#/book/14204/',
    ['id' => '14204', 'title' => '渣 女 图 鉴', 'author' => "\n 小丫么小刺花\n ", 'full' => '连载', 'intro' => '简介', 'dirid' => 14204],
    ['list' => ['第1章 西湖龙井', '第2章 西湖龙井', '第3章 西湖龙井']]
);
$assert($catalog['title'] === '渣 女 图 鉴', 'BQG SPA catalog should keep title.');
$assert($catalog['author'] === '小丫么小刺花', 'BQG SPA catalog should normalize author whitespace.');
$assert(count($catalog['chapters']) === 3, 'BQG SPA catalog should create one chapter per API list item.');
$assert(str_contains((string) $catalog['cover_url'], '/bookimg/14/14204.jpg'), 'BQG SPA cover URL should follow site image convention.');

$detector = new NovelAutoDetector();
$chapterBody = $detector->extractChapterBody((string) $catalog['chapters'][0]['url'], '{"txt":"第一段正文\\n第二段正文"}');
$clean = (new HtmlSanitizer())->clean($chapterBody);
$assert(str_contains($clean['plaintext'], '第一段正文'), 'BQG SPA JSON chapter body should be extracted from txt.');

$html = '<!doctype html><a href="/#/book/14204/">渣 女 图 鉴</a><script src="/js/common.js?v=1.2606"></script>';
$found = CatalogUrlDiscoverer::discover('https://www.bqg204.xyz/', 2, static function (string $url) use ($html): array {
    if (str_contains($url, '/api/index')) {
        return ['url' => $url, 'status' => 200, 'headers' => [], 'body' => '{"hotlist":[{"id":"14204","title":"渣 女 图 鉴","author":"小丫么小刺花"}]}'];
    }
    return ['url' => $url, 'status' => 200, 'headers' => [], 'body' => $html];
});
$assert($found[0]['url'] === 'https://www.bqg204.xyz/#/book/14204/', 'BQG SPA discovery should emit hash catalog URLs.');

$plainHtml = '<h1>万相之王</h1><p>作者：天蚕土豆</p><dd><a href ="/book/14204/1.html">第一章 开始</a></dd><dd><a href ="/book/14204/2.html">第二章 风起</a></dd><dd><a href ="/book/14204/3.html">第三章 云动</a></dd>';
$detected = $detector->detect('https://www.bqg211.cc/book/14204/', $plainHtml);
$assert(count($detected['chapters']) === 3, 'Generic detector should accept whitespace around href equals.');

echo "novel_collector_bqg_spa_adapter: PASS\n";

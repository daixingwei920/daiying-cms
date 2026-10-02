<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Routing\BasePath;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

function sitemap_test_controller(string $siteUrl): ContentFrontController
{
    $db = sys_get_temp_dir() . '/daiying-sitemap-' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        '2026_08_12_000001_core_schema.php',
        '2026_08_12_000002_content_media_schema.php',
    ] as $migrationFile) {
        $migration = require CMS_ROOT . '/system/migrations/' . $migrationFile;
        $migration->up($pdo);
    }
    $now = '2026-10-02T00:00:00+00:00';
    $stmt = $pdo->prepare('INSERT INTO cms_contents (content_type, title, slug, status, blocks_json, meta_json, created_at, updated_at, published_at) VALUES (:type, :title, :slug, "published", "[]", :meta, :created, :updated, :published)');
    $stmt->execute([
        ':type' => 'article',
        ':title' => 'Hello',
        ':slug' => 'hello-world',
        ':meta' => json_encode(['robots_index' => true], JSON_UNESCAPED_SLASHES),
        ':created' => $now,
        ':updated' => $now,
        ':published' => $now,
    ]);

    $settings = Settings::fromArray([
        'database' => ['dsn' => 'sqlite:' . $db],
        'site' => ['url' => $siteUrl],
        'seo' => ['robots_index' => true],
        'app' => ['debug' => false, 'mode' => 'NORMAL'],
    ]);

    return new ContentFrontController(CMS_ROOT, $settings, new FileLogger(sys_get_temp_dir() . '/daiying-sitemap-test.log'));
}

/** @return array{0:DOMDocument,1:DOMXPath} */
function sitemap_parse(string $xml): array
{
    $document = new DOMDocument();
    $ok = $document->loadXML($xml);
    if (!$ok) {
        throw new RuntimeException('Sitemap XML cannot be parsed.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    return [$document, $xpath];
}

BasePath::setCurrent('');
$controller = sitemap_test_controller('https://www.daiyingcms.com');
$absoluteUrl = new ReflectionMethod(ContentFrontController::class, 'absoluteUrl');
$buildXml = new ReflectionMethod(ContentFrontController::class, 'buildSitemapXml');
$seo = new ReflectionMethod(ContentFrontController::class, 'seo');

$check($absoluteUrl->invoke($controller, 'https://www.daiyingcms.com/articles') === 'https://www.daiyingcms.com/articles', 'absolute URL is not prefixed again');
$check($absoluteUrl->invoke($controller, '/articles') === 'https://www.daiyingcms.com/articles', '/articles becomes an absolute URL');
$check($absoluteUrl->invoke($controller, 'articles') === 'https://www.daiyingcms.com/articles', 'articles becomes an absolute URL');
$check(($seo->invoke($controller, ['title' => 'Articles', 'path' => '/articles'])['canonical'] ?? '') === 'https://www.daiyingcms.com/articles', 'canonical fallback uses the same absolute URL normalization');
$check(($seo->invoke($controller, ['title' => 'Articles', 'canonical' => 'https://www.daiyingcms.com/articles', 'path' => '/ignored'])['canonical'] ?? '') === 'https://www.daiyingcms.com/articles', 'absolute canonical URL is not prefixed again');

$response = $controller->sitemap();
$check($response->status() === 200, 'sitemap response is HTTP 200');
$check(str_starts_with($response->headers()['Content-Type'] ?? '', 'application/xml'), 'sitemap response Content-Type is XML');
$check(str_starts_with($response->body(), '<?xml version="1.0" encoding="UTF-8"?>'), 'sitemap starts with XML declaration and no leading text');
[$document, $xpath] = sitemap_parse($response->body());
$check($document->documentElement !== null && $document->documentElement->localName === 'urlset', 'sitemap root element is urlset');
$check($document->documentElement !== null && $document->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9', 'sitemap uses the sitemap namespace');
$locs = [];
foreach ($xpath->query('//sm:loc') ?: [] as $loc) {
    $locs[] = $loc->textContent;
}
$check(in_array('https://www.daiyingcms.com/articles', $locs, true), '<loc> contains the articles URL');
$check(in_array('https://www.daiyingcms.com/articles/hello-world', $locs, true), '<loc> contains the article URL');
$check(!str_contains($response->body(), 'https://www.daiyingcms.com/https://www.daiyingcms.com/'), 'sitemap does not contain a double-prefixed domain');
$lastmod = (string) (($xpath->query('//sm:url[sm:loc="https://www.daiyingcms.com/articles/hello-world"]/sm:lastmod')->item(0)?->textContent) ?? '');
$check($lastmod !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $lastmod), '<lastmod> is emitted in a legal Atom date-time format');

$escapedXml = $buildXml->invoke($controller, [[
    'loc' => 'https://example.test/search?q=a&x=<tag>',
    'lastmod' => '2026-10-02T00:00:00+00:00',
]]);
$check(str_contains($escapedXml, 'q=a&amp;x=&lt;tag&gt;'), 'XML special characters are escaped');
[, $escapedXpath] = sitemap_parse($escapedXml);
$check((string) (($escapedXpath->query('//sm:loc')->item(0)?->textContent) ?? '') === 'https://example.test/search?q=a&x=<tag>', 'escaped <loc> parses back to the original URL');

$shoeController = sitemap_test_controller('https://www.shoe-zy.com');
$shoeResponse = $shoeController->sitemap();
$check(str_contains($shoeResponse->body(), 'https://www.shoe-zy.com/articles'), 'different site_url uses its own domain');
$check(!str_contains($shoeResponse->body(), 'https://www.daiyingcms.com'), 'different site_url does not leak the official domain');
$robots = $shoeController->robots()->body();
$check(str_contains($robots, 'Sitemap: https://www.shoe-zy.com/sitemap.xml'), 'robots.txt sitemap points to the same site /sitemap.xml');

BasePath::setCurrent('');

if ($failures > 0) {
    echo 'Core sitemap tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Core sitemap tests PASS' . PHP_EOL;

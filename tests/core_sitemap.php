<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Routing\BasePath;
use Cms\Core\Theme\ThemeManager;

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
        '2026_10_03_000001_seo_keyword_system_p1.php',
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
        ':meta' => json_encode(['robots_index' => true, 'seo_keywords' => 'custom article keyword', 'target_keywords' => 'shared keyword'], JSON_UNESCAPED_SLASHES),
        ':created' => $now,
        ':updated' => $now,
        ':published' => $now,
    ]);
    $articleId = (int) $pdo->lastInsertId();
    $termStmt = $pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, meta_json, created_at, updated_at) VALUES (:taxonomy, :name, :slug, :meta, :created_at, :updated_at)');
    $termStmt->execute([':taxonomy' => 'category', ':name' => 'Docs', ':slug' => 'docs', ':meta' => json_encode(['seo_title' => 'Docs SEO', 'seo_description' => 'Docs description', 'seo_keywords' => 'docs keyword', 'target_keywords' => 'shared keyword'], JSON_UNESCAPED_SLASHES), ':created_at' => $now, ':updated_at' => $now]);
    $categoryId = (int) $pdo->lastInsertId();
    $termStmt->execute([':taxonomy' => 'tag', ':name' => 'Daiying CMS', ':slug' => 'daiying-cms', ':meta' => json_encode(['seo_title' => 'Daiying CMS Tag SEO', 'seo_keywords' => 'tag keyword'], JSON_UNESCAPED_SLASHES), ':created_at' => $now, ':updated_at' => $now]);
    $tagId = (int) $pdo->lastInsertId();
    $termStmt->execute([':taxonomy' => 'category', ':name' => 'Noindex', ':slug' => 'noindex', ':meta' => json_encode(['robots_index' => false], JSON_UNESCAPED_SLASHES), ':created_at' => $now, ':updated_at' => $now]);
    $noindexCategoryId = (int) $pdo->lastInsertId();
    $termStmt->execute([':taxonomy' => 'category', ':name' => 'Empty', ':slug' => 'empty', ':meta' => '{}', ':created_at' => $now, ':updated_at' => $now]);
    $attach = $pdo->prepare('INSERT INTO cms_content_terms (content_id, term_id, created_at) VALUES (:content_id, :term_id, :created_at)');
    foreach ([$categoryId, $tagId, $noindexCategoryId] as $termId) {
        $attach->execute([':content_id' => $articleId, ':term_id' => $termId, ':created_at' => $now]);
    }

    $settings = Settings::fromArray([
        'database' => ['dsn' => 'sqlite:' . $db],
        'site' => ['url' => $siteUrl],
        'seo' => ['robots_index' => true],
        'theme' => [
            'active' => 'daiying_media',
            'settings' => ['daiying_media' => ['hero_mode' => 'magazine']],
        ],
        'app' => ['debug' => false, 'mode' => 'NORMAL', 'version' => '1.2.77'],
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

function html_canonical(string $html): string
{
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    foreach ($document->getElementsByTagName('link') as $link) {
        if (strtolower($link->getAttribute('rel')) === 'canonical') {
            return $link->getAttribute('href');
        }
    }

    return '';
}

function html_meta(string $html, string $name): string
{
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    foreach ($document->getElementsByTagName('meta') as $meta) {
        if (strtolower($meta->getAttribute('name')) === strtolower($name)) {
            return $meta->getAttribute('content');
        }
    }

    return '';
}

function html_title(string $html): string
{
    return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) === 1
        ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'))
        : '';
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

$articlesHtml = $controller->articles(new Request('GET', '/articles', ['page' => '1']))->body();
$articlesPageTwoHtml = $controller->articles(new Request('GET', '/articles', ['page' => '2']))->body();
$categoryHtml = $controller->term(new Request('GET', '/category/docs', ['page' => '1']))->body();
$categoryPageTwoHtml = $controller->term(new Request('GET', '/category/docs', ['page' => '2']))->body();
$tagHtml = $controller->term(new Request('GET', '/tag/daiying-cms', ['page' => '1']))->body();
$tagPageTwoHtml = $controller->term(new Request('GET', '/tag/daiying-cms', ['page' => '2']))->body();
$articleHtml = $controller->article(new Request('GET', '/articles/hello-world'))->body();
$check(html_canonical($articlesHtml) === 'https://www.daiyingcms.com/articles', '/articles canonical points to itself');
$check(html_canonical($articlesPageTwoHtml) === 'https://www.daiyingcms.com/articles?page=2', '/articles?page=2 canonical points to the paginated URL');
$check(html_canonical($categoryHtml) === 'https://www.daiyingcms.com/category/docs', '/category/{slug} canonical points to itself');
$check(html_canonical($categoryPageTwoHtml) === 'https://www.daiyingcms.com/category/docs?page=2', '/category/{slug}?page=2 canonical points to the paginated URL');
$check(html_canonical($tagHtml) === 'https://www.daiyingcms.com/tag/daiying-cms', '/tag/{slug} canonical points to itself');
$check(html_canonical($tagPageTwoHtml) === 'https://www.daiyingcms.com/tag/daiying-cms?page=2', '/tag/{slug}?page=2 canonical points to the paginated URL');
$check(html_title($articleHtml) !== '' && html_meta($articleHtml, 'description') !== '', 'article title and description SEO output still render');
$check(html_meta($articleHtml, 'keywords') === 'custom article keyword', 'article seo_keywords output as meta keywords');
$check(html_canonical($articleHtml) === 'https://www.daiyingcms.com/articles/hello-world', 'article canonical still points to the article URL');
$check(html_title($categoryHtml) === 'Docs SEO', 'category manual SEO title overrides fallback title');
$check(html_meta($categoryHtml, 'keywords') === 'docs keyword', 'category seo_keywords output as meta keywords');
$check(html_title($tagHtml) === 'Daiying CMS Tag SEO', 'tag manual SEO title overrides fallback title');
$check(html_meta($tagHtml, 'keywords') === 'tag keyword', 'tag seo_keywords output as meta keywords');

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
$check(in_array('https://www.daiyingcms.com/category/docs', $locs, true), '<loc> contains non-empty category URL');
$check(in_array('https://www.daiyingcms.com/tag/daiying-cms', $locs, true), '<loc> contains non-empty tag URL');
$check(!in_array('https://www.daiyingcms.com/category/empty', $locs, true), '<loc> excludes empty category URL');
$check(!in_array('https://www.daiyingcms.com/category/noindex', $locs, true), '<loc> excludes noindex category URL');
$check(count($locs) === count(array_unique($locs)), 'sitemap does not contain duplicate URLs');
$check(!str_contains($response->body(), 'https://www.daiyingcms.com/https://www.daiyingcms.com/'), 'sitemap does not contain a double-prefixed domain');
$lastmod = (string) (($xpath->query('//sm:url[sm:loc="https://www.daiyingcms.com/articles/hello-world"]/sm:lastmod')->item(0)?->textContent) ?? '');
$check($lastmod !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $lastmod), '<lastmod> is emitted in a legal Atom date-time format');
$termLastmod = (string) (($xpath->query('//sm:url[sm:loc="https://www.daiyingcms.com/category/docs"]/sm:lastmod')->item(0)?->textContent) ?? '');
$check($termLastmod !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $termLastmod), 'category <lastmod> is emitted in a legal Atom date-time format');

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
$repoForKeywords = (new ReflectionMethod(ContentFrontController::class, 'repo'))->invoke($controller);
$conflicts = $repoForKeywords->targetKeywordConflicts();
$check(isset($conflicts['shared keyword']) && count($conflicts['shared keyword']) === 2, 'target keyword cannibalization query groups duplicate bindings');

$themeSettings = Settings::fromArray([
    'theme' => [
        'active' => 'daiying_media',
        'settings' => ['daiying_media' => ['hero_mode' => 'magazine']],
    ],
    'site' => ['url' => 'https://www.daiyingcms.com'],
]);
$theme = (new ThemeManager(CMS_ROOT . '/content/themes', $themeSettings, new FileLogger(sys_get_temp_dir() . '/daiying-sitemap-test.log')))->load('daiying_media');
$homeHtml = $theme->render('home', [
    'site_name' => 'Daiying CMS',
    'contents' => [[
        'content_type' => 'article',
        'status' => 'published',
        'title' => 'Featured Article',
        'slug' => 'featured-article',
        'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Featured summary']]],
        'published_at' => '2026-10-02T00:00:00+00:00',
        'media' => [],
    ]],
    'navigation' => [],
    'ad_slots' => [],
]);
$homeDocument = new DOMDocument();
@$homeDocument->loadHTML('<?xml encoding="UTF-8">' . $homeHtml);
$homeXpath = new DOMXPath($homeDocument);
$check($homeXpath->query('//h1')->length === 1, 'Daiying Media home renders exactly one H1');
$check(trim((string) ($homeXpath->query('//h1')->item(0)?->textContent ?? '')) === 'Daiying CMS', 'Daiying Media home H1 uses the site name');
$check($homeXpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " focus-card ")]//h2')->length >= 1, 'Daiying Media featured card title remains visible as H2');

BasePath::setCurrent('');

if ($failures > 0) {
    echo 'Core sitemap tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Core sitemap tests PASS' . PHP_EOL;

<?php

declare(strict_types=1);

define('TEST_SOURCE_ROOT', dirname(__DIR__));
$directory = sys_get_temp_dir() . '/cms-book-title-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
define('CMS_ROOT', $directory);
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentException;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\Slugger;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\OfficialExportContentImporter;
use Cms\Core\Http\Request;
use Cms\Core\Import\ImporterAdapterInterface;
use Cms\Core\Import\ImportService;
use Cms\Core\Import\UnifiedContent;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\UrlMapping\UrlMappingRepository;

$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
    echo '[PASS] ' . $message . PHP_EOL;
};
register_shutdown_function(static function () use ($directory): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
});
$database = static function (string $path): PDO {
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $migrations = array_map(static fn (string $filename) => require $filename, glob(TEST_SOURCE_ROOT . '/system/migrations/*.php'));
    (new MigrationRunner($pdo, $migrations))->run();
    return $pdo;
};
$pdo = $database($directory . '/source.sqlite');
mkdir($directory . '/content/themes/daiying_media', 0700, true);
$themeSource = TEST_SOURCE_ROOT . '/content/themes/daiying_media';
$themeEntries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($themeEntries as $entry) {
    $target = $directory . '/content/themes/daiying_media/' . substr($entry->getPathname(), strlen($themeSource) + 1);
    $entry->isDir() ? mkdir($target, 0700) : copy($entry->getPathname(), $target);
}
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults(), [], $directory);
$slug = '主题《西方二圣-立教》授权码';
$blocks = [['type' => 'paragraph', 'data' => ['text' => 'Isolated compatibility test, not customer content.']]];
$articleId = $repo->create('article', 'Compatibility article', $slug, $blocks, 'published');
$check($repo->find($articleId)['slug'] === $slug, 'Explicit create preserves book-title marks.');
$check($repo->publicBySlug('article', $slug)['id'] === $articleId, 'Public lookup accepts the saved explicit slug.');
$repo->update($articleId, 'article', 'Edited article', $slug, $blocks, 'published');
$repo->patch($articleId, ['title' => 'Patched title']);
$check($repo->find($articleId)['slug'] === $slug, 'Edit and partial update preserve explicit punctuation.');
$automatic = $repo->create('article', '主题《西方二圣·立教》授权码', '', $blocks, 'published');
$check($repo->find($automatic)['slug'] === '主题-西方二圣-立教-授权码', 'Automatic title slugs remain concise without book-title punctuation.');
$check(Slugger::fromInput('Hello World', '') === 'hello-world', 'Existing explicit ASCII normalization remains compatible.');
$pageId = $repo->create('page', 'Page', '《帮助》', $blocks, 'published');
$check($repo->publicBySlug('page', '《帮助》')['id'] === $pageId, 'Pages share the same safe explicit-slug rule.');
$termId = $repo->saveTerm('category', 'Reference books', '《分类》');
$check($repo->termBySlug('category', '《分类》')['id'] === $termId, 'Taxonomy save and lookup use the same slug policy.');
$pdo->prepare('INSERT INTO cms_content_terms (content_id, term_id, created_at) VALUES (?, ?, ?)')->execute([$articleId, $termId, gmdate('c')]);
$settings = Settings::fromArray(['database' => ['dsn' => 'sqlite:' . $directory . '/source.sqlite'], 'site' => ['url' => 'https://isolated.example'], 'theme' => ['active' => 'daiying_media'], 'app' => ['debug' => false, 'mode' => 'NORMAL', 'version' => '1.2.83']]);
$front = new ContentFrontController(CMS_ROOT, $settings, new FileLogger($directory . '/test.log'));
$path = '/articles/' . rawurlencode($slug);
foreach ([$path, '/articles/' . $slug] as $requestPath) {
    $response = $front->article(new Request('GET', $requestPath));
    $check($response->status() === 200, 'Raw and once-encoded article paths resolve.');
    $check(str_contains($response->body(), 'https://isolated.example' . $path), 'Article canonical URL is consistently percent-encoded.');
}
$check($front->page(new Request('GET', '/' . rawurlencode('《帮助》')))->status() === 200, 'Encoded page path resolves.');
$sitemap = $front->sitemap()->body();
$document = new DOMDocument();
$check($document->loadXML($sitemap), 'Sitemap remains valid XML.');
$check(str_contains($sitemap, 'https://isolated.example' . $path), 'Sitemap contains the encoded explicit article URL.');
$check(str_contains($sitemap, '/category/' . rawurlencode('《分类》')), 'Taxonomy sitemap uses encoded slugs.');
$mapping = new UrlMappingRepository($pdo);
$mapping->record('/old-book', $path, 301, 'isolated-test');
$redirect = $front->article(new Request('GET', '/old-book'));
$check($redirect->status() === 301 && $redirect->headers()['Location'] === $path, 'Native URL mapping redirects to the encoded punctuation URL.');
$adapter = new class implements ImporterAdapterInterface {
    public function platformId(): string { return 'isolated-test'; }
    public function supports(string $sourceType, string $sample): bool { return true; }
    public function parse(string $payload): array {
        return [new UnifiedContent('article', 'Imported', '导入《菜谱》', 'published', [], [], '/old-import')];
    }
};
$check((new ImportService($repo, $mapping, [$adapter]))->import('{}') === 1, 'Native import service preserves an explicit punctuation slug.');
$check($mapping->recent()[0]['target_url'] === '/articles/' . rawurlencode('导入《菜谱》'), 'Import-created URL mappings use the saved encoded slug.');
$package = (new ExportPackageBuilder($directory, $pdo, '1.2.83'))->build('isolated-slug-test');
$destination = $database($directory . '/destination.sqlite');
$importer = new OfficialExportContentImporter($destination);
$result = $importer->importPackage($package);
$check($result['content']['created'] === 4, 'Official export package imports all four content rows.');
$destinationRepo = new ContentRepository($destination, ContentTypeRegistry::defaults(), [], $directory);
$check($destinationRepo->publicBySlug('article', $slug) !== null, 'Official export/import round trip preserves the explicit public URL.');
$check($importer->importPackage($package)['content']['updated'] === 4, 'Repeated official import updates rather than duplicates content.');
foreach (['../escape', 'a/b', 'a\\b', 'a?b', 'a#b', '%2f', '%252f', 'a%00b', "a\0b", "a\nb", "a\x7Fb", str_repeat('字', 64), "bad\xFF"] as $unsafe) {
    $check(!Slugger::isPublic($unsafe) && $repo->publicBySlug('article', $unsafe) === null, 'Unsafe or overlong public slug is rejected.');
    $rejected = false;
    try {
        $repo->create('article', 'Unsafe', $unsafe, [], 'published');
    } catch (ContentException) {
        $rejected = true;
    }
    $check($rejected, 'Unsafe explicit save is rejected without silently rewriting the URL.');
}
foreach (['%252F', '%2F', '%5C', '%00', '%2e%2e', rawurlencode(rawurlencode($slug))] as $segment) {
    $check($front->article(new Request('GET', '/articles/' . $segment))->status() === 404, 'Encoded traversal, separators, controls and double decoding remain rejected.');
}
$check($front->article(new Request('GET', '/old-book'))->status() === 301, 'Existing redirect mappings remain intact after imports.');
echo 'Book-title slug contract: ' . $assertions . ' assertions passed.' . PHP_EOL;

<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function category_slug_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/cms-category-slug-' . bin2hex(random_bytes(4));
mkdir($root . '/storage/database', 0755, true);
$settings = Settings::fromArray([
    'database' => [
        'dsn' => 'sqlite:' . $root . '/storage/database/cms.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$pdo = ConnectionFactory::make($settings);

$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$repo->saveTerm('category', '插件购买', 'plugin-purchase');
$contentId = $repo->create('article', '微信支付插件授权码购买', '', [['type' => 'paragraph', 'data' => ['text' => '微信支付插件说明']]], 'published', [], ['插件购买'], []);
$terms = $repo->termsForContent($contentId);
$categories = array_values(array_filter($terms, static fn (array $term): bool => ($term['taxonomy'] ?? '') === 'category'));

category_slug_check(count($categories) === 1, 'content is attached to one category');
category_slug_check(($categories[0]['name'] ?? '') === '插件购买', 'content category keeps the selected category name');
category_slug_check(($categories[0]['slug'] ?? '') === 'plugin-purchase', 'content category keeps the existing custom slug');

$matching = array_values(array_filter($repo->terms('category'), static fn (array $term): bool => ($term['name'] ?? '') === '插件购买'));
category_slug_check(count($matching) === 1, 'saving content does not create a duplicate category with the same name');

$listed = $repo->publicByTerm('category', 'plugin-purchase', 1, 10);
category_slug_check(count($listed) === 1 && (int) ($listed[0]['id'] ?? 0) === $contentId, 'category archive lists content under the existing custom slug');

$pageId = $repo->create('page', '插件购买', 'plugin-purchase', [['type' => 'paragraph', 'data' => ['text' => '插件购买入口']]], 'published', [], [], []);
$page = $repo->publicBySlug('page', 'plugin-purchase');
category_slug_check($page !== null && (int) ($page['id'] ?? 0) === $pageId, 'plugin purchase page is public');

$controller = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/cms.log'));
$viewModel = (new ReflectionMethod(ContentFrontController::class, 'viewModel'))->invoke($controller, $page, false, null);
$pageCategory = is_array($viewModel['page_category'] ?? null) ? $viewModel['page_category'] : [];
$pageCategoryItems = is_array($viewModel['page_category_items'] ?? null) ? $viewModel['page_category_items'] : [];
category_slug_check(($pageCategory['slug'] ?? '') === 'plugin-purchase', 'page with matching slug resolves the canonical category');
category_slug_check(count($pageCategoryItems) === 1 && (int) (($pageCategoryItems[0]['content']['id'] ?? 0)) === $contentId, 'page view model includes articles from its matching category');

@unlink($root . '/storage/database/cms.sqlite');
@rmdir($root . '/storage/database');
@rmdir($root . '/storage');
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " category slug preservation checks failed.\n");
    exit(1);
}

echo "Content category slug preservation tests passed.\n";

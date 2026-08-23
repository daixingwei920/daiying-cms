<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\BlockRenderer;
use Cms\Core\Content\ContentException;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\ExportException;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\OfficialExportContentImporter;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Support\PublicApiRegistry;
use Cms\Core\UrlMapping\UrlMappingRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function content_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function content_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function content_copy_dir(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
        } else {
            copy((string) $item->getPathname(), $destination);
        }
    }
}

function content_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
}

function content_controller(string $root): AdminController
{
    return new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root);
}

function content_request(string $method, string $path, array $query = [], array $body = []): Request
{
    return new Request($method, $path, $query, $body);
}

function content_prepare_restore_root(string $root, string $label): array
{
    $restoreRoot = sys_get_temp_dir() . '/cms-content-restore-' . $label . '-' . bin2hex(random_bytes(4));
    content_remove($restoreRoot);
    foreach (['config', 'storage/logs', 'storage/database', 'storage/exports', 'content/uploads', 'content/themes', 'content/plugins', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
        mkdir($restoreRoot . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $restoreRoot . '/storage/restore.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['site'] = ['name' => 'Content Restore Site', 'url' => 'https://restore.example.test', 'id' => 'content-restore-' . $label, 'secret' => 'content-restore-secret'];
    content_write($restoreRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $settings = Settings::load($restoreRoot);
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
    file_put_contents($restoreRoot . '/storage/installed.lock', '{}');

    return [$restoreRoot, $settings, $pdo];
}

function content_assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
        content_check(false, $message);
    } catch (ContentException) {
        content_check(true, $message);
    }
}

function content_assert_export_throws(callable $callback, string $message): void
{
    try {
        $callback();
        content_check(false, $message);
    } catch (ExportException) {
        content_check(true, $message);
    }
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-content-editor-seo-' . bin2hex(random_bytes(4));
content_remove($root);
foreach (['config', 'storage/logs', 'content/themes', 'content/plugins', 'content/uploads', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
content_copy_dir(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
content_copy_dir(CMS_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');

$dbPath = $root . '/storage/content.sqlite';
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $dbPath, 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Content SEO Site', 'url' => 'https://cms.example.test', 'id' => 'content-seo', 'secret' => 'content-secret'];
$config['theme'] = ['active' => 'default', 'settings' => ['default' => []]];
content_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
content_write($root . '/content/themes/default/templates/content.php', <<<'PHP'
<?php
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
$seo = $context->get('seo', []);
?>
<!doctype html><html lang="zh-CN"><head>
<title><?= $context->e($seo['title'] ?? $context->get('title', '')) ?></title>
<meta name="description" content="<?= $context->e($seo['description'] ?? '') ?>">
<link rel="canonical" href="<?= $context->e($context->get('canonical', '')) ?>">
<meta name="robots" content="<?= $context->e($seo['robots'] ?? '') ?>">
<meta property="og:title" content="<?= $context->e($seo['title'] ?? '') ?>">
<meta property="og:type" content="<?= $context->e($seo['og_type'] ?? '') ?>">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $context->get('title', '')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
</head><body data-rendered-by="<?= $context->e($context->theme->manifest->id) ?>"><h1><?= $context->e($context->get('title', '')) ?></h1><main><?= $context->get('rendered_blocks', '') ?></main></body></html>
PHP);
content_write($root . '/content/themes/default/templates/list.php', <<<'PHP'
<?php
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
$seo = $context->get('seo', []);
$pagination = $context->get('pagination', []);
?>
<!doctype html><html lang="zh-CN"><head><title><?= $context->e($seo['title'] ?? $context->get('title', '')) ?></title><meta name="description" content="<?= $context->e($seo['description'] ?? '') ?>"><link rel="canonical" href="<?= $context->e($seo['canonical'] ?? '') ?>"><meta name="robots" content="<?= $context->e($seo['robots'] ?? '') ?>"></head><body data-rendered-by="<?= $context->e($context->theme->manifest->id) ?>"><h1><?= $context->e($context->get('title', '')) ?></h1><p data-page="<?= (int) ($pagination['page'] ?? 1) ?>" data-total="<?= (int) ($pagination['total'] ?? 0) ?>"></p><?php foreach ($context->get('items', []) as $item): ?><a href="<?= $context->e($item['content']['content_type'] === 'article' ? '/articles/' . $item['content']['slug'] : '/' . $item['content']['slug']) ?>"><?= $context->e($item['title']) ?></a><?php endforeach; ?></body></html>
PHP);

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
file_put_contents($root . '/storage/installed.lock', '{}');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();
$controller = content_controller($root);

$settingsForm = $controller->siteSettings(content_request('GET', '/admin/settings'));
content_check($settingsForm->status() === 200 && str_contains($settingsForm->body(), '站点设置') && str_contains($settingsForm->body(), 'name="robots_index"') && str_contains($settingsForm->body(), 'name="market_enabled"') && str_contains($settingsForm->body(), 'name="developer_mode"') && str_contains($settingsForm->body(), '保存站点设置'), 'admin site settings form exposes site identity, SEO robots and V1.2 market developer mode controls');
$settingsBadCsrf = $controller->siteSettingsSave(content_request('POST', '/admin/settings', [], [
    '_csrf' => 'invalid',
    'site_name' => 'Bad CSRF Site',
    'site_url' => 'https://bad-csrf.example.test',
    'robots_index' => '1',
]));
$settingsGetBlocked = $controller->siteSettingsSave(content_request('GET', '/admin/settings', [], [
    '_csrf' => $csrf,
    'site_name' => 'GET Should Not Save',
    'site_url' => 'https://get-save.example.test',
    'robots_index' => '1',
]));
content_check($settingsBadCsrf->status() === 403 && str_contains($settingsBadCsrf->body(), 'CSRF 校验失败') && $settingsGetBlocked->status() === 405 && ($settingsGetBlocked->headers()['Allow'] ?? '') === 'POST' && str_contains($settingsGetBlocked->body(), '必须通过 POST') && ((require $root . '/config/app.php')['site']['name'] ?? '') === 'Content SEO Site', 'admin site settings save requires POST, valid CSRF and leaves config unchanged');
$settingsBadUrl = $controller->siteSettingsSave(content_request('POST', '/admin/settings', [], [
    '_csrf' => $csrf,
    'site_name' => 'Bad URL Site',
    'site_url' => 'javascript:alert(1)',
    'robots_index' => '1',
]));
content_check($settingsBadUrl->status() === 422 && str_contains($settingsBadUrl->body(), '站点 URL 只允许 http 或 https 完整地址') && ((require $root . '/config/app.php')['site']['name'] ?? '') === 'Content SEO Site', 'admin site settings reject unsafe site URL before writing config');
$settingsSaved = $controller->siteSettingsSave(content_request('POST', '/admin/settings', [], [
    '_csrf' => $csrf,
    'site_name' => 'Content SEO Admin Site',
    'site_url' => 'https://admin-seo.example.test/',
    'robots_index' => '',
    'market_enabled' => '1',
    'developer_mode' => '1',
]));
$savedConfig = require $root . '/config/app.php';
$settingsReload = (new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root))->siteSettings(content_request('GET', '/admin/settings', ['saved' => '1']));
$settingsAuditJson = (string) $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'site.settings_saved' AND actor_id = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
$settingsAudit = json_decode($settingsAuditJson, true);
content_check($settingsSaved->status() === 302 && ($settingsSaved->headers()['Location'] ?? '') === '/admin/settings?saved=1' && ($savedConfig['site']['name'] ?? '') === 'Content SEO Admin Site' && ($savedConfig['site']['url'] ?? '') === 'https://admin-seo.example.test' && ($savedConfig['seo']['robots_index'] ?? true) === false && ($savedConfig['market']['enabled'] ?? false) === true && ($savedConfig['market']['developer_mode'] ?? false) === true && is_array($settingsAudit) && ($settingsAudit['robots_index'] ?? null) === false, 'admin site settings persist site name, canonical base URL, robots indexing and V1.2 market developer mode flags with audit');
content_check($settingsReload->status() === 200 && str_contains($settingsReload->body(), '站点设置已保存') && str_contains($settingsReload->body(), 'Content SEO Admin Site') && !str_contains($settingsReload->body(), 'name="robots_index" value="1" checked') && str_contains($settingsReload->body(), 'name="market_enabled" value="1" checked') && str_contains($settingsReload->body(), 'name="developer_mode" value="1" checked'), 'admin site settings reload displays the saved disabled robots indexing state and enabled V1.2 developer mode');
$dashboardWithDeveloperMode = (new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root))->dashboard();
content_check($dashboardWithDeveloperMode->status() === 200 && str_contains($dashboardWithDeveloperMode->body(), '插件市场') && str_contains($dashboardWithDeveloperMode->body(), '开发者中心'), 'admin dashboard shows Developer Center only when market developer mode is enabled');
$config['site'] = ['name' => 'Content SEO Site', 'url' => 'https://cms.example.test', 'id' => 'content-seo', 'secret' => 'content-secret'];
$config['seo']['robots_index'] = true;
$config['market'] = ['enabled' => true, 'developer_mode' => false];
content_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$controller = content_controller($root);
$dashboardWithoutDeveloperMode = $controller->dashboard();
content_check($dashboardWithoutDeveloperMode->status() === 200 && str_contains($dashboardWithoutDeveloperMode->body(), '插件市场') && !str_contains($dashboardWithoutDeveloperMode->body(), '开发者中心'), 'admin dashboard hides Developer Center when V1.2 market is enabled but developer mode is disabled');

$newForm = $controller->contentCreate();
content_check($newForm->status() === 200 && str_contains($newForm->body(), '返回内容管理') && str_contains($newForm->body(), '内容区块') && str_contains($newForm->body(), 'editor-shell') && str_contains($newForm->body(), '保存草稿') && str_contains($newForm->body(), '发布') && !str_contains($newForm->body(), 'Block 1') && !str_contains($newForm->body(), 'blocks_json'), 'admin content form provides Chinese block-card editor without developer terminology');

$transferForm = $controller->transferIndex(content_request('GET', '/admin/transfer'));
content_check($transferForm->status() === 200 && str_contains($transferForm->body(), '导入内容') && str_contains($transferForm->body(), 'WordPress XML') && str_contains($transferForm->body(), 'Z-Blog JSON') && str_contains($transferForm->body(), '导入为草稿'), 'admin transfer page exposes WordPress and Z-Blog content import form');
$exportBlocked = $controller->transferExport(content_request('POST', '/admin/transfer/export', [], [
    '_csrf' => 'invalid',
]));
content_check($exportBlocked->status() === 403, 'admin official export requires CSRF protection through Request body');
$exportCreated = $controller->transferExport(content_request('POST', '/admin/transfer/export', [], [
    '_csrf' => $csrf,
]));
$exportFile = glob($root . '/storage/exports/cms-export-*.zip')[0] ?? '';
$exportName = basename($exportFile);
$transferWithExport = $controller->transferIndex(content_request('GET', '/admin/transfer'));
content_check($exportCreated->status() === 302 && is_file($exportFile) && str_contains($transferWithExport->body(), '/admin/transfer/preflight/' . rawurlencode($exportName)) && str_contains($transferWithExport->body(), '预检') && str_contains($transferWithExport->body(), 'class="admin-danger"'), 'admin transfer creates official export package and exposes compact safe actions');
$blockedPreflight = $controller->transferPreflight(content_request('POST', '/admin/transfer/preflight/' . $exportName, [], [
    '_csrf' => 'invalid',
]));
content_check($blockedPreflight->status() === 403, 'official export preflight requires CSRF protection');
$preflight = $controller->transferPreflight(content_request('POST', '/admin/transfer/preflight/' . $exportName, [], [
    '_csrf' => $csrf,
]));
content_check($preflight->status() === 302 && ($preflight->headers()['Location'] ?? '') === '/admin/transfer?preflight=1', 'official export preflight verifies manifest, checksums, media and payment ledger without importing data');
$preflightNotice = $controller->transferIndex(content_request('GET', '/admin/transfer', ['preflight' => '1']));
content_check($preflightNotice->status() === 200 && str_contains($preflightNotice->body(), '导出包预检通过'), 'admin transfer shows Chinese success notice after export preflight');
content_write($root . '/storage/exports/cms-export-bad-preflight.zip', 'not a zip');
$badPreflight = $controller->transferPreflight(content_request('POST', '/admin/transfer/preflight/cms-export-bad-preflight.zip', [], [
    '_csrf' => $csrf,
]));
content_check($badPreflight->status() === 302 && ($badPreflight->headers()['Location'] ?? '') === '/admin/transfer?preflight_failed=1', 'official export preflight redirects with Chinese failure state for invalid packages');
$tamperedExport = $root . '/storage/exports/cms-export-tampered-content.zip';
$sourceZip = new ZipArchive();
$tamperedZip = new ZipArchive();
if ($sourceZip->open($exportFile) === true && $tamperedZip->open($tamperedExport, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    for ($entryIndex = 0; $entryIndex < $sourceZip->numFiles; $entryIndex++) {
        $entryName = $sourceZip->getNameIndex($entryIndex);
        if (!is_string($entryName) || $entryName === 'content/content.json') {
            continue;
        }
        $entryBody = $sourceZip->getFromName($entryName);
        if (is_string($entryBody)) {
            $tamperedZip->addFromString($entryName, $entryBody);
        }
    }
    $tamperedZip->addFromString('content/content.json', '{}');
    $tamperedZip->close();
    $sourceZip->close();
}
$tamperedPreflight = $controller->transferPreflight(content_request('POST', '/admin/transfer/preflight/cms-export-tampered-content.zip', [], [
    '_csrf' => $csrf,
]));
content_check($tamperedPreflight->status() === 302 && ($tamperedPreflight->headers()['Location'] ?? '') === '/admin/transfer?preflight_failed=1', 'official export preflight rejects tampered content payload checksums');
$preflightFailureNotice = $controller->transferIndex(content_request('GET', '/admin/transfer', ['preflight_failed' => '1']));
content_check($preflightFailureNotice->status() === 200 && str_contains($preflightFailureNotice->body(), '导出包预检失败'), 'admin transfer shows Chinese failure notice after export preflight failure');
$blockedImport = $controller->transferImport(content_request('POST', '/admin/transfer/import', [], [
    '_csrf' => 'invalid',
    'import_payload' => '{"platform":"zblog","posts":[]}',
]));
content_check($blockedImport->status() === 403, 'admin content import requires CSRF protection');
$wordpressImportPayload = '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><item><title>Imported WP Article</title><link>https://old.example/imported-wp</link><description>Imported intro</description></item></channel></rss>';
$wordpressImport = $controller->transferImport(content_request('POST', '/admin/transfer/import', [], [
    '_csrf' => $csrf,
    'import_payload' => $wordpressImportPayload,
]));
$wordpressImported = $pdo->query("SELECT status, blocks_json FROM cms_contents WHERE title = 'Imported WP Article'")->fetch(PDO::FETCH_ASSOC) ?: [];
$wordpressBlocks = json_decode((string) ($wordpressImported['blocks_json'] ?? '[]'), true) ?: [];
content_check($wordpressImport->status() === 302 && ($wordpressImport->headers()['Location'] ?? '') === '/admin/transfer?imported=1' && ($wordpressImported['status'] ?? '') === 'draft' && ($wordpressBlocks[0]['data']['text'] ?? '') === 'Imported intro', 'admin transfer imports WordPress XML into draft Article content');
content_check((int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_platform = 'wordpress' AND source_url = 'https://old.example/imported-wp' AND target_url = '/articles/imported-wp'")->fetchColumn() === 1, 'admin transfer import records WordPress full source URL mapping to the real public route');
content_check((int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_platform = 'wordpress' AND source_url = '/imported-wp' AND target_url = '/articles/imported-wp'")->fetchColumn() === 1, 'admin transfer import records WordPress path URL mapping for frontend 301');
$zblogImportPayload = json_encode(['platform' => 'zblog', 'posts' => [['title' => 'Imported ZBlog Article', 'alias' => 'imported-zblog', 'content' => 'ZBlog body', 'url' => 'https://old.example/imported-zblog']]]);
$zblogImport = $controller->transferImport(content_request('POST', '/admin/transfer/import', [], [
    '_csrf' => $csrf,
    'import_payload' => (string) $zblogImportPayload,
]));
$zblogImported = $pdo->query("SELECT slug, status FROM cms_contents WHERE title = 'Imported ZBlog Article'")->fetch(PDO::FETCH_ASSOC) ?: [];
content_check($zblogImport->status() === 302 && ($zblogImported['slug'] ?? '') === 'imported-zblog' && ($zblogImported['status'] ?? '') === 'draft', 'admin transfer imports Z-Blog JSON into draft Article content');
content_check((int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_platform = 'zblogphp' AND source_url = '/imported-zblog' AND target_url = '/articles/imported-zblog'")->fetchColumn() === 1, 'admin transfer import records Z-Blog path URL mapping to the real public route');
$transferNotice = $controller->transferIndex(content_request('GET', '/admin/transfer', ['imported' => '2']));
content_check($transferNotice->status() === 200 && str_contains($transferNotice->body(), '导入成功，已创建 2 篇草稿内容'), 'admin transfer shows Chinese success notice after import');
$transferUrlMappings = $controller->transferIndex(content_request('GET', '/admin/transfer'));
content_check(
    $transferUrlMappings->status() === 200
    && str_contains($transferUrlMappings->body(), 'URL Mapping')
	    && str_contains($transferUrlMappings->body(), 'https://old.example/imported-wp')
	    && str_contains($transferUrlMappings->body(), '/articles/imported-wp')
	    && str_contains($transferUrlMappings->body(), '/admin/transfer/url-mappings/')
	    && str_contains($transferUrlMappings->body(), 'class="admin-danger"')
	    && str_contains($transferUrlMappings->body(), '删除映射只影响跳转，不会删除文章、页面、媒体、支付或发卡数据'),
	    'admin transfer page lists imported URL mappings with CSRF-protected delete actions'
	);
$unsupportedImport = $controller->transferImport(content_request('POST', '/admin/transfer/import', [], [
    '_csrf' => $csrf,
    'import_payload' => 'plain text is not a supported CMS import payload',
]));
content_check($unsupportedImport->status() === 422 && str_contains($unsupportedImport->body(), '导入失败'), 'admin transfer import shows Chinese error for unsupported formats');

$blocks = [
    ['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>Hello <b>CMS</b> onclick="bad"']],
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => 'Section']],
    ['type' => 'unordered_list', 'data' => ['items_text' => "One\nTwo"]],
    ['type' => 'ordered_list', 'data' => ['items_text' => "First\nSecond"]],
    ['type' => 'quote', 'data' => ['text' => 'Quote', 'cite' => 'Author']],
    ['type' => 'code', 'data' => ['language' => 'php', 'code' => '<?php echo "safe";']],
    ['type' => 'divider', 'data' => []],
    ['type' => 'button', 'data' => ['text' => 'Open', 'url' => 'https://example.com/path', 'target' => '_blank']],
    ['type' => 'table', 'data' => ['rows_text' => "A | B\n1 | 2"]],
    ['type' => 'raw_text', 'data' => ['text' => '<b>Plain</b>']],
];

$createArticle = $controller->contentStore(content_request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'save',
    'content_type' => 'article',
    'title' => 'Published Article',
    'slug' => 'published-article',
    'status' => 'published',
    'categories' => 'News',
    'tags' => 'CMS, Release',
    'seo_title' => 'Custom Article SEO',
    'seo_description' => 'Custom description',
    'canonical_url' => 'https://cms.example.test/articles/published-article',
    'robots_index' => '1',
    'robots_follow' => '1',
    'blocks' => $blocks,
]));
content_check($createArticle->status() === 302, 'creates and publishes an Article from the admin structured editor');

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$article = $repo->publicBySlug('article', 'published-article');
content_check(is_array($article) && count($article['blocks']) === 10 && ($article['blocks'][1]['data']['level'] ?? 0) === 2, 'saves and reloads all basic block schemas consistently');
$draftButton = $controller->contentStore(content_request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'draft',
    'content_type' => 'article',
    'title' => 'Draft Button',
    'slug' => 'draft-button',
    'status' => 'published',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Draft button content']]],
]));
$draftButtonRow = $pdo->query("SELECT status, blocks_json FROM cms_contents WHERE slug = 'draft-button'")->fetch(PDO::FETCH_ASSOC) ?: [];
$draftButtonBlocks = json_decode((string) ($draftButtonRow['blocks_json'] ?? '[]'), true) ?: [];
content_check($draftButton->status() === 302 && ($draftButtonRow['status'] ?? '') === 'draft' && ($draftButtonBlocks[0]['type'] ?? '') === 'paragraph', 'save draft action keeps compatible block data and forces draft status');
$publishButton = $controller->contentStore(content_request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'publish',
    'content_type' => 'article',
    'title' => 'Publish Button',
    'slug' => 'publish-button',
    'status' => 'draft',
    'blocks' => [['type' => 'quote', 'data' => ['text' => 'Publish button content']]],
]));
$publishButtonItem = $repo->publicBySlug('article', 'publish-button');
content_check($publishButton->status() === 302 && is_array($publishButtonItem) && ($publishButtonItem['status'] ?? '') === 'published' && ($publishButtonItem['blocks'][0]['type'] ?? '') === 'quote', 'publish action keeps compatible block data and forces published status');
$rendered = (new BlockRenderer())->render($article['blocks']);
content_check(str_contains($rendered, '<h2>Section</h2>') && str_contains($rendered, '<ul>') && str_contains($rendered, '<ol>') && str_contains($rendered, '<table>'), 'renders paragraph, heading, lists, quote, code, divider, button, table and raw text blocks');
content_check(!str_contains(strtolower($rendered), '<script') && !str_contains(strtolower($rendered), 'javascript:') && !str_contains(strtolower($rendered), 'onclick'), 'cleans script tags, event attributes and javascript URLs from output');

$createPage = $controller->contentStore(content_request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'save',
    'content_type' => 'page',
    'title' => 'About Page',
    'slug' => 'about',
    'status' => 'published',
    'robots_index' => '1',
    'robots_follow' => '1',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'About content']]],
]));
content_check($createPage->status() === 302 && is_array($repo->publicBySlug('page', 'about')), 'creates and publishes a Page from the admin structured editor');

for ($i = 1; $i <= 10; $i++) {
    $repo->create(
        'article',
        'News Archive Item ' . $i,
        'news-archive-item-' . $i,
        [['type' => 'paragraph', 'data' => ['text' => 'News archive item ' . $i]]],
        'published',
        ['robots_index' => false],
        ['News'],
        []
    );
}

$contentIndex = $controller->contentIndex();
content_check($contentIndex->status() === 200 && str_contains($contentIndex->body(), '内容管理') && str_contains($contentIndex->body(), '设置前台导航') && str_contains($contentIndex->body(), '文章') && str_contains($contentIndex->body(), '页面') && str_contains($contentIndex->body(), '已发布'), 'admin content list uses Chinese labels and links to navigation settings');

$twoBlocks = [
    ['type' => 'paragraph', 'data' => ['text' => 'Alpha']],
    ['type' => 'paragraph', 'data' => ['text' => 'Beta']],
];
$copy = $controller->contentStore(content_request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'copy:0', 'blocks' => $twoBlocks]));
$delete = $controller->contentStore(content_request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'delete:0', 'blocks' => $twoBlocks]));
$up = $controller->contentStore(content_request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'up:1', 'blocks' => $twoBlocks]));
$down = $controller->contentStore(content_request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'down:0', 'blocks' => $twoBlocks]));
$add = $controller->contentStore(content_request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'add', 'blocks' => [$twoBlocks[0]]]));
content_check($copy->status() === 200 && substr_count($copy->body(), 'Alpha') === 2, 'copies blocks in the editor');
content_check($delete->status() === 200 && !str_contains($delete->body(), 'Alpha') && str_contains($delete->body(), 'Beta'), 'deletes blocks in the editor');
content_check($up->status() === 200 && strpos($up->body(), 'Beta') < strpos($up->body(), 'Alpha'), 'moves blocks up in the editor');
content_check($down->status() === 200 && strpos($down->body(), 'Beta') < strpos($down->body(), 'Alpha'), 'moves blocks down in the editor');
content_check($add->status() === 200 && str_contains($add->body(), '区块 2'), 'adds blocks in the editor');

content_assert_throws(static fn () => $repo->create('article', 'Bad H7', 'bad-h7', [['type' => 'heading', 'data' => ['level' => 7, 'text' => 'Bad']]], 'draft'), 'rejects illegal block schema before save');
content_assert_throws(static fn () => $repo->create('article', 'Bad Button', 'bad-button', [['type' => 'button', 'data' => ['text' => 'Bad', 'url' => 'javascript:alert(1)']]], 'draft'), 'rejects unsafe button URLs before save');

$unknownId = $repo->create('article', 'Plugin Block', 'plugin-block', [['type' => 'shop_product', 'plugin_id' => 'shop', 'data' => ['sku' => 'ABC', 'label' => '<b>Keep</b>']]], 'published');
$unknown = $repo->find($unknownId);
content_check(($unknown['blocks'][0]['type'] ?? '') === 'missing-extension' && ($unknown['blocks'][0]['original']['data']['sku'] ?? '') === 'ABC', 'preserves unknown plugin block original data as missing-extension');

$draftId = $repo->create('article', 'Draft Article', 'draft-article', [['type' => 'paragraph', 'data' => ['text' => 'Draft body']]], 'draft');
$draft = $repo->find($draftId);
$app = Application::boot($root);
$importedPathRedirect = $app->handle(content_request('GET', '/imported-wp'));
content_check($importedPathRedirect->status() === 301 && ($importedPathRedirect->headers()['Location'] ?? '') === '/articles/imported-wp', 'imported WordPress path URL mapping redirects to the real public Article route');
content_check($app->handle(content_request('GET', '/articles/draft-article'))->status() === 404, 'does not expose draft content to visitors');
$previewWithoutToken = $app->handle(content_request('GET', '/preview/' . $draftId));
content_check($previewWithoutToken->status() === 403 && str_contains($previewWithoutToken->body(), '预览链接无效或已过期') && !str_contains($previewWithoutToken->body(), 'Forbidden'), 'rejects draft preview without token');
content_check($app->handle(content_request('GET', '/preview/' . $draftId, ['token' => (string) $draft['meta']['preview_token']]))->status() === 200, 'allows administrator-controlled token preview for drafts');
content_check($repo->publicBySlug('article', ' published-article') === null && $repo->publicBySlug('article', '../published-article') === null, 'public content lookup rejects non-canonical slugs before querying');
content_check($repo->publicBySlug('unknown_type', 'published-article') === null && $repo->publicCount('unknown_type') === 0 && $repo->publicList('unknown_type') === [], 'public content lookup rejects unregistered content types');
content_check($repo->termBySlug('category', '../news') === null && $repo->publicByTerm('category', '../news') === [] && $repo->publicCountByTerm('category', '../news') === 0, 'public term archives reject non-canonical term slugs');
content_check($repo->publicByTerm('category ', 'news') === [] && $repo->publicCountByTerm('category ', 'news') === 0, 'public term archives reject non-canonical taxonomy names');
content_check($repo->previewByToken($draftId, ' ' . (string) $draft['meta']['preview_token']) === null && $repo->previewByToken(0, (string) $draft['meta']['preview_token']) === null, 'preview lookup rejects non-canonical tokens and invalid ids');

content_assert_throws(static fn () => $repo->create('page', 'Duplicate Slug', 'published-article', [['type' => 'paragraph', 'data' => ['text' => 'Dup']]], 'draft'), 'rejects Article/Page slug conflicts');
content_assert_throws(static fn () => $repo->create('page', 'Admin Route', 'admin', [['type' => 'paragraph', 'data' => ['text' => 'Reserved']]], 'draft'), 'rejects reserved system route slugs');
content_assert_throws(static fn () => $repo->create('article', 'Bad Canonical', 'bad-canonical', [['type' => 'paragraph', 'data' => ['text' => 'Bad']]], 'published', ['canonical_url' => 'javascript:alert(1)']), 'rejects unsafe canonical URLs');

$articleResponse = $app->handle(content_request('GET', '/articles/published-article'));
$pageResponse = $app->handle(content_request('GET', '/about'));
$listResponse = $app->handle(content_request('GET', '/articles', ['page' => 1]));
$categoryResponse = $app->handle(content_request('GET', '/category/news'));
$tagResponse = $app->handle(content_request('GET', '/tag/cms'));
content_check($articleResponse->status() === 200 && str_contains($articleResponse->body(), '<h1>Published Article</h1>') && str_contains($articleResponse->body(), 'data-rendered-by="default"'), 'renders Article detail with the currently active theme');
content_check($pageResponse->status() === 200 && str_contains($pageResponse->body(), '<h1>About Page</h1>'), 'renders Page detail route');
content_check($listResponse->status() === 200 && str_contains($listResponse->body(), 'News Archive Item') && str_contains($listResponse->body(), 'data-page="1"'), 'renders article list and pagination context');
content_check($categoryResponse->status() === 200 && str_contains($categoryResponse->body(), 'News Archive Item'), 'renders category archive');
content_check($tagResponse->status() === 200 && str_contains($tagResponse->body(), 'Published Article'), 'renders tag archive');
content_check(str_contains($categoryResponse->body(), 'data-total="11"'), 'category archive pagination uses the full published term count');

content_check(str_contains($articleResponse->body(), '<title>Custom Article SEO</title>') && str_contains($articleResponse->body(), 'Custom description') && str_contains($articleResponse->body(), 'https://cms.example.test/articles/published-article') && str_contains($articleResponse->body(), 'og:type') && str_contains($articleResponse->body(), 'application/ld+json'), 'outputs custom SEO, canonical, Open Graph and Article structured data');
content_check(str_contains($pageResponse->body(), '<title>About Page</title>') && str_contains($pageResponse->body(), '<meta name="description" content="About Page">') && str_contains($pageResponse->body(), 'https://cms.example.test/about'), 'uses safe SEO defaults for Pages');

$sitemap = $app->handle(content_request('GET', '/sitemap.xml'));
$robots = $app->handle(content_request('GET', '/robots.txt'));
content_check(
    $sitemap->status() === 200
    && str_starts_with((string) ($sitemap->headers()['Content-Type'] ?? ''), 'application/xml')
    && (string) ($sitemap->headers()['X-Content-Type-Options'] ?? '') === 'nosniff'
    && str_contains($sitemap->body(), '<loc>https://cms.example.test/</loc>')
    && str_contains($sitemap->body(), '<loc>https://cms.example.test/articles</loc>')
    && str_contains($sitemap->body(), 'https://cms.example.test/articles/published-article')
    && str_contains($sitemap->body(), 'https://cms.example.test/about')
    && !str_contains($sitemap->body(), 'draft-article'),
    'sitemap contains stable site entrypoints and only public indexable content'
);
content_check(
    $robots->status() === 200
    && str_starts_with((string) ($robots->headers()['Content-Type'] ?? ''), 'text/plain')
    && (string) ($robots->headers()['X-Content-Type-Options'] ?? '') === 'nosniff'
    && str_contains($robots->body(), 'Sitemap: https://cms.example.test/sitemap.xml')
    && str_contains($robots->body(), "Disallow: /admin\n")
    && str_contains($robots->body(), "Disallow: /install\n")
    && str_contains($robots->body(), "Disallow: /recovery\n")
    && str_contains($robots->body(), "Disallow: /preview/\n"),
    'robots.txt points at sitemap.xml and excludes backend/system entrypoints'
);

$config['seo']['robots_index'] = false;
content_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$noIndexApp = Application::boot($root);
$noIndexArticle = $noIndexApp->handle(content_request('GET', '/articles/published-article'));
$noIndexList = $noIndexApp->handle(content_request('GET', '/articles'));
$noIndexSitemap = $noIndexApp->handle(content_request('GET', '/sitemap.xml'));
$noIndexRobots = $noIndexApp->handle(content_request('GET', '/robots.txt'));
content_check(
    $noIndexArticle->status() === 200
    && str_contains($noIndexArticle->body(), '<meta name="robots" content="noindex,nofollow">')
    && $noIndexList->status() === 200
    && str_contains($noIndexList->body(), '<meta name="robots" content="noindex,nofollow">'),
    'site-wide SEO robots_index=false forces public detail and list pages to noindex,nofollow'
);
content_check(
    $noIndexSitemap->status() === 200
    && !str_contains($noIndexSitemap->body(), 'https://cms.example.test/articles/published-article')
    && !str_contains($noIndexSitemap->body(), 'https://cms.example.test/about')
    && str_contains($noIndexRobots->body(), "Disallow: /\n")
    && !str_contains($noIndexRobots->body(), "Allow: /\n"),
    'site-wide SEO robots_index=false removes public content from sitemap and disallows crawling in robots.txt'
);
$config['seo']['robots_index'] = true;
content_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$app = Application::boot($root);

$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/legacy-post', ':target' => '/articles/published-article', ':source_platform' => 'legacy', ':created_at' => gmdate('c')]);
$legacyMappingId = (int) $pdo->query("SELECT id FROM cms_url_mappings WHERE source_url = '/legacy-post' ORDER BY id DESC LIMIT 1")->fetchColumn();
$mapped = $app->handle(content_request('GET', '/legacy-post'));
$publicApiIds = PublicApiRegistry::ids();
$contentContract = PublicApiRegistry::contract('content.repository');
$paymentContract = PublicApiRegistry::contract('payment.service');
content_check(PublicApiRegistry::CONTRACT_VERSION === '1.2.0' && count($publicApiIds) >= 6, 'Core public API registry declares the V1.2 contract version and stable API set');
content_check(is_array($contentContract) && ($contentContract['class'] ?? '') === ContentRepository::class && in_array('content.write', $contentContract['capabilities'] ?? [], true), 'Core public API registry exposes ContentRepository with declared content capabilities');
content_check(is_array($paymentContract) && ($paymentContract['class'] ?? '') === 'Cms\\Core\\Payment\\PaymentService' && in_array('payment.capture', $paymentContract['capabilities'] ?? [], true), 'Core public API registry exposes PaymentService with declared payment capabilities');
content_check(PublicApiRegistry::isPublicClass(ContentRepository::class) && !PublicApiRegistry::isPublicClass(AdminController::class), 'Core public API registry distinguishes extension-facing APIs from internal admin controllers');
content_check($mapped->status() === 301 && ($mapped->headers()['Location'] ?? '') === '/articles/published-article', 'returns 301 redirects from URL mappings');
content_check($repo->mappedUrl('https://old.example/legacy-post') === null, 'runtime URL Mapping rejects absolute external source URLs before lookup');
content_check($repo->mappedUrl('//old.example/legacy-post') === null, 'runtime URL Mapping rejects protocol-relative source URLs before lookup');
content_check($repo->mappedUrl('/legacy-post?next=/admin') === null, 'runtime URL Mapping rejects source paths with query strings before lookup');
$urlMappingRepo = new UrlMappingRepository($pdo);
content_assert_throws(static fn () => $urlMappingRepo->record('/unsafe-local?next=/admin', '/articles/published-article', 301, 'legacy'), 'URL Mapping repository rejects source paths with query strings before storage');
content_assert_throws(static fn () => $urlMappingRepo->record('/safe-local', 'https://evil.example/phish', 301, 'legacy'), 'URL Mapping repository rejects external target URLs before storage');
content_assert_throws(static fn () => $urlMappingRepo->record('/safe-local', '/articles/published-article', 200, 'legacy'), 'URL Mapping repository rejects non-redirect status codes before storage');
content_assert_throws(static fn () => $urlMappingRepo->record('/safe-local', '/articles/published-article', 301, 'bad platform'), 'URL Mapping repository rejects non-canonical source platform labels before storage');
$blockedMappingDelete = $app->handle(content_request('POST', '/admin/transfer/url-mappings/' . $legacyMappingId . '/delete', [], [
    '_csrf' => 'invalid',
]));
content_check($blockedMappingDelete->status() === 403 && (int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE id = " . $legacyMappingId)->fetchColumn() === 1, 'URL Mapping delete requires valid CSRF and leaves mapping intact on failure');
$deletedMapping = $app->handle(content_request('POST', '/admin/transfer/url-mappings/' . $legacyMappingId . '/delete', [], [
    '_csrf' => $csrf,
]));
content_check($deletedMapping->status() === 302 && ($deletedMapping->headers()['Location'] ?? '') === '/admin/transfer?url_mapping_deleted=1', 'URL Mapping delete uses POST action and redirects with Chinese success state');
content_check((int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE id = " . $legacyMappingId)->fetchColumn() === 0 && $app->handle(content_request('GET', '/legacy-post'))->status() === 404, 'deleted URL Mapping no longer redirects while target content remains untouched');
$urlMappingDeleteNotice = $controller->transferIndex(content_request('GET', '/admin/transfer', ['url_mapping_deleted' => '1']));
content_check($urlMappingDeleteNotice->status() === 200 && str_contains($urlMappingDeleteNotice->body(), 'URL Mapping 已删除。'), 'admin transfer shows Chinese success notice after URL Mapping delete');
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/unsafe-external-map', ':target' => 'https://evil.example/phish', ':source_platform' => 'corrupt', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/unsafe-protocol-map', ':target' => '//evil.example/phish', ':source_platform' => 'corrupt', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 200, :source_platform, :created_at)')
    ->execute([':source' => '/unsafe-status-map', ':target' => '/articles/published-article', ':source_platform' => 'corrupt', ':created_at' => gmdate('c')]);
content_check($app->handle(content_request('GET', '/unsafe-external-map'))->status() === 404, 'runtime URL Mapping ignores restored external redirect targets');
content_check($app->handle(content_request('GET', '/unsafe-protocol-map'))->status() === 404, 'runtime URL Mapping ignores restored protocol-relative redirect targets');
content_check($app->handle(content_request('GET', '/unsafe-status-map'))->status() === 404, 'runtime URL Mapping ignores restored non-redirect status codes');
content_check($app->handle(content_request('GET', '/missing-page'))->status() === 404, 'returns 404 for missing clean URLs');

$adminList = $controller->contentIndex();
content_check($adminList->status() === 200 && str_contains($adminList->body(), '/preview/' . $draftId . '?token='), 'admin content list exposes protected preview links');

$mediaSource = $root . '/storage/restore-image.png';
content_write($mediaSource, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
$mediaId = (new MediaLibrary($pdo, $root . '/content/uploads'))->registerLocalFile($mediaSource, 'restore-image.png');
$mediaArticleId = $repo->create('article', 'Restorable Image Article', 'restorable-image-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Restorable intro']],
    ['type' => 'image', 'data' => ['media_id' => $mediaId, 'alt' => 'Restore image', 'caption' => 'Image caption']],
], 'published');
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/restore-old-image', ':target' => '/articles/restorable-image-article', ':source_platform' => 'official-export-test', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_core_extension_data (extension_id, data_type, data_key, payload, status, created_at, updated_at) VALUES (:extension_id, :data_type, :data_key, :payload, :status, :created_at, :updated_at)')
    ->execute([
        ':extension_id' => 'missing.gallery-plugin',
        ':data_type' => 'block_payload',
        ':data_key' => 'gallery:restorable-image-article',
        ':payload' => json_encode(['media_ids' => [$mediaId], 'caption' => 'Dormant extension data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => 'dormant',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
$contentExportPackage = (new ExportPackageBuilder($root, $pdo, '1.2.0-content-restore-test'))->build('content-restore-test');

[$restoreRoot, $restoreSettings, $restorePdo] = content_prepare_restore_root($root, 'direct');
$directPreflight = (new OfficialExportContentImporter($restorePdo))->preflight($contentExportPackage);
$directImport = (new OfficialExportContentImporter($restorePdo))->importPackage($contentExportPackage);
$restoredRepo = new ContentRepository($restorePdo, ContentTypeRegistry::defaults());
$restoredArticle = $restoredRepo->publicBySlug('article', 'restorable-image-article');
$restoredPage = $restoredRepo->publicBySlug('page', 'about');
$restoredBlocks = is_array($restoredArticle) ? $restoredArticle['blocks'] : [];
$restoredMediaId = (int) ($restoredBlocks[1]['data']['media_id'] ?? 0);
content_check(
    $directPreflight['content_count'] >= 1
    && $directPreflight['media_count'] >= 1
    && $directPreflight['url_mapping_count'] >= 1,
    'official export content-data preflight counts content, media metadata and URL mappings'
);
content_check(
    ($directImport['content']['created'] ?? 0) >= 1
    && is_array($restoredArticle)
    && is_array($restoredPage)
    && $restoredMediaId > 0
    && (int) $restorePdo->query('SELECT COUNT(*) FROM cms_media_references WHERE content_id = ' . (int) ($restoredArticle['id'] ?? 0) . ' AND media_id = ' . $restoredMediaId)->fetchColumn() === 1,
    'official export content-data importer restores Article, Page and image media references'
);
content_check(
    ($directPreflight['extension_data_count'] ?? 0) >= 1
    && ($directImport['extension_data']['created'] ?? 0) >= 1
    && (int) $restorePdo->query("SELECT COUNT(*) FROM cms_core_extension_data WHERE extension_id = 'missing.gallery-plugin' AND data_type = 'block_payload' AND data_key = 'gallery:restorable-image-article' AND payload LIKE '%Dormant extension data%'")->fetchColumn() === 1,
    'official export content-data importer restores dormant extension data payloads without requiring plugin code'
);
content_check(
    (int) $restorePdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_url = '/restore-old-image' AND target_url = '/articles/restorable-image-article'")->fetchColumn() === 1,
    'official export content-data importer restores URL Mapping rows'
);
$directImportAgain = (new OfficialExportContentImporter($restorePdo))->importPackage($contentExportPackage);
content_check(
    ($directImportAgain['content']['updated'] ?? 0) >= ($directImport['content']['created'] ?? 0)
    && (int) $restorePdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_url = '/restore-old-image' AND target_url = '/articles/restorable-image-article'")->fetchColumn() === 1
    && (int) $restorePdo->query("SELECT COUNT(*) FROM cms_core_extension_data WHERE extension_id = 'missing.gallery-plugin' AND data_type = 'block_payload' AND data_key = 'gallery:restorable-image-article'")->fetchColumn() === 1,
    'official export content-data importer is idempotent for content, URL mappings and dormant extension data'
);
content_remove($restoreRoot);

$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/restore-unsafe-target', ':target' => 'https://evil.example/phish', ':source_platform' => 'corrupt-package-test', ':created_at' => gmdate('c')]);
$badUrlMappingPackage = (new ExportPackageBuilder($root, $pdo, '1.2.0-content-restore-bad-url-map-test'))->build('content-restore-bad-url-map-test');
$badUrlMappingPackageName = basename($badUrlMappingPackage);
$badUrlAdminPreflight = $controller->transferPreflight(content_request('POST', '/admin/transfer/preflight/' . $badUrlMappingPackageName, [], [
    '_csrf' => $csrf,
]));
$badUrlAdminPreflightNotice = $controller->transferIndex(content_request('GET', '/admin/transfer', ['preflight_failed' => '1', 'reason' => 'url_mapping']));
content_check(
    $badUrlAdminPreflight->status() === 302
    && ($badUrlAdminPreflight->headers()['Location'] ?? '') === '/admin/transfer?preflight_failed=1&reason=url_mapping'
    && str_contains($badUrlAdminPreflightNotice->body(), 'URL Mapping 数据校验失败'),
    'admin official export preflight reports URL Mapping validation failures with a safe Chinese reason'
);
[$badUrlRestoreRoot, $badUrlRestoreSettings, $badUrlRestorePdo] = content_prepare_restore_root($root, 'bad-url-map');
$badUrlImporter = new OfficialExportContentImporter($badUrlRestorePdo);
content_assert_export_throws(static fn () => $badUrlImporter->preflight($badUrlMappingPackage), 'official export content-data preflight rejects unsafe URL Mapping targets before import');
content_assert_export_throws(static fn () => $badUrlImporter->importPackage($badUrlMappingPackage), 'official export content-data importer rejects unsafe URL Mapping targets with a clear validation error');
content_check(
    (int) $badUrlRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn() === 0
    && (int) $badUrlRestorePdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_url = '/restore-unsafe-target'")->fetchColumn() === 0,
    'official export content-data importer rolls back content and URL mappings when URL Mapping validation fails'
);
content_remove($badUrlRestoreRoot);

[$adminRestoreRoot, $adminRestoreSettings, $adminRestorePdo] = content_prepare_restore_root($root, 'admin');
$adminExportName = basename($contentExportPackage);
copy($contentExportPackage, $adminRestoreRoot . '/storage/exports/' . $adminExportName);
copy($badUrlMappingPackage, $adminRestoreRoot . '/storage/exports/' . $badUrlMappingPackageName);
content_write($adminRestoreRoot . '/storage/exports/cms-export-admin-bad.zip', 'not a zip');
$adminRestoreController = content_controller($adminRestoreRoot);
$adminRestorePage = $adminRestoreController->transferIndex(content_request('GET', '/admin/transfer'));
$adminRestoreBlocked = $adminRestoreController->transferRestoreContent(content_request('POST', '/admin/transfer/restore-content/' . $adminExportName, [], [
    '_csrf' => $csrf . '-invalid',
]));
$adminRestoreContentCountAfterBlocked = (int) $adminRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn();
$adminRestoreFailed = $adminRestoreController->transferRestoreContent(content_request('POST', '/admin/transfer/restore-content/cms-export-admin-bad.zip', [], [
    '_csrf' => $csrf,
]));
$adminRestoreFailureNotice = $adminRestoreController->transferIndex(content_request('GET', '/admin/transfer', ['content_restore_failed' => '1']));
$adminRestoreUrlMappingFailed = $adminRestoreController->transferRestoreContent(content_request('POST', '/admin/transfer/restore-content/' . $badUrlMappingPackageName, [], [
    '_csrf' => $csrf,
]));
$adminRestoreUrlMappingFailureNotice = $adminRestoreController->transferIndex(content_request('GET', '/admin/transfer', ['content_restore_failed' => '1', 'reason' => 'url_mapping']));
$adminRestore = $adminRestoreController->transferRestoreContent(content_request('POST', '/admin/transfer/restore-content/' . $adminExportName, [], [
    '_csrf' => $csrf,
]));
$adminRestoreLocation = (string) ($adminRestore->headers()['Location'] ?? '');
parse_str((string) parse_url($adminRestoreLocation, PHP_URL_QUERY), $adminRestoreQuery);
$adminRestoreNotice = $adminRestoreController->transferIndex(content_request('GET', '/admin/transfer', $adminRestoreQuery));
content_check(
    $adminRestorePage->status() === 200
    && str_contains($adminRestorePage->body(), '/admin/transfer/restore-content/' . rawurlencode($adminExportName))
    && str_contains($adminRestorePage->body(), '恢复内容数据'),
    'admin transfer export row exposes CSRF-protected official content-data restore action'
);
content_check($adminRestoreBlocked->status() === 403, 'admin content-data restore rejects invalid CSRF');
content_check(
    $adminRestoreContentCountAfterBlocked === 0,
    'admin content-data restore does not import rows after invalid CSRF'
);
content_check(
    $adminRestoreFailed->status() === 302
    && ($adminRestoreFailed->headers()['Location'] ?? '') === '/admin/transfer?content_restore_failed=1'
    && $adminRestoreFailureNotice->status() === 200
    && str_contains($adminRestoreFailureNotice->body(), '内容数据恢复失败'),
    'admin content-data restore redirects with Chinese failure notice for invalid packages'
);
content_check(
    $adminRestoreUrlMappingFailed->status() === 302
    && ($adminRestoreUrlMappingFailed->headers()['Location'] ?? '') === '/admin/transfer?content_restore_failed=1&reason=url_mapping'
    && str_contains($adminRestoreUrlMappingFailureNotice->body(), 'URL Mapping 数据校验失败')
    && (int) $adminRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn() === 0,
    'admin content-data restore reports URL Mapping validation failures and leaves content unchanged'
);
content_check(
    $adminRestore->status() === 302
    && str_starts_with($adminRestoreLocation, '/admin/transfer?content_restored=1')
    && (int) $adminRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn() === 1
    && (int) $adminRestorePdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_url = '/restore-old-image'")->fetchColumn() === 1
    && str_contains($adminRestoreNotice->body(), '内容数据恢复完成'),
    'admin content-data restore imports official export content and shows Chinese success notice'
);
content_check(
    (int) $adminRestorePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'transfer.content_data_restored'")->fetchColumn() === 1
    && (int) $adminRestorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === 0
    && (int) $adminRestorePdo->query('SELECT COUNT(*) FROM cms_card_products')->fetchColumn() === 0,
    'admin content-data restore writes audit and does not restore payment or Card Delivery business rows'
);
content_remove($adminRestoreRoot);

[$routeRestoreRoot, $routeRestoreSettings, $routeRestorePdo] = content_prepare_restore_root($root, 'route');
copy($contentExportPackage, $routeRestoreRoot . '/storage/exports/' . $adminExportName);
$routeRestoreApp = Application::boot($routeRestoreRoot);
$routeRestoreBlocked = $routeRestoreApp->handle(content_request('POST', '/admin/transfer/restore-content/' . $adminExportName, [], [
    '_csrf' => 'invalid',
]));
$routeRestoreContentCountAfterBlocked = (int) $routeRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn();
$routeRestore = $routeRestoreApp->handle(content_request('POST', '/admin/transfer/restore-content/' . $adminExportName, [], [
    '_csrf' => $csrf,
]));
content_check(
    $routeRestoreBlocked->status() === 403
    && $routeRestoreContentCountAfterBlocked === 0,
    'registered admin content-data restore route rejects invalid CSRF without importing rows'
);
content_check(
    $routeRestore->status() === 302
    && str_starts_with((string) ($routeRestore->headers()['Location'] ?? ''), '/admin/transfer?content_restored=1')
    && (int) $routeRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn() === 1
    && (int) $routeRestorePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'transfer.content_data_restored'")->fetchColumn() === 1,
    'registered admin content-data restore route imports content and records audit'
);
content_remove($routeRestoreRoot);

[$cliRestoreRoot, $cliRestoreSettings, $cliRestorePdo] = content_prepare_restore_root($root, 'cli');
$preflightCommand = 'CMS_ROOT_OVERRIDE=' . escapeshellarg($cliRestoreRoot) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/cli.php') . ' preflight-content-data ' . escapeshellarg($contentExportPackage);
$preflightOutput = [];
$preflightCode = 1;
exec($preflightCommand, $preflightOutput, $preflightCode);
$preflightJson = json_decode(implode("\n", $preflightOutput), true);
$importCommand = 'CMS_ROOT_OVERRIDE=' . escapeshellarg($cliRestoreRoot) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/cli.php') . ' import-content-data ' . escapeshellarg($contentExportPackage);
$importOutput = [];
$importCode = 1;
exec($importCommand, $importOutput, $importCode);
$importJson = json_decode(implode("\n", $importOutput), true);
content_check(
    $preflightCode === 0
    && is_array($preflightJson)
    && ($preflightJson['status'] ?? '') === 'Verified'
    && (int) ($preflightJson['content_count'] ?? 0) >= 1,
    'Core CLI preflights official export content data without writing rows'
);
content_check(
    $importCode === 0
    && is_array($importJson)
    && ($importJson['status'] ?? '') === 'Completed'
    && (int) $cliRestorePdo->query("SELECT COUNT(*) FROM cms_contents WHERE slug = 'restorable-image-article'")->fetchColumn() === 1
    && (int) $cliRestorePdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE source_url = '/restore-old-image'")->fetchColumn() === 1,
    'Core CLI restores official export content data into a clean CMS database'
);
content_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_card_products')->fetchColumn() === 0
    && (int) $restorePdo->query('SELECT COUNT(*) FROM cms_card_products')->fetchColumn() === 0
    && (int) $cliRestorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === 0,
    'official export content-data restore does not create Card Delivery products or Payment ledger rows'
);
content_remove($cliRestoreRoot);

content_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " content editor and SEO checks failed.\n");
    exit(1);
}

echo "Content editor, routing and SEO tests passed.\n";

<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\FrontendExtensionRenderer;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Support\PublicApiRegistry;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$fixture = frontend_extension_fixture_root();
$root = $fixture['root'];
$pdo = $fixture['pdo'];

try {
    $manager = new PluginManager(
        $root . '/content/plugins',
        $pdo,
        new FileLogger($root . '/storage/logs/app.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        new PluginRuntimeRegistry(),
        new OfficialPluginRegistry($root, $pdo),
        null,
        Settings::load($root),
    );
    $discovered = $manager->discover();
    $check(isset($discovered['official.ai-customer-service']), 'official.ai-customer-service is discoverable through the unified official registry.');
    $check(!isset($discovered['official.fake-ai-service']), 'unregistered official.* trusted plugin is rejected during discovery.');

    $manager->syncDiscovered();
    $row = $pdo->query("SELECT source, review_status, table_prefixes_json FROM cms_plugins WHERE plugin_id = 'official.ai-customer-service'")->fetch();
    $prefixes = is_array($row) ? json_decode((string) ($row['table_prefixes_json'] ?? '[]'), true) : [];
    $check(is_array($row) && ($row['source'] ?? '') === 'bundled_official' && ($row['review_status'] ?? '') === 'official_trusted', 'official.ai-customer-service is installed as a trusted bundled official plugin record.');
    $check($prefixes === ['ai_customer_service_'], 'official.ai-customer-service receives only its registered table namespace.');

    $installer = new LocalPluginPackageInstaller($root, $pdo);
    $installer->enable('official.ai-customer-service', 1);
    $check($pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.ai-customer-service'")->fetchColumn() === PluginLifecycle::ENABLED, 'official.ai-customer-service enables through the normal plugin lifecycle.');

    $runtime = new PluginRuntimeRegistry();
    $booted = (new PluginManager(
        $root . '/content/plugins',
        $pdo,
        new FileLogger($root . '/storage/logs/app.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        $runtime,
        new OfficialPluginRegistry($root, $pdo),
        null,
        Settings::load($root),
    ))->bootEnabled();
    $html = (new FrontendExtensionRenderer($runtime, new FileLogger($root . '/storage/logs/app.log')))->inject(
        new Request('GET', '/', [], [], []),
        Response::html('<!doctype html><html><head></head><body><main>普通主题页面</main></body></html>')
    )->body();
    $check($booted === 1 && substr_count($html, 'public%2Fai-customer-service%2Fmount.js') === 1, 'ordinary themes get the AI customer service mount script once after the plugin is enabled.');

    $installer->disableWithDependents('official.ai-customer-service', 1, true);
    $runtime = new PluginRuntimeRegistry();
    (new PluginManager(
        $root . '/content/plugins',
        $pdo,
        new FileLogger($root . '/storage/logs/app.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        $runtime,
        new OfficialPluginRegistry($root, $pdo),
        null,
        Settings::load($root),
    ))->bootEnabled();
    $disabledHtml = (new FrontendExtensionRenderer($runtime, new FileLogger($root . '/storage/logs/app.log')))->inject(
        new Request('GET', '/', [], [], []),
        Response::html('<html><body><main>普通主题页面</main></body></html>')
    )->body();
    $check(!str_contains($disabledHtml, 'mount.js'), 'disabled plugins no longer inject frontend assets.');

    $installer->enable('official.ai-customer-service', 1);
    $check($pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.ai-customer-service'")->fetchColumn() === PluginLifecycle::ENABLED, 'official.ai-customer-service can be re-enabled through the normal lifecycle.');

    frontend_extension_boundary_tests($check, $root);
    $contract = PublicApiRegistry::contract('frontend.extensions');
    $check(($contract['class'] ?? '') === 'Cms\\Core\\Plugin\\PluginRuntimeRegistry' && in_array('frontend.asset', $contract['capabilities'] ?? [], true), 'frontend extension API is listed as a stable public Core contract.');
} finally {
    frontend_extension_remove_tree($root);
}

if ($failures > 0) {
    echo 'frontend_extension_api failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'frontend_extension_api: PASS' . PHP_EOL;

/** @return array{root:string,pdo:PDO} */
function frontend_extension_fixture_root(): array
{
    $root = sys_get_temp_dir() . '/daiying-frontend-extension-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/content/plugins/official.ai-customer-service/public/ai-customer-service', 0777, true);
    mkdir($root . '/content/plugins/official.fake-ai-service/public', 0777, true);
    mkdir($root . '/storage/logs', 0777, true);
    mkdir($root . '/storage/plugin-installs', 0777, true);
    file_put_contents($root . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.62'], 'security' => ['encryption_key' => 'test']];\n");
    file_put_contents($root . '/content/plugins/official.ai-customer-service/public/ai-customer-service/mount.js', 'window.DaiyingAiCustomerServiceMounted = true;');
    file_put_contents($root . '/content/plugins/official.ai-customer-service/plugin.php', "<?php\nreturn static function (\\Cms\\Core\\Plugin\\PluginContext \$context): void {\n    \$context->frontendScript('public/ai-customer-service/mount.js', 'mount');\n    \$context->frontendScript('public/ai-customer-service/mount.js', 'mount');\n};\n");
    file_put_contents($root . '/content/plugins/official.ai-customer-service/plugin.json', json_encode([
        'plugin_id' => 'official.ai-customer-service',
        'name' => 'Daiying AI Customer Service',
        'version' => '1.0.0',
        'author' => 'Daiying CMS',
        'core' => ['min' => '1.2.52'],
        'php' => '>=8.3.0',
        'entry' => 'plugin.php',
        'trust_level' => 'trusted_php',
        'capabilities' => ['frontend.asset', 'ai.use', 'network.external', 'ai_customer_service.chat'],
        'capability_namespaces' => ['ai_customer_service'],
        'table_prefixes' => ['ai_customer_service_'],
        'bundled' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($root . '/content/plugins/official.fake-ai-service/plugin.php', "<?php\nreturn static function (): void {};\n");
    file_put_contents($root . '/content/plugins/official.fake-ai-service/plugin.json', json_encode([
        'plugin_id' => 'official.fake-ai-service',
        'name' => 'Fake Official AI Service',
        'version' => '1.0.0',
        'author' => 'Unknown',
        'core' => ['min' => '1.2.52'],
        'php' => '>=8.3.0',
        'entry' => 'plugin.php',
        'trust_level' => 'trusted_php',
        'capabilities' => ['frontend.asset'],
        'table_prefixes' => ['ai_customer_service_'],
        'bundled' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE cms_plugins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plugin_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        version TEXT NOT NULL,
        author TEXT NOT NULL,
        status TEXT NOT NULL,
        trust_level TEXT NOT NULL,
        capabilities_json TEXT NOT NULL,
        installed_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        source TEXT,
        review_status TEXT,
        dependencies_json TEXT,
        optional_dependencies_json TEXT,
        declared_permissions_json TEXT,
        permission_grant_status TEXT,
        data_policy_json TEXT,
        data_schema_version TEXT,
        dormant_data_json TEXT,
        removed_at TEXT,
        last_error TEXT,
        table_prefixes_json TEXT
    )');
    $pdo->exec('CREATE TABLE cms_extension_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, extension_id TEXT, extension_type TEXT, source TEXT, market_id TEXT, version TEXT, installed_at TEXT, metadata_json TEXT)');
    $pdo->exec('CREATE TABLE cms_plugin_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, plugin_version TEXT, migration_id TEXT, checksum TEXT, status TEXT, affected_objects_json TEXT, started_at TEXT, completed_at TEXT, rollback_at TEXT, error_code TEXT, error_summary TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE cms_plugin_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, status TEXT, updated_at TEXT, cancel_requested_at TEXT, cancel_reason TEXT)');
    $pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE cms_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT)');

    return ['root' => $root, 'pdo' => $pdo];
}

function frontend_extension_boundary_tests(callable $check, string $root): void
{
    $runtime = new PluginRuntimeRegistry();
    $manifest = new PluginManifest('vendor.cookie-consent', 'Cookie Consent', '1.0.0', 'Vendor', '1.2.52', '>=8.3.0', 'plugin.php', 'api', ['frontend.asset'], [], [], 'plugin', false, [], [], '');
    $context = new PluginContext(
        $manifest,
        new EventDispatcher(),
        new BlockRegistry(),
        new PluginDataStore(new PDO('sqlite::memory:'), 'vendor.cookie-consent'),
        null,
        $runtime,
        pluginRoot: $root . '/content/plugins/official.ai-customer-service',
    );
    $context->frontendScript('public/ai-customer-service/mount.js', 'shared');
    $context->frontendStyle('public/ai-customer-service/mount.css', 'style');
    $context->frontendBodyEnd(static fn (): string => '<div id="cookie-consent-root"></div>', 'root');
    $context->frontendBodyEnd(static fn (): string => '<div id="duplicate-root"></div>', 'root');
    $runtime->frontendBodyEnd('vendor.broken-widget', static function (): string {
        throw new RuntimeException('frontend callback boom');
    }, 'throwing');
    $runtime->frontendAsset('vendor.second-widget', 'script', '/extension-assets/plugin/vendor.second-widget?file=public%2Fwidget.js', 'widget', ['module' => true]);

    $renderer = new FrontendExtensionRenderer($runtime, new FileLogger($root . '/storage/logs/app.log'));
    $html = $renderer->inject(new Request('GET', '/article/test', [], [], []), Response::html('<html><body><main>article</main></body></html>'))->body();
    $check(substr_count($html, 'public%2Fai-customer-service%2Fmount.js') === 1, 'duplicate frontend script registrations are rendered once.');
    $check(str_contains($html, 'public%2Fai-customer-service%2Fmount.css') && str_contains($html, 'rel="stylesheet"'), 'frontend styles can be registered by plugins.');
    $check(str_contains($html, 'vendor.second-widget') && str_contains($html, 'type="module"'), 'two plugins can register frontend assets without overriding each other.');
    $check(substr_count($html, 'cookie-consent-root') === 1 && !str_contains($html, 'duplicate-root'), 'duplicate frontend body-end callback keys are deduplicated.');
    $check(str_contains($html, '</body>') && str_contains($html, 'widget.js'), 'frontend snippets are injected at body end for normal HTML responses.');
    $check(str_contains((string) file_get_contents($root . '/storage/logs/app.log'), 'Plugin frontend extension failed'), 'frontend callback exceptions are isolated and logged.');

    $json = $renderer->inject(new Request('GET', '/api/v1', [], [], []), Response::json(['ok' => true]))->body();
    $check(!str_contains($json, 'extension-assets'), 'API JSON responses are not polluted by frontend injection.');
    $admin = $renderer->inject(new Request('GET', '/admin/settings', [], [], []), Response::html('<html><body>admin</body></html>'))->body();
    $check(!str_contains($admin, 'extension-assets'), 'admin HTML responses do not use frontend extension injection.');
    $xml = $renderer->inject(new Request('GET', '/sitemap.xml', [], [], []), new Response('<xml></xml>', 200, ['Content-Type' => 'application/xml']))->body();
    $check(!str_contains($xml, 'extension-assets'), 'XML responses are not injected.');
    $redirect = $renderer->inject(new Request('GET', '/login', [], [], []), Response::redirect('/admin/login'))->body();
    $check(!str_contains($redirect, 'extension-assets'), 'redirect responses are not injected.');
    $head = $renderer->inject(new Request('HEAD', '/', [], [], []), Response::html('<html><body>head</body></html>'))->body();
    $check(!str_contains($head, 'extension-assets'), 'HEAD/download-style requests are not injected.');
}

function frontend_extension_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

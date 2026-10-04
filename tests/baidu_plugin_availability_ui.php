<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionResult.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionTransportInterface.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Seo\SearchEngine\OfficialBaiduSubmitBridge;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

function baidu_availability_pdo(): PDO
{
    $db = sys_get_temp_dir() . '/daiying-baidu-availability-' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach ([
        '2026_08_12_000001_core_schema.php',
        '2026_08_12_000003_plugin_schema.php',
        '2026_08_15_000001_plugin_public_contract_schema.php',
        '2026_09_08_000003_foundation_system_services.php',
    ] as $migrationFile) {
        (require CMS_ROOT . '/system/migrations/' . $migrationFile)->up($pdo);
    }
    $pluginMigration = require CMS_ROOT . '/content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php';
    $pluginMigration['up']($pdo);
    return $pdo;
}

function baidu_availability_install(PDO $pdo, string $status): void
{
    $now = gmdate('c');
    $pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at) VALUES (:plugin_id, :name, :version, :author, :status, :trust_level, :capabilities_json, :installed_at, :updated_at)')
        ->execute([
            ':plugin_id' => 'official.seo.baidu-submit',
            ':name' => 'Baidu URL Submit',
            ':version' => '0.1.0-alpha.2',
            ':author' => 'Daiying CMS',
            ':status' => $status,
            ':trust_level' => 'trusted_php',
            ':capabilities_json' => json_encode(['seo.manage', 'seo.submit', 'queue.register', 'network.external'], JSON_UNESCAPED_SLASHES),
            ':installed_at' => $now,
            ':updated_at' => $now,
        ]);
}

function baidu_availability_configure(PDO $pdo, string $siteUrl, bool $enabled, ?string $token): void
{
    require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php';
    $class = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionRepository';
    $repo = new $class($pdo, new PluginSecretStore($pdo, 'unit-secret-key'));
    $repo->saveSettings($siteUrl, $enabled, 1800, $token);
}

function baidu_availability_temp_root(bool $route): string
{
    $root = sys_get_temp_dir() . '/daiying-baidu-root-' . bin2hex(random_bytes(4));
    $plugin = $root . '/content/plugins/official.seo.baidu-submit';
    mkdir($plugin, 0777, true);
    $manifest = [
        'plugin_id' => 'official.seo.baidu-submit',
        'name' => 'Baidu URL Submit',
        'version' => '0.1.0-alpha.2',
        'author' => 'Daiying CMS',
        'entry' => 'plugin.php',
        'admin_routes' => $route ? ['/admin/seo/baidu-submit'] : [],
    ];
    file_put_contents($plugin . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
    file_put_contents($plugin . '/plugin.php', '<?php return static function (): void {};');
    return $root;
}

function baidu_availability_html(array $status): string
{
    $admin = new AdminController(Settings::fromArray(['database' => ['dsn' => 'sqlite::memory:'], 'site' => ['url' => 'https://www.daiyingcms.com']]), new FileLogger(sys_get_temp_dir() . '/daiying-baidu-availability-ui.log'), CMS_ROOT);
    $method = new ReflectionMethod(AdminController::class, 'seoSearchEnginesHtml');
    return (string) $method->invoke($admin, $status, [], '');
}

$pdo = baidu_availability_pdo();
baidu_availability_install($pdo, 'Enabled');
baidu_availability_configure($pdo, 'https://www.daiyingcms.com', true, 'plugin-owned-token');
$connected = (new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key'))->status();
$html = baidu_availability_html($connected);
$check(($connected['status'] ?? '') === 'connected' && !empty($connected['management_available']), 'installed + enabled + configured + route available is Connected');
$check(str_contains($html, '管理百度推送插件') && str_contains($html, '/admin/seo/baidu-submit'), 'Connected state renders a valid manage button');

$pdo = baidu_availability_pdo();
baidu_availability_install($pdo, 'Enabled');
baidu_availability_configure($pdo, 'https://www.daiyingcms.com', true, null);
$notConfigured = (new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key'))->status();
$html = baidu_availability_html($notConfigured);
$check(($notConfigured['status'] ?? '') === 'not_configured' && ($notConfigured['status_label'] ?? '') === 'Not Configured', 'enabled route with missing token is Not Configured');
$check(str_contains($html, '管理百度推送插件') && str_contains($html, '/admin/seo/baidu-submit'), 'Not Configured state allows manage button for token setup');

$pdo = baidu_availability_pdo();
baidu_availability_install($pdo, 'Disabled');
baidu_availability_configure($pdo, 'https://www.daiyingcms.com', true, 'plugin-owned-token');
$disabled = (new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key'))->status();
$html = baidu_availability_html($disabled);
$check(($disabled['status'] ?? '') === 'plugin_disabled' && empty($disabled['management_available']), 'installed + disabled is Plugin Disabled');
$check(!str_contains($html, 'href="/admin/seo/baidu-submit"') && str_contains($html, '百度推送插件未启用'), 'Disabled state does not render dead manage link');

$pdo = baidu_availability_pdo();
baidu_availability_configure($pdo, 'https://www.shoe-zy.com', true, 'plugin-owned-token');
$notInstalled = (new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key'))->status();
$html = baidu_availability_html($notInstalled);
$check(($notInstalled['status'] ?? '') === 'plugin_not_installed' && empty($notInstalled['management_available']), 'missing cms_plugins row is Plugin Not Installed');
$check(!str_contains($html, 'href="/admin/seo/baidu-submit"') && str_contains($html, '百度推送插件未安装'), 'Not Installed state does not render dead manage link');

$pdo = baidu_availability_pdo();
baidu_availability_install($pdo, 'Enabled');
baidu_availability_configure($pdo, 'https://www.daiyingcms.com', true, 'plugin-owned-token');
$routeMissingRoot = baidu_availability_temp_root(false);
$routeMissing = (new OfficialBaiduSubmitBridge($pdo, $routeMissingRoot, 'unit-secret-key'))->status();
$html = baidu_availability_html($routeMissing);
$check(($routeMissing['status'] ?? '') === 'integration_error' && empty($routeMissing['management_available']), 'enabled plugin with missing route is Integration Error');
$check(!str_contains($html, 'href="/admin/seo/baidu-submit"') && str_contains($html, '百度推送插件路由不可用'), 'Route missing state does not render dead manage link');

$siteA = baidu_availability_pdo();
baidu_availability_install($siteA, 'Enabled');
baidu_availability_configure($siteA, 'https://www.daiyingcms.com', true, 'site-a-token');
$siteB = baidu_availability_pdo();
baidu_availability_install($siteB, 'Enabled');
baidu_availability_configure($siteB, 'https://www.shoe-zy.com', true, null);
$stateA = (new OfficialBaiduSubmitBridge($siteA, CMS_ROOT, 'unit-secret-key'))->status();
$stateB = (new OfficialBaiduSubmitBridge($siteB, CMS_ROOT, 'unit-secret-key'))->status();
$check(($stateA['status'] ?? '') === 'connected' && ($stateA['site'] ?? '') === 'https://www.daiyingcms.com', 'site A keeps its own configured plugin state');
$check(($stateB['status'] ?? '') === 'not_configured' && ($stateB['site'] ?? '') === 'https://www.shoe-zy.com', 'site B does not inherit site A token/config/log state');

$coreTokenSecrets = (int) $siteA->query("SELECT COUNT(*) FROM cms_plugin_secrets WHERE plugin_id = 'core.seo.search_engine.baidu'")->fetchColumn();
$check($coreTokenSecrets === 0, 'Core still does not own a Baidu token');
$check(!class_exists('Cms\\Core\\Seo\\SearchEngine\\BaiduSearchResourceProvider'), 'Core direct Baidu provider remains absent');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' Baidu plugin availability checks failed.' . PHP_EOL);
    exit(1);
}

echo 'Baidu plugin availability UI checks passed.' . PHP_EOL;

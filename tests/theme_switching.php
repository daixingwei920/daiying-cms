<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Theme\ThemeManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function theme_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function theme_remove(string $path): void
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

function theme_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
}

function theme_manifest(string $root, string $id, array $extra = []): void
{
    $manifest = array_replace_recursive([
        'theme_id' => $id,
        'name' => ucfirst($id) . ' Theme',
        'version' => '1.0.0',
        'author' => 'Theme Tests',
        'core' => ['min' => '1.2.0-rc1', 'max' => '1.x'],
        'content_types' => ['article', 'page'],
        'recommended_plugins' => [],
        'required_plugins' => [],
        'settings_schema' => [
            'accent_color' => ['type' => 'string', 'default' => '#111111'],
        ],
    ], $extra);
    theme_write($root . '/content/themes/' . $id . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    theme_write($root . '/content/themes/' . $id . '/templates/home.php', <<<'PHP'
<?php
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
?>
<main data-theme="<?= $context->e($context->theme->manifest->id) ?>" data-accent="<?= $context->e($context->setting('accent_color', 'none')) ?>"><?= $context->e($context->theme->manifest->name) ?> Home</main>
PHP);
}

function theme_controller(string $root): AdminController
{
    return new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root);
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-theme-switch-' . bin2hex(random_bytes(4));
theme_remove($root);
foreach (['config', 'storage/logs', 'content/themes', 'content/plugins', 'content/uploads', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}

$dbPath = $root . '/storage/theme.sqlite';
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $dbPath, 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Theme Switch Site', 'url' => '', 'id' => 'theme-switch', 'secret' => 'theme-secret'];
$config['theme'] = [
    'active' => 'alpha',
    'settings' => [
        'alpha' => ['accent_color' => '#aa0000'],
        'beta' => ['accent_color' => '#0000bb'],
    ],
];
theme_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

theme_manifest($root, 'alpha');
theme_manifest($root, 'beta');
theme_manifest($root, 'need_plugin', ['required_plugins' => ['needed_plugin']]);
theme_manifest($root, 'too_new', ['core' => ['min' => '9.0.0', 'max' => '9.x']]);
theme_write($root . '/content/themes/broken/theme.json', '{"theme_id":');
theme_manifest($root, 'missing_template');
unlink($root . '/content/themes/missing_template/templates/home.php');
theme_manifest($root, 'safe', ['settings_schema' => []]);

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
$pdo->exec("INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at) VALUES ('needed_plugin', 'Needed Plugin', '1.0.0', 'Tests', 'Enabled', 'api', '[]', 'now', 'now')");
file_put_contents($root . '/storage/installed.lock', '{}');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();

$index = theme_controller($root)->themeIndex();
theme_check($index->status() === 200 && str_contains($index->body(), '当前') && str_contains($index->body(), 'Beta Theme') && str_contains($index->body(), 'Too_new Theme') && str_contains($index->body(), 'Invalid theme'), 'lists current, available, compatible and invalid themes');

$activateGet = theme_controller($root)->themeActivate(new Request('GET', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'beta']));
theme_check($activateGet->status() === 405 && ($activateGet->headers()['Allow'] ?? '') === 'POST' && str_contains($activateGet->body(), '必须通过 POST') && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'rejects non-POST theme activation before writing config');

$activateBeta = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'beta']));
theme_check($activateBeta->status() === 302 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'beta' && (int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'theme.activated' ORDER BY id DESC LIMIT 1")->fetchColumn() === 1, 'switches theme A to theme B and records administrator audit');

$settingsGet = theme_controller($root)->themeSettingsSave(new Request('GET', '/admin/themes/settings', [], ['_csrf' => $csrf, 'theme_id' => 'beta', 'settings' => ['accent_color' => '#badbad']]));
theme_check($settingsGet->status() === 405 && ($settingsGet->headers()['Allow'] ?? '') === 'POST' && str_contains($settingsGet->body(), '必须通过 POST') && ((require $root . '/config/app.php')['theme']['settings']['beta']['accent_color'] ?? '') !== '#badbad', 'rejects non-POST theme settings save before writing config');

$saveBeta = theme_controller($root)->themeSettingsSave(new Request('POST', '/admin/themes/settings', [], ['_csrf' => $csrf, 'theme_id' => 'beta', 'settings' => ['accent_color' => '#00bbff']]));
$configAfterBeta = require $root . '/config/app.php';
theme_check($saveBeta->status() === 302 && ($configAfterBeta['theme']['settings']['alpha']['accent_color'] ?? '') === '#aa0000' && ($configAfterBeta['theme']['settings']['beta']['accent_color'] ?? '') === '#00bbff' && (int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'theme.settings_saved' ORDER BY id DESC LIMIT 1")->fetchColumn() === 1, 'saves theme B settings without deleting theme A settings and records administrator audit');

$activateAlpha = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'alpha']));
$alphaRuntime = (new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->load('alpha');
theme_check($activateAlpha->status() === 302 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha' && ($alphaRuntime->settings['accent_color'] ?? '') === '#aa0000', 'switches theme B back to theme A and restores theme A settings');

$betaRuntime = (new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->load('beta');
theme_check(($betaRuntime->settings['accent_color'] ?? '') === '#00bbff' && !isset($betaRuntime->settings['alpha']), 'theme B cannot read or overwrite theme A private settings');

$tooNew = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'too_new']));
theme_check($tooNew->status() === 400 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'rejects incompatible themes');

$broken = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'broken']));
theme_check($broken->status() === 400 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'rejects broken theme manifests');

$pdo->exec("UPDATE cms_plugins SET status = 'Disabled' WHERE plugin_id = 'needed_plugin'");
$missingDep = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'need_plugin']));
theme_check($missingDep->status() === 400 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'rejects themes with missing required plugin dependencies');

$badCsrf = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => 'bad', 'theme_id' => 'beta']));
theme_check($badCsrf->status() === 403 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'rejects theme activation with invalid CSRF token');

unset($_SESSION['admin_user']);
$noPermission = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'beta']));
theme_check($noPermission->status() === 302 && (($noPermission->headers()['Location'] ?? '') === '/admin/login'), 'rejects theme activation without admin permission');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];

chmod($root . '/config', 0555);
$writeFail = theme_controller($root)->themeActivate(new Request('POST', '/admin/themes/activate', [], ['_csrf' => $csrf, 'theme_id' => 'beta']));
chmod($root . '/config', 0755);
theme_check($writeFail->status() === 400 && ((require $root . '/config/app.php')['theme']['active'] ?? '') === 'alpha', 'keeps original theme when config write fails');

$configMissing = require $root . '/config/app.php';
$configMissing['theme']['active'] = 'missing_current';
theme_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configMissing, true) . ";\n");
$fallbackRuntime = (new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->activeWithPlugins([]);
theme_check($fallbackRuntime->manifest->id === 'safe' && isset($configMissing['theme']['settings']['alpha']), 'falls back to safe theme when current theme is missing while preserving settings');

$configBeta = require $root . '/config/app.php';
$configBeta['theme']['active'] = 'beta';
theme_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configBeta, true) . ";\n");
$front = Application::boot($root)->handle(new Request('GET', '/'));
theme_check($front->status() === 200 && str_contains($front->body(), 'Beta Theme Home') && str_contains($front->body(), 'data-theme="beta"'), 'front page renders the newly active theme');

$configDep = require $root . '/config/app.php';
$configDep['theme']['active'] = 'need_plugin';
theme_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configDep, true) . ";\n");
$pdo->exec("UPDATE cms_plugins SET status = 'Disabled' WHERE plugin_id = 'needed_plugin'");
$fallbackFront = Application::boot($root)->handle(new Request('GET', '/'));
theme_check($fallbackFront->status() === 200 && str_contains($fallbackFront->body(), 'Safe Theme Home'), 'uses safe theme when active theme dependency plugin is disabled');

theme_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " theme switching checks failed.\n");
    exit(1);
}

echo "Theme switching tests passed.\n";

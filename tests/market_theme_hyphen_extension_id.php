<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Market\ExtensionDependency;
use Cms\Core\Market\ExtensionRemovalService;
use Cms\Core\Market\MarketException;
use Cms\Core\Market\MarketPackageManifest;
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Theme\ThemeManifest;
use Cms\Core\Theme\ThemeManager;

$failures = 0;

function market_theme_id_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$manifest = MarketPackageManifest::fromArray([
    'extension_id' => 'daiying-video',
    'type' => 'theme',
    'version' => '1.0.0',
    'source' => 'official_market',
    'review_status' => 'published',
    'files' => [
        'content/themes/daiying-video/theme.json' => hash('sha256', '{}'),
        'content/themes/daiying-video/templates/home.php' => hash('sha256', '<?php'),
    ],
    'dependencies' => [
        ['extension_id' => 'official.video-collector', 'type' => 'plugin', 'version' => '*'],
    ],
]);

market_theme_id_check($manifest->extensionId === 'daiying-video', 'allows official theme IDs with hyphens');
market_theme_id_check($manifest->type === 'theme', 'keeps theme package type');
market_theme_id_check(count($manifest->dependencies) === 1, 'allows dotted and hyphenated plugin dependency IDs');

$dependency = ExtensionDependency::fromArray(['extension_id' => 'daiying-video', 'type' => 'theme', 'version' => '*']);
market_theme_id_check($dependency->extensionId === 'daiying-video', 'allows hyphenated theme dependency IDs');

$theme = ThemeManifest::fromArray([
    'theme_id' => 'daiying-video',
    'name' => 'Daiying Video Theme',
    'version' => '1.0.0',
    'author' => 'Daiying CMS',
    'core' => ['min' => '1.2.0', 'max' => '1.999.999'],
]);
market_theme_id_check($theme->id === 'daiying-video', 'allows hyphenated theme manifests');

$tempRoot = sys_get_temp_dir() . '/daiying-theme-hyphen-' . bin2hex(random_bytes(4));
mkdir($tempRoot . '/themes/daiying-video/templates', 0777, true);
file_put_contents($tempRoot . '/themes/daiying-video/theme.json', json_encode([
    'theme_id' => 'daiying-video',
    'name' => 'Daiying Video Theme',
    'version' => '1.0.0',
    'author' => 'Daiying CMS',
    'core' => ['min' => '1.2.0', 'max' => '1.999.999'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($tempRoot . '/themes/daiying-video/templates/home.php', '<?php echo "ok";');
$logger = new FileLogger($tempRoot . '/logs');
$manager = new ThemeManager($tempRoot . '/themes', Settings::fromArray(['app' => ['version' => '1.2.20'], 'theme' => ['active' => 'safe']]), $logger);
$runtime = $manager->load('daiying-video');
market_theme_id_check($runtime->manifest->id === 'daiying-video', 'loads hyphenated theme directories');

$controller = new AdminController(Settings::fromArray([]), $logger, $tempRoot);
$method = new ReflectionMethod(AdminController::class, 'themeIdFromSettingsPath');
market_theme_id_check($method->invoke($controller, '/admin/themes/daiying-video/settings') === 'daiying-video', 'parses hyphenated theme settings paths');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_extension_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    extension_id TEXT NOT NULL,
    extension_type TEXT NOT NULL,
    source TEXT NOT NULL,
    market_id TEXT,
    version TEXT NOT NULL,
    installed_at TEXT NOT NULL,
    metadata_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE cms_market_install_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_id TEXT NOT NULL,
    extension_id TEXT NOT NULL,
    extension_type TEXT NOT NULL,
    status TEXT NOT NULL,
    plan_json TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
(new ExtensionRemovalService($tempRoot))->uninstall('daiying-video', 'theme', $pdo);
$source = $pdo->query("SELECT extension_id, extension_type, source FROM cms_extension_sources ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
market_theme_id_check(($source['extension_id'] ?? '') === 'daiying-video' && ($source['source'] ?? '') === 'uninstalled', 'uninstalls hyphenated theme IDs');

foreach ([
    ['extension_id' => 'Daiying-video', 'type' => 'theme', 'files' => []],
    ['extension_id' => 'daiying.video', 'type' => 'theme', 'files' => []],
    ['extension_id' => 'daiying/video', 'type' => 'theme', 'files' => []],
] as $badManifest) {
    try {
        MarketPackageManifest::fromArray($badManifest + ['version' => '1.0.0']);
        market_theme_id_check(false, 'rejects invalid theme id: ' . $badManifest['extension_id']);
    } catch (MarketException) {
        market_theme_id_check(true, 'rejects invalid theme id: ' . $badManifest['extension_id']);
    }
}

try {
    MarketPackageManifest::fromArray([
        'extension_id' => 'daiying-video',
        'type' => 'theme',
        'version' => '1.0.0',
        'files' => ['content/themes/daiying_novel/theme.json' => hash('sha256', '{}')],
    ]);
    market_theme_id_check(false, 'rejects files outside hyphenated theme prefix');
} catch (MarketException) {
    market_theme_id_check(true, 'rejects files outside hyphenated theme prefix');
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($tempRoot);

if ($failures > 0) {
    echo 'Market theme hyphen extension ID tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Market theme hyphen extension ID tests passed.' . PHP_EOL;

<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Theme\LocalThemePackageInstaller;
use Cms\Core\Theme\ThemeManager;

require __DIR__ . '/theme_t1_common.php';

function theme_zip_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function theme_zip_make(string $path, string $themeId = 'demo_theme'): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test theme ZIP.');
    }
    $zip->addFromString($themeId . '/theme.json', json_encode([
        'theme_id' => $themeId,
        'name' => 'Demo Theme',
        'version' => '1.0.0',
        'author' => 'Tests',
        'core' => ['min' => '1.2.0-rc1', 'max' => '1.x'],
        'content_types' => ['article', 'page'],
        'recommended_plugins' => [],
        'settings_schema' => [],
    ], JSON_UNESCAPED_SLASHES));
    $zip->addFromString($themeId . '/templates/home.php', "<?php echo 'Demo home';\n");
    $zip->close();
}

$root = theme_t1_root('theme-zip');
$settings = Settings::load($root);
$installer = new LocalThemePackageInstaller($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$zipPath = $root . '/storage/demo-theme.zip';
theme_zip_make($zipPath);

$result = $installer->install($zipPath);
theme_zip_check($result['theme_id'] === 'demo_theme' && is_file($root . '/content/themes/demo_theme/theme.json'), 'installs a valid local theme ZIP into content/themes');
theme_zip_check((new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->load('demo_theme')->manifest->name === 'Demo Theme', 'installed theme passes ThemeManager manifest validation');

try {
    $installer->install($zipPath);
    theme_zip_check(false, 'rejects duplicate theme id');
} catch (Throwable $exception) {
    theme_zip_check(str_contains($exception->getMessage(), '同 ID 主题已存在'), 'rejects duplicate theme id');
}

$unsafe = $root . '/storage/unsafe-theme.zip';
$zip = new ZipArchive();
$zip->open($unsafe, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('../evil/theme.json', '{}');
$zip->close();
try {
    $installer->install($unsafe);
    theme_zip_check(false, 'rejects theme ZIP path traversal');
} catch (Throwable $exception) {
    theme_zip_check(str_contains($exception->getMessage(), '不安全路径'), 'rejects theme ZIP path traversal');
}

$tooLarge = $root . '/storage/too-large-theme.zip';
file_put_contents($tooLarge, str_repeat('x', 10_485_761));
try {
    $installer->install($tooLarge);
    theme_zip_check(false, 'rejects theme ZIP files over the upload package size limit');
} catch (Throwable $exception) {
    theme_zip_check(str_contains($exception->getMessage(), '大小限制'), 'rejects theme ZIP files over the upload package size limit');
}

if (method_exists(ZipArchive::class, 'setExternalAttributesName')) {
    $symlink = $root . '/storage/symlink-theme.zip';
    $zip = new ZipArchive();
    $zip->open($symlink, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('link_theme/theme.json', json_encode([
        'theme_id' => 'link_theme',
        'name' => 'Link Theme',
        'version' => '1.0.0',
        'author' => 'Tests',
        'core' => ['min' => '1.2.0-rc1', 'max' => '1.x'],
        'content_types' => ['article', 'page'],
    ], JSON_UNESCAPED_SLASHES));
    $zip->addFromString('link_theme/templates/home.php', "<?php echo 'Link';\n");
    $zip->addFromString('link_theme/link', 'target');
    $zip->setExternalAttributesName('link_theme/link', ZipArchive::OPSYS_UNIX, 0120000 << 16);
    $zip->close();
    try {
        $installer->install($symlink);
        theme_zip_check(false, 'rejects symlink entries in theme ZIP files');
    } catch (Throwable $exception) {
        theme_zip_check(str_contains($exception->getMessage(), '链接或特殊文件'), 'rejects symlink entries in theme ZIP files');
    }
} else {
    theme_zip_check(true, 'skips theme symlink ZIP check when ZipArchive external attributes API is unavailable');
}

$phtml = $root . '/storage/phtml-theme.zip';
theme_zip_make($phtml, 'phtml_theme');
$zip = new ZipArchive();
$zip->open($phtml);
$zip->addFromString('phtml_theme/templates/alt.phtml', '<?php echo "unsafe";');
$zip->close();
try {
    $installer->install($phtml);
    theme_zip_check(false, 'rejects theme ZIP files with PHP alternate executable extensions');
} catch (Throwable $exception) {
    theme_zip_check(str_contains($exception->getMessage(), '高风险可执行文件'), 'rejects theme ZIP files with PHP alternate executable extensions');
}

$binary = $root . '/storage/binary-theme.zip';
theme_zip_make($binary, 'binary_theme');
$zip = new ZipArchive();
$zip->open($binary);
$zip->addFromString('binary_theme/assets/tool.exe', 'MZ');
$zip->close();
try {
    $installer->install($binary);
    theme_zip_check(false, 'rejects theme ZIP files with executable binary extensions');
} catch (Throwable $exception) {
    theme_zip_check(str_contains($exception->getMessage(), '高风险可执行文件'), 'rejects theme ZIP files with executable binary extensions');
}

theme_t1_remove($root);
echo "Theme ZIP install tests passed.\n";

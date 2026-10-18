<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Market\ExtensionCompatibilityChecker;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Market\MarketPackageManifest;
use Cms\Core\Market\VersionConstraint;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$directory = sys_get_temp_dir() . '/daiying-market-php-min-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create test directory.');
}

try {
    $id = 'demo_market_php';
    $plugin = json_encode([
        'plugin_id' => $id,
        'name' => 'PHP minimum test',
        'version' => '0.1.0',
        'author' => 'Daiying',
        'type' => 'plugin',
        'entry' => 'plugin.php',
        'core' => ['min' => '1.2.84'],
        'php' => '8.3.0',
    ], JSON_THROW_ON_ERROR);
    $entry = "<?php\nreturn static function (): void {};\n";
    $zipPath = $directory . '/plugin.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Cannot create test ZIP.');
    }
    $zip->addFromString($id . '/plugin.json', $plugin);
    $zip->addFromString($id . '/plugin.php', $entry);
    $zip->close();

    $authorization = new InstallAuthorization('', '', gmdate('c', time() + 3600), hash_file('sha256', $zipPath));
    $plan = (new MarketPackageInstaller($directory))->verifyAndPlan($zipPath, $authorization);
    $check($plan['php_constraint'] === '>=8.3.0', 'Raw plugin PHP field is a minimum version.');
    $check($plan['core_constraint'] === '>=1.2.84', 'Raw plugin Core minimum is retained.');
    $check(VersionConstraint::matches('8.3.0', $plan['php_constraint']), 'PHP 8.3.0 is accepted.');
    $check(VersionConstraint::matches('8.5.9', $plan['php_constraint']), 'Newer PHP 8.5.9 is accepted.');
    $check(!VersionConstraint::matches('8.2.30', $plan['php_constraint']), 'Older PHP 8.2 is rejected.');

    $explicit = new MarketPackageManifest($id, 'plugin', '0.1.0', 'official_market', 'published', [], '>=1.2.84', '>=8.3.0');
    if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
        (new ExtensionCompatibilityChecker('1.2.84'))->assertCompatible($explicit);
        $checks++;
    }
} finally {
    @unlink($zipPath);
    @rmdir($directory);
}

echo "PASS ({$checks} checks)\n";

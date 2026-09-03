<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Market\ExtensionDependency;
use Cms\Core\Market\MarketException;
use Cms\Core\Market\MarketPackageManifest;

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

if ($failures > 0) {
    echo 'Market theme hyphen extension ID tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Market theme hyphen extension ID tests passed.' . PHP_EOL;

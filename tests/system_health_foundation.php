<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Support\SystemHealthDoctor;
use Cms\Core\Support\SystemHealthService;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$db = sys_get_temp_dir() . '/daiying-health-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_09_08_000003_foundation_system_services.php',
    '2026_09_08_000005_core_scheduler_foundation.php',
] as $migrationFile) {
    $migration = require CMS_ROOT . '/system/migrations/' . $migrationFile;
    $migration->up($pdo);
}

$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db],
    'site' => ['url' => 'https://www.daiyingcms.com'],
    'app' => ['version' => '1.2.29', 'debug' => false, 'mode' => 'NORMAL'],
]);

$doctor = (new SystemHealthDoctor(CMS_ROOT, $settings, $pdo))->diagnose();
$seo = [];
foreach ($doctor['checks'] ?? [] as $item) {
    if (($item['id'] ?? '') === 'seo.sitemap') {
        $seo = $item;
        break;
    }
}
$check(($seo['status'] ?? '') === 'PASS', 'SystemHealthDoctor treats sitemap/robots as dynamic Core routes');

$serviceChecks = (new SystemHealthService(CMS_ROOT, $settings))->checks();
$byId = [];
foreach ($serviceChecks as $item) {
    $byId[$item['id']] = $item;
}
$check(($byId['queue.table']['status'] ?? '') === 'PASS', 'SystemHealthService checks Core queue table');
$check(($byId['scheduler.table']['status'] ?? '') === 'PASS', 'SystemHealthService checks Core scheduler table');

@unlink($db);

if ($failures > 0) {
    echo 'System health foundation tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'System health foundation tests PASS' . PHP_EOL;

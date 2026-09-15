<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentScheduler;
use Cms\Core\Database\ConnectionFactory;

$root = getenv('CMS_ROOT_OVERRIDE') !== false ? (string) getenv('CMS_ROOT_OVERRIDE') : dirname(__DIR__);

$autoload = $root . '/system/core/Bootstrap/autoload.php';
$pointerFile = $root . '/storage/updates/current-release.json';
if (is_file($pointerFile)) {
    $pointer = json_decode((string) file_get_contents($pointerFile), true);
    $candidate = is_array($pointer) ? (string) ($pointer['path'] ?? '') . '/system/core/Bootstrap/autoload.php' : '';
    if ($candidate !== '' && is_file($candidate)) {
        $autoload = $candidate;
    }
}

require $autoload;

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'TXT'
Usage: php scripts/publish_scheduled_content.php [now] [limit]

Arguments:
  now    Optional ISO-8601 timestamp used as the scheduler clock. Defaults to current time.
  limit  Optional maximum number of due scheduled items to publish. Defaults to 50.

Configure cron from the CMS root, for example:
  * * * * cd /path/to/php-cms && php scripts/publish_scheduled_content.php
TXT;
    echo PHP_EOL;
    exit(0);
}

$now = $argv[1] ?? null;
$limit = max(1, (int) ($argv[2] ?? 50));
$settings = Settings::load($root);
$result = (new ContentScheduler(ConnectionFactory::make($settings)))->publishDue($now, $limit);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

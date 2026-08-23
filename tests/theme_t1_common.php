<?php

declare(strict_types=1);

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;

define('THEME_T1_SOURCE_ROOT', dirname(__DIR__));

require_once THEME_T1_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

function theme_t1_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function theme_t1_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function theme_t1_copy_dir(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
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

function theme_t1_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
}

function theme_t1_root(string $suffix): string
{
    $root = sys_get_temp_dir() . '/cms-theme-t1-' . $suffix . '-' . bin2hex(random_bytes(4));
    theme_t1_remove($root);
    foreach (['config', 'storage/logs', 'content/themes', 'content/plugins', 'content/uploads', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    theme_t1_copy_dir(THEME_T1_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
    theme_t1_copy_dir(THEME_T1_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');

    $config = require THEME_T1_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/theme-t1.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['site'] = ['name' => 'Daiying 站点', 'url' => 'https://theme.example.test', 'id' => 'theme-t1', 'secret' => 'theme-secret'];
    $config['theme'] = ['active' => 'default', 'settings' => ['default' => ['accent_color' => '#1f6feb', 'site_description' => '中文内容发布站']]];
    theme_t1_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];
    foreach (glob(THEME_T1_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
    file_put_contents($root . '/storage/installed.lock', '{}');

    return $root;
}

function theme_t1_app(string $root): Application
{
    return Application::boot($root);
}

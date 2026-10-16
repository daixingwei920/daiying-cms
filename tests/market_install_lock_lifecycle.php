<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Market\MarketInstallLock;

if (!function_exists('pcntl_fork')) {
    echo "market_install_lock_lifecycle: SKIP (pcntl unavailable)\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/daiying-market-lock-' . bin2hex(random_bytes(5));
mkdir($root, 0700);
$path = $root . '/install.lock';
$first = new MarketInstallLock($path);
$first->acquire();
$inode = fileinode($path);

$child = pcntl_fork();
if ($child === -1) {
    throw new RuntimeException('Cannot create independent lock contender.');
}
if ($child === 0) {
    $handle = fopen($path, 'c');
    file_put_contents($root . '/opened', '1');
    for ($i = 0; $i < 500 && !is_file($root . '/go'); $i++) {
        usleep(1000);
    }
    $acquired = flock($handle, LOCK_EX | LOCK_NB);
    file_put_contents($root . '/held', $acquired ? '1' : '0');
    for ($i = 0; $i < 500 && !is_file($root . '/finish'); $i++) {
        usleep(1000);
    }
    if ($acquired) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    exit($acquired ? 0 : 1);
}

try {
    for ($i = 0; $i < 500 && !is_file($root . '/opened'); $i++) {
        usleep(1000);
    }
    if (!is_file($root . '/opened')) {
        throw new RuntimeException('Independent contender did not open the lock inode.');
    }
    $first->release();
    file_put_contents($root . '/go', '1');
    for ($i = 0; $i < 500 && !is_file($root . '/held'); $i++) {
        usleep(1000);
    }
    if (!is_file($root . '/held') || file_get_contents($root . '/held') !== '1') {
        throw new RuntimeException('Independent contender did not acquire the original inode.');
    }
    clearstatcache(true, $path);
    if (!is_file($path) || fileinode($path) !== $inode) {
        throw new RuntimeException('Market lock file inode changed after release.');
    }
    $third = new MarketInstallLock($path);
    try {
        $third->acquire();
        throw new RuntimeException('Third contender acquired a second lock inode.');
    } catch (\Cms\Core\Market\MarketException) {
        echo "[PASS] third contender rejects while independent process holds the original inode\n";
    } finally {
        $third->release();
    }
    file_put_contents($root . '/finish', '1');
    pcntl_waitpid($child, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('Independent contender did not exit cleanly.');
    }
    $after = new MarketInstallLock($path);
    $after->acquire();
    $after->release();
    clearstatcache(true, $path);
    if (fileinode($path) !== $inode) {
        throw new RuntimeException('Market lock inode changed on subsequent acquisition.');
    }
    echo "[PASS] persistent market lock inode remains stable across release and reacquire\n";
    chmod($path, 0444);
    $readOnly = new MarketInstallLock($path);
    try {
        $readOnly->acquire();
        $code = 'require ' . var_export(dirname(__DIR__) . '/system/core/Bootstrap/autoload.php', true) . '; '
            . '$lock = new Cms\\Core\\Market\\MarketInstallLock($argv[1]); '
            . 'try { $lock->acquire(); exit(1); } catch (Cms\\Core\\Market\\MarketException) { exit(0); }';
        $process = proc_open([PHP_BINARY, '-r', $code, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot create read-only lock contender.');
        }
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Read-only lock did not reject an independent contender: ' . $error);
        }
        echo "[PASS] existing read-only lock file excludes an independent process\n";
    } finally {
        $readOnly->release();
        chmod($path, 0644);
    }
    echo "market_install_lock_lifecycle: PASS\n";
} finally {
    file_put_contents($root . '/finish', '1');
    pcntl_waitpid($child, $status, WNOHANG);
    foreach (['opened', 'go', 'held', 'finish', 'install.lock'] as $file) {
        @unlink($root . '/' . $file);
    }
    @rmdir($root);
}

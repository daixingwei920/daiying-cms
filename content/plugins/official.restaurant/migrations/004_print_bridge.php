<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $columns = $pdo->query($sqlite ? 'PRAGMA table_info(restaurant_print_jobs)' : 'SHOW COLUMNS FROM restaurant_print_jobs')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, $sqlite ? 'name' : 'Field');
    foreach (['dedup_key' => 'VARCHAR(191) NULL', 'source_job_id' => 'BIGINT NULL', 'job_kind' => "VARCHAR(32) NOT NULL DEFAULT 'order'", 'dispatch_started_at' => 'DATETIME NULL'] as $name => $definition) {
        if (!in_array($name, $names, true)) {
            $pdo->exec('ALTER TABLE restaurant_print_jobs ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
    $seen = [];
    foreach ($pdo->query('SELECT id, dedup_key FROM restaurant_print_jobs WHERE dedup_key IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $existing) {
        $seen[$existing['dedup_key']] = $existing['id'];
    }
    $update = $pdo->prepare('UPDATE restaurant_print_jobs SET dedup_key = :key WHERE id = :id');
    foreach ($pdo->query('SELECT id, ticket_id, printer_id, station_code FROM restaurant_print_jobs WHERE dedup_key IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $key = 'ticket:' . $job['ticket_id'] . ':printer:' . ($job['printer_id'] ?? 0) . ':station:' . $job['station_code'];
        if (isset($seen[$key])) {
            $hold = $pdo->prepare("UPDATE restaurant_print_jobs SET status = 'needs_review', last_error = 'LEGACY_DUPLICATE_REQUIRES_REVIEW' WHERE id IN (:first_id, :second_id) AND status <> 'printed'");
            $hold->execute([':first_id' => $seen[$key], ':second_id' => $job['id']]);
            $key = 'legacy-duplicate:' . $job['id'];
        } else {
            $seen[$key] = $job['id'];
        }
        $update->execute([':key' => $key, ':id' => $job['id']]);
    }
    if ($sqlite) {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_restaurant_print_dedup ON restaurant_print_jobs (dedup_key)');
    } elseif ($pdo->query("SHOW INDEX FROM restaurant_print_jobs WHERE Key_name = 'uq_restaurant_print_dedup'")->fetch() === false) {
        $pdo->exec('CREATE UNIQUE INDEX uq_restaurant_print_dedup ON restaurant_print_jobs (dedup_key)');
    }
    $deviceColumns = $pdo->query($sqlite ? 'PRAGMA table_info(restaurant_print_devices)' : 'SHOW COLUMNS FROM restaurant_print_devices')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('bridge_last_seen_at', array_column($deviceColumns, $sqlite ? 'name' : 'Field'), true)) {
        $pdo->exec('ALTER TABLE restaurant_print_devices ADD COLUMN bridge_last_seen_at DATETIME NULL');
    }
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_print_audit (id $id, job_id BIGINT NULL, action VARCHAR(64) NOT NULL, actor VARCHAR(191) NOT NULL, message VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL)");
};

return ['id' => 'official_restaurant_004_print_bridge', 'affected_objects' => ['table:restaurant_print_jobs', 'table:restaurant_print_devices', 'table:restaurant_print_audit'], 'up' => $up, 'down' => static function (PDO $pdo): void {}];

<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $createIndex = static function (PDO $pdo, string $table, string $index, string $columns) use ($driver): void {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . $columns);
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_ai_modules (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        provider_type VARCHAR(32) NOT NULL DEFAULT 'custom',
        protocol VARCHAR(64) NOT NULL DEFAULT 'openai_compatible',
        endpoint VARCHAR(1024) NULL,
        model VARCHAR(191) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'disabled',
        billing_type VARCHAR(16) NOT NULL DEFAULT 'free',
        sort_order INT NOT NULL DEFAULT 0,
        capabilities_json $json NULL,
        public_config_json $json NULL,
        credential_ciphertext $text NULL,
        last_test_status VARCHAR(32) NULL,
        last_test_message VARCHAR(500) NULL,
        last_tested_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'commerce_ai_modules', 'idx_commerce_ai_modules_status', '(status, billing_type, sort_order)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_ai_invocations (
        id $id,
        module_id BIGINT NULL,
        module_name VARCHAR(191) NULL,
        task VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL,
        billing_type VARCHAR(16) NOT NULL,
        error_message VARCHAR(500) NULL,
        prompt_hash VARCHAR(64) NOT NULL,
        response_summary VARCHAR(500) NULL,
        created_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'commerce_ai_invocations', 'idx_commerce_ai_invocations_module', '(module_id, created_at)');
    $createIndex($pdo, 'commerce_ai_invocations', 'idx_commerce_ai_invocations_task', '(task, status, created_at)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS commerce_ai_invocations');
    $pdo->exec('DROP TABLE IF EXISTS commerce_ai_modules');
};

return [
    'id' => 'commerce_005_ai_modules',
    'affected_objects' => [
        'table:commerce_ai_modules',
        'table:commerce_ai_invocations',
    ],
    'up' => $up,
    'down' => $rollback,
];

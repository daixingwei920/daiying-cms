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

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_oauth_configs (
        id $id,
        provider VARCHAR(32) NOT NULL UNIQUE,
        status VARCHAR(32) NOT NULL DEFAULT 'disabled',
        client_id VARCHAR(500) NULL,
        tenant VARCHAR(191) NULL,
        scopes_json $json NULL,
        redirect_uri VARCHAR(1024) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_accounts (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        provider VARCHAR(32) NOT NULL,
        email VARCHAR(191) NOT NULL,
        display_name VARCHAR(191) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'connected',
        scopes_json $json NULL,
        access_token_expires_at DATETIME NULL,
        last_connected_at DATETIME NULL,
        last_sync_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(provider, email)
    )");
    $createIndex($pdo, 'mail_accounts', 'idx_mail_accounts_provider_status', '(provider, status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_messages (
        id $id,
        account_id BIGINT NOT NULL,
        remote_id VARCHAR(191) NOT NULL,
        thread_id VARCHAR(191) NULL,
        folder VARCHAR(64) NOT NULL DEFAULT 'inbox',
        sender_name VARCHAR(191) NULL,
        sender_email VARCHAR(191) NULL,
        subject VARCHAR(500) NULL,
        snippet VARCHAR(500) NULL,
        received_at DATETIME NULL,
        is_read INT NOT NULL DEFAULT 0,
        has_attachments INT NOT NULL DEFAULT 0,
        payload_json $text NULL,
        cached_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(account_id, remote_id)
    )");
    $createIndex($pdo, 'mail_messages', 'idx_mail_messages_account_received', '(account_id, folder, received_at)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS mail_messages');
    $pdo->exec('DROP TABLE IF EXISTS mail_accounts');
    $pdo->exec('DROP TABLE IF EXISTS mail_oauth_configs');
};

return [
    'id' => 'official_mail_001_mail_client',
    'affected_objects' => [
        'table:mail_oauth_configs',
        'table:mail_accounts',
        'table:mail_messages',
    ],
    'up' => $up,
    'down' => $rollback,
];

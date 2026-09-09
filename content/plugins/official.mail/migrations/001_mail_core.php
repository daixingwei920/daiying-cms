<?php

declare(strict_types=1);

return [
    'id' => 'mail_001_mail_core',
    'affected_objects' => [
        'table:mail_settings',
        'table:mail_messages',
    ],
    'up' => static function (PDO $pdo): void {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $createIndex = static function (string $table, string $index, string $columns) use ($pdo, $driver): void {
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_settings (
            id $id,
            status VARCHAR(32) NOT NULL DEFAULT 'disabled',
            transport VARCHAR(32) NOT NULL DEFAULT 'smtp',
            host VARCHAR(255) NOT NULL DEFAULT '',
            port INT NOT NULL DEFAULT 587,
            encryption VARCHAR(16) NOT NULL DEFAULT 'starttls',
            username VARCHAR(255) NOT NULL DEFAULT '',
            auth_mode VARCHAR(16) NOT NULL DEFAULT 'auto',
            from_email VARCHAR(255) NOT NULL DEFAULT '',
            from_name VARCHAR(191) NOT NULL DEFAULT '',
            reply_to VARCHAR(255) NOT NULL DEFAULT '',
            timeout_seconds INT NOT NULL DEFAULT 10,
            last_test_status VARCHAR(32) NULL,
            last_test_message VARCHAR(500) NULL,
            last_tested_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_messages (
            id $id,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(191) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            transport VARCHAR(32) NOT NULL DEFAULT 'smtp',
            error_message VARCHAR(500) NULL,
            provider_message_id VARCHAR(255) NULL,
            body_hash VARCHAR(64) NOT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");
        $createIndex('mail_messages', 'idx_mail_messages_status_created', '(status, created_at)');
        $createIndex('mail_messages', 'idx_mail_messages_recipient', '(recipient_email, created_at)');

        $stmt = $pdo->query('SELECT COUNT(*) FROM mail_settings');
        if ((int) $stmt->fetchColumn() === 0) {
            $now = gmdate('c');
            $pdo->prepare('INSERT INTO mail_settings (status, transport, host, port, encryption, username, auth_mode, from_email, from_name, reply_to, timeout_seconds, created_at, updated_at)
                VALUES (:status, :transport, :host, :port, :encryption, :username, :auth_mode, :from_email, :from_name, :reply_to, :timeout, :created_at, :updated_at)')
                ->execute([
                    ':status' => 'disabled',
                    ':transport' => 'smtp',
                    ':host' => '',
                    ':port' => 587,
                    ':encryption' => 'starttls',
                    ':username' => '',
                    ':auth_mode' => 'auto',
                    ':from_email' => '',
                    ':from_name' => '',
                    ':reply_to' => '',
                    ':timeout' => 10,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
        }
    },
    'down' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS mail_messages');
        $pdo->exec('DROP TABLE IF EXISTS mail_settings');
    },
];

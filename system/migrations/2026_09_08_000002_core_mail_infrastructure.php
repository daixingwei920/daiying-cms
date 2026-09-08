<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000002_core_mail_infrastructure';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_mail_settings (
                id INTEGER PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                provider_id VARCHAR(120) NOT NULL DEFAULT "smtp",
                smtp_host VARCHAR(255) NOT NULL DEFAULT "",
                smtp_port INTEGER NOT NULL DEFAULT 587,
                smtp_encryption VARCHAR(16) NOT NULL DEFAULT "tls",
                smtp_username VARCHAR(191) NOT NULL DEFAULT "",
                smtp_password_ciphertext ' . $longText . ' NULL,
                from_name VARCHAR(191) NOT NULL DEFAULT "",
                from_email VARCHAR(191) NOT NULL DEFAULT "",
                reply_to VARCHAR(191) NOT NULL DEFAULT "",
                timeout_seconds INTEGER NOT NULL DEFAULT 20,
                queue_enabled INTEGER NOT NULL DEFAULT 1,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        foreach ($this->mailSettingsColumns($longText) as $column => $definition) {
            if (!in_array($column, $this->columns($pdo, 'cms_core_mail_settings'), true)) {
                $pdo->exec('ALTER TABLE cms_core_mail_settings ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $stmt = $pdo->query('SELECT COUNT(*) FROM cms_core_mail_settings WHERE id = 1');
        if ((int) $stmt->fetchColumn() === 0) {
            $now = gmdate('c');
            $pdo->prepare(
                'INSERT INTO cms_core_mail_settings
                 (id, enabled, provider_id, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_ciphertext, from_name, from_email, reply_to, timeout_seconds, queue_enabled, created_at, updated_at)
                 VALUES (1, 0, "smtp", "", 587, "tls", "", "", "", "", "", 20, 1, :created_at, :updated_at)'
            )->execute([':created_at' => $now, ':updated_at' => $now]);
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_mail_templates (
                id ' . $idColumn . ',
                template_id VARCHAR(120) NOT NULL,
                locale VARCHAR(32) NOT NULL DEFAULT "default",
                subject VARCHAR(998) NOT NULL,
                html_body ' . $longText . ' NOT NULL,
                text_body ' . $longText . ' NOT NULL,
                variables_json ' . $longText . ' NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                owner VARCHAR(120) NOT NULL DEFAULT "core",
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createUnique($pdo, 'cms_mail_templates', 'idx_mail_templates_template_locale', ['template_id', 'locale']);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_mail_queue (
                id ' . $idColumn . ',
                provider_id VARCHAR(120) NOT NULL,
                event_id VARCHAR(120) NOT NULL DEFAULT "",
                status VARCHAR(32) NOT NULL DEFAULT "pending",
                payload_json ' . $longText . ' NOT NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                provider_message_id VARCHAR(191) NOT NULL DEFAULT "",
                last_error ' . $longText . ' NOT NULL,
                not_before VARCHAR(64) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                sent_at VARCHAR(64) NULL
            )'
        );
        $this->createIndex($pdo, 'cms_mail_queue', 'idx_mail_queue_status_not_before', ['status', 'not_before']);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_mail_events (
                id ' . $idColumn . ',
                event_id VARCHAR(120) NOT NULL,
                label VARCHAR(191) NOT NULL,
                owner VARCHAR(120) NOT NULL DEFAULT "core",
                variables_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createUnique($pdo, 'cms_mail_events', 'idx_mail_events_event_id', ['event_id']);
        $this->seedEvents($pdo);
    }

    /** @return array<string,string> */
    private function mailSettingsColumns(string $longText): array
    {
        return [
            'enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'provider_id' => 'VARCHAR(120) NOT NULL DEFAULT "smtp"',
            'smtp_host' => 'VARCHAR(255) NOT NULL DEFAULT ""',
            'smtp_port' => 'INTEGER NOT NULL DEFAULT 587',
            'smtp_encryption' => 'VARCHAR(16) NOT NULL DEFAULT "tls"',
            'smtp_username' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'smtp_password_ciphertext' => $longText . ' NULL',
            'from_name' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'from_email' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'reply_to' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'timeout_seconds' => 'INTEGER NOT NULL DEFAULT 20',
            'queue_enabled' => 'INTEGER NOT NULL DEFAULT 1',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ];
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC));
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param list<string> $columns */
    private function createUnique(\PDO $pdo, string $table, string $name, array $columns): void
    {
        $this->createIndex($pdo, $table, $name, $columns, true);
    }

    /** @param list<string> $columns */
    private function createIndex(\PDO $pdo, string $table, string $name, array $columns, bool $unique = false): void
    {
        $list = implode(', ', $columns);
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $list . ')');
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $name . ' ON ' . $table . ' (' . $list . ')');
        }
    }

    private function seedEvents(\PDO $pdo): void
    {
        $now = gmdate('c');
        $events = [
            'user.registered',
            'user.password_reset',
            'order.created',
            'order.paid',
            'order.refunded',
            'license.issued',
            'plugin.reviewed',
            'system.error',
            'system.update',
            'security.alert',
        ];
        $insert = $pdo->prepare(
            'INSERT INTO cms_mail_events (event_id, label, owner, variables_json, created_at, updated_at)
             VALUES (:event_id, :label, "core", :variables_json, :created_at, :updated_at)'
        );
        foreach ($events as $event) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM cms_mail_events WHERE event_id = :event_id');
            $stmt->execute([':event_id' => $event]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $insert->execute([
                ':event_id' => $event,
                ':label' => $event,
                ':variables_json' => '["site_name"]',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }
};

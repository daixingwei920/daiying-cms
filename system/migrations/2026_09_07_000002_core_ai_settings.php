<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_07_000002_core_ai_settings';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $decimal = $driver === 'sqlite' ? 'REAL' : 'DECIMAL(4,2)';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_ai_settings (
                id INTEGER PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                provider VARCHAR(64) NOT NULL DEFAULT "openai_compatible",
                base_url VARCHAR(2048) NOT NULL DEFAULT "",
                model VARCHAR(191) NOT NULL DEFAULT "",
                timeout_seconds INTEGER NOT NULL DEFAULT 30,
                max_tokens INTEGER NOT NULL DEFAULT 1024,
                temperature ' . $decimal . ' NOT NULL DEFAULT 0.7,
                api_key_ciphertext ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        foreach ($this->expectedColumns($longText, $decimal) as $column => $definition) {
            if (!in_array($column, $this->columns($pdo, 'cms_core_ai_settings'), true)) {
                $pdo->exec('ALTER TABLE cms_core_ai_settings ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $stmt = $pdo->query('SELECT COUNT(*) FROM cms_core_ai_settings WHERE id = 1');
        if ((int) $stmt->fetchColumn() === 0) {
            $now = gmdate('c');
            $pdo->prepare(
                'INSERT INTO cms_core_ai_settings
                 (id, enabled, provider, base_url, model, timeout_seconds, max_tokens, temperature, api_key_ciphertext, created_at, updated_at)
                 VALUES (1, 0, "openai_compatible", "", "", 30, 1024, 0.7, "", :created_at, :updated_at)'
            )->execute([':created_at' => $now, ':updated_at' => $now]);
        }
    }

    /** @return array<string,string> */
    private function expectedColumns(string $longText, string $decimal): array
    {
        return [
            'enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'provider' => 'VARCHAR(64) NOT NULL DEFAULT "openai_compatible"',
            'base_url' => 'VARCHAR(2048) NOT NULL DEFAULT ""',
            'model' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'timeout_seconds' => 'INTEGER NOT NULL DEFAULT 30',
            'max_tokens' => 'INTEGER NOT NULL DEFAULT 1024',
            'temperature' => $decimal . ' NOT NULL DEFAULT 0.7',
            'api_key_ciphertext' => $longText . ' NULL',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ];
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }
};

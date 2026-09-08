<?php

declare(strict_types=1);

use Cms\Core\Ai\AiProviderPresets;
use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000001_core_ai_provider_presets';
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
                adapter VARCHAR(64) NOT NULL DEFAULT "openai_compatible",
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
        if (!in_array('adapter', $this->columns($pdo, 'cms_core_ai_settings'), true)) {
            $pdo->exec('ALTER TABLE cms_core_ai_settings ADD COLUMN adapter VARCHAR(64) NOT NULL DEFAULT "openai_compatible"');
        }
        $stmt = $pdo->query('SELECT COUNT(*) FROM cms_core_ai_settings WHERE id = 1');
        if ((int) $stmt->fetchColumn() === 0) {
            $now = gmdate('c');
            $pdo->prepare(
                'INSERT INTO cms_core_ai_settings
                 (id, enabled, provider, adapter, base_url, model, timeout_seconds, max_tokens, temperature, api_key_ciphertext, created_at, updated_at)
                 VALUES (1, 0, "openai_compatible", "openai_compatible", "", "", 30, 1024, 0.7, "", :created_at, :updated_at)'
            )->execute([':created_at' => $now, ':updated_at' => $now]);
        }

        $rows = $pdo->query('SELECT id, provider, adapter FROM cms_core_ai_settings')->fetchAll();
        $stmt = $pdo->prepare('UPDATE cms_core_ai_settings SET provider = :provider, adapter = :adapter WHERE id = :id');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $provider = AiProviderPresets::normalize((string) ($row['provider'] ?? 'openai_compatible'));
            $adapter = AiProviderPresets::adapter($provider, (string) ($row['adapter'] ?? ''));
            $stmt->execute([
                ':provider' => $provider,
                ':adapter' => $adapter,
                ':id' => (int) ($row['id'] ?? 0),
            ]);
        }
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

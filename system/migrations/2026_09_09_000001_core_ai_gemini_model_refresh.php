<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_09_000001_core_ai_gemini_model_refresh';
    }

    public function up(\PDO $pdo): void
    {
        $tables = $this->tables($pdo);
        if (!in_array('cms_core_ai_settings', $tables, true)) {
            return;
        }

        $columns = $this->columns($pdo, 'cms_core_ai_settings');
        if (!in_array('provider', $columns, true) || !in_array('model', $columns, true)) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE cms_core_ai_settings
             SET model = "gemini-3.6-flash", updated_at = :updated_at
             WHERE provider = "gemini" AND (model = "" OR model = "gemini-2.5-flash")'
        );
        $stmt->execute([':updated_at' => gmdate('c')]);
    }

    /** @return list<string> */
    private function tables(\PDO $pdo): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) array_values($row)[0], $pdo->query('SHOW TABLES')->fetchAll());
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

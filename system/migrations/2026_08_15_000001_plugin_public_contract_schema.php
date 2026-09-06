<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_15_000001_plugin_public_contract_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';

        $columns = $this->columns($pdo, 'cms_plugins');
        if (!in_array('table_prefixes_json', $columns, true)) {
            $pdo->exec('ALTER TABLE cms_plugins ADD COLUMN table_prefixes_json ' . $longText . ' NULL');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_secrets (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                secret_key VARCHAR(191) NOT NULL,
                ciphertext ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createUnique($pdo, 'cms_plugin_secrets', 'idx_plugin_secrets_plugin_key', ['plugin_id', 'secret_key']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cms_plugin_secrets');
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }

    /** @param list<string> $columns */
    private function createUnique(\PDO $pdo, string $table, string $name, array $columns): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $list = implode(', ', $columns);
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $list . ')');
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . $list . ')');
        }
    }
};

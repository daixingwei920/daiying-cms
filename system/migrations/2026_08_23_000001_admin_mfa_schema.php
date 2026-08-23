<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_23_000001_admin_mfa_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $columns = $this->columns($pdo, 'cms_admin_users');
        foreach ([
            'mfa_totp_secret' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'mfa_enabled_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'mfa_recovery_codes_json' => $text . ' NULL',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_admin_users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $pdo->exec("UPDATE cms_admin_users SET mfa_recovery_codes_json = '[]' WHERE mfa_recovery_codes_json IS NULL OR mfa_recovery_codes_json = ''");
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $stmt->execute([':table' => $table]);

            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
    }
};

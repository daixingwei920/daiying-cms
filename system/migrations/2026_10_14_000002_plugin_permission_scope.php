<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_14_000002_plugin_permission_scope';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(cms_plugin_permission_grants)')->fetchAll(\PDO::FETCH_ASSOC);
            $exists = in_array('scope_sha256', array_column($columns, 'name'), true);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
            $stmt->execute([':table' => 'cms_plugin_permission_grants', ':column' => 'scope_sha256']);
            $exists = (int) $stmt->fetchColumn() > 0;
        }
        if (!$exists) {
            $pdo->exec('ALTER TABLE cms_plugin_permission_grants ADD COLUMN scope_sha256 VARCHAR(64) NULL');
        }
    }
};

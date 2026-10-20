<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_20_000001_member_editorial_access';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_member_roles (
            member_id INTEGER NOT NULL PRIMARY KEY,
            role_id VARCHAR(64) NOT NULL,
            assigned_by INTEGER NOT NULL,
            assigned_at VARCHAR(64) NOT NULL
        )');

        $columns = $driver === 'sqlite'
            ? $pdo->query('PRAGMA table_info(cms_contents)')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('SHOW COLUMNS FROM cms_contents')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn (array $column): string => (string) ($column[$driver === 'sqlite' ? 'name' : 'Field'] ?? ''), $columns);
        if (!in_array('owner_member_id', $names, true)) {
            $pdo->exec('ALTER TABLE cms_contents ADD COLUMN owner_member_id INTEGER NULL');
        }

        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS cms_contents_owner_draft_idx ON cms_contents (owner_member_id, status, id)');
        } else {
            $indexes = $pdo->query("SHOW INDEX FROM cms_contents WHERE Key_name = 'cms_contents_owner_draft_idx'")->fetchAll();
            if ($indexes === []) {
                $pdo->exec('CREATE INDEX cms_contents_owner_draft_idx ON cms_contents (owner_member_id, status, id)');
            }
        }

        $now = gmdate('c');
        $role = $pdo->prepare('SELECT 1 FROM cms_roles WHERE role_id = :role_id LIMIT 1');
        $role->execute([':role_id' => 'ai_operator']);
        if (!$role->fetchColumn()) {
            $pdo->prepare('INSERT INTO cms_roles (role_id, label, built_in, created_at, updated_at) VALUES (:role_id, :label, 1, :created_at, :updated_at)')
                ->execute([':role_id' => 'ai_operator', ':label' => 'AI 运营', ':created_at' => $now, ':updated_at' => $now]);
        }
        foreach (['content.own_draft', 'seo.read'] as $capability) {
            $exists = $pdo->prepare('SELECT 1 FROM cms_role_capabilities WHERE role_id = :role_id AND capability = :capability LIMIT 1');
            $exists->execute([':role_id' => 'ai_operator', ':capability' => $capability]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO cms_role_capabilities (role_id, capability, created_at) VALUES (:role_id, :capability, :created_at)')
                    ->execute([':role_id' => 'ai_operator', ':capability' => $capability, ':created_at' => $now]);
            }
        }
    }
};

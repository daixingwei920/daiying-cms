<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_03_000001_seo_keyword_system_p1';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_terms')) {
            return;
        }

        $longText = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        if (!$this->columnExists($pdo, 'cms_terms', 'meta_json')) {
            $pdo->exec('ALTER TABLE cms_terms ADD COLUMN meta_json ' . $longText . ' NULL');
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};

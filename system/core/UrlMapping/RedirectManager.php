<?php

declare(strict_types=1);

namespace Cms\Core\UrlMapping;

use PDO;

final class RedirectManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(string $source, string $target, int $status = 301, bool $enabled = true): int
    {
        if ($source === $target || $this->wouldLoop($source, $target)) {
            throw new \InvalidArgumentException('Redirect loop detected.');
        }
        (new UrlMappingRepository($this->pdo))->record($source, $target, $status, 'cms');
        $id = (int) $this->pdo->lastInsertId();
        if ($this->hasColumn('cms_url_mappings', 'enabled')) {
            $this->pdo->prepare('UPDATE cms_url_mappings SET enabled = :enabled WHERE id = :id')
                ->execute([':id' => $id, ':enabled' => $enabled ? 1 : 0]);
        }

        return $id;
    }

    public function hit(string $source): void
    {
        if (!$this->hasColumn('cms_url_mappings', 'hit_count')) {
            return;
        }
        $this->pdo->prepare('UPDATE cms_url_mappings SET hit_count = hit_count + 1, last_hit_at = :last_hit_at WHERE source_url = :source_url')
            ->execute([':source_url' => $source, ':last_hit_at' => gmdate('c')]);
    }

    private function wouldLoop(string $source, string $target): bool
    {
        $seen = [$source => true];
        $current = $target;
        for ($i = 0; $i < 20; $i++) {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $stmt = $this->pdo->prepare('SELECT target_url FROM cms_url_mappings WHERE source_url = :source_url ORDER BY id DESC LIMIT 1');
            $stmt->execute([':source_url' => $current]);
            $next = $stmt->fetchColumn();
            if (!is_string($next) || $next === '') {
                return false;
            }
            $current = $next;
        }

        return true;
    }

    private function hasColumn(string $table, string $column): bool
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

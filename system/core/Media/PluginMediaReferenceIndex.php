<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use PDO;
use Throwable;

final class PluginMediaReferenceIndex
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $ownerPluginId = null,
    )
    {
    }

    /** @param list<int> $mediaIds */
    public function replaceForReference(string $pluginId, string $referenceType, string $referenceId, array $mediaIds): void
    {
        $this->assertPluginId($pluginId);
        if ($this->ownerPluginId !== null && $pluginId !== $this->ownerPluginId) {
            throw new MediaException('Plugin media reference owner mismatch.');
        }
        if (!$this->tableExists()) {
            return;
        }

        $this->pdo->prepare(
            'DELETE FROM cms_plugin_media_references
             WHERE plugin_id = :plugin_id AND reference_type = :reference_type AND reference_id = :reference_id'
        )->execute([
            ':plugin_id' => $pluginId,
            ':reference_type' => $this->cleanToken($referenceType),
            ':reference_id' => $this->cleanToken($referenceId),
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_plugin_media_references
                (plugin_id, media_id, reference_type, reference_id, created_at, updated_at)
             VALUES
                (:plugin_id, :media_id, :reference_type, :reference_id, :created_at, :updated_at)'
        );
        $now = gmdate('c');
        foreach (array_values(array_unique(array_filter(array_map('intval', $mediaIds)))) as $mediaId) {
            $stmt->execute([
                ':plugin_id' => $pluginId,
                ':media_id' => $mediaId,
                ':reference_type' => $this->cleanToken($referenceType),
                ':reference_id' => $this->cleanToken($referenceId),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function references(int $mediaId): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, plugin_id, media_id, reference_type, reference_id, created_at, updated_at
             FROM cms_plugin_media_references
             WHERE media_id = :media_id
             ORDER BY plugin_id, reference_type, reference_id'
        );
        $stmt->execute([':media_id' => $mediaId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'media_id' => (int) $row['media_id'],
            'content_id' => (int) preg_replace('/\D+/', '', (string) $row['reference_id']),
            'block_type' => 'plugin:' . (string) $row['reference_type'],
            'field_name' => (string) $row['plugin_id'],
            'title' => 'Plugin media reference: ' . (string) $row['plugin_id'],
            'slug' => (string) $row['reference_id'],
            'content_type' => 'plugin_reference',
            'plugin_id' => (string) $row['plugin_id'],
            'reference_type' => (string) $row['reference_type'],
            'reference_id' => (string) $row['reference_id'],
        ], $stmt->fetchAll());
    }

    public function referenceCount(int $mediaId): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_plugin_media_references WHERE media_id = :media_id');
        $stmt->execute([':media_id' => $mediaId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertPluginId(string $pluginId): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,94}[a-z0-9])?$/', $pluginId) !== 1 || str_contains($pluginId, '..')) {
            throw new MediaException('Invalid plugin media reference owner.');
        }
    }

    private function cleanToken(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191 || str_contains($value, "\0") || str_contains($value, '..')) {
            throw new MediaException('Invalid plugin media reference token.');
        }
        return $value;
    }

    private function tableExists(): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'cms_plugin_media_references'");
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute([':table' => 'cms_plugin_media_references']);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

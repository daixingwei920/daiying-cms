<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class PluginDataStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $pluginId,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function put(string $type, string $key, array $payload): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_plugin_data (plugin_id, data_type, data_key, payload_json, created_at, updated_at)
             VALUES (:plugin_id, :data_type, :data_key, :payload_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':plugin_id' => $this->pluginId,
            ':data_type' => $type,
            ':data_key' => $key,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $type): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT data_key, payload_json, created_at, updated_at FROM cms_plugin_data
             WHERE plugin_id = :plugin_id AND data_type = :data_type ORDER BY id DESC'
        );
        $stmt->execute([
            ':plugin_id' => $this->pluginId,
            ':data_type' => $type,
        ]);

        return $stmt->fetchAll();
    }
}

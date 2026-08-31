<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class MarketInstallRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function recordSource(string $extensionId, string $type, string $source, string $marketId, string $version, array $metadata = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_extension_sources (extension_id, extension_type, source, market_id, version, installed_at, metadata_json) VALUES (:extension_id, :extension_type, :source, :market_id, :version, :installed_at, :metadata_json)');
        $stmt->execute([
            ':extension_id' => $extensionId,
            ':extension_type' => $type,
            ':source' => $source,
            ':market_id' => $marketId,
            ':version' => $version,
            ':installed_at' => gmdate('c'),
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string, mixed> $plan */
    public function recordLog(string $marketId, string $extensionId, string $type, string $status, array $plan): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_install_logs (market_id, extension_id, extension_type, status, plan_json, created_at) VALUES (:market_id, :extension_id, :extension_type, :status, :plan_json, :created_at)');
        $stmt->execute([
            ':market_id' => $marketId,
            ':extension_id' => $extensionId,
            ':extension_type' => $type,
            ':status' => $status,
            ':plan_json' => json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function latestSources(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_extension_sources ORDER BY id DESC');

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function latestSource(string $extensionId, string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_extension_sources WHERE extension_id = :extension_id AND extension_type = :extension_type ORDER BY id DESC');
        $stmt->execute([':extension_id' => $extensionId, ':extension_type' => $type]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function latestInstalledByExtension(string $type = ''): array
    {
        $sql = 'SELECT s.* FROM cms_extension_sources s INNER JOIN (SELECT extension_id, extension_type, MAX(id) AS max_id FROM cms_extension_sources GROUP BY extension_id, extension_type) latest ON latest.max_id = s.id';
        $params = [];
        if ($type !== '') {
            $sql .= ' WHERE s.extension_type = :type';
            $params[':type'] = $type;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function markUninstalled(string $extensionId, string $type): void
    {
        $this->recordLog('local', $extensionId, $type, 'Uninstalled', [
            'extension_id' => $extensionId,
            'type' => $type,
            'uninstalled_at' => gmdate('c'),
        ]);
        $this->recordSource($extensionId, $type, 'uninstalled', 'local', '0.0.0', ['status' => 'Uninstalled']);
    }
}

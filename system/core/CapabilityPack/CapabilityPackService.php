<?php

declare(strict_types=1);

namespace Cms\Core\CapabilityPack;

use Cms\Core\Timeline\SiteTimelineService;
use PDO;
use Throwable;

final class CapabilityPackService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SiteTimelineService $timeline = null,
    ) {
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function preview(array $manifest): array
    {
        $this->validate($manifest);
        $required = array_values(array_map('strval', $manifest['required_plugins'] ?? []));
        $optional = array_values(array_map('strval', $manifest['optional_plugins'] ?? []));

        return [
            'status' => 'preview',
            'pack_id' => (string) $manifest['pack_id'],
            'name' => (string) $manifest['name'],
            'will_install_or_enable' => [
                'recommended_theme' => (string) ($manifest['recommended_theme'] ?? ''),
                'required_plugins' => $required,
                'optional_plugins' => $optional,
                'block_templates' => count((array) ($manifest['block_templates'] ?? [])),
                'page_templates' => count((array) ($manifest['page_templates'] ?? [])),
                'menu_presets' => count((array) ($manifest['menu_presets'] ?? [])),
            ],
            'permission_summary' => $this->permissionSummary($manifest),
            'requires_confirmation' => true,
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function install(array $manifest, int $adminId, string $confirmation): array
    {
        $preview = $this->preview($manifest);
        if ($confirmation !== 'INSTALL CAPABILITY PACK') {
            throw new CapabilityPackException('Capability Pack installation requires administrator confirmation.');
        }
        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_capability_packs (pack_id, name, version, status, manifest_json, installed_at, updated_at)
                 VALUES (:pack_id, :name, :version, :status, :manifest_json, :installed_at, :updated_at)'
            );
            $stmt->execute([
                ':pack_id' => (string) $manifest['pack_id'],
                ':name' => (string) $manifest['name'],
                ':version' => (string) $manifest['version'],
                ':status' => 'Installed',
                ':manifest_json' => $this->json($manifest),
                ':installed_at' => $now,
                ':updated_at' => $now,
            ]);
            foreach ((array) ($manifest['required_plugins'] ?? []) as $pluginId) {
                $this->pdo->prepare("UPDATE cms_plugins SET status = 'Enabled', updated_at = :updated_at WHERE plugin_id = :plugin_id")
                    ->execute([':updated_at' => $now, ':plugin_id' => (string) $pluginId]);
            }
            $this->timeline?->record('admin', $adminId, 'capability_pack.install', 'capability_pack', (string) $manifest['pack_id'], null, (string) $manifest['version'], 'snapshot', null, $preview);
            $this->pdo->commit();

            return ['status' => 'PASS', 'pack_id' => (string) $manifest['pack_id'], 'preview' => $preview];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new CapabilityPackException('Capability Pack install rolled back: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function validate(array $manifest): void
    {
        foreach (['pack_id', 'name', 'version'] as $key) {
            if (!isset($manifest[$key]) || trim((string) $manifest[$key]) === '') {
                throw new CapabilityPackException('Capability Pack manifest missing key: ' . $key);
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', (string) $manifest['pack_id']) !== 1) {
            throw new CapabilityPackException('Capability Pack id is invalid.');
        }
    }

    /** @param array<string,mixed> $manifest @return list<string> */
    private function permissionSummary(array $manifest): array
    {
        $permissions = [];
        foreach (['required_plugins', 'optional_plugins'] as $key) {
            foreach ((array) ($manifest[$key] ?? []) as $pluginId) {
                $permissions[] = 'enable plugin: ' . (string) $pluginId;
            }
        }
        if (!empty($manifest['recommended_theme'])) {
            $permissions[] = 'activate theme: ' . (string) $manifest['recommended_theme'];
        }

        return array_values(array_unique($permissions));
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new CapabilityPackException('Unable to encode Capability Pack manifest.');
        }

        return $json;
    }
}

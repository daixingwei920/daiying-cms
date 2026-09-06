<?php

declare(strict_types=1);

namespace Cms\Core\PortableBlock;

use PDO;
use ZipArchive;

final class PortableBlockService
{
    public const FORMAT = 'daiying-portable-block-v1';
    public const EXTENSION = '.dblock';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $block @param list<string> $requiredPlugins */
    public function saveReusable(string $name, array $block, array $requiredPlugins = []): string
    {
        $blockId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        $payload = $this->payload($block, $requiredPlugins);
        $sha = hash('sha256', $this->json($payload));
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO cms_reusable_blocks (block_id, name, block_type, schema_version, payload_json, required_plugins_json, integrity_sha256, created_at, updated_at)
             VALUES (:block_id, :name, :block_type, :schema_version, :payload_json, :required_plugins_json, :integrity_sha256, :created_at, :updated_at)'
        )->execute([
            ':block_id' => $blockId,
            ':name' => substr(trim($name), 0, 191),
            ':block_type' => substr((string) ($block['type'] ?? 'unknown'), 0, 96),
            ':schema_version' => '1',
            ':payload_json' => $this->json($payload),
            ':required_plugins_json' => $this->json(array_values($requiredPlugins)),
            ':integrity_sha256' => $sha,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $blockId;
    }

    /** @param array<string,mixed> $block @param list<string> $requiredPlugins */
    public function exportBlock(array $block, string $targetPath, array $requiredPlugins = []): string
    {
        if (!str_ends_with($targetPath, self::EXTENSION)) {
            throw new PortableBlockException('Portable block must use the .dblock extension.');
        }
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }
        $payload = $this->payload($block, $requiredPlugins);
        $json = $this->json($payload);
        $manifest = [
            'package_format' => self::FORMAT,
            'schema_version' => 1,
            'block_type' => (string) ($block['type'] ?? 'unknown'),
            'required_plugins' => array_values($requiredPlugins),
            'integrity' => ['payload_sha256' => hash('sha256', $json)],
            'created_at' => gmdate('c'),
        ];
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PortableBlockException('Unable to create portable block.');
        }
        $zip->addFromString('manifest.json', $this->json($manifest));
        $zip->addFromString('block.json', $json);
        $zip->close();

        return $targetPath;
    }

    /** @return array{status:string,block:array<string,mixed>,dependencies:list<array<string,string>>} */
    public function importBlock(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new PortableBlockException('Unable to open portable block.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $block = json_decode((string) $zip->getFromName('block.json'), true);
        $zip->close();
        if (!is_array($manifest) || ($manifest['package_format'] ?? '') !== self::FORMAT || !is_array($block)) {
            throw new PortableBlockException('Portable block is invalid.');
        }
        $encoded = $this->json($block);
        if (hash('sha256', $encoded) !== (string) ($manifest['integrity']['payload_sha256'] ?? '')) {
            throw new PortableBlockException('Portable block checksum mismatch.');
        }

        $dependencies = $this->dependencyStatus($manifest['required_plugins'] ?? []);
        $blocked = array_filter($dependencies, static fn (array $item): bool => $item['status'] !== 'installed');

        return [
            'status' => $blocked === [] ? 'PASS' : 'BLOCKED',
            'block' => (array) ($block['block'] ?? $block),
            'dependencies' => array_values($dependencies),
        ];
    }

    /** @return list<array<string,string>> */
    public function dependencyStatus(mixed $requiredPlugins): array
    {
        $required = is_array($requiredPlugins) ? array_values(array_map('strval', $requiredPlugins)) : [];
        if ($required === []) {
            return [];
        }
        $stmt = $this->pdo->query('SELECT plugin_id, version, status FROM cms_plugins');
        $installed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $installed[(string) $row['plugin_id']] = $row;
        }
        $result = [];
        foreach ($required as $pluginId) {
            $row = $installed[$pluginId] ?? null;
            $result[] = [
                'plugin_id' => $pluginId,
                'status' => is_array($row) ? 'installed' : 'missing',
                'message' => is_array($row) ? 'Dependency is installed.' : 'This block requires ' . $pluginId . '.',
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $block @param list<string> $requiredPlugins @return array<string,mixed> */
    private function payload(array $block, array $requiredPlugins): array
    {
        return [
            'package_format' => self::FORMAT,
            'schema_version' => 1,
            'block' => $block,
            'styles_supported_by_cms' => ['body', 'lead', 'small', 'alignment'],
            'required_plugin_ids' => array_values($requiredPlugins),
            'asset_dependencies' => $block['assets'] ?? [],
            'metadata' => ['exported_at' => gmdate('c')],
        ];
    }

    /** @param mixed $data */
    private function json($data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new PortableBlockException('Unable to encode portable block JSON.');
        }

        return $json . "\n";
    }
}

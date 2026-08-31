<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

use PDO;
use ZipArchive;

final class DaiyingMigrationPackageBuilder
{
    public function __construct(private readonly string $rootPath, private readonly PDO $pdo)
    {
    }

    public function build(string $targetPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new MigrationException('ZipArchive extension is required for migration package export.');
        }
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new MigrationException('无法创建 Daiying Migration Package。');
        }
        $now = gmdate('c');
        $zip->addFromString('manifest.json', $this->json(['migration_package_version' => '1', 'source_system' => 'daiying_cms', 'source_version' => '1.2.0', 'generated_at' => $now]));
        $zip->addFromString('site.json', $this->json(['source_site_id' => 'daiying:' . substr(hash('sha256', $this->rootPath), 0, 16), 'title' => 'Daiying CMS']));
        $zip->addFromString('users.json', $this->json($this->rows('SELECT id AS source_id, email, display_name FROM cms_admin_users ORDER BY id')));
        $zip->addFromString('categories.json', $this->json($this->rows("SELECT id AS source_id, name, slug FROM cms_terms WHERE taxonomy = 'category' ORDER BY id")));
        $zip->addFromString('tags.json', $this->json($this->rows("SELECT id AS source_id, name, slug FROM cms_terms WHERE taxonomy = 'tag' ORDER BY id")));
        $contents = '';
        foreach ($this->rows('SELECT * FROM cms_contents ORDER BY id') as $row) {
            $contents .= $this->json(['source_id' => (string) $row['id'], 'type' => (string) $row['content_type'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug'], 'status' => (string) $row['status'], 'content_html' => $this->blocksToHtml((string) ($row['blocks_json'] ?? '')), 'published_at' => $row['published_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null, 'metadata' => ['source_platform' => 'daiying_cms', 'source_id' => (string) $row['id'], 'meta' => json_decode((string) ($row['meta_json'] ?? '{}'), true)]]) . "\n";
        }
        $zip->addFromString('contents.jsonl', $contents);
        $media = [];
        foreach ($this->rows('SELECT id AS source_id, original_name, relative_path, storage_key FROM cms_media ORDER BY id') as $row) {
            $relativePath = ltrim(str_replace('\\', '/', (string) ($row['relative_path'] ?? '')), '/');
            $packagePath = $relativePath !== '' ? 'media/' . basename($relativePath) : '';
            if ($packagePath !== '') {
                $absolutePath = $this->rootPath . '/content/uploads/' . $relativePath;
                if (is_file($absolutePath) && is_readable($absolutePath)) {
                    $zip->addFile($absolutePath, $packagePath);
                }
            }
            $media[] = [
                'source_id' => (string) ($row['source_id'] ?? ''),
                'original_name' => (string) ($row['original_name'] ?? ''),
                'package_path' => $packagePath,
                'storage_key' => (string) ($row['storage_key'] ?? ''),
            ];
        }
        $zip->addFromString('media.json', $this->json($media));
        $zip->addFromString('comments.jsonl', '');
        $zip->addFromString('redirects.json', $this->json($this->rows('SELECT * FROM cms_url_mappings ORDER BY id')));
        $zip->addFromString('metadata.json', $this->json(['notes' => 'Daiying CMS Migration Package V1']));
        $zip->close();

        return $targetPath;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function blocksToHtml(string $json): string
    {
        $blocks = json_decode($json, true);
        if (!is_array($blocks)) {
            return '';
        }
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $html .= match ((string) ($block['type'] ?? '')) {
                'html' => (string) ($data['html'] ?? ''),
                'paragraph', 'raw_text' => '<p>' . htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>',
                'heading' => '<h2>' . htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h2>',
                default => '',
            };
        }

        return $html;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

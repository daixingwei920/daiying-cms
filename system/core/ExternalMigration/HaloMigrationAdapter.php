<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class HaloMigrationAdapter implements MigrationAdapterInterface
{
    use JsonMigrationAdapterTrait;

    public function id(): string { return 'halo'; }
    public function label(): string { return 'Halo'; }

    public function supports(string $filename, string $payload): bool
    {
        $lower = strtolower($filename);
        return str_ends_with($lower, '.json') && (str_contains(strtolower($payload), 'halo') || str_contains(strtolower($payload), 'singlepages'));
    }

    public function scan(string $filename, string $payload): array
    {
        $package = $this->toPackage($filename, $payload);
        return ['source_system' => 'halo', 'source_version' => (string) ($package['source_version'] ?? ''), 'source_site_id' => (string) ($package['site']['source_site_id'] ?? 'halo:' . substr(hash('sha256', $payload), 0, 16)), 'counts' => ['users' => count($package['users'] ?? []), 'categories' => count($package['categories'] ?? []), 'tags' => count($package['tags'] ?? []), 'contents' => count($package['contents'] ?? []), 'media' => count($package['media'] ?? []), 'comments' => count($package['comments'] ?? []), 'redirects' => count($package['redirects'] ?? [])]];
    }

    public function toPackage(string $filename, string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new MigrationException('Halo JSON 解析失败。');
        }
        if (($decoded['migration_package_version'] ?? '') === '1') {
            return $this->packageFromGenericJson($payload, 'halo', 'Halo 站点');
        }
        $contents = [];
        foreach (array_values((array) ($decoded['posts'] ?? [])) as $post) {
            if (is_array($post)) {
                $post['type'] = 'article';
                $contents[] = $this->normalizeContent($post, 'halo');
            }
        }
        foreach (array_values((array) ($decoded['singlePages'] ?? $decoded['single_pages'] ?? $decoded['pages'] ?? [])) as $page) {
            if (is_array($page)) {
                $page['type'] = 'page';
                $contents[] = $this->normalizeContent($page, 'halo');
            }
        }

        return ['migration_package_version' => '1', 'source_system' => 'halo', 'source_version' => (string) ($decoded['version'] ?? ''), 'site' => ['source_site_id' => (string) ($decoded['site_id'] ?? 'halo:' . substr(hash('sha256', $payload), 0, 16)), 'title' => (string) ($decoded['site']['title'] ?? 'Halo 站点')], 'users' => array_values((array) ($decoded['users'] ?? $decoded['authors'] ?? [])), 'categories' => array_values((array) ($decoded['categories'] ?? [])), 'tags' => array_values((array) ($decoded['tags'] ?? [])), 'contents' => $contents, 'media' => array_values((array) ($decoded['attachments'] ?? $decoded['media'] ?? [])), 'comments' => array_values((array) ($decoded['comments'] ?? [])), 'redirects' => array_values((array) ($decoded['redirects'] ?? [])), 'metadata' => []];
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class TypechoMigrationAdapter extends GenericSqlCmsMigrationAdapter
{
    public function id(): string { return 'typecho'; }
    public function label(): string { return 'Typecho'; }
    protected function tableNames(): array { return ['contents', 'metas', 'relationships', 'users', 'comments', 'fields']; }
    protected function signatures(): array { return ['/\bINSERT\s+INTO\s+`?[^`\s]*typecho_contents`?/i']; }

    protected function packageFromTables(array $tables, string $payload): array
    {
        $metas = [];
        foreach ($tables['metas'] ?? [] as $meta) {
            $metas[(string) ($meta['mid'] ?? '')] = $meta;
        }
        $rels = [];
        foreach ($tables['relationships'] ?? [] as $rel) {
            $rels[(string) ($rel['cid'] ?? '')][] = (string) ($rel['mid'] ?? '');
        }
        $contents = [];
        $media = [];
        foreach ($tables['contents'] ?? [] as $row) {
            $cid = (string) ($row['cid'] ?? '');
            $type = (string) ($row['type'] ?? 'post');
            if ($type === 'attachment') {
                $media[] = ['source_id' => $cid, 'source_url' => (string) ($row['attachment'] ?? $row['text'] ?? ''), 'title' => (string) ($row['title'] ?? '')];
                continue;
            }
            if (!in_array($type, ['post', 'page'], true)) {
                continue;
            }
            $cats = [];
            $tags = [];
            foreach ($rels[$cid] ?? [] as $mid) {
                $meta = $metas[$mid] ?? [];
                if (($meta['type'] ?? '') === 'category') {
                    $cats[] = (string) ($meta['name'] ?? '');
                } elseif (($meta['type'] ?? '') === 'tag') {
                    $tags[] = (string) ($meta['name'] ?? '');
                }
            }
            $contents[] = ['source_id' => $cid, 'type' => $type === 'page' ? 'page' : 'article', 'title' => (string) ($row['title'] ?? ''), 'slug' => (string) ($row['slug'] ?? ''), 'excerpt' => '', 'content_html' => (string) ($row['text'] ?? ''), 'status' => ((string) ($row['status'] ?? 'draft')) === 'publish' ? 'published' : 'draft', 'published_at' => $this->time($row['created'] ?? null), 'updated_at' => $this->time($row['modified'] ?? null), 'author_ref' => (string) ($row['authorId'] ?? ''), 'categories' => array_values(array_filter($cats)), 'tags' => array_values(array_filter($tags)), 'source_url' => '/' . ($type === 'page' ? '' : 'archives/') . ((string) ($row['slug'] ?? $cid)), 'metadata' => ['source_platform' => 'typecho', 'source_id' => $cid]];
        }

        return ['migration_package_version' => '1', 'source_system' => 'typecho', 'source_version' => '', 'site' => ['source_site_id' => 'typecho:' . substr(hash('sha256', $payload), 0, 16), 'title' => 'Typecho 站点'], 'users' => array_values($tables['users'] ?? []), 'categories' => [], 'tags' => [], 'contents' => $contents, 'media' => $media, 'comments' => array_values($tables['comments'] ?? []), 'redirects' => [], 'metadata' => []];
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class EmlogMigrationAdapter extends GenericSqlCmsMigrationAdapter
{
    public function id(): string { return 'emlog'; }
    public function label(): string { return 'Emlog'; }
    protected function tableNames(): array { return ['blog', 'sort', 'tag', 'user', 'comment', 'attachment']; }
    protected function signatures(): array { return ['/\bINSERT\s+INTO\s+`?[^`\s]*blog`?\s*\([^)]*gid/i']; }

    protected function packageFromTables(array $tables, string $payload): array
    {
        $categories = [];
        foreach ($tables['sort'] ?? [] as $sort) {
            $categories[(string) ($sort['sid'] ?? '')] = (string) ($sort['sortname'] ?? $sort['name'] ?? '');
        }
        $contents = [];
        foreach ($tables['blog'] ?? [] as $row) {
            $id = (string) ($row['gid'] ?? '');
            $isPage = ((string) ($row['type'] ?? 'blog')) === 'page';
            $status = ((string) ($row['hide'] ?? 'n')) === 'n' ? 'published' : 'draft';
            $contents[] = ['source_id' => $id, 'type' => $isPage ? 'page' : 'article', 'title' => (string) ($row['title'] ?? ''), 'slug' => (string) ($row['alias'] ?? ''), 'excerpt' => (string) ($row['excerpt'] ?? ''), 'content_html' => (string) ($row['content'] ?? ''), 'status' => $status, 'published_at' => $this->time($row['date'] ?? null), 'updated_at' => null, 'author_ref' => (string) ($row['author'] ?? ''), 'categories' => array_values(array_filter([(string) ($categories[(string) ($row['sortid'] ?? '')] ?? '')])), 'tags' => $this->listFromCsv($row, 'tags'), 'source_url' => $isPage ? '/?post=' . $id : '/post-' . $id . '.html', 'metadata' => ['source_platform' => 'emlog', 'source_id' => $id]];
        }

        return ['migration_package_version' => '1', 'source_system' => 'emlog', 'source_version' => '', 'site' => ['source_site_id' => 'emlog:' . substr(hash('sha256', $payload), 0, 16), 'title' => 'Emlog 站点'], 'users' => array_values($tables['user'] ?? []), 'categories' => array_values(array_map(fn (string $name): array => ['name' => $name], array_filter($categories))), 'tags' => array_values($tables['tag'] ?? []), 'contents' => $contents, 'media' => array_values($tables['attachment'] ?? []), 'comments' => array_values($tables['comment'] ?? []), 'redirects' => [], 'metadata' => []];
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class ZBlogMigrationAdapter implements MigrationAdapterInterface
{
    private const ALLOWED_TABLES = ['post', 'category', 'member', 'tag', 'comment', 'upload'];

    public function id(): string
    {
        return 'zblogphp';
    }

    public function label(): string
    {
        return 'Z-BlogPHP';
    }

    public function supports(string $filename, string $payload): bool
    {
        $lower = strtolower($filename);
        return str_ends_with($lower, '.json') && str_contains(strtolower($payload), 'zblog')
            || str_ends_with($lower, '.sql') && preg_match('/\bINSERT\s+INTO\s+`?[^`\s]*zbp_/i', $payload) === 1;
    }

    public function scan(string $filename, string $payload): array
    {
        $package = $this->toPackage($filename, $payload);

        return [
            'source_system' => 'zblogphp',
            'source_version' => (string) ($package['source_version'] ?? ''),
            'source_site_id' => (string) ($package['site']['source_site_id'] ?? 'zblogphp:' . substr(hash('sha256', $payload), 0, 16)),
            'counts' => [
                'users' => count($package['users'] ?? []),
                'categories' => count($package['categories'] ?? []),
                'tags' => count($package['tags'] ?? []),
                'contents' => count($package['contents'] ?? []),
                'media' => count($package['media'] ?? []),
                'comments' => count($package['comments'] ?? []),
                'redirects' => count($package['redirects'] ?? []),
            ],
        ];
    }

    public function toPackage(string $filename, string $payload): array
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.json')) {
            return $this->fromJson($payload);
        }
        if (str_ends_with($lower, '.sql')) {
            return $this->fromSql($payload);
        }

        throw new MigrationException('暂不支持这个 Z-Blog 迁移文件。');
    }

    /** @return array<string, mixed> */
    private function fromJson(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new MigrationException('Z-Blog JSON 解析失败。');
        }
        $package = [
            'source_system' => 'zblogphp',
            'source_version' => (string) ($decoded['version'] ?? $decoded['zblog_version'] ?? ''),
            'site' => [
                'source_site_id' => (string) ($decoded['site_id'] ?? 'zblogphp:' . substr(hash('sha256', $payload), 0, 16)),
                'title' => (string) ($decoded['site']['title'] ?? $decoded['title'] ?? 'Z-Blog 站点'),
            ],
            'users' => array_values((array) ($decoded['users'] ?? $decoded['members'] ?? [])),
            'categories' => array_values((array) ($decoded['categories'] ?? [])),
            'tags' => array_values((array) ($decoded['tags'] ?? [])),
            'contents' => [],
            'media' => array_values((array) ($decoded['media'] ?? $decoded['uploads'] ?? [])),
            'comments' => array_values((array) ($decoded['comments'] ?? [])),
            'redirects' => array_values((array) ($decoded['redirects'] ?? [])),
        ];
        foreach (array_values((array) ($decoded['posts'] ?? $decoded['articles'] ?? [])) as $post) {
            if (is_array($post)) {
                $package['contents'][] = $this->normalizePost($post);
            }
        }

        return $package;
    }

    /** @return array<string, mixed> */
    private function fromSql(string $payload): array
    {
        $this->assertSqlIsSafe($payload);
        $tables = $this->parseInserts($payload);
        $posts = $tables['post'] ?? [];
        $categories = $tables['category'] ?? [];
        $tags = $tables['tag'] ?? [];

        $normalizedCategories = [];
        foreach ($categories as $row) {
            $normalizedCategories[] = [
                'source_id' => (string) ($row['cate_ID'] ?? $row['id'] ?? ''),
                'name' => (string) ($row['cate_Name'] ?? $row['name'] ?? ''),
                'slug' => (string) ($row['cate_Alias'] ?? $row['slug'] ?? ''),
                'parent_source_id' => (string) ($row['cate_ParentID'] ?? ''),
            ];
        }
        $normalizedTags = [];
        foreach ($tags as $row) {
            $normalizedTags[] = [
                'source_id' => (string) ($row['tag_ID'] ?? $row['id'] ?? ''),
                'name' => (string) ($row['tag_Name'] ?? $row['name'] ?? ''),
                'slug' => (string) ($row['tag_Alias'] ?? $row['slug'] ?? ''),
            ];
        }
        $normalizedCategories = $this->uniqueRowsByKey($normalizedCategories, ['source_id']);
        $normalizedTags = $this->uniqueRowsByKey($normalizedTags, ['source_id']);

        $contents = [];
        foreach ($posts as $post) {
            $contents[] = $this->normalizePost($post, $normalizedCategories, $normalizedTags);
        }
        $contents = $this->uniqueRowsByKey($contents, ['source_id']);

        return [
            'source_system' => 'zblogphp',
            'source_version' => $this->detectVersion($payload),
            'site' => [
                'source_site_id' => 'zblogphp:' . substr(hash('sha256', $payload), 0, 16),
                'title' => 'Z-Blog 迁移站点',
            ],
            'users' => $this->uniqueRowsByKey(array_values($tables['member'] ?? []), ['mem_ID', 'id', 'source_id']),
            'categories' => $normalizedCategories,
            'tags' => $normalizedTags,
            'contents' => $contents,
            'media' => $this->uniqueRowsByKey(array_values($tables['upload'] ?? []), ['ul_ID', 'id', 'source_id']),
            'comments' => $this->uniqueRowsByKey(array_values($tables['comment'] ?? []), ['comm_ID', 'id', 'source_id']),
            'redirects' => [],
        ];
    }

    /** @param list<array<string,mixed>> $categories @param list<array<string,mixed>> $tags @return array<string,mixed> */
    private function normalizePost(array $post, array $categories = [], array $tags = []): array
    {
        $id = (string) ($post['source_id'] ?? $post['log_ID'] ?? $post['id'] ?? '');
        $title = trim((string) ($post['title'] ?? $post['log_Title'] ?? ''));
        $alias = trim((string) ($post['slug'] ?? $post['alias'] ?? $post['log_Alias'] ?? ''));
        $typeValue = (string) ($post['type'] ?? $post['log_Type'] ?? '0');
        $type = in_array(strtolower($typeValue), ['page', '1'], true) ? 'page' : 'article';
        $statusValue = strtolower((string) ($post['status'] ?? $post['log_Status'] ?? '0'));
        $status = in_array($statusValue, ['publish', 'published', 'public', '0'], true) ? 'published' : 'draft';
        $content = (string) ($post['content_html'] ?? $post['content'] ?? $post['log_Content'] ?? $post['intro'] ?? $post['log_Intro'] ?? '');
        $intro = (string) ($post['excerpt'] ?? $post['log_Intro'] ?? '');
        $publishedAt = $this->timeValue($post['published_at'] ?? $post['log_PostTime'] ?? null);
        $updatedAt = $this->timeValue($post['updated_at'] ?? $post['log_UpdateTime'] ?? null);
        $categoryRefs = $this->categoryRefs((string) ($post['log_CateID'] ?? $post['category_id'] ?? ''), $categories);
        $tagRefs = is_array($post['tags'] ?? null) ? array_values($post['tags']) : $this->tagRefs((string) ($post['log_Tag'] ?? ''), $tags);
        $sourceUrl = (string) ($post['source_url'] ?? $post['url'] ?? '');
        if ($sourceUrl === '' && $id !== '') {
            $sourceUrl = $type === 'article' ? '/post/' . ($alias !== '' ? $alias : $id) . '.html' : '/page/' . ($alias !== '' ? $alias : $id) . '.html';
        }

        return [
            'source_id' => $id !== '' ? $id : substr(hash('sha256', $title . $content), 0, 16),
            'type' => $type,
            'title' => $title,
            'slug' => $alias,
            'excerpt' => $intro,
            'content_html' => $content,
            'status' => $status,
            'published_at' => $publishedAt,
            'updated_at' => $updatedAt,
            'author_ref' => (string) ($post['author_ref'] ?? $post['log_AuthorID'] ?? ''),
            'categories' => $categoryRefs,
            'tags' => $tagRefs,
            'featured_image_ref' => (string) ($post['featured_image_ref'] ?? ''),
            'source_url' => $sourceUrl,
            'metadata' => ['source_platform' => 'zblogphp', 'source_id' => $id],
        ];
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $keys @return list<array<string,mixed>> */
    private function uniqueRowsByKey(array $rows, array $keys): array
    {
        $seen = [];
        $unique = [];
        foreach ($rows as $index => $row) {
            $id = '';
            foreach ($keys as $key) {
                $candidate = trim((string) ($row[$key] ?? ''));
                if ($candidate !== '') {
                    $id = $candidate;
                    break;
                }
            }
            $dedupeKey = $id !== ''
                ? $id
                : 'row:' . $index . ':' . substr(hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    private function assertSqlIsSafe(string $payload): void
    {
        if (strlen($payload) > 52428800) {
            throw new MigrationException('SQL 文件超过迁移大小限制。');
        }
        if (preg_match('/\b(DROP|ALTER|TRUNCATE|CREATE\s+TRIGGER|CREATE\s+PROCEDURE|CREATE\s+FUNCTION|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE)\b/i', $payload) === 1) {
            throw new MigrationException('SQL 文件包含危险语句，已拒绝迁移。');
        }
    }

    /** @return array<string,list<array<string,string>>> */
    private function parseInserts(string $payload): array
    {
        $tables = [];
        foreach ($this->insertStatements($payload) as $statement) {
            if (preg_match('/^INSERT\s+INTO\s+`?([^`\s(]+)`?\s*\(([^)]*)\)\s*VALUES\s*(.*)$/is', $statement, $match) !== 1) {
                continue;
            }
            $table = $this->canonicalTable((string) $match[1]);
            if ($table === '') {
                continue;
            }
            $columns = array_map(static fn (string $v): string => trim($v, " `\t\r\n"), explode(',', (string) $match[2]));
            foreach ($this->parseValueRows((string) $match[3]) as $values) {
                if (count($columns) !== count($values)) {
                    continue;
                }
                $tables[$table][] = array_combine($columns, $values);
            }
        }

        return $tables;
    }

    /** @return list<string> */
    private function insertStatements(string $payload): array
    {
        $statements = [];
        $offset = 0;
        $length = strlen($payload);
        while (($start = stripos($payload, 'INSERT', $offset)) !== false) {
            $inString = false;
            $escape = false;
            for ($i = $start; $i < $length; $i++) {
                $char = $payload[$i];
                if ($inString) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }
                    if ($char === '\\') {
                        $escape = true;
                        continue;
                    }
                    if ($char === "'") {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === "'") {
                    $inString = true;
                    continue;
                }
                if ($char === ';') {
                    $statements[] = trim(substr($payload, $start, $i - $start));
                    $offset = $i + 1;
                    continue 2;
                }
            }
            break;
        }

        return $statements;
    }

    private function canonicalTable(string $table): string
    {
        $table = strtolower($table);
        foreach (self::ALLOWED_TABLES as $allowed) {
            if (str_ends_with($table, 'zbp_' . $allowed) || $table === 'zbp_' . $allowed || str_ends_with($table, '_' . $allowed)) {
                return $allowed;
            }
        }

        return '';
    }

    /** @return list<list<string>> */
    private function parseValueRows(string $valuesSql): array
    {
        $rows = [];
        $length = strlen($valuesSql);
        $row = [];
        $value = '';
        $inString = false;
        $escape = false;
        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $valuesSql[$i];
            if ($inString) {
                if ($escape) {
                    $value .= match ($char) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $char,
                    };
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === "'") {
                    $inString = false;
                    continue;
                }
                $value .= $char;
                continue;
            }
            if ($char === "'") {
                $inString = true;
                continue;
            }
            if ($char === '(') {
                $depth++;
                if ($depth === 1) {
                    $row = [];
                    $value = '';
                    continue;
                }
            }
            if ($char === ')' && $depth === 1) {
                $row[] = $this->sqlValue($value);
                $rows[] = $row;
                $row = [];
                $value = '';
                $depth = 0;
                continue;
            }
            if ($char === ',' && $depth === 1) {
                $row[] = $this->sqlValue($value);
                $value = '';
                continue;
            }
            if ($depth >= 1) {
                $value .= $char;
            }
        }

        return $rows;
    }

    private function sqlValue(string $value): string
    {
        $value = trim($value);
        return strcasecmp($value, 'NULL') === 0 ? '' : $value;
    }

    private function detectVersion(string $payload): string
    {
        return preg_match('/Z-?BlogPHP\s*([0-9][0-9A-Za-z._-]*)/i', $payload, $m) === 1 ? (string) $m[1] : '';
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $time = (int) $value;
            return $time > 0 ? gmdate('c', $time) : null;
        }
        $time = strtotime((string) $value);
        return $time === false ? null : gmdate('c', $time);
    }

    /** @param list<array<string,mixed>> $categories @return list<string> */
    private function categoryRefs(string $categoryId, array $categories): array
    {
        if ($categoryId === '' || $categoryId === '0') {
            return [];
        }
        foreach ($categories as $category) {
            if ((string) ($category['source_id'] ?? '') === $categoryId && (string) ($category['name'] ?? '') !== '') {
                return [(string) $category['name']];
            }
        }

        return [$categoryId];
    }

    /** @param list<array<string,mixed>> $tags @return list<string> */
    private function tagRefs(string $raw, array $tags): array
    {
        if ($raw === '') {
            return [];
        }
        preg_match_all('/\{([^}]+)\}/', $raw, $matches);
        $ids = $matches[1] ?? [];
        $result = [];
        foreach ($ids as $id) {
            foreach ($tags as $tag) {
                if ((string) ($tag['source_id'] ?? '') === (string) $id && (string) ($tag['name'] ?? '') !== '') {
                    $result[] = (string) $tag['name'];
                }
            }
        }

        return $result;
    }
}

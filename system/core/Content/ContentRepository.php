<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;
use Cms\Core\Media\MediaLibrary;

final class ContentRepository
{
    private const STATUSES = ['draft', 'published', 'scheduled', 'archived'];
    private const RESERVED_SLUGS = ['install', 'admin', 'login', 'register', 'logout', 'comments', 'health', 'recovery', 'api', 'articles', 'category', 'tag', 'search', 'sitemap.xml', 'robots.txt'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentTypeRegistry $types,
        private readonly array $registeredBlockTypes = [],
    ) {
    }

    /** @param list<array<string, mixed>> $blocks @param array<string, mixed> $meta @param list<string> $categories @param list<string> $tags */
    public function create(string $type, string $title, string $slug, array $blocks, string $status = 'draft', array $meta = [], array $categories = [], array $tags = []): int
    {
        [$slug, $cleanBlocks] = $this->prepareForSave($type, $title, $slug, $blocks, $status, null);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_contents
                (content_type, title, slug, status, blocks_json, meta_json, created_at, updated_at, published_at, scheduled_at)
             VALUES
                (:content_type, :title, :slug, :status, :blocks_json, :meta_json, :created_at, :updated_at, :published_at, :scheduled_at)'
        );
        $cleanMeta = $this->cleanMeta($meta, $title);
        $stmt->execute([
            ':content_type' => $type,
            ':title' => $title,
            ':slug' => $slug,
            ':status' => $status,
            ':blocks_json' => $this->json($cleanBlocks),
            ':meta_json' => $this->json($cleanMeta),
            ':created_at' => $now,
            ':updated_at' => $now,
            ':published_at' => $status === 'published' ? $now : null,
            ':scheduled_at' => $this->scheduledAt($status, $cleanMeta),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->syncTerms($id, $categories, $tags);
        $this->mediaLibrary()->syncContentReferences($id, $cleanBlocks);

        return $id;
    }

    /** @param list<array<string, mixed>> $blocks @param array<string, mixed> $meta @param list<string> $categories @param list<string> $tags */
    public function update(int $id, string $type, string $title, string $slug, array $blocks, string $status, array $meta = [], array $categories = [], array $tags = []): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new ContentException('Content not found.');
        }
        [$slug, $cleanBlocks] = $this->prepareForSave($type, $title, $slug, $blocks, $status, $id);
        $now = gmdate('c');
        $publishedAt = $existing['published_at'] ?? null;
        if ($status === 'published' && ($publishedAt === null || $publishedAt === '')) {
            $publishedAt = $now;
        }
        $cleanMeta = $this->cleanMeta($meta, $title);
        $stmt = $this->pdo->prepare(
            'UPDATE cms_contents SET content_type = :content_type, title = :title, slug = :slug, status = :status,
                blocks_json = :blocks_json, meta_json = :meta_json, updated_at = :updated_at, published_at = :published_at,
                scheduled_at = :scheduled_at WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':content_type' => $type,
            ':title' => $title,
            ':slug' => $slug,
            ':status' => $status,
            ':blocks_json' => $this->json($cleanBlocks),
            ':meta_json' => $this->json($cleanMeta),
            ':updated_at' => $now,
            ':published_at' => $publishedAt,
            ':scheduled_at' => $this->scheduledAt($status, $cleanMeta),
        ]);
        $this->syncTerms($id, $categories, $tags);
        $this->mediaLibrary()->syncContentReferences($id, $cleanBlocks);
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new ContentException('Content not found.');
        }
        $content = $this->find($id);
        if ($content === null) {
            throw new ContentException('Content not found.');
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->deleteIfTableExists('cms_content_terms', 'content_id', $id);
            $this->deleteIfTableExists('cms_media_references', 'content_id', $id);
            $this->deleteIfTableExists('cms_content_events', 'content_id', $id);
            $this->deleteUrlMappingsForContent($content);
            $stmt = $this->pdo->prepare('DELETE FROM cms_contents WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, content_type, title, slug, status, meta_json, created_at, updated_at, published_at
             FROM cms_contents ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function adminList(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT id, content_type, title, slug, status, meta_json, created_at, updated_at, published_at
             FROM cms_contents ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function adminCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cms_contents')->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_contents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function publicBySlug(string $type, string $slug): ?array
    {
        if (!$this->safePublicType($type) || !$this->safePublicSlug($slug)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM cms_contents WHERE content_type = :type AND slug = :slug AND status = 'published' LIMIT 1");
        $stmt->execute([':type' => $type, ':slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function previewByToken(int $id, string $token): ?array
    {
        if ($id <= 0 || !$this->safePreviewToken($token)) {
            return null;
        }
        $content = $this->find($id);
        if ($content === null) {
            return null;
        }
        $meta = $content['meta'];
        if (!is_array($meta) || !hash_equals((string) ($meta['preview_token'] ?? ''), $token) || strtotime((string) ($meta['preview_expires_at'] ?? '')) < time()) {
            return null;
        }

        return $content;
    }

    /** @return list<array<string, mixed>> */
    public function publicList(string $type = 'article', int $page = 1, int $perPage = 10): array
    {
        if (!$this->safePublicType($type)) {
            return [];
        }
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT * FROM cms_contents WHERE content_type = :type AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function publicCount(string $type = 'article'): int
    {
        if (!$this->safePublicType($type)) {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cms_contents WHERE content_type = :type AND status = 'published'");
        $stmt->execute([':type' => $type]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function publicSearch(string $query, int $page = 1, int $perPage = 10): array
    {
        $query = $this->cleanPublicSearchQuery($query);
        if ($query === '') {
            return [];
        }
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));
        $offset = ($page - 1) * $perPage;
        $like = '%' . $this->escapeLike($query) . '%';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_contents
             WHERE status = 'published'
                AND content_type IN ('article', 'page')
                AND (title LIKE :query_title ESCAPE '\\' OR slug LIKE :query_slug ESCAPE '\\' OR blocks_json LIKE :query_blocks ESCAPE '\\' OR meta_json LIKE :query_meta ESCAPE '\\')
             ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':query_title', $like);
        $stmt->bindValue(':query_slug', $like);
        $stmt->bindValue(':query_blocks', $like);
        $stmt->bindValue(':query_meta', $like);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function publicSearchCount(string $query): int
    {
        $query = $this->cleanPublicSearchQuery($query);
        if ($query === '') {
            return 0;
        }
        $like = '%' . $this->escapeLike($query) . '%';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM cms_contents
             WHERE status = 'published'
                AND content_type IN ('article', 'page')
                AND (title LIKE :query_title ESCAPE '\\' OR slug LIKE :query_slug ESCAPE '\\' OR blocks_json LIKE :query_blocks ESCAPE '\\' OR meta_json LIKE :query_meta ESCAPE '\\')"
        );
        $stmt->execute([
            ':query_title' => $like,
            ':query_slug' => $like,
            ':query_blocks' => $like,
            ':query_meta' => $like,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{id:int,taxonomy:string,name:string,slug:string}|null */
    public function termBySlug(string $taxonomy, string $slug): ?array
    {
        if (!$this->safeTaxonomy($taxonomy) || !$this->safePublicSlug($slug)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, taxonomy, name, slug FROM cms_terms WHERE taxonomy = :taxonomy AND slug = :slug LIMIT 1');
        $stmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'taxonomy' => (string) $row['taxonomy'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
        ] : null;
    }

    /** @return list<array<string, mixed>> */
    public function publicByTerm(string $taxonomy, string $slug, int $page = 1, int $perPage = 10): array
    {
        if (!$this->safeTaxonomy($taxonomy) || !$this->safePublicSlug($slug)) {
            return [];
        }
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT c.* FROM cms_contents c
             INNER JOIN cms_content_terms ct ON ct.content_id = c.id
             INNER JOIN cms_terms t ON t.id = ct.term_id
             WHERE c.status = 'published' AND c.content_type = 'article' AND t.taxonomy = :taxonomy AND t.slug = :slug
             ORDER BY c.published_at DESC, c.id DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':taxonomy', $taxonomy);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function publicCountByTerm(string $taxonomy, string $slug): int
    {
        if (!$this->safeTaxonomy($taxonomy) || !$this->safePublicSlug($slug)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM cms_contents c
             INNER JOIN cms_content_terms ct ON ct.content_id = c.id
             INNER JOIN cms_terms t ON t.id = ct.term_id
             WHERE c.status = 'published' AND c.content_type = 'article' AND t.taxonomy = :taxonomy AND t.slug = :slug"
        );
        $stmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{id:int,name:string,slug:string,taxonomy:string}> */
    public function termsForContent(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, t.taxonomy FROM cms_terms t INNER JOIN cms_content_terms ct ON ct.term_id = t.id WHERE ct.content_id = :id ORDER BY t.taxonomy, t.name'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetchAll();
    }

    /** @return list<array{id:int,taxonomy:string,name:string,slug:string,content_count:int,created_at:string,updated_at:string}> */
    public function terms(string $taxonomy): array
    {
        if (!$this->safeTaxonomy($taxonomy)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.taxonomy, t.name, t.slug, t.created_at, t.updated_at, COUNT(ct.content_id) AS content_count
             FROM cms_terms t
             LEFT JOIN cms_content_terms ct ON ct.term_id = t.id
             WHERE t.taxonomy = :taxonomy
             GROUP BY t.id, t.taxonomy, t.name, t.slug, t.created_at, t.updated_at
             ORDER BY t.name ASC'
        );
        $stmt->execute([':taxonomy' => $taxonomy]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'taxonomy' => (string) $row['taxonomy'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'content_count' => (int) ($row['content_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $stmt->fetchAll());
    }

    /** @return array{id:int,taxonomy:string,name:string,slug:string,content_count:int,created_at:string,updated_at:string}|null */
    public function termById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.taxonomy, t.name, t.slug, t.created_at, t.updated_at, COUNT(ct.content_id) AS content_count
             FROM cms_terms t
             LEFT JOIN cms_content_terms ct ON ct.term_id = t.id
             WHERE t.id = :id
             GROUP BY t.id, t.taxonomy, t.name, t.slug, t.created_at, t.updated_at
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'taxonomy' => (string) $row['taxonomy'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'content_count' => (int) ($row['content_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function saveTerm(string $taxonomy, string $name, string $slug, ?int $id = null): int
    {
        if (!$this->safeTaxonomy($taxonomy)) {
            throw new ContentException('Unsupported taxonomy.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new ContentException('Category name is required.');
        }
        $slug = Slugger::make($slug !== '' ? $slug : $name);
        $existing = $this->pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy = :taxonomy AND slug = :slug LIMIT 1');
        $existing->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
        $existingId = (int) $existing->fetchColumn();
        if ($existingId > 0 && ($id === null || $existingId !== $id)) {
            throw new ContentException('Category slug already exists.');
        }

        $now = gmdate('c');
        if ($id !== null && $id > 0) {
            $current = $this->termById($id);
            if ($current === null || $current['taxonomy'] !== $taxonomy) {
                throw new ContentException('Category not found.');
            }
            $stmt = $this->pdo->prepare('UPDATE cms_terms SET name = :name, slug = :slug, updated_at = :updated_at WHERE id = :id AND taxonomy = :taxonomy');
            $stmt->execute([':id' => $id, ':taxonomy' => $taxonomy, ':name' => $name, ':slug' => $slug, ':updated_at' => $now]);

            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, created_at, updated_at) VALUES (:taxonomy, :name, :slug, :created_at, :updated_at)');
        $stmt->execute([':taxonomy' => $taxonomy, ':name' => $name, ':slug' => $slug, ':created_at' => $now, ':updated_at' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteTerm(int $id, string $taxonomy = 'category'): void
    {
        $term = $this->termById($id);
        if ($term === null || $term['taxonomy'] !== $taxonomy) {
            throw new ContentException('Category not found.');
        }
        if ((int) $term['content_count'] > 0) {
            throw new ContentException('Category is in use.');
        }
        $stmt = $this->pdo->prepare('DELETE FROM cms_terms WHERE id = :id AND taxonomy = :taxonomy');
        $stmt->execute([':id' => $id, ':taxonomy' => $taxonomy]);
    }

    /** @return array{target:string,status:int}|null */
    public function mappedUrl(string $path): ?array
    {
        if (!$this->safeMappedSource($path)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT target_url, status_code FROM cms_url_mappings WHERE source_url = :source ORDER BY id DESC LIMIT 1');
        $stmt->execute([':source' => $path]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $target = (string) ($row['target_url'] ?? '');
        $status = (int) ($row['status_code'] ?? 0);
        if (!$this->safeMappedTarget($target) || !in_array($status, [301, 302, 307, 308], true)) {
            return null;
        }

        return ['target' => $target, 'status' => $status];
    }

    /** @return list<array<string, mixed>> */
    public function sitemapItems(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM cms_contents WHERE status = 'published' ORDER BY updated_at DESC");

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @param list<array<string, mixed>> $blocks @return array{0:string,1:list<array<string,mixed>>} */
    private function prepareForSave(string $type, string $title, string $slug, array $blocks, string $status, ?int $id): array
    {
        if (!$this->types->has($type)) {
            throw new ContentException('Unknown content type: ' . $type);
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new ContentException('Invalid content status.');
        }
        $errors = BlockSanitizer::validate($blocks);
        if ($errors !== []) {
            throw new ContentException(implode(' ', $errors));
        }
        $cleanBlocks = BlockSanitizer::sanitize($blocks, $this->registeredBlockTypes);
        $mediaErrors = $this->mediaLibrary()->validateBlocks($cleanBlocks);
        if ($mediaErrors !== []) {
            throw new ContentException(implode(' ', $mediaErrors));
        }
        $slug = Slugger::make($slug !== '' ? $slug : $title);
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new ContentException('Slug is reserved.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM cms_contents WHERE slug = :slug AND id <> :id LIMIT 1');
        $stmt->execute([':slug' => $slug, ':id' => $id ?? 0]);
        if (is_array($stmt->fetch())) {
            throw new ContentException('Slug already exists.');
        }

        return [$slug, $cleanBlocks];
    }

    private function mediaLibrary(): MediaLibrary
    {
        $root = dirname(__DIR__, 4);
        return new MediaLibrary($this->pdo, $root . '/content/uploads');
    }

    private function deleteIfTableExists(string $table, string $column, int $contentId): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' = :content_id');
        $stmt->execute([':content_id' => $contentId]);
    }

    /** @param array<string,mixed> $content */
    private function deleteUrlMappingsForContent(array $content): void
    {
        if (!$this->tableExists('cms_url_mappings')) {
            return;
        }
        $target = $this->publicPathForContent(
            (string) ($content['content_type'] ?? ''),
            (string) ($content['slug'] ?? ''),
        );
        if ($target === '') {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM cms_url_mappings WHERE target_url = :target_url');
        $stmt->execute([':target_url' => $target]);
    }

    private function publicPathForContent(string $type, string $slug): string
    {
        if (!$this->safePublicSlug($slug)) {
            return '';
        }
        if ($type === 'article') {
            return '/articles/' . rawurlencode($slug);
        }
        if ($type === 'page') {
            return '/' . rawurlencode($slug);
        }

        return '';
    }

    private function safeMappedTarget(string $target): bool
    {
        return $target !== ''
            && $target === trim($target)
            && strlen($target) <= 512
            && str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '..')
            && !str_contains($target, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $target) !== 1;
    }

    private function safeMappedSource(string $source): bool
    {
        return $source !== ''
            && $source === trim($source)
            && strlen($source) <= 512
            && str_starts_with($source, '/')
            && !str_starts_with($source, '//')
            && !str_contains($source, '..')
            && !str_contains($source, '\\')
            && !str_contains($source, '?')
            && !str_contains($source, '#')
            && preg_match('/[\x00-\x1F\x7F]/', $source) !== 1;
    }

    private function safePublicType(string $type): bool
    {
        return $type === trim($type)
            && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) === 1
            && $this->types->has($type);
    }

    private function safePublicSlug(string $slug): bool
    {
        return $slug !== ''
            && $slug === trim($slug)
            && strlen($slug) <= 191
            && !str_contains($slug, '/')
            && !str_contains($slug, '\\')
            && !str_contains($slug, '..')
            && !str_contains($slug, '?')
            && !str_contains($slug, '#')
            && preg_match('/[\x00-\x1F\x7F]/', $slug) !== 1
            && preg_match('/^[\p{L}\p{N}-]+$/u', $slug) === 1;
    }

    private function safeTaxonomy(string $taxonomy): bool
    {
        return $taxonomy === trim($taxonomy)
            && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $taxonomy) === 1;
    }

    private function safePreviewToken(string $token): bool
    {
        return strlen($token) === 32
            && $token === trim($token)
            && preg_match('/^[a-f0-9]+$/', $token) === 1;
    }

    private function cleanPublicSearchQuery(string $query): string
    {
        $query = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $query) ?? '');
        if ($query === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($query, 0, 80, 'UTF-8') : substr($query, 0, 80);
    }

    private function escapeLike(string $query): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
    }

    private function tableExists(string $table): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
            $stmt->execute([':name' => $table]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<mixed,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContentException('Content JSON payload is invalid.');
        }
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    private function cleanMeta(array $meta, string $title): array
    {
        $canonical = trim((string) ($meta['canonical_url'] ?? ''));
        $scheme = strtolower((string) parse_url($canonical, PHP_URL_SCHEME));
        if ($canonical !== '' && !in_array($scheme, ['http', 'https'], true)) {
            throw new ContentException('Canonical URL must be HTTP or HTTPS.');
        }

        $seoTitle = trim((string) ($meta['seo_title'] ?? ''));
        $paidLabel = trim(strip_tags((string) ($meta['paid_content_label'] ?? '解锁全文')));
        $paidEnabled = (bool) ($meta['paid_content_enabled'] ?? false);
        $paidPrice = $this->cleanPaidContentPrice($meta, $paidEnabled);
        $paidCurrency = $this->cleanPaidContentCurrency($meta, $paidEnabled);
        $paidPreviewBlocks = $this->cleanPaidContentPreviewBlocks($meta, $paidEnabled);

        return [
            'seo_title' => $seoTitle !== '' ? $seoTitle : $title,
            'seo_description' => trim((string) ($meta['seo_description'] ?? '')),
            'canonical_url' => $canonical,
            'robots_index' => (bool) ($meta['robots_index'] ?? true),
            'robots_follow' => (bool) ($meta['robots_follow'] ?? true),
            'scheduled_at' => $this->cleanScheduledAt((string) ($meta['scheduled_at'] ?? '')),
            'preview_token' => (string) ($meta['preview_token'] ?? bin2hex(random_bytes(16))),
            'preview_expires_at' => (string) ($meta['preview_expires_at'] ?? gmdate('c', time() + 900)),
            'paid_content_enabled' => $paidEnabled,
            'paid_content_price_minor' => $paidPrice,
            'paid_content_currency' => $paidCurrency,
            'paid_content_label' => $paidLabel !== '' ? $paidLabel : '解锁全文',
            'paid_content_preview_blocks' => $paidPreviewBlocks,
        ];
    }

    /** @param array<string, mixed> $meta */
    private function cleanPaidContentCurrency(array $meta, bool $enabled): string
    {
        $currency = (string) ($meta['paid_content_currency'] ?? 'USD');
        if (!$enabled && $currency === '') {
            return 'USD';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ContentException('Paid content currency must be a three-letter uppercase code.');
        }

        return $currency;
    }

    /** @param array<string, mixed> $meta */
    private function cleanPaidContentPrice(array $meta, bool $enabled): int
    {
        $raw = (string) ($meta['paid_content_price_minor'] ?? '0');
        if (!$enabled && $raw === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,18}$/', $raw) !== 1) {
            if (!$enabled) {
                return 0;
            }
            throw new ContentException('Paid content price must be a positive integer minor-unit value.');
        }
        $amount = (int) $raw;
        if ($enabled && $amount <= 0) {
            throw new ContentException('Paid content price must be a positive integer minor-unit value.');
        }

        return $amount;
    }

    /** @param array<string, mixed> $meta */
    private function cleanPaidContentPreviewBlocks(array $meta, bool $enabled): int
    {
        $raw = trim((string) ($meta['paid_content_preview_blocks'] ?? '1'));
        if (!$enabled && $raw === '') {
            return 1;
        }
        if (preg_match('/^[0-9]{1,3}$/', $raw) !== 1) {
            if (!$enabled) {
                return 1;
            }
            throw new ContentException('Paid content preview block count must be an integer between 0 and 100.');
        }
        $count = (int) $raw;
        if ($count > 100) {
            if (!$enabled) {
                return 1;
            }
            throw new ContentException('Paid content preview block count must be an integer between 0 and 100.');
        }

        return $count;
    }

    /** @param array<string, mixed> $meta */
    private function scheduledAt(string $status, array $meta): ?string
    {
        if ($status !== 'scheduled') {
            return null;
        }
        $value = (string) ($meta['scheduled_at'] ?? '');

        return $value !== '' ? $value : gmdate('c', time() + 3600);
    }

    private function cleanScheduledAt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ContentException('Scheduled publish time is invalid.');
        }

        return gmdate('c', $timestamp);
    }

    /** @param list<string> $categories @param list<string> $tags */
    private function syncTerms(int $contentId, array $categories, array $tags): void
    {
        $this->pdo->prepare('DELETE FROM cms_content_terms WHERE content_id = :id')->execute([':id' => $contentId]);
        foreach ([['category', $categories], ['tag', $tags]] as [$taxonomy, $items]) {
            foreach ($items as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                $slug = Slugger::make($name);
                $termStmt = $this->pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy = :taxonomy AND slug = :slug LIMIT 1');
                $termStmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
                $termId = (int) $termStmt->fetchColumn();
                if ($termId <= 0) {
                    $now = gmdate('c');
                    $insert = $this->pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, created_at, updated_at) VALUES (:taxonomy, :name, :slug, :created_at, :updated_at)');
                    $insert->execute([':taxonomy' => $taxonomy, ':name' => $name, ':slug' => $slug, ':created_at' => $now, ':updated_at' => $now]);
                    $termId = (int) $this->pdo->lastInsertId();
                }
                $existing = $this->pdo->prepare('SELECT content_id FROM cms_content_terms WHERE content_id = :content_id AND term_id = :term_id LIMIT 1');
                $existing->execute([':content_id' => $contentId, ':term_id' => $termId]);
                if ($existing->fetchColumn() === false) {
                    $this->pdo->prepare('INSERT INTO cms_content_terms (content_id, term_id, created_at) VALUES (:content_id, :term_id, :created_at)')
                        ->execute([':content_id' => $contentId, ':term_id' => $termId, ':created_at' => gmdate('c')]);
                }
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['blocks'] = json_decode((string) ($row['blocks_json'] ?? '[]'), true) ?: [];
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $row['meta'] = is_array($meta) ? $meta : [];
        $row['_meta_json_malformed'] = !is_array($meta);

        return $row;
    }
}

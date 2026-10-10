<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;
use Cms\Core\Media\MediaLibrary;

final class ContentRepository
{
    private const STATUSES = ['draft', 'published', 'scheduled', 'archived', 'trash'];
    private const RESERVED_SLUGS = ['install', 'admin', 'login', 'register', 'logout', 'comments', 'health', 'recovery', 'api', 'articles', 'category', 'tag', 'search', 'sitemap.xml', 'robots.txt'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentTypeRegistry $types,
        private readonly array $registeredBlockTypes = [],
        private readonly ?string $rootPath = null,
    ) {
    }

    /** @param list<array<string, mixed>> $blocks @param array<string, mixed> $meta @param list<string> $categories @param list<string> $tags */
    public function create(string $type, string $title, string $slug, array $blocks, string $status = 'draft', array $meta = [], array $categories = [], array $tags = []): int
    {
        $this->mediaLibrary();
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
        (new ContentRevisionRepository($this->pdo))->recordFromContent($existing, null, 'before_update');
        $this->mediaLibrary();
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

    /** Partial update; legacy update() remains a full replacement API.
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function patch(int $id, array $patch, ?array &$before = null): array
    {
        $owns = !$this->pdo->inTransaction();
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($owns) {
            $sqlite ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->pdo->beginTransaction();
        }
        try {
            if (!$sqlite) {
                $lock = $this->pdo->prepare('SELECT id FROM cms_contents WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
            }
            $existing = $this->find($id);
            if ($existing === null) {
                throw new ContentException('Content not found.');
            }
            if (array_key_exists('expected_digest', $patch) && (!is_string($patch['expected_digest'])
                || !hash_equals($this->snapshotDigest($existing, $this->termsForContent($id)), $patch['expected_digest']))) {
                throw new ContentException('Content revision conflict.');
            }
            $before = $existing;
            $mode = $patch['meta_mode'] ?? 'merge';
            if (!in_array($mode, ['merge', 'replace'], true)) {
                throw new ContentException('Invalid meta_mode.');
            }
            foreach (['meta', 'categories', 'tags'] as $key) {
                if (array_key_exists($key, $patch) && !is_array($patch[$key])) {
                    throw new ContentException($key . ' must be an array.');
                }
            }
            $meta = $existing['meta'];
            if (array_key_exists('meta', $patch)) {
                $meta = $mode === 'replace' ? $patch['meta'] : array_replace($meta, $patch['meta']);
            }
            $categories = []; $tags = [];
            foreach ($this->termsForContent($id) as $term) {
                if ($term['taxonomy'] === 'category') { $categories[] = $term['name']; }
                if ($term['taxonomy'] === 'tag') { $tags[] = $term['name']; }
            }
            $this->update($id,
                (string) ($patch['type'] ?? $patch['content_type'] ?? $existing['content_type']),
                (string) ($patch['title'] ?? $existing['title']),
                (string) ($patch['slug'] ?? $existing['slug']),
                $patch['blocks'] ?? $existing['blocks'],
                (string) ($patch['status'] ?? $existing['status']), $meta,
                $patch['categories'] ?? $categories, $patch['tags'] ?? $tags);
            $result = $this->find($id);
            if ($mode === 'merge') {
                // Preserve opaque existing extension fields without accepting arbitrary new meta.
                $untouched = array_diff_key($existing['meta'], $patch['meta'] ?? []);
                $preserved = array_replace($untouched, $result['meta']);
                if ($preserved !== $result['meta']) {
                    $this->pdo->prepare('UPDATE cms_contents SET meta_json = ? WHERE id = ?')
                        ->execute([json_encode($preserved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
                    $result = $this->find($id);
                }
            }
            if ($owns) { $sqlite ? $this->pdo->exec('COMMIT') : $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $error) {
            if ($owns) { $sqlite ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** A content and taxonomy snapshot token; use expected_digest for atomic PATCH. */
    public function digest(int $id): string
    {
        return $this->snapshot($id)['digest'];
    }

    /** A consistent content/terms snapshot for conditional clients. */
    public function snapshot(int $id): array
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) { $this->pdo->beginTransaction(); }
        try {
            $content = $this->find($id);
            if ($content === null) { throw new ContentException('Content not found.'); }
            $digest = $this->snapshotDigest($content, $this->termsForContent($id));
            if ($owns) { $this->pdo->commit(); }
            return ['item' => $content, 'digest' => $digest];
        } catch (\Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function snapshotDigest(array $content, array $terms): string
    {
        $content = array_filter($content, static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
        return hash('sha256', json_encode([$content, $terms], JSON_THROW_ON_ERROR));
    }

    /** Write audit labels only; never accept request bodies or credentials. */
    public function auditWrite(int $id, string $actorType, ?int $actorId, string $action, array $context = []): void
    {
        $safe = ['content_id' => $id];
        foreach (['plugin_id', 'task_id'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && preg_match('/^[A-Za-z0-9_.:-]{1,96}$/D', $context[$key])) {
                $safe[$key] = $context[$key];
            }
        }
        (new \Cms\Core\Audit\AuditLogger($this->pdo))->record($actorType, $actorId, $action, $safe);
    }

    public function delete(int $id): void
    {
        $this->trash($id);
    }

    public function trash(int $id, ?int $actorId = null): void
    {
        $content = $this->find($id);
        if ($content === null) {
            throw new ContentException('Content not found.');
        }
        (new ContentRevisionRepository($this->pdo))->recordFromContent($content, $actorId, 'before_trash');
        $this->pdo->prepare("UPDATE cms_contents SET status = 'trash', deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $id, ':deleted_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
    }

    public function restoreFromTrash(int $id, string $status = 'draft'): void
    {
        if (!in_array($status, ['draft', 'published', 'scheduled', 'archived'], true)) {
            throw new ContentException('Invalid content status.');
        }
        $content = $this->find($id);
        if ($content === null || (string) ($content['status'] ?? '') !== 'trash') {
            throw new ContentException('Trashed content not found.');
        }
        $this->pdo->prepare('UPDATE cms_contents SET status = :status, deleted_at = NULL, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $id, ':status' => $status, ':updated_at' => gmdate('c')]);
    }

    public function hardDelete(int $id): void
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

    public function revisionCount(int $id): int
    {
        return (new ContentRevisionRepository($this->pdo))->countForContent($id);
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

    /** @return array{previous:array<string,mixed>|null,next:array<string,mixed>|null} */
    public function adjacentPublishedArticles(int $contentId, ?string $publishedAt): array
    {
        $publishedAt = trim((string) $publishedAt);
        if ($contentId <= 0 || $publishedAt === '') {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $this->adjacentPublishedArticle($contentId, $publishedAt, 'previous'),
            'next' => $this->adjacentPublishedArticle($contentId, $publishedAt, 'next'),
        ];
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

    /** @return array<string,mixed>|null */
    private function adjacentPublishedArticle(int $contentId, string $publishedAt, string $direction): ?array
    {
        $operator = $direction === 'previous' ? '<' : '>';
        $order = $direction === 'previous' ? 'DESC' : 'ASC';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_contents
             WHERE content_type = 'article'
                AND status = 'published'
                AND id <> :id_exclude
                AND published_at IS NOT NULL
                AND published_at <> ''
                AND (
                    published_at {$operator} :published_at
                    OR (published_at = :published_at_tie AND id {$operator} :id_tie)
                )
             ORDER BY published_at {$order}, id {$order}
             LIMIT 1"
        );
        $stmt->execute([
            ':id_exclude' => $contentId,
            ':published_at' => $publishedAt,
            ':published_at_tie' => $publishedAt,
            ':id_tie' => $contentId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
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

    /** @return array{id:int,taxonomy:string,name:string,slug:string,meta:array<string,mixed>}|null */
    public function termBySlug(string $taxonomy, string $slug): ?array
    {
        if (!$this->safeTaxonomy($taxonomy) || !$this->safePublicSlug($slug)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, taxonomy, name, slug, meta_json FROM cms_terms WHERE taxonomy = :taxonomy AND slug = :slug LIMIT 1');
        $stmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'taxonomy' => (string) $row['taxonomy'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'meta' => $this->decodeMeta($row['meta_json'] ?? null),
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

    /** @return list<array{id:int,name:string,slug:string,taxonomy:string,meta:array<string,mixed>}> */
    public function termsForContent(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, t.taxonomy, t.meta_json FROM cms_terms t INNER JOIN cms_content_terms ct ON ct.term_id = t.id WHERE ct.content_id = :id ORDER BY t.taxonomy, t.name'
        );
        $stmt->execute([':id' => $id]);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'taxonomy' => (string) $row['taxonomy'],
            'meta' => $this->decodeMeta($row['meta_json'] ?? null),
        ], $stmt->fetchAll());
    }

    /** @return list<array{id:int,taxonomy:string,name:string,slug:string,content_count:int,created_at:string,updated_at:string,meta:array<string,mixed>}> */
    public function terms(string $taxonomy): array
    {
        if (!$this->safeTaxonomy($taxonomy)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.taxonomy, t.name, t.slug, t.meta_json, t.created_at, t.updated_at, COUNT(ct.content_id) AS content_count
             FROM cms_terms t
             LEFT JOIN cms_content_terms ct ON ct.term_id = t.id
             WHERE t.taxonomy = :taxonomy
             GROUP BY t.id, t.taxonomy, t.name, t.slug, t.meta_json, t.created_at, t.updated_at
             ORDER BY t.name ASC'
        );
        $stmt->execute([':taxonomy' => $taxonomy]);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'taxonomy' => (string) $row['taxonomy'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'content_count' => (int) ($row['content_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'meta' => $this->decodeMeta($row['meta_json'] ?? null),
        ], $stmt->fetchAll());
    }

    /** @return array{id:int,taxonomy:string,name:string,slug:string,content_count:int,created_at:string,updated_at:string,meta:array<string,mixed>}|null */
    public function termById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.taxonomy, t.name, t.slug, t.meta_json, t.created_at, t.updated_at, COUNT(ct.content_id) AS content_count
             FROM cms_terms t
             LEFT JOIN cms_content_terms ct ON ct.term_id = t.id
             WHERE t.id = :id
             GROUP BY t.id, t.taxonomy, t.name, t.slug, t.meta_json, t.created_at, t.updated_at
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
            'meta' => $this->decodeMeta($row['meta_json'] ?? null),
        ];
    }

    /** @param array<string,mixed>|null $meta */
    public function saveTerm(string $taxonomy, string $name, string $slug, ?int $id = null, ?array $meta = null): int
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
        $cleanMeta = $meta !== null ? $this->cleanTermMeta($meta) : $this->cleanTermMeta([]);
        if ($id !== null && $id > 0) {
            if ($meta === null) {
                $existingTerm = $this->termById($id);
                $cleanMeta = $this->cleanTermMeta(is_array($existingTerm['meta'] ?? null) ? $existingTerm['meta'] : []);
            }
            $stmt = $this->pdo->prepare('UPDATE cms_terms SET name = :name, slug = :slug, meta_json = :meta_json, updated_at = :updated_at WHERE id = :id AND taxonomy = :taxonomy');
            $stmt->execute([':id' => $id, ':taxonomy' => $taxonomy, ':name' => $name, ':slug' => $slug, ':meta_json' => $this->json($cleanMeta), ':updated_at' => $now]);
            if ($stmt->rowCount() < 1 && $this->termById($id) === null) {
                throw new ContentException('Category not found.');
            }

            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, meta_json, created_at, updated_at) VALUES (:taxonomy, :name, :slug, :meta_json, :created_at, :updated_at)');
        $stmt->execute([':taxonomy' => $taxonomy, ':name' => $name, ':slug' => $slug, ':meta_json' => $this->json($cleanMeta), ':created_at' => $now, ':updated_at' => $now]);

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

    /** @return list<array{taxonomy:string,name:string,slug:string,content_count:int,updated_at:string}> */
    public function sitemapTerms(): array
    {
        $stmt = $this->pdo->query(
            "SELECT t.taxonomy, t.name, t.slug, t.meta_json, COUNT(DISTINCT c.id) AS content_count, MAX(c.updated_at) AS updated_at
             FROM cms_terms t
             INNER JOIN cms_content_terms ct ON ct.term_id = t.id
             INNER JOIN cms_contents c ON c.id = ct.content_id
             WHERE c.status = 'published'
               AND c.content_type = 'article'
               AND t.taxonomy IN ('category', 'tag')
             GROUP BY t.id, t.taxonomy, t.name, t.slug, t.meta_json
             HAVING COUNT(DISTINCT c.id) > 0
             ORDER BY t.taxonomy ASC, t.name ASC"
        );

        $terms = [];
        foreach ($stmt->fetchAll() as $row) {
            $slug = (string) $row['slug'];
            if (!$this->safePublicSlug($slug)) {
                continue;
            }
            $meta = $this->decodeMeta($row['meta_json'] ?? null);
            if (($meta['robots_index'] ?? true) !== true) {
                continue;
            }
            $terms[] = [
                'taxonomy' => (string) $row['taxonomy'],
                'name' => (string) $row['name'],
                'slug' => $slug,
                'content_count' => (int) ($row['content_count'] ?? 0),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'meta' => $meta,
            ];
        }

        return $terms;
    }

    /** @return list<array<string,mixed>> */
    public function targetKeywordBindings(): array
    {
        $bindings = [];
        $stmt = $this->pdo->query("SELECT id, content_type, title, slug, meta_json FROM cms_contents WHERE status = 'published' ORDER BY id ASC");
        foreach ($stmt->fetchAll() as $row) {
            $meta = $this->decodeMeta($row['meta_json'] ?? null);
            foreach ($this->keywordList((string) ($meta['target_keywords'] ?? '')) as $keyword) {
                $type = (string) ($row['content_type'] ?? 'article');
                $slug = (string) ($row['slug'] ?? '');
                $urlPath = $type === 'page' ? '/' . ltrim($slug, '/') : '/articles/' . ltrim($slug, '/');
                $fallbackTitle = (string) $row['title'];
                $bindings[] = [
                    'keyword' => $keyword,
                    'source_type' => 'content:' . $type,
                    'source_id' => (int) $row['id'],
                    'content_type' => $type,
                    'title' => $fallbackTitle,
                    'url_path' => $urlPath,
                    'index_status' => (($meta['robots_index'] ?? true) === true) ? 'index' : 'noindex',
                    'canonical' => trim((string) ($meta['canonical_url'] ?? '')) !== '' ? (string) $meta['canonical_url'] : $urlPath,
                    'seo_title' => (string) ($meta['seo_title'] ?? $fallbackTitle),
                    'seo_description' => (string) ($meta['seo_description'] ?? ''),
                    'target_keywords' => (string) ($meta['target_keywords'] ?? ''),
                ];
            }
        }

        $termStmt = $this->pdo->query("SELECT id, taxonomy, name, slug, meta_json FROM cms_terms WHERE taxonomy IN ('category', 'tag') ORDER BY id ASC");
        foreach ($termStmt->fetchAll() as $row) {
            $meta = $this->decodeMeta($row['meta_json'] ?? null);
            foreach ($this->keywordList((string) ($meta['target_keywords'] ?? '')) as $keyword) {
                $taxonomy = (string) $row['taxonomy'];
                $urlPath = '/' . ($taxonomy === 'tag' ? 'tag' : 'category') . '/' . ltrim((string) $row['slug'], '/');
                $bindings[] = [
                    'keyword' => $keyword,
                    'source_type' => 'term:' . $taxonomy,
                    'source_id' => (int) $row['id'],
                    'content_type' => $taxonomy,
                    'title' => (string) $row['name'],
                    'url_path' => $urlPath,
                    'index_status' => (($meta['robots_index'] ?? true) === true) ? 'index' : 'noindex',
                    'canonical' => trim((string) ($meta['canonical_url'] ?? '')) !== '' ? (string) $meta['canonical_url'] : $urlPath,
                    'seo_title' => trim((string) ($meta['seo_title'] ?? '')) !== '' ? (string) $meta['seo_title'] : ucfirst($taxonomy) . ': ' . (string) $row['name'],
                    'seo_description' => (string) ($meta['seo_description'] ?? ''),
                    'target_keywords' => (string) ($meta['target_keywords'] ?? ''),
                ];
            }
        }

        return $bindings;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function targetKeywordConflicts(): array
    {
        $grouped = [];
        foreach ($this->targetKeywordBindings() as $binding) {
            $grouped[$binding['keyword']][] = $binding;
        }

        return array_filter($grouped, static fn (array $items): bool => count($items) > 1);
    }

    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function seoKeywordCenterRows(array $filters = []): array
    {
        $bindingsByKeyword = [];
        foreach ($this->targetKeywordBindings() as $binding) {
            $bindingsByKeyword[(string) $binding['keyword']][] = $binding;
        }
        $configs = $this->seoKeywordConfigs();
        $keywords = array_values(array_unique(array_merge(array_keys($bindingsByKeyword), array_keys($configs))));
        sort($keywords, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];
        foreach ($keywords as $keyword) {
            $bindings = $bindingsByKeyword[$keyword] ?? [];
            $config = $configs[$keyword] ?? [];
            $bindingCount = count($bindings);
            $status = (string) ($config['status'] ?? ($bindingCount > 0 ? 'active' : 'draft'));
            $source = (string) ($config['source'] ?? ($bindingCount > 0 ? 'content' : 'manual'));
            $primaryUrl = (string) ($config['primary_url'] ?? '');
            $contentTypes = array_values(array_unique(array_map(static fn (array $binding): string => (string) ($binding['content_type'] ?? $binding['source_type'] ?? ''), $bindings)));
            $updatedAt = (string) ($config['updated_at'] ?? '');
            $conflict = $bindingCount > 1;

            if (!$this->seoKeywordRowMatches($keyword, $status, $conflict, $contentTypes, $filters)) {
                continue;
            }

            $rows[] = [
                'keyword' => $keyword,
                'primary_url' => $primaryUrl,
                'binding_count' => $bindingCount,
                'content_types' => $contentTypes,
                'source' => $source,
                'status' => $status,
                'conflict' => $conflict,
                'updated_at' => $updatedAt,
                'bindings' => $bindings,
                'search_metrics_connected' => false,
            ];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function seoKeywordCenterStats(): array
    {
        $rows = $this->seoKeywordCenterRows();

        return [
            'total' => count($rows),
            'active' => count(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'active')),
            'conflicts' => count(array_filter($rows, static fn (array $row): bool => ($row['conflict'] ?? false) === true)),
            'unassigned_draft' => count(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'draft' || (string) ($row['primary_url'] ?? '') === '')),
            'search_metrics_connected' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function seoKeywordDetail(string $keyword): array
    {
        $keyword = $this->cleanKeyword($keyword);
        $rows = $this->seoKeywordCenterRows(['q' => $keyword]);
        foreach ($rows as $row) {
            if ((string) ($row['keyword'] ?? '') === $keyword) {
                $row['observed_metrics'] = $this->seoKeywordMetrics($keyword);
                return $row;
            }
        }

        return [
            'keyword' => $keyword,
            'primary_url' => '',
            'binding_count' => 0,
            'content_types' => [],
            'source' => 'manual',
            'status' => 'draft',
            'conflict' => false,
            'updated_at' => '',
            'bindings' => [],
            'observed_metrics' => $this->seoKeywordMetrics($keyword),
            'search_metrics_connected' => false,
        ];
    }

    public function saveSeoKeyword(string $keyword, string $primaryUrl, string $status, string $source, string $notes = ''): void
    {
        $keyword = $this->cleanKeyword($keyword);
        if ($keyword === '') {
            throw new ContentException('Keyword is required.');
        }
        if (!in_array($status, ['draft', 'active', 'paused'], true)) {
            throw new ContentException('Invalid keyword status.');
        }
        if (!in_array($source, ['manual', 'content', 'baidu', 'google', 'ai_suggestion'], true)) {
            throw new ContentException('Invalid keyword source.');
        }
        $primaryUrl = trim($primaryUrl);
        if ($primaryUrl !== '' && !str_starts_with($primaryUrl, '/') && !in_array(strtolower((string) parse_url($primaryUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new ContentException('Primary URL must be a site path or HTTP/HTTPS URL.');
        }
        $notes = trim(strip_tags($notes));
        $now = gmdate('c');
        $existing = $this->pdo->prepare('SELECT id FROM cms_seo_keywords WHERE keyword = :keyword LIMIT 1');
        $existing->execute([':keyword' => $keyword]);
        $id = (int) $existing->fetchColumn();
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE cms_seo_keywords SET primary_url = :primary_url, status = :status, source = :source, notes = :notes, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([':id' => $id, ':primary_url' => $primaryUrl, ':status' => $status, ':source' => $source, ':notes' => $notes, ':updated_at' => $now]);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_seo_keywords (keyword, primary_url, status, source, notes, created_at, updated_at) VALUES (:keyword, :primary_url, :status, :source, :notes, :created_at, :updated_at)');
        $stmt->execute([':keyword' => $keyword, ':primary_url' => $primaryUrl, ':status' => $status, ':source' => $source, ':notes' => $notes, ':created_at' => $now, ':updated_at' => $now]);
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
        $root = $this->rootPath ?? (defined('CMS_ROOT') ? (string) constant('CMS_ROOT') : '');
        $resolvedRoot = $root !== '' ? realpath($root) : false;
        if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link(rtrim($root, '/'))) {
            throw new ContentException('A trusted CMS instance root is required for media storage.');
        }

        foreach (['content', 'content/uploads'] as $relative) {
            if (is_link($resolvedRoot . '/' . $relative)) {
                throw new ContentException('Symlinked media directories are not allowed.');
            }
        }
        return new MediaLibrary($this->pdo, $resolvedRoot . '/content/uploads');
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
            'seo_keywords' => implode(', ', $this->keywordList((string) ($meta['seo_keywords'] ?? ''))),
            'target_keywords' => implode(', ', $this->keywordList((string) ($meta['target_keywords'] ?? ''))),
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

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function cleanTermMeta(array $meta): array
    {
        $canonical = trim((string) ($meta['canonical_url'] ?? ''));
        $scheme = strtolower((string) parse_url($canonical, PHP_URL_SCHEME));
        if ($canonical !== '' && !in_array($scheme, ['http', 'https'], true)) {
            throw new ContentException('Canonical URL must be HTTP or HTTPS.');
        }

        return [
            'seo_title' => trim((string) ($meta['seo_title'] ?? '')),
            'seo_description' => trim((string) ($meta['seo_description'] ?? '')),
            'seo_keywords' => implode(', ', $this->keywordList((string) ($meta['seo_keywords'] ?? ''))),
            'target_keywords' => implode(', ', $this->keywordList((string) ($meta['target_keywords'] ?? ''))),
            'canonical_url' => $canonical,
            'robots_index' => (bool) ($meta['robots_index'] ?? true),
            'robots_follow' => (bool) ($meta['robots_follow'] ?? true),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function seoKeywordConfigs(): array
    {
        if (!$this->tableExists('cms_seo_keywords')) {
            return [];
        }
        $configs = [];
        foreach ($this->pdo->query('SELECT keyword, primary_url, status, source, notes, created_at, updated_at FROM cms_seo_keywords ORDER BY keyword ASC')->fetchAll() as $row) {
            $keyword = $this->cleanKeyword((string) ($row['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $configs[$keyword] = [
                'keyword' => $keyword,
                'primary_url' => (string) ($row['primary_url'] ?? ''),
                'status' => (string) ($row['status'] ?? 'draft'),
                'source' => (string) ($row['source'] ?? 'manual'),
                'notes' => (string) ($row['notes'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $configs;
    }

    /** @return list<array<string,string>> */
    private function seoKeywordMetrics(string $keyword): array
    {
        if (!$this->tableExists('cms_seo_keyword_metrics')) {
            return [];
        }
        $keyword = $this->cleanKeyword($keyword);
        try {
            $stmt = $this->pdo->prepare('SELECT keyword, url_path, search_engine, impressions, clicks, ctr, average_position, period_start, period_end, source, created_at FROM cms_seo_keyword_metrics WHERE keyword = :keyword ORDER BY period_end DESC, search_engine ASC, url_path ASC');
            $stmt->execute([':keyword' => $keyword]);
            return array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => $value === null || $value === '' ? 'Not Available' : (string) $value, $row), $stmt->fetchAll());
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $contentTypes @param array<string,string> $filters */
    private function seoKeywordRowMatches(string $keyword, string $status, bool $conflict, array $contentTypes, array $filters): bool
    {
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '' && stripos($keyword, $query) === false) {
            return false;
        }
        $statusFilter = trim((string) ($filters['status'] ?? ''));
        if ($statusFilter !== '' && $statusFilter !== 'all' && $status !== $statusFilter) {
            return false;
        }
        $conflictFilter = trim((string) ($filters['conflict'] ?? ''));
        if ($conflictFilter === 'yes' && !$conflict) {
            return false;
        }
        if ($conflictFilter === 'no' && $conflict) {
            return false;
        }
        $typeFilter = trim((string) ($filters['type'] ?? ''));
        if ($typeFilter !== '' && $typeFilter !== 'all' && !in_array($typeFilter, $contentTypes, true)) {
            return false;
        }

        return true;
    }

    private function cleanKeyword(string $keyword): string
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags($keyword)) ?? $keyword);
        if (function_exists('mb_substr')) {
            return mb_substr($keyword, 0, 120, 'UTF-8');
        }

        return substr($keyword, 0, 120);
    }

    /** @return list<string> */
    private function keywordList(string $value): array
    {
        $parts = preg_split('/[,，\n\r]+/u', strip_tags($value)) ?: [];
        $keywords = [];
        foreach ($parts as $part) {
            $keyword = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? (string) $part);
            if ($keyword === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $keyword = mb_substr($keyword, 0, 80, 'UTF-8');
            } else {
                $keyword = substr($keyword, 0, 80);
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
            $keywords[$key] = $keyword;
            if (count($keywords) >= 20) {
                break;
            }
        }

        return array_values($keywords);
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
                $termStmt = $this->pdo->prepare(
                    'SELECT id FROM cms_terms
                     WHERE taxonomy = :taxonomy AND (name = :name OR slug = :slug)
                     ORDER BY CASE WHEN name = :name_order THEN 0 ELSE 1 END, id ASC
                     LIMIT 1'
                );
                $termStmt->execute([
                    ':taxonomy' => $taxonomy,
                    ':name' => $name,
                    ':slug' => $slug,
                    ':name_order' => $name,
                ]);
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
        $row['meta'] = $this->decodeMeta($row['meta_json'] ?? null);
        $row['_meta_json_malformed'] = !is_array(json_decode((string) ($row['meta_json'] ?? '{}'), true));

        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeMeta(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\Rest;

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Comment\CommentRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Foundation\FoundationVersions;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Security\SecretRedactor;
use Cms\Core\Support\PublicApiRegistry;
use PDO;

final class ApiV1Controller
{
    public function __construct(private readonly Settings $settings, private readonly string $rootPath)
    {
    }

    public function index(Request $request): Response
    {
        return $this->ok([
            'name' => 'Daiying CMS REST API',
            'versions' => FoundationVersions::all(),
            'resources' => [
                'contents' => '/api/v1/contents',
                'pages' => '/api/v1/pages',
                'categories' => '/api/v1/categories',
                'tags' => '/api/v1/tags',
                'media' => '/api/v1/media',
                'comments' => '/api/v1/comments',
                'users' => '/api/v1/users',
                'settings' => '/api/v1/settings',
            ],
            'contracts' => PublicApiRegistry::contracts(),
        ]);
    }

    public function contents(Request $request): Response
    {
        $type = (string) ($request->query['type'] ?? 'article');
        if (str_starts_with($request->path, '/api/v1/pages')) {
            $type = 'page';
        }
        $repo = $this->contentsRepository();
        $id = $this->resourceId($request, 'contents');
        if ($request->method === 'POST') {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            return $this->createContent($repo, $request, $type);
        }
        if (in_array($request->method, ['PATCH', 'PUT'], true)) {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            return $this->updateContent($repo, $request, $id);
        }
        if ($request->method === 'DELETE') {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            return $this->deleteContent($repo, $request, $id);
        }
        if ($id > 0) {
            $content = $repo->find($id);
            if ($content === null || ((string) ($content['status'] ?? '') !== 'published' && !$this->authorized($request))) {
                return $this->error('not_found', 'Content not found.', 404);
            }

            return $this->ok(['item' => $this->serializeContent($content)]);
        }
        $page = $this->positiveInt($request->query['page'] ?? 1, 1, 100000);
        $perPage = $this->positiveInt($request->query['per_page'] ?? 10, 1, 50);
        $items = array_map(fn (array $content): array => $this->serializeContent($content), $repo->publicList($type, $page, $perPage));
        $total = $repo->publicCount($type);

        return $this->ok([
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ]);
    }

    public function categories(Request $request): Response
    {
        return $this->terms($request, 'category');
    }

    public function tags(Request $request): Response
    {
        return $this->terms($request, 'tag');
    }

    public function media(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return $this->notImplemented('Media write support will be added behind the REST API v1 contract.');
        }
        $page = $this->positiveInt($request->query['page'] ?? 1, 1, 100000);
        $perPage = $this->positiveInt($request->query['per_page'] ?? 20, 1, 50);
        $type = (string) ($request->query['type'] ?? '');
        $library = new MediaLibrary($this->pdo(), $this->rootPath . '/content/uploads');
        $items = $library->list($type !== '' ? ['type' => $type, 'status' => 'Active'] : ['status' => 'Active'], $perPage);
        if ($page > 1) {
            $items = [];
        }

        return $this->ok([
            'items' => array_map(fn (array $media): array => $this->serializeMedia($media), $items),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'has_more' => count($items) === $perPage],
        ]);
    }

    public function comments(Request $request): Response
    {
        if (!$this->authorized($request)) {
            return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
        }
        $repo = new CommentRepository($this->pdo());
        $id = $this->resourceId($request, 'comments');
        if (in_array($request->method, ['PATCH', 'PUT'], true)) {
            $status = (string) ($request->body['status'] ?? '');
            try {
                $repo->setStatus($id, $status);
                return $this->ok(['id' => $id, 'status' => $status]);
            } catch (\Throwable $exception) {
                return $this->error('invalid_request', $exception->getMessage(), 422);
            }
        }
        if ($request->method === 'DELETE') {
            try {
                $repo->delete($id);
                return $this->ok(['id' => $id, 'deleted' => true]);
            } catch (\Throwable $exception) {
                return $this->error('invalid_request', $exception->getMessage(), 422);
            }
        }
        $status = (string) ($request->query['status'] ?? '');
        $limit = $this->positiveInt($request->query['per_page'] ?? 50, 1, 100);

        return $this->ok([
            'items' => array_map(fn (array $comment): array => $this->serializeComment($comment), $repo->adminList($status, $limit)),
        ]);
    }

    public function users(Request $request): Response
    {
        if (!$this->authorized($request)) {
            return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
        }

        return $this->ok([
            'admins' => $this->adminUsers(),
            'front_users' => $this->frontUsers(),
        ]);
    }

    public function settings(Request $request): Response
    {
        if (!$this->authorized($request)) {
            return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
        }

        return $this->ok([
            'site' => [
                'name' => (string) $this->settings->get('site.name', ''),
                'url' => (string) $this->settings->get('site.url', ''),
            ],
            'app' => [
                'version' => (string) $this->settings->get('app.version', ''),
            ],
            'api' => [
                'versions' => FoundationVersions::all(),
            ],
        ]);
    }

    private function terms(Request $request, string $taxonomy): Response
    {
        $repo = $this->contentsRepository();
        if ($request->method === 'POST') {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            return $this->saveTerm($repo, $request, $taxonomy);
        }
        if (in_array($request->method, ['PATCH', 'PUT'], true)) {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            $id = $this->resourceId($request, $taxonomy === 'tag' ? 'tags' : 'categories');
            if ($id <= 0) {
                return $this->error('invalid_request', 'Term id is required.', 422);
            }
            return $this->saveTerm($repo, $request, $taxonomy, $id);
        }
        if ($request->method === 'DELETE') {
            if (!$this->authorized($request)) {
                return $this->error('unauthorized', 'REST API admin token or admin session is required.', 401);
            }
            try {
                $id = $this->resourceId($request, $taxonomy === 'tag' ? 'tags' : 'categories');
                $repo->deleteTerm($id, $taxonomy);
                return $this->ok(['id' => $id, 'deleted' => true]);
            } catch (\Throwable $exception) {
                return $this->error('invalid_request', $exception->getMessage(), 422);
            }
        }

        return $this->ok(['items' => $repo->terms($taxonomy)]);
    }

    private function contentsRepository(): ContentRepository
    {
        return new ContentRepository($this->pdo(), ContentTypeRegistry::defaults());
    }

    private function pdo(): PDO
    {
        return ConnectionFactory::make($this->settings);
    }

    private function createContent(ContentRepository $repo, Request $request, string $type): Response
    {
        try {
            $id = $repo->create(
                (string) ($request->body['type'] ?? $request->body['content_type'] ?? $type),
                $this->requiredString($request->body['title'] ?? '', 'title'),
                (string) ($request->body['slug'] ?? ''),
                $this->arrayInput($request->body['blocks'] ?? []),
                (string) ($request->body['status'] ?? 'draft'),
                $this->arrayInput($request->body['meta'] ?? []),
                $this->stringList($request->body['categories'] ?? []),
                $this->stringList($request->body['tags'] ?? []),
            );

            return Response::json(['ok' => true, 'data' => ['item' => $this->serializeContent($repo->find($id) ?? ['id' => $id])]], 201)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 422);
        }
    }

    private function updateContent(ContentRepository $repo, Request $request, int $id): Response
    {
        try {
            $existing = $repo->find($id);
            if ($existing === null) {
                return $this->error('not_found', 'Content not found.', 404);
            }
            $repo->update(
                $id,
                (string) ($request->body['type'] ?? $request->body['content_type'] ?? $existing['content_type']),
                (string) ($request->body['title'] ?? $existing['title']),
                (string) ($request->body['slug'] ?? $existing['slug']),
                $this->arrayInput($request->body['blocks'] ?? $existing['blocks'] ?? []),
                (string) ($request->body['status'] ?? $existing['status']),
                $this->arrayInput($request->body['meta'] ?? $existing['meta'] ?? []),
                $this->stringList($request->body['categories'] ?? []),
                $this->stringList($request->body['tags'] ?? []),
            );

            return $this->ok(['item' => $this->serializeContent($repo->find($id) ?? ['id' => $id])]);
        } catch (\Throwable $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 422);
        }
    }

    private function deleteContent(ContentRepository $repo, Request $request, int $id): Response
    {
        try {
            if ((bool) ($request->body['hard_delete'] ?? $request->query['hard_delete'] ?? false)) {
                $repo->hardDelete($id);
                return $this->ok(['id' => $id, 'deleted' => true, 'hard_deleted' => true]);
            }
            $repo->trash($id);

            return $this->ok(['id' => $id, 'deleted' => true, 'status' => 'trash']);
        } catch (\Throwable $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 422);
        }
    }

    private function saveTerm(ContentRepository $repo, Request $request, string $taxonomy, ?int $id = null): Response
    {
        try {
            $termId = $repo->saveTerm(
                $taxonomy,
                $this->requiredString($request->body['name'] ?? '', 'name'),
                (string) ($request->body['slug'] ?? ''),
                $id,
            );

            return $this->ok(['item' => $repo->termById($termId)]);
        } catch (\Throwable $exception) {
            return $this->error('invalid_request', $exception->getMessage(), 422);
        }
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function serializeContent(array $content): array
    {
        return [
            'id' => (int) $content['id'],
            'type' => (string) $content['content_type'],
            'title' => (string) $content['title'],
            'slug' => (string) $content['slug'],
            'status' => (string) $content['status'],
            'blocks' => $content['blocks'] ?? [],
            'meta' => SecretRedactor::redact(is_array($content['meta'] ?? null) ? $content['meta'] : []),
            'created_at' => (string) ($content['created_at'] ?? ''),
            'updated_at' => (string) ($content['updated_at'] ?? ''),
            'published_at' => (string) ($content['published_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $media @return array<string,mixed> */
    private function serializeMedia(array $media): array
    {
        return [
            'id' => (int) $media['id'],
            'type' => (string) $media['media_type'],
            'mime_type' => (string) $media['mime_type'],
            'filename' => (string) $media['original_name'],
            'url' => '/media/' . (int) $media['id'],
            'byte_size' => (int) $media['byte_size'],
            'title' => (string) ($media['title'] ?? ''),
            'alt_text' => (string) ($media['alt_text'] ?? ''),
            'provider' => (string) ($media['storage_provider'] ?? 'local'),
            'created_at' => (string) ($media['created_at'] ?? ''),
            'updated_at' => (string) ($media['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $comment @return array<string,mixed> */
    private function serializeComment(array $comment): array
    {
        return [
            'id' => (int) $comment['id'],
            'content_id' => (int) ($comment['content_id'] ?? 0),
            'author_name' => (string) ($comment['author_name'] ?? ''),
            'author_email' => $this->redactedEmail((string) ($comment['author_email'] ?? '')),
            'body' => (string) ($comment['body'] ?? ''),
            'status' => (string) ($comment['status'] ?? ''),
            'content_title' => (string) ($comment['content_title'] ?? ''),
            'created_at' => (string) ($comment['created_at'] ?? ''),
            'updated_at' => (string) ($comment['updated_at'] ?? ''),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function adminUsers(): array
    {
        if (!$this->tableExists('cms_admin_users')) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT id, email, display_name, created_at, updated_at FROM cms_admin_users ORDER BY id ASC LIMIT 100')->fetchAll();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => $this->redactedEmail((string) ($row['email'] ?? '')),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'role' => 'admin',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function frontUsers(): array
    {
        if (!$this->tableExists('cms_front_users')) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT id, email, display_name, status, created_at, updated_at, last_login_at FROM cms_front_users ORDER BY id ASC LIMIT 100')->fetchAll();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => $this->redactedEmail((string) ($row['email'] ?? '')),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'last_login_at' => (string) ($row['last_login_at'] ?? ''),
        ], $rows);
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int,has_more:bool} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ];
    }

    private function positiveInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function resourceId(Request $request, string $resource): int
    {
        if (isset($request->body['id']) || isset($request->query['id'])) {
            return max(0, (int) ($request->body['id'] ?? $request->query['id']));
        }
        if (preg_match('#/api/v1/' . preg_quote($resource, '#') . '/([0-9]+)$#', $request->path, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' is required.');
        }

        return $value;
    }

    /** @return array<string,mixed>|list<mixed> */
    private function arrayInput(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n|,/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }

    private function redactedEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }
        [$name, $domain] = explode('@', $email, 2);
        $prefix = substr($name, 0, 1);

        return $prefix . '***@' . $domain;
    }

    private function tableExists(string $table): bool
    {
        try {
            if ((string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
                $stmt->execute([':name' => $table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table));
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function authorized(Request $request): bool
    {
        try {
            if ((new AdminAuthenticator($this->pdo()))->user() !== null) {
                return true;
            }
        } catch (\Throwable) {
        }
        $header = (string) ($request->server['HTTP_AUTHORIZATION'] ?? $request->server['Authorization'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return false;
        }
        $token = trim($matches[1]);
        if ($token === '') {
            return false;
        }
        $expected = (string) ($this->settings->get('api.admin_token_sha256', '') ?: $this->settings->get('api.tokens.admin_sha256', ''));
        return $expected !== '' && hash_equals($expected, hash('sha256', $token));
    }

    /** @param array<string,mixed> $data */
    private function ok(array $data): Response
    {
        return Response::json(['ok' => true, 'data' => $data])->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function notImplemented(string $message): Response
    {
        return $this->error('not_implemented', $message, 501);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ], $status)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}

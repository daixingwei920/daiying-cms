<?php

declare(strict_types=1);

namespace Cms\Core\Rest;

use Cms\Core\Auth\AdminAuthenticator;
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
                'settings' => '/api/v1/settings',
            ],
            'contracts' => PublicApiRegistry::contracts(),
        ]);
    }

    public function contents(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return $this->notImplemented('Write support for contents will be added behind the REST API v1 contract.');
        }
        $type = (string) ($request->query['type'] ?? 'article');
        if ($request->path === '/api/v1/pages') {
            $type = 'page';
        }
        $page = $this->positiveInt($request->query['page'] ?? 1, 1, 100000);
        $perPage = $this->positiveInt($request->query['per_page'] ?? 10, 1, 50);
        $repo = $this->contentsRepository();
        $items = array_map(fn (array $content): array => $this->serializeContent($content), $repo->publicList($type, $page, $perPage));
        $total = $repo->publicCount($type);

        return $this->ok([
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ]);
    }

    public function categories(Request $request): Response
    {
        return $this->terms('category');
    }

    public function tags(Request $request): Response
    {
        return $this->terms('tag');
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

    private function terms(string $taxonomy): Response
    {
        $repo = $this->contentsRepository();

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

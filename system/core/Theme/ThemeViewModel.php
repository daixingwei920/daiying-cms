<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

final class ThemeViewModel
{
    /** @param array<string,mixed> $media @return array{id:int|null,url:string,title:string,alt:string,mime:string,size:int|null,provider:string,metadata:array<string,mixed>} */
    public static function media(array $media): array
    {
        $meta = is_array($media['metadata'] ?? null) ? $media['metadata'] : (is_array($media['meta'] ?? null) ? $media['meta'] : []);
        $url = trim((string) ($media['url'] ?? $media['public_url'] ?? $media['path'] ?? ''));

        return [
            'id' => isset($media['id']) ? (int) $media['id'] : null,
            'url' => $url,
            'title' => trim((string) ($media['title'] ?? $media['filename'] ?? $media['name'] ?? '')),
            'alt' => trim((string) ($media['alt'] ?? $media['alt_text'] ?? $media['title'] ?? '')),
            'mime' => trim((string) ($media['mime_type'] ?? $media['mime'] ?? '')),
            'size' => isset($media['size']) ? (int) $media['size'] : (isset($media['file_size']) ? (int) $media['file_size'] : null),
            'provider' => trim((string) ($media['provider'] ?? $media['storage_provider'] ?? 'local')),
            'metadata' => $meta,
        ];
    }

    /** @param array<string,mixed> $query @return array{current:int,total:int,per_page:int,total_items:int|null,has_prev:bool,has_next:bool,prev_url:string|null,next_url:string|null,pages:list<array{page:int,url:string,current:bool}>} */
    public static function pagination(int $current, int $total, string $basePath, array $query = [], int $perPage = 20, ?int $totalItems = null): array
    {
        $current = max(1, $current);
        $total = max(1, $total);
        $current = min($current, $total);
        $windowStart = max(1, $current - 2);
        $windowEnd = min($total, $current + 2);
        $pages = [];
        for ($page = $windowStart; $page <= $windowEnd; $page++) {
            $pages[] = [
                'page' => $page,
                'url' => self::url($basePath, $query + ['page' => $page]),
                'current' => $page === $current,
            ];
        }

        return [
            'current' => $current,
            'total' => $total,
            'per_page' => max(1, $perPage),
            'total_items' => $totalItems,
            'has_prev' => $current > 1,
            'has_next' => $current < $total,
            'prev_url' => $current > 1 ? self::url($basePath, $query + ['page' => $current - 1]) : null,
            'next_url' => $current < $total ? self::url($basePath, $query + ['page' => $current + 1]) : null,
            'pages' => $pages,
        ];
    }

    /** @param list<array<string,mixed>|string> $items @return list<array{label:string,url:string|null,current:bool}> */
    public static function breadcrumb(array $items): array
    {
        $result = [];
        foreach ($items as $index => $item) {
            if (is_string($item)) {
                $result[] = ['label' => $item, 'url' => null, 'current' => $index === array_key_last($items)];
                continue;
            }
            $label = trim((string) ($item['label'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $url = isset($item['url']) ? trim((string) $item['url']) : null;
            $result[] = [
                'label' => $label,
                'url' => $url !== '' ? $url : null,
                'current' => (bool) ($item['current'] ?? ($index === array_key_last($items))),
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $query */
    public static function url(string $path, array $query = []): string
    {
        $path = trim($path) !== '' ? trim($path) : '/';
        if (!str_starts_with($path, '/') && !preg_match('/^https?:\/\//i', $path)) {
            $path = '/' . $path;
        }
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $query === [] ? $path : $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
    }

    /** @param list<array<string,mixed>> $items @return list<array{label:string,url:string,current:bool}> */
    public static function menu(array $items, string $currentPath = ''): array
    {
        $menu = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? $item['title'] ?? $item['name'] ?? ''));
            $url = trim((string) ($item['url'] ?? $item['href'] ?? '#'));
            if ($label === '') {
                continue;
            }
            $menu[] = [
                'label' => $label,
                'url' => $url !== '' ? $url : '#',
                'current' => (bool) ($item['current'] ?? ($currentPath !== '' && $url === $currentPath)),
            ];
        }

        return $menu;
    }

    public static function assetUrl(string $themeId, string $assetPath): string
    {
        $assetPath = self::cleanRelativePath($assetPath);

        return '/content/themes/' . rawurlencode($themeId) . '/assets/' . str_replace('%2F', '/', rawurlencode($assetPath));
    }

    public static function cleanRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ThemeException('Theme path traversal is not allowed.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}

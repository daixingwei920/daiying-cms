<?php

declare(strict_types=1);

namespace Official\Storage\Baidu;

use Cms\Core\Media\MediaProviderItem;
use Cms\Core\Media\RemoteMediaProviderInterface;

final class BaiduStorageProvider implements RemoteMediaProviderInterface
{
    public function __construct(
        private readonly BaiduApiClient $api,
        private readonly BaiduFileBrowser $browser,
        private readonly string $downloadSecret,
    ) {
    }

    public function id(): string
    {
        return BaiduTokenRepository::PLUGIN_ID;
    }

    public function label(): string
    {
        return '百度网盘';
    }

    public function defaultPath(): string
    {
        return 'baidu://root';
    }

    /** @return array{items:list<MediaProviderItem>,pagination:array<string,mixed>,parent?:array<string,mixed>|null} */
    public function list(string $path = '', array $options = []): array
    {
        $page = max(0, (int) ($options['page'] ?? 0));
        $pageSize = max(1, min(100, (int) ($options['page_size'] ?? 50)));
        $result = $this->api->listFiles($this->apiPath($path), $page, $pageSize);
        $items = $this->items($result['list'] ?? []);
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => count($items) >= $pageSize,
            ],
            'parent' => $this->parent($path),
        ];
    }

    /** @return array{items:list<MediaProviderItem>,pagination:array<string,mixed>} */
    public function search(string $query, string $path = '', array $options = []): array
    {
        $page = max(0, (int) ($options['page'] ?? 0));
        $pageSize = max(1, min(100, (int) ($options['page_size'] ?? 50)));
        $result = $this->api->searchFiles($query, $this->apiPath($path), $page, $pageSize);
        $items = $this->items($result['list'] ?? []);
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => count($items) >= $pageSize,
            ],
        ];
    }

    public function get(string $remoteId, string $path = ''): MediaProviderItem
    {
        $meta = $this->api->fileMeta($remoteId, false);
        if (!isset($meta['path']) && $path !== '') {
            $meta['path'] = $this->apiPath($path);
        }
        return $this->browser->item($meta);
    }

    /** @param array<string,mixed> $media @return array{url:string,expires:?string,headers?:array<string,string>} */
    public function resolveUrl(array $media, array $options = []): array
    {
        $mediaId = (int) ($media['id'] ?? 0);
        $remoteId = (string) ($media['metadata']['remote_id'] ?? '');
        if ($mediaId <= 0 || $remoteId === '') {
            throw new \RuntimeException('百度网盘媒体引用无效。');
        }
        $expires = time() + 300;
        $sig = hash_hmac('sha256', $mediaId . ':' . $remoteId . ':' . $expires, $this->downloadSecret);
        return [
            'url' => '/baidu-storage/media/' . $mediaId . '?expires=' . $expires . '&sig=' . $sig,
            'expires' => gmdate('c', $expires),
        ];
    }

    public function upload(string $sourcePath, string $filename, string $targetPath, string $mimeType): MediaProviderItem
    {
        throw new \RuntimeException('当前版本暂不支持上传到百度网盘。');
    }

    public function delete(string $remoteId, string $path = ''): void
    {
        throw new \RuntimeException('当前版本不会删除百度网盘真实文件。');
    }

    public function move(string $remoteId, string $path, string $destinationPath): MediaProviderItem
    {
        throw new \RuntimeException('当前版本暂不支持移动百度网盘文件。');
    }

    /** @return array<string,mixed> */
    public function metadata(string $remoteId, string $path = ''): array
    {
        return $this->api->fileMeta($remoteId, false);
    }

    /** @return array{filename:string,mime_type:string,byte_size:int} */
    public function downloadTo(string $remoteId, string $path, string $targetPath, int $maxBytes): array
    {
        $item = $this->get($remoteId, $path);
        $url = $this->api->resolveDownloadUrl($remoteId);
        $stream = fopen($url, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('百度网盘文件下载失败。');
        }
        $out = fopen($targetPath, 'wb');
        if (!is_resource($out)) {
            fclose($stream);
            throw new \RuntimeException('无法写入临时文件。');
        }
        $bytes = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if (!is_string($chunk)) {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($stream);
                fclose($out);
                @unlink($targetPath);
                throw new \RuntimeException('百度网盘文件超过 CMS 允许大小。');
            }
            fwrite($out, $chunk);
        }
        fclose($stream);
        fclose($out);
        return ['filename' => $item->name, 'mime_type' => $item->mimeType, 'byte_size' => $bytes];
    }

    public function validateDownloadSignature(int $mediaId, string $remoteId, int $expires, string $sig): bool
    {
        if ($mediaId <= 0 || $remoteId === '' || $expires < time() || $sig === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $mediaId . ':' . $remoteId . ':' . $expires, $this->downloadSecret);
        return hash_equals($expected, $sig);
    }

    /** @param mixed $rows @return list<MediaProviderItem> */
    private function items(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->browser->item($row);
            }
        }
        return $items;
    }

    private function apiPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === 'baidu://root') {
            return '/';
        }
        if (str_starts_with($path, 'baidu://')) {
            return '/' . ltrim(substr($path, strlen('baidu://')), '/');
        }
        return '/' . ltrim($path, '/');
    }

    /** @return array<string,mixed>|null */
    private function parent(string $path): ?array
    {
        $apiPath = rtrim($this->apiPath($path), '/');
        if ($apiPath === '' || $apiPath === '/') {
            return null;
        }
        $parent = dirname($apiPath);
        return ['path' => 'baidu://' . ltrim($parent === '/' ? 'root' : $parent, '/'), 'label' => '上级目录'];
    }
}

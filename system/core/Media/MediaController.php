<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Payment\PaidDownloadService;
use Throwable;

final class MediaController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
    ) {
    }

    public function show(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return Response::text('媒体文件只能通过 GET 或 HEAD 访问。', 405)
                ->withHeaders(['Allow' => 'GET, HEAD', 'Cache-Control' => 'private, no-store']);
        }

        $id = $this->pathMediaId($request->path);
        if ($id <= 0) {
            return $this->mediaNotFoundResponse();
        }
        try {
            $library = new MediaLibrary(ConnectionFactory::make($this->settings), $this->rootPath . '/content/uploads');
            $mediaRow = $library->find($id);
            if (is_array($mediaRow) && $this->isRemoteMedia($mediaRow)) {
                return $this->remoteMediaResponse($request, $library, $mediaRow);
            }
            $result = $library->fileForResponse($id, $this->variant($request));
            $media = $result['media'];
            $path = $result['path'];
            $size = filesize($path);
            if (!is_int($size) || $size < 0) {
                return $this->mediaNotFoundResponse();
            }
            $download = $this->downloadRequested($request) || (string) $media['media_type'] === 'attachment';
            $protectedAttachment = false;
            $filename = $this->safeDownloadName((string) $media['original_name']);
            $headers = [
                'Content-Type' => (string) $media['mime_type'],
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ];
            $rangeHeader = $this->rangeHeader($request);
            $range = $request->method === 'HEAD'
                ? null
                : ($rangeHeader === false ? false : $this->parseRange($rangeHeader, $size));
            if ((string) $media['media_type'] === 'attachment') {
                $service = new PaidDownloadService(ConnectionFactory::make($this->settings), $this->settings);
                $protectedAttachment = $service->requiresAuthorizationForMedia($id);
                if ($protectedAttachment) {
                    $headers['Cache-Control'] = 'private, no-store';
                }
                if ($protectedAttachment && !$this->authorizedAttachment($request, $id, $service, false)) {
                    return $this->paymentRequiredResponse();
                }
            }

            if ($request->method === 'HEAD') {
                return new Response('', 200, $headers + ['Content-Length' => (string) $size]);
            }

            if ($range === false) {
                return new Response('', 416, $headers + [
                    'Content-Range' => 'bytes */' . $size,
                    'Content-Length' => '0',
                ]);
            }
            if ($protectedAttachment) {
                $service = new PaidDownloadService(ConnectionFactory::make($this->settings), $this->settings);
                if (!$this->authorizedAttachment($request, $id, $service, true)) {
                    return $this->paymentRequiredResponse();
                }
            }

            if ($range !== null) {
                [$start, $end] = $range;
                $length = $end - $start + 1;
                $body = $this->readSlice($path, $start, $length);

                return new Response($body, 206, $headers + [
                    'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $size,
                    'Content-Length' => (string) strlen($body),
                ]);
            }

            $body = file_get_contents($path);
            if (!is_string($body)) {
                return $this->mediaNotFoundResponse();
            }

            return new Response($body, 200, $headers + ['Content-Length' => (string) strlen($body)]);
        } catch (Throwable) {
            return $this->mediaNotFoundResponse();
        }
    }

    /** @param array<string,mixed> $media */
    private function remoteMediaResponse(Request $request, MediaLibrary $library, array $media): Response
    {
        if ((string) ($media['status'] ?? '') !== 'Active') {
            return $this->mediaNotFoundResponse();
        }

        $id = (int) ($media['id'] ?? 0);
        $download = $this->downloadRequested($request) || (string) ($media['media_type'] ?? '') === 'attachment';
        if ((string) ($media['media_type'] ?? '') === 'attachment') {
            $service = new PaidDownloadService(ConnectionFactory::make($this->settings), $this->settings);
            $protected = $service->requiresAuthorizationForMedia($id);
            if ($protected && !$this->authorizedAttachment($request, $id, $service, $request->method !== 'HEAD')) {
                return $this->paymentRequiredResponse();
            }
        }

        $providerId = (string) ($media['storage_provider'] ?? '');
        $provider = RemoteMediaProviderRegistry::get($providerId);
        if ($provider === null) {
            return Response::text('远程媒体 Provider 暂不可用，请确认相关插件已启用并完成授权。', 503)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        try {
            $resolved = $provider->resolveUrl($media, [
                'download' => $download,
                'variant' => $this->variant($request),
            ]);
            $url = (string) ($resolved['url'] ?? '');
            if ($url === '') {
                throw new MediaException('Remote media URL is empty.');
            }
        } catch (Throwable) {
            return Response::text('远程媒体暂不可用，请管理员检查对应插件授权状态。', 503)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return Response::redirect($url, 302)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Daiying-Media-Provider' => $providerId,
        ]);
    }

    /** @param array<string,mixed> $media */
    private function isRemoteMedia(array $media): bool
    {
        $provider = (string) ($media['storage_provider'] ?? 'local');

        return $provider !== '' && $provider !== 'local';
    }

    private function mediaNotFoundResponse(): Response
    {
        return Response::text('媒体文件不存在或暂不可用。', 404)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function paymentRequiredResponse(): Response
    {
        return Response::text('该文件需要完成支付后才能下载。', 402)->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function downloadRequested(Request $request): bool
    {
        $value = $request->query['download'] ?? '';

        return is_string($value) && $value === '1';
    }

    private function variant(Request $request): string
    {
        $value = $request->query['variant'] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return in_array($value, ['thumbnail', 'small'], true) ? $value : '';
    }

    private function rangeHeader(Request $request): string|false
    {
        if (!array_key_exists('HTTP_RANGE', $request->server)) {
            return '';
        }
        $value = $request->server['HTTP_RANGE'];

        return is_string($value) ? $value : false;
    }

    /** @return array{0:int,1:int}|false|null */
    private function parseRange(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return false;
        }

        $startText = $matches[1];
        $endText = $matches[2];
        if ($startText === '' && $endText === '') {
            return false;
        }

        if ($startText === '') {
            $suffix = (int) $endText;
            if ($suffix <= 0 || $size === 0) {
                return false;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $startText;
            $end = $endText === '' ? $size - 1 : (int) $endText;
        }

        if ($size === 0 || $start < 0 || $end < $start || $start >= $size) {
            return false;
        }

        return [$start, min($end, $size - 1)];
    }

    private function readSlice(string $path, int $start, int $length): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, $start);
        $data = fread($handle, $length);
        fclose($handle);

        return is_string($data) ? $data : '';
    }

    private function authorizedAttachment(Request $request, int $mediaId, PaidDownloadService $service, bool $consume): bool
    {
        $contentId = $this->positiveQueryId($request->query['content_id'] ?? null);
        $token = $this->queryString($request, 'payment_token');
        if ($contentId <= 0 || $token === '') {
            return false;
        }

        return $consume
            ? $service->consumeAuthorization($contentId, $mediaId, $token)
            : $service->isAuthorized($contentId, $mediaId, $token);
    }

    private function pathMediaId(string $path): int
    {
        $parts = explode('/', trim($path, '/'));

        return $this->positiveId($parts[1] ?? null);
    }

    private function positiveQueryId(mixed $value): int
    {
        return $this->positiveId($value);
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function positiveId(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            return 0;
        }
        $text = is_int($value) ? (string) $value : $value;
        if ($text === '' || preg_match('/^[1-9][0-9]{0,17}$/', $text) !== 1) {
            return 0;
        }

        return (int) $text;
    }

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/', '', $name) ?? '';

        return $name !== '' ? $name : 'download.bin';
    }
}

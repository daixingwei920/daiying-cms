<?php

declare(strict_types=1);

namespace Cms\Core\Advertising;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;

final class AdController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function adsTxt(): Response
    {
        $body = $this->repo()->adsTxt();
        return Response::text($body !== '' ? $body . "\n" : '');
    }

    public function track(Request $request): Response
    {
        [$slotKey, $eventType] = $this->eventFromPath($request->path);
        $this->repo()->recordEvent(
            $slotKey,
            $eventType,
            $request->path,
            (string) ($request->server['HTTP_REFERER'] ?? ''),
            (string) ($request->server['HTTP_USER_AGENT'] ?? ''),
            (string) ($request->server['REMOTE_ADDR'] ?? ''),
        );
        if ($eventType === 'click') {
            $target = (string) $request->input('to', '');
            if ($this->safeTarget($target)) {
                return Response::redirect($target);
            }
        }

        return new Response('', 204, ['Cache-Control' => 'no-store, max-age=0']);
    }

    private function repo(): AdRepository
    {
        return new AdRepository(ConnectionFactory::make($this->settings));
    }

    /** @return array{0:string,1:string} */
    private function eventFromPath(string $path): array
    {
        if (preg_match('#^/ads/track/([a-zA-Z0-9_-]{1,48})/(impression|click)$#', $path, $m) !== 1) {
            return ['', ''];
        }
        return [strtolower((string) $m[1]), (string) $m[2]];
    }

    private function safeTarget(string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }
        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}


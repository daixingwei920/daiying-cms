<?php

declare(strict_types=1);

namespace Cms\Core\Routing;

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;

final class BasePath
{
    private static string $current = '';

    public static function fromSettings(Settings $settings): string
    {
        return self::normalize((string) $settings->get('site.base_path', ''));
    }

    public static function setCurrent(string $basePath): void
    {
        self::$current = self::normalize($basePath);
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function normalize(string $basePath): string
    {
        $basePath = trim(str_replace('\\', '/', $basePath));
        if ($basePath === '' || $basePath === '/') {
            return '';
        }
        $parts = parse_url($basePath);
        if (is_array($parts) && isset($parts['path']) && ((string) ($parts['scheme'] ?? '') !== '' || (string) ($parts['host'] ?? '') !== '')) {
            $basePath = (string) $parts['path'];
        }
        if (str_contains($basePath, '?') || str_contains($basePath, '#') || str_contains($basePath, "\0")) {
            return '';
        }
        $basePath = '/' . trim($basePath, '/');
        $basePath = preg_replace('#/+#', '/', $basePath) ?: '';
        if ($basePath === '/' || $basePath === '') {
            return '';
        }
        foreach (explode('/', trim($basePath, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return rtrim($basePath, '/');
    }

    public static function stripCurrent(string $path): string
    {
        return self::strip($path, self::$current);
    }

    public static function strip(string $path, string $basePath): string
    {
        $path = Request::normalizePath((string) (parse_url($path, PHP_URL_PATH) ?: $path));
        $basePath = self::normalize($basePath);
        if ($basePath === '') {
            return $path;
        }
        if ($path === $basePath || $path === $basePath . '/') {
            return '/';
        }
        if (str_starts_with($path, $basePath . '/')) {
            return Request::normalizePath(substr($path, strlen($basePath)));
        }

        return $path;
    }

    public static function prefixCurrent(string $url): string
    {
        return self::prefix($url, self::$current);
    }

    public static function prefix(string $url, string $basePath): string
    {
        $url = trim($url);
        $basePath = self::normalize($basePath);
        if ($basePath === '' || $url === '') {
            return $url;
        }
        if (self::isExternalOrSpecial($url)) {
            return $url;
        }
        if ($url === $basePath || str_starts_with($url, $basePath . '/') || str_starts_with($url, $basePath . '?') || str_starts_with($url, $basePath . '#')) {
            return $url;
        }
        if ($url === '/') {
            return $basePath . '/';
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        return $basePath . $url;
    }

    public static function isExternalOrSpecial(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '//')) {
            return true;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return true;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Cms\Core\Http;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, mixed> $server */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
    public readonly array $server = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = self::captureBody(
            $method,
            $_POST,
            (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
            (string) file_get_contents('php://input'),
        );

        return new self(
            $method,
            self::normalizePath(is_string($path) ? $path : '/'),
            $_GET,
            $body,
            $_SERVER,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function captureBody(string $method, array $post, string $contentType, string $rawBody): array
    {
        $method = strtoupper($method);
        $contentType = strtolower($contentType);
        if ($post !== [] || !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || ($contentType !== '' && !str_contains($contentType, 'application/x-www-form-urlencoded'))) {
            return $post;
        }

        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}

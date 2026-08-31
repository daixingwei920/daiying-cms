<?php

declare(strict_types=1);

namespace Cms\Core\Routing;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    public function patch(string $path, callable|array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    public function delete(string $path, callable|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    public function route(string $method, string $path, callable|array $handler): void
    {
        $this->add(strtoupper($method), $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $requestMethod = $request->method === 'HEAD' ? 'GET' : $request->method;
        $path = Request::normalizePath($request->path);
        $key = $requestMethod . ' ' . $path;
        $allowed = [];

        if ($request->method === 'OPTIONS') {
            foreach ($this->routes as $routeKey => $_) {
                [$method, $routePath] = explode(' ', $routeKey, 2);
                if ($this->matches($routePath, $path)) {
                    $allowed[] = $method;
                }
            }
            if ($allowed !== []) {
                $allowed = $this->allowedMethods($allowed);
                return new Response('', 204, ['Allow' => implode(', ', $allowed)]);
            }
        }

        if (isset($this->routes[$key])) {
            return ($this->routes[$key])($request);
        }

        foreach ($this->routes as $routeKey => $handler) {
            [$method, $routePath] = explode(' ', $routeKey, 2);
            if ($this->matches($routePath, $path)) {
                $allowed[] = $method;
            }
            if ($method === $requestMethod && $this->matches($routePath, $path)) {
                return $handler($request);
            }
        }

        if ($allowed !== []) {
            $allowed = $this->allowedMethods($allowed);
            return Response::text('请求方法不被允许。', 405)
                ->withHeaders(['Allow' => implode(', ', $allowed), 'Cache-Control' => 'private, no-store']);
        }

        return Response::text('页面不存在。', 404);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler */
    private function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[$method . ' ' . Request::normalizePath($path)] = $this->normalizeHandler($handler);
    }

    /** @param callable(Request): Response|array{0:object,1:string} $handler @return callable(Request): Response */
    private function normalizeHandler(callable|array $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }
        $controller = $handler[0] ?? null;
        $method = (string) ($handler[1] ?? '');

        return static function (Request $request) use ($controller, $method): Response {
            if (!is_object($controller) || $method === '' || !method_exists($controller, $method)) {
                return Response::text('页面不存在。', 404);
            }
            $response = $controller->{$method}($request);

            return $response instanceof Response ? $response : Response::text('服务器暂时无法处理请求，请稍后再试。', 500);
        };
    }

    private function matches(string $routePath, string $requestPath): bool
    {
        $pattern = '#^' . preg_replace('#\\\{[a-zA-Z_][a-zA-Z0-9_]*\\\}#', '[^/]+', preg_quote($routePath, '#')) . '$#';
        return preg_match($pattern, $requestPath) === 1;
    }

    /** @param list<string> $methods @return list<string> */
    private function allowedMethods(array $methods): array
    {
        $allowed = array_values(array_unique($methods));
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        $allowed[] = 'OPTIONS';
        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return $allowed;
    }
}

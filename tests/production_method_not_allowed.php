<?php

declare(strict_types=1);

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Routing\Router;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function method_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$router = new Router();
$router->get('/content/{slug}', static fn (Request $request): Response => Response::text('get:' . $request->path));
$router->post('/content/{slug}', static fn (Request $request): Response => Response::text('post:' . $request->path));
$router->get('/readonly', static fn (): Response => Response::text('readonly'));

$missing = $router->dispatch(new Request('GET', '/missing'));
method_check($missing->status() === 404 && $missing->body() === '页面不存在。' && !str_contains($missing->body(), 'Not Found'), 'unknown paths return localized 404');

$put = $router->dispatch(new Request('PUT', '/content/demo'));
method_check($put->status() === 405 && $put->body() === '请求方法不被允许。' && !str_contains($put->body(), 'Method Not Allowed'), 'unsupported method on known dynamic path returns localized 405');
method_check(($put->headers()['Allow'] ?? '') === 'GET, HEAD, OPTIONS, POST', '405 response includes Allow header with GET HEAD OPTIONS POST');
method_check(($put->headers()['Cache-Control'] ?? '') === 'private, no-store', '405 response is not cached');
method_check(($put->headers()['X-Content-Type-Options'] ?? '') === 'nosniff', '405 response keeps default security headers');

$delete = $router->dispatch(new Request('DELETE', '/readonly'));
method_check($delete->status() === 405 && ($delete->headers()['Allow'] ?? '') === 'GET, HEAD, OPTIONS', '405 Allow header includes HEAD when GET exists');

$options = $router->dispatch(new Request('OPTIONS', '/content/demo'));
method_check($options->status() === 204 && $options->body() === '', 'OPTIONS on known path returns empty 204');
method_check(($options->headers()['Allow'] ?? '') === 'GET, HEAD, OPTIONS, POST', 'OPTIONS response exposes allowed methods');

$head = $router->dispatch(new Request('HEAD', '/readonly'));
method_check($head->status() === 200 && $head->body() === 'readonly', 'HEAD still dispatches through GET route before send-layer body suppression');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production method-not-allowed checks failed.\n");
    exit(1);
}

echo "Production method-not-allowed tests passed.\n";

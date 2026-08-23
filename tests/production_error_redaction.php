<?php

declare(strict_types=1);

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Routing\Router;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function error_redaction_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function error_redaction_app(bool $debug, string $logPath): Application
{
    $settings = Settings::fromArray(['app' => ['debug' => $debug]]);
    $logger = new FileLogger($logPath);
    $router = new Router();
    $router->get('/boom', static function (): never {
        throw new RuntimeException('boom password=hunter2 token=abc123 session=sess456 dsn=mysql:host=127.0.0.1;dbname=secret_db file=/private/tmp/cms-secret/path.php');
    });

    $ref = new ReflectionClass(Application::class);
    $app = $ref->newInstanceWithoutConstructor();
    foreach (['rootPath' => CMS_SOURCE_ROOT, 'settings' => $settings, 'logger' => $logger, 'router' => $router] as $property => $value) {
        $prop = $ref->getProperty($property);
        $prop->setValue($app, $value);
    }

    return $app;
}

/** @return array{status:int, stdout:string, stderr:string, log:string} */
function error_redaction_uncaught(bool $debug, string $logPath): array
{
    $script = dirname($logPath) . '/uncaught-' . ($debug ? 'debug' : 'prod') . '.php';
    file_put_contents($script, "<?php\n"
        . "declare(strict_types=1);\n"
        . "require " . var_export(CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php', true) . ";\n"
        . "\\Cms\\Core\\Error\\ErrorHandler::register(new \\Cms\\Core\\Logging\\FileLogger(" . var_export($logPath, true) . "), " . ($debug ? 'true' : 'false') . ");\n"
        . "throw new \\RuntimeException('uncaught password=hunter2 token=abc123 session=sess456 dsn=mysql:host=127.0.0.1;dbname=secret_db file=/private/tmp/cms-secret/path.php');\n");
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to spawn PHP process for uncaught exception test.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $status = proc_close($process);
    @unlink($script);

    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'log' => is_file($logPath) ? (string) file_get_contents($logPath) : '',
    ];
}

$work = sys_get_temp_dir() . '/cms-error-redaction-' . bin2hex(random_bytes(4));
mkdir($work, 0755, true);
$prodLog = $work . '/prod.log';
$debugLog = $work . '/debug.log';
$uncaughtProdLog = $work . '/uncaught-prod.log';
$uncaughtDebugLog = $work . '/uncaught-debug.log';

$prod = error_redaction_app(false, $prodLog);
$prodResponse = $prod->handle(new Request('GET', '/boom'));
$prodBody = $prodResponse->body();
$prodLogText = is_file($prodLog) ? (string) file_get_contents($prodLog) : '';

error_redaction_check($prodResponse->status() === 500 && $prodBody === '服务器暂时无法处理请求，请稍后再试。' && !str_contains($prodBody, 'Internal Server Error'), 'production error response is localized and generic');
error_redaction_check(!str_contains($prodBody, 'hunter2') && !str_contains($prodBody, 'abc123') && !str_contains($prodBody, '/private/tmp') && !str_contains($prodBody, 'secret_db'), 'production error response does not expose secrets or paths');
error_redaction_check(str_contains($prodLogText, 'Unhandled request failure') && str_contains($prodLogText, 'password=[redacted]') && str_contains($prodLogText, 'token=[redacted]') && str_contains($prodLogText, 'session=[redacted]') && str_contains($prodLogText, 'dsn=[redacted]'), 'production error log redacts inline sensitive values');
error_redaction_check(!str_contains($prodLogText, 'hunter2') && !str_contains($prodLogText, 'abc123') && !str_contains($prodLogText, 'sess456') && !str_contains($prodLogText, '/private/tmp') && !str_contains($prodLogText, 'secret_db'), 'production error log does not retain raw secrets or absolute paths');

$debug = error_redaction_app(true, $debugLog);
$debugResponse = $debug->handle(new Request('GET', '/boom'));
error_redaction_check($debugResponse->status() === 500 && str_contains($debugResponse->body(), 'RuntimeException: boom'), 'debug error response includes exception class and message for local development');

$uncaughtProd = error_redaction_uncaught(false, $uncaughtProdLog);
error_redaction_check($uncaughtProd['stdout'] === '服务器暂时无法处理请求，请稍后再试。' && !str_contains($uncaughtProd['stdout'], 'Internal Server Error') && !str_contains($uncaughtProd['stdout'], 'hunter2') && !str_contains($uncaughtProd['stdout'], '/private/tmp'), 'global uncaught exception handler returns localized non-blank production fallback');
error_redaction_check(str_contains($uncaughtProd['log'], 'Uncaught exception') && str_contains($uncaughtProd['log'], 'password=[redacted]') && str_contains($uncaughtProd['log'], 'token=[redacted]') && !str_contains($uncaughtProd['log'], 'hunter2') && !str_contains($uncaughtProd['log'], '/private/tmp'), 'global uncaught exception handler logs redacted diagnostics only');

$uncaughtDebug = error_redaction_uncaught(true, $uncaughtDebugLog);
error_redaction_check(str_contains($uncaughtDebug['stdout'], 'RuntimeException: uncaught') && !str_contains($uncaughtDebug['stderr'], 'Fatal error'), 'global uncaught exception handler exposes debug details only when debug is enabled');

@unlink($prodLog);
@unlink($debugLog);
@unlink($uncaughtProdLog);
@unlink($uncaughtDebugLog);
@rmdir($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " production error redaction checks failed.\n");
    exit(1);
}

echo "Production error redaction tests passed.\n";

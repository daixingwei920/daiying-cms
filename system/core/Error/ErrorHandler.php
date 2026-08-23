<?php

declare(strict_types=1);

namespace Cms\Core\Error;

use Cms\Core\Logging\FileLogger;
use Throwable;

final class ErrorHandler
{
    public static function register(FileLogger $logger, bool $debug): void
    {
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $logger->error('PHP error', [
                'source' => 'Core',
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $line,
            ]);

            return true;
        });

        set_exception_handler(static function (Throwable $exception) use ($logger, $debug): void {
            $logger->error('Uncaught exception', [
                'source' => 'Core',
                'error' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            if (function_exists('header_remove')) {
                header_remove('X-Powered-By');
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            }

            echo $debug
                ? $exception::class . ': ' . $exception->getMessage()
                : '服务器暂时无法处理请求，请稍后再试。';
        });
    }
}

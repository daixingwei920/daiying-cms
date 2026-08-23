<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function maintenance_security_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$launcher = (string) file_get_contents(CMS_SOURCE_ROOT . '/public/index.php');

maintenance_security_check(str_contains($launcher, 'parse_url($uri, PHP_URL_PATH)'), 'maintenance gate uses parsed request path instead of raw URI prefix');
maintenance_security_check(str_contains($launcher, "\$path === '/health'"), 'maintenance gate only exempts exact /health path');
maintenance_security_check(str_contains($launcher, "\$path === '/admin'") && str_contains($launcher, "str_starts_with(\$path, '/admin/')"), 'maintenance gate only exempts admin exact path or admin slash subtree');
maintenance_security_check(str_contains($launcher, "\$path === '/recovery'") && str_contains($launcher, "str_starts_with(\$path, '/recovery/')"), 'maintenance gate only exempts recovery exact path or recovery slash subtree');
maintenance_security_check(!str_contains($launcher, "str_starts_with(\$path, '/admin'))"), 'maintenance gate rejects broad admin prefix bypasses');
maintenance_security_check(!str_contains($launcher, "str_starts_with(\$path, '/recovery'))"), 'maintenance gate rejects broad recovery prefix bypasses');
maintenance_security_check(str_contains($launcher, "header_remove('X-Powered-By')"), 'maintenance response removes PHP runtime disclosure header');
maintenance_security_check(str_contains($launcher, 'Cache-Control: no-store, no-cache, must-revalidate'), 'maintenance response disables caching');
maintenance_security_check(str_contains($launcher, 'X-Content-Type-Options: nosniff') && str_contains($launcher, 'X-Frame-Options: SAMEORIGIN'), 'maintenance response emits core browser security headers');
maintenance_security_check(str_contains($launcher, 'Permissions-Policy: geolocation=(), microphone=(), camera=()'), 'maintenance response emits Permissions-Policy');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production maintenance response security checks failed.\n");
    exit(1);
}

echo "Production maintenance response security tests passed.\n";

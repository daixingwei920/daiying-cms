<?php

declare(strict_types=1);

/**
 * @return array{status:string,errors:int,warnings:int,checks:list<array{id:string,severity:string,ok:bool,message:string,detail:string}>}
 */
function cms_validate_production_readiness(string $rootPath): array
{
    $rootPath = rtrim($rootPath, '/');
    $config = cms_read_readiness_config($rootPath . '/config/app.php');
    $checks = [];

    $add = static function (string $id, string $severity, bool $ok, string $message, string $detail = '') use (&$checks): void {
        $checks[] = [
            'id' => $id,
            'severity' => $severity,
            'ok' => $ok,
            'message' => $message,
            'detail' => cms_redact_readiness_detail($detail),
        ];
    };

    $runtimeRequirements = cms_readiness_runtime_requirements($rootPath);
    $add('php.version', 'error', version_compare(PHP_VERSION, $runtimeRequirements['php_min'], '>='), 'PHP version is supported', PHP_VERSION . ' detected; ' . $runtimeRequirements['php_min'] . '+ required');
    foreach ($runtimeRequirements['extensions'] as $extension) {
        $add('php.extension.' . $extension, 'error', extension_loaded($extension), strtoupper($extension) . ' extension is loaded');
    }
    $uploadMax = cms_ini_bytes((string) ini_get('upload_max_filesize'));
    $postMax = cms_ini_bytes((string) ini_get('post_max_size'));
    $memoryLimit = cms_ini_bytes((string) ini_get('memory_limit'));
    $mediaMax = cms_readiness_media_max_bytes($config);
    $add('php.file_uploads', 'error', filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL), 'PHP file uploads are enabled for the media library');
    $add('php.upload_max_filesize', 'warning', $mediaMax !== null && $uploadMax !== null && $uploadMax >= $mediaMax, 'PHP upload_max_filesize supports configured media max file size', 'upload_max_filesize=' . (string) ini_get('upload_max_filesize') . '; media.max_file_bytes=' . ($mediaMax === null ? 'not configured' : (string) $mediaMax));
    $add('php.post_max_size', 'warning', $uploadMax !== null && $postMax !== null && $postMax >= $uploadMax, 'PHP post_max_size is not smaller than upload_max_filesize', 'post_max_size=' . (string) ini_get('post_max_size') . '; upload_max_filesize=' . (string) ini_get('upload_max_filesize'));
    $add('php.memory_limit', 'warning', $memoryLimit === null || ($postMax !== null && $memoryLimit >= $postMax), 'PHP memory_limit can accommodate upload request parsing', 'memory_limit=' . (string) ini_get('memory_limit') . '; post_max_size=' . (string) ini_get('post_max_size'));

    $add('public.launcher', 'error', is_file($rootPath . '/public/index.php'), 'stable public launcher exists');
    $add('scheduler.publish_cli', 'error', is_file($rootPath . '/scripts/publish_scheduled_content.php'), 'scheduled content publish CLI exists for cron execution');
    $add('core.bootstrap', 'error', is_file($rootPath . '/system/core/Bootstrap/Application.php'), 'Core bootstrap exists');
    $add('core.manifest', 'error', cms_valid_readiness_json_file($rootPath . '/system/core-manifest.json'), 'Core manifest is present and valid JSON');
    $coreManifestIntegrity = cms_readiness_core_manifest_integrity($rootPath);
    $add('core.manifest_integrity', 'error', $coreManifestIntegrity['ok'], 'Core manifest hashes match installed Core files', $coreManifestIntegrity['detail']);
    $add('config.exists', 'error', is_file($rootPath . '/config/app.php'), 'config/app.php exists');
    $add('config.app_private', 'warning', cms_readiness_private_file_mode($rootPath . '/config/app.php'), 'config/app.php is not readable by group or others after installation');
    $add('install.lock_exists', 'error', is_file($rootPath . '/storage/installed.lock'), 'installed lock exists for an installed production site');
    $add('install.lock_private', 'warning', cms_readiness_private_file_mode($rootPath . '/storage/installed.lock'), 'installed lock is not readable by group or others');
    $add('config.example', 'warning', is_file($rootPath . '/config/app.example.php'), 'config/app.example.php is available for reference');
    $rootHtaccess = is_file($rootPath . '/.htaccess') ? (string) file_get_contents($rootPath . '/.htaccess') : '';
    $rootNginx = is_file($rootPath . '/nginx-root-security.conf') ? (string) file_get_contents($rootPath . '/nginx-root-security.conf') : '';
    $add('webroot.apache.root_guard', 'warning', str_contains($rootHtaccess, 'RewriteRule ^(config|storage|system|tests|scripts|content/plugins|content/themes)') && str_contains($rootHtaccess, 'Require all denied'), 'Apache project-root guard is present for misconfigured document roots');
    $add('webroot.nginx.root_guard', 'warning', str_contains($rootNginx, 'deny all') && str_contains($rootNginx, 'try_files /public$uri'), 'Nginx project-root guard example is present for misconfigured document roots');
    $add('webroot.apache.release_artifact_guard', 'warning', cms_apache_release_artifact_guard_present($rootHtaccess), 'Apache project-root guard blocks direct release report and artifact downloads');
    $add('webroot.nginx.release_artifact_guard', 'warning', cms_nginx_release_artifact_guard_present($rootNginx), 'Nginx project-root guard blocks direct release report and artifact downloads');
    $publicHtaccess = is_file($rootPath . '/public/.htaccess') ? (string) file_get_contents($rootPath . '/public/.htaccess') : '';
    $add('webroot.apache.root_sensitive_file_guard', 'warning', cms_apache_sensitive_file_guard_present($rootHtaccess), 'Apache project-root guard blocks common secret, database, key and backup files');
    $add('webroot.nginx.root_sensitive_file_guard', 'warning', cms_nginx_sensitive_file_guard_present($rootNginx), 'Nginx project-root guard blocks common secret, database, key and backup files');
    $add('webroot.apache.public_sensitive_file_guard', 'warning', cms_apache_sensitive_file_guard_present($publicHtaccess), 'Apache public document-root guard blocks common secret, database, key and backup files');

    $siteUrl = (string) cms_readiness_get($config, 'site.url', '');
    $add('site.url', 'error', cms_valid_https_url($siteUrl), 'production site.url is a valid HTTPS URL', $siteUrl);
    $add('seo.robots_index', 'warning', cms_readiness_get($config, 'seo.robots_index', true) === true, 'site-wide SEO indexing is enabled for public production launch', 'enable in /admin/settings before public launch if this site should be indexed');
    $add('app.debug', 'error', cms_readiness_get($config, 'app.debug', true) === false, 'debug mode is disabled');
    $add('app.mode', 'error', cms_readiness_get($config, 'app.mode', '') === 'NORMAL', 'application mode is NORMAL for production');
    $add('app.secure_cookies', 'error', cms_readiness_get($config, 'app.secure_cookies', false) === true, 'secure cookies are enabled for HTTPS production');
    $encryptionKey = (string) cms_readiness_get($config, 'security.encryption_key', '');
    $add('security.encryption_key', 'error', cms_valid_encryption_key($encryptionKey), 'security.encryption_key is a unique production secret', cms_encryption_key_readiness_detail($encryptionKey));
    $adminMfaReserved = cms_readiness_admin_mfa_reserved($config);
    $add('security.admin_mfa.reserved_methods', 'warning', $adminMfaReserved['ok'], 'admin MFA reserve methods are explicitly declared for TOTP, Passkey and recovery-code rollout', $adminMfaReserved['detail']);
    $adminMfaRuntime = cms_readiness_admin_mfa_runtime($rootPath, $config);
    $add('security.admin_mfa.runtime_enforcement', 'warning', $adminMfaRuntime['ok'], 'admin MFA TOTP and recovery-code runtime enforcement is available', $adminMfaRuntime['detail']);
    $add('security.hsts_enabled', 'warning', !cms_valid_https_url($siteUrl) || cms_readiness_get($config, 'security.hsts_enabled', false) === true, 'HSTS is enabled for HTTPS production');
    $add('security.hsts_max_age', 'warning', !cms_valid_https_url($siteUrl) || cms_readiness_hsts_max_age($config) >= 15552000, 'HSTS max-age is at least 180 days for production');
    $add('security.hsts_subdomains', 'warning', !cms_valid_https_url($siteUrl) || cms_readiness_get($config, 'security.hsts_include_subdomains', false) === true, 'HSTS includeSubDomains is enabled or consciously adjusted for production');

    $dsn = (string) cms_readiness_get($config, 'database.dsn', '');
    $dbUser = (string) cms_readiness_get($config, 'database.username', '');
    $dbPassword = (string) cms_readiness_get($config, 'database.password', '');
    $add('database.dsn', 'error', $dsn !== '' && (str_starts_with($dsn, 'mysql:') || str_starts_with($dsn, 'sqlite:')), 'database DSN is configured for MySQL/MariaDB or SQLite', $dsn);
    $add('database.mysql.charset', 'warning', !str_starts_with($dsn, 'mysql:') || str_contains(strtolower($dsn), 'charset=utf8mb4'), 'MySQL/MariaDB DSN uses utf8mb4 charset');
    $add('database.mysql.least_privilege', 'error', !str_starts_with($dsn, 'mysql:') || ($dbUser !== '' && strtolower($dbUser) !== 'root'), 'MySQL/MariaDB uses a non-root CMS database user', $dbUser);
    $add('database.password', 'warning', !str_starts_with($dsn, 'mysql:') || ($dbPassword !== '' && !in_array($dbPassword, ['change-me', 'password', 'root'], true)), 'database password is not empty or a known placeholder');
    $add('database.mysql.tls_documented', 'warning', !str_starts_with($dsn, 'mysql:') || cms_mysql_tls_hint_present($config), 'remote MySQL/MariaDB TLS options are configured or consciously documented');
    $mysqldumpCommand = (string) cms_readiness_get($config, 'database.mysqldump_command', 'mysqldump');
    $mysqlCommand = (string) cms_readiness_get($config, 'database.mysql_command', 'mysql');
    $add('database.mysql.mysqldump_tool', 'error', !str_starts_with($dsn, 'mysql:') || cms_readiness_command_available($mysqldumpCommand), 'mysqldump is available for MySQL/MariaDB logical backup restore points', $mysqldumpCommand);
    $add('database.mysql.mysql_tool', 'error', !str_starts_with($dsn, 'mysql:') || cms_readiness_command_available($mysqlCommand), 'mysql client is available for MySQL/MariaDB logical restore', $mysqlCommand);
    $updatePublicKey = (string) cms_readiness_get($config, 'updates.public_key', '');
    $add('updates.public_key', 'error', cms_valid_update_public_key($updatePublicKey), 'Core update signing public key is configured with a valid RSA PEM or Ed25519 public key');
    foreach (cms_readiness_payment_provider_checks($config) as $check) {
        $add($check['id'], $check['severity'], $check['ok'], $check['message'], $check['detail']);
    }

    foreach (['storage', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'storage/updates/incoming', 'storage/recovery', 'storage/plugin-installs/uploads', 'storage/plugin-installs/staging', 'content/uploads'] as $directory) {
        $path = $rootPath . '/' . $directory;
        $add('writable.' . str_replace('/', '.', $directory), 'error', is_dir($path) && is_writable($path), $directory . ' exists and is writable');
    }

    $htaccess = is_file($rootPath . '/content/uploads/.htaccess') ? (string) file_get_contents($rootPath . '/content/uploads/.htaccess') : '';
    $nginx = is_file($rootPath . '/content/uploads/upload-security.nginx.conf') ? (string) file_get_contents($rootPath . '/content/uploads/upload-security.nginx.conf') : '';
    $add('uploads.apache.deny', 'error', str_contains($htaccess, 'Require all denied') || str_contains($htaccess, 'Deny from all'), 'Apache upload script execution denial rule is present');
    $add('uploads.nginx.deny', 'warning', str_contains($nginx, 'return 403'), 'Nginx upload script execution denial example is present');

    $releaseZip = $rootPath . '/daiying-cms-1.2.0.zip';
    if (is_file($releaseZip)) {
        $zipCheck = cms_readiness_package_runtime_exclusions($releaseZip);
        $add('package.runtime_exclusions', 'error', $zipCheck['ok'], 'release package excludes runtime artifacts', implode(', ', $zipCheck['violations']));
        $sidecarCheck = cms_readiness_package_sidecars($releaseZip);
        $add('package.sha256_sidecar', 'error', $sidecarCheck['sha256_ok'], 'release package SHA-256 sidecar exists and matches', implode(', ', $sidecarCheck['violations']));
        $add('package.manifest_sidecar', 'error', $sidecarCheck['manifest_ok'], 'release package artifact manifest exists and matches', implode(', ', $sidecarCheck['violations']));
    } else {
        $add('package.exists', 'warning', false, 'release package is not present in this checkout');
    }

    $errors = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'error' && !$check['ok']));
    $warnings = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'warning' && !$check['ok']));

    return [
        'status' => $errors === 0 ? ($warnings === 0 ? 'ready' : 'ready_with_warnings') : 'not_ready',
        'errors' => $errors,
        'warnings' => $warnings,
        'checks' => $checks,
    ];
}

/** @return array<string, mixed> */
function cms_read_readiness_config(string $configFile): array
{
    if (!is_file($configFile)) {
        return [];
    }

    $config = require $configFile;

    return is_array($config) ? $config : [];
}

function cms_readiness_get(array $items, string $key, mixed $default = null): mixed
{
    $value = $items;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function cms_valid_https_url(string $url): bool
{
    if ($url === '') {
        return false;
    }

    $parts = parse_url($url);

    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
}

/**
 * @return array{php_min:string,extensions:list<string>}
 */
function cms_readiness_runtime_requirements(string $rootPath): array
{
    $autoload = rtrim($rootPath, '/') . '/system/core/Bootstrap/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists(\Cms\Core\Support\RuntimeRequirements::class)) {
        $extensions = \Cms\Core\Support\RuntimeRequirements::requiredExtensions();
        return [
            'php_min' => \Cms\Core\Support\RuntimeRequirements::PHP_MIN,
            'extensions' => array_values(array_unique(array_map('strtolower', $extensions))),
        ];
    }

    return [
        'php_min' => '8.3.0',
        'extensions' => ['pdo', 'json', 'openssl', 'fileinfo', 'zip'],
    ];
}

function cms_valid_readiness_json_file(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    try {
        json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return true;
    } catch (JsonException) {
        return false;
    }
}

/** @return array{ok:bool,detail:string} */
function cms_readiness_core_manifest_integrity(string $rootPath): array
{
    $manifestPath = rtrim($rootPath, '/') . '/system/core-manifest.json';
    $corePath = rtrim($rootPath, '/') . '/system/core';
    if (!is_file($manifestPath)) {
        return ['ok' => false, 'detail' => 'manifest-missing'];
    }
    if (!is_dir($corePath)) {
        return ['ok' => false, 'detail' => 'core-directory-missing'];
    }

    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'detail' => 'manifest-invalid-json'];
    }
    if (!is_array($manifest)) {
        return ['ok' => false, 'detail' => 'manifest-not-object'];
    }

    $expected = [];
    foreach ($manifest as $relative => $hash) {
        if (!is_string($relative) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return ['ok' => false, 'detail' => 'manifest-invalid-entry'];
        }
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, '../') || str_contains($relative, '/..')) {
            return ['ok' => false, 'detail' => 'manifest-unsafe-path:' . $relative];
        }
        $path = $corePath . '/' . $relative;
        if (!is_file($path)) {
            return ['ok' => false, 'detail' => 'manifest-missing-file:' . $relative];
        }
        if (hash_file('sha256', $path) !== $hash) {
            return ['ok' => false, 'detail' => 'manifest-hash-mismatch:' . $relative];
        }
        $expected[$relative] = true;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($corePath, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $file) {
        if (!$file->isFile() || $file->getBasename() === '.DS_Store') {
            continue;
        }
        $relative = str_replace('\\', '/', substr((string) $file->getPathname(), strlen($corePath) + 1));
        if (!isset($expected[$relative])) {
            return ['ok' => false, 'detail' => 'manifest-missing-entry:' . $relative];
        }
    }

    return ['ok' => true, 'detail' => 'ok'];
}

function cms_readiness_private_file_mode(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $perms = fileperms($path);
    if ($perms === false) {
        return false;
    }

    return ($perms & 0077) === 0;
}

function cms_readiness_hsts_max_age(array $config): int
{
    $value = cms_readiness_get($config, 'security.hsts_max_age', 0);
    if (is_int($value)) {
        return max(0, $value);
    }

    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }

    return 0;
}

function cms_apache_release_artifact_guard_present(string $rules): bool
{
    return str_contains($rules, 'CMS_RELEASE_.*\.md')
        && str_contains($rules, 'CMS_COMPLETION_STATUS_AUDIT.*\.md')
        && str_contains($rules, 'zip(\.sha256)?')
        && str_contains($rules, 'manifest\.json')
        && str_contains($rules, 'Require all denied');
}

function cms_nginx_release_artifact_guard_present(string $rules): bool
{
    return str_contains($rules, 'CMS_RELEASE_.*\.md')
        && str_contains($rules, 'CMS_COMPLETION_STATUS_AUDIT.*\.md')
        && str_contains($rules, 'zip(\.sha256)?')
        && str_contains($rules, 'manifest\.json')
        && str_contains($rules, 'deny all');
}

function cms_apache_sensitive_file_guard_present(string $rules): bool
{
    return str_contains($rules, 'Require all denied')
        && str_contains($rules, '\.env')
        && str_contains($rules, 'sql')
        && str_contains($rules, 'sqlite')
        && str_contains($rules, 'backup')
        && str_contains($rules, 'pem')
        && str_contains($rules, 'key');
}

function cms_nginx_sensitive_file_guard_present(string $rules): bool
{
    return str_contains($rules, 'deny all')
        && str_contains($rules, '\.env')
        && str_contains($rules, 'sql')
        && str_contains($rules, 'sqlite')
        && str_contains($rules, 'backup')
        && str_contains($rules, 'pem')
        && str_contains($rules, 'key');
}

function cms_mysql_tls_hint_present(array $config): bool
{
    $options = cms_readiness_get($config, 'database.options', []);
    if (!is_array($options)) {
        return false;
    }

    foreach ($options as $key => $value) {
        $name = strtoupper((string) $key);
        if (str_contains($name, 'MYSQL_ATTR_SSL') && $value !== '' && $value !== false && $value !== null) {
            return true;
        }
    }

    return (bool) cms_readiness_get($config, 'database.tls_documented', false);
}

function cms_valid_update_public_key(string $publicKey): bool
{
    $publicKey = trim($publicKey);
    if ($publicKey === '' || str_contains($publicKey, '...')) {
        return false;
    }

    if (!str_contains($publicKey, '-----BEGIN')) {
        $decoded = base64_decode($publicKey, true);
        return is_string($decoded)
            && defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    $key = openssl_pkey_get_public($publicKey);
    if ($key === false) {
        return false;
    }

    return true;
}

function cms_valid_encryption_key(string $key): bool
{
    $key = trim($key);
    if ($key === '' || strlen($key) < 32 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
        return false;
    }
    $lower = strtolower($key);
    foreach (['change-me', 'placeholder', 'example', 'password', 'secret', 'generate-a-random', 'your-key'] as $marker) {
        if (str_contains($lower, $marker)) {
            return false;
        }
    }

    return true;
}

/** @return array{ok:bool,detail:string} */
function cms_readiness_admin_mfa_reserved(array $config): array
{
    $methods = cms_readiness_get($config, 'security.admin_mfa.reserved_methods', []);
    if (!is_array($methods)) {
        return ['ok' => false, 'detail' => 'security.admin_mfa.reserved_methods must be an array'];
    }

    $normalized = [];
    foreach ($methods as $method) {
        if (is_string($method)) {
            $normalized[] = strtolower($method);
        }
    }

    $missing = array_values(array_diff(['totp', 'passkey', 'recovery_codes'], $normalized));
    if ($missing !== []) {
        return ['ok' => false, 'detail' => 'missing reserved methods: ' . implode(', ', $missing)];
    }

    return ['ok' => true, 'detail' => 'reserved methods declared'];
}

/** @return array{ok:bool,detail:string} */
function cms_readiness_admin_mfa_runtime(string $rootPath, array $config): array
{
    if (cms_readiness_get($config, 'security.admin_mfa.runtime_enforcement', false) !== true) {
        return ['ok' => false, 'detail' => 'security.admin_mfa.runtime_enforcement must be true'];
    }
    $implemented = cms_readiness_get($config, 'security.admin_mfa.implemented_methods', []);
    if (!is_array($implemented)) {
        return ['ok' => false, 'detail' => 'security.admin_mfa.implemented_methods must be an array'];
    }
    $normalized = [];
    foreach ($implemented as $method) {
        if (is_string($method)) {
            $normalized[] = strtolower($method);
        }
    }
    $missing = array_values(array_diff(['totp', 'recovery_codes'], $normalized));
    if ($missing !== []) {
        return ['ok' => false, 'detail' => 'missing implemented methods: ' . implode(', ', $missing)];
    }
    foreach ([
        '/system/core/Auth/AdminMfaService.php',
        '/system/migrations/2026_08_23_000001_admin_mfa_schema.php',
    ] as $file) {
        if (!is_file($rootPath . $file)) {
            return ['ok' => false, 'detail' => 'missing MFA runtime file: ' . ltrim($file, '/')];
        }
    }
    $application = is_file($rootPath . '/system/core/Bootstrap/Application.php') ? (string) file_get_contents($rootPath . '/system/core/Bootstrap/Application.php') : '';
    if (!str_contains($application, '/admin/mfa') || !str_contains($application, '/admin/security/mfa-enable')) {
        return ['ok' => false, 'detail' => 'admin MFA routes are not registered'];
    }

    return ['ok' => true, 'detail' => 'TOTP and recovery-code enrollment/enforcement are implemented; passkey remains reserved'];
}

function cms_encryption_key_readiness_detail(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return 'missing';
    }
    if (strlen($key) < 32) {
        return 'too-short';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
        return 'invalid-characters';
    }
    $lower = strtolower($key);
    foreach (['change-me', 'placeholder', 'example', 'password', 'secret', 'generate-a-random', 'your-key'] as $marker) {
        if (str_contains($lower, $marker)) {
            return 'placeholder';
        }
    }

    return 'configured';
}

function cms_readiness_command_available(string $command): bool
{
    $command = trim($command);
    if ($command === '' || str_contains($command, "\0")) {
        return false;
    }

    if (str_contains($command, '/') || str_contains($command, '\\')) {
        return is_file($command) && is_executable($command);
    }

    if (preg_match('/^[A-Za-z0-9._-]+$/', $command) !== 1) {
        return false;
    }

    $path = (string) getenv('PATH');
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command;
        if (is_file($candidate) && is_executable($candidate)) {
            return true;
        }
    }

    return false;
}

function cms_ini_bytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }

    if (!preg_match('/^([0-9]+)([kmg])?$/i', $value, $matches)) {
        return null;
    }

    $bytes = (int) $matches[1];
    $unit = strtolower($matches[2] ?? '');
    foreach (['k', 'm', 'g'] as $candidate) {
        if ($unit === '' || $unit === $candidate) {
            break;
        }
        $bytes *= 1024;
    }

    return $unit === '' ? $bytes : $bytes * 1024;
}

function cms_readiness_media_max_bytes(array $config): ?int
{
    $value = cms_readiness_get($config, 'media.max_file_bytes');
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
        return null;
    }

    $bytes = (int) $value;
    return $bytes > 0 ? $bytes : null;
}

/** @return list<array{id:string,severity:string,ok:bool,message:string,detail:string}> */
function cms_readiness_payment_provider_checks(array $config): array
{
    $dsn = (string) cms_readiness_get($config, 'database.dsn', '');
    if (!str_starts_with($dsn, 'sqlite:')) {
        return [[
            'id' => 'payment.providers.database_inspected',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider storage was not inspected by the offline readiness check',
            'detail' => str_starts_with($dsn, 'mysql:') ? 'run /admin/diagnostics after deployment for live database Provider health' : 'database DSN is not SQLite',
        ]];
    }

    try {
        $pdo = new PDO($dsn, '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $exception) {
        return [[
            'id' => 'payment.providers.database_inspected',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider storage was not inspected because the configured SQLite database is unavailable',
            'detail' => $exception->getMessage(),
        ]];
    }

    try {
        $stmt = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY provider_id ASC, updated_at ASC, id ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return [[
            'id' => 'payment.providers.table',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider settings table is not present yet',
            'detail' => 'run migrations and configure /admin/payments/providers before publishing paid content',
        ]];
    }

    $byProvider = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $providerId = (string) ($row['provider_id'] ?? '');
        if ($providerId !== '') {
            $byProvider[$providerId][] = $row;
        }
    }
    $duplicates = [];
    foreach ($byProvider as $providerId => $providerRows) {
        if (count($providerRows) > 1) {
            $duplicates[] = $providerId;
        }
    }
    $columns = cms_readiness_table_columns($pdo, 'cms_payment_provider_settings');
    $legacyStorageDebt = cms_readiness_payment_provider_legacy_storage_debt($columns, $rows);
    $secretCiphertextIssues = cms_readiness_payment_provider_secret_ciphertext_issues($config, $rows);

    $enabledCheckout = [];
    $defaultCheckout = [];
    foreach ($byProvider as $providerId => $providerRows) {
        usort($providerRows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
        $latest = $providerRows[0] ?? [];
        [$public, $publicOk] = cms_readiness_payment_provider_public_config($latest);
        $enabled = (string) ($latest['status'] ?? '') === 'enabled';
        $checkout = $enabled && $publicOk && cms_readiness_payment_provider_checkout_available($providerId, $public);
        if ($checkout) {
            $enabledCheckout[] = $providerId;
        }
        if ($checkout && ($public['default_provider'] ?? null) === true) {
            $defaultCheckout[] = $providerId;
        }
    }

    return [
        [
            'id' => 'payment.providers.database_inspected',
            'severity' => 'warning',
            'ok' => true,
            'message' => 'Payment Provider storage was inspected',
            'detail' => 'sqlite',
        ],
        [
            'id' => 'payment.providers.unique_provider_id',
            'severity' => 'error',
            'ok' => $duplicates === [],
            'message' => 'Payment Provider storage has one row per Provider ID',
            'detail' => $duplicates === [] ? '' : 'duplicates: ' . implode(', ', $duplicates) . '; repair in /admin/payments/providers or run php scripts/diagnose_payment_providers.php --json --repair',
        ],
        [
            'id' => 'payment.providers.legacy_plugin_storage',
            'severity' => 'warning',
            'ok' => $legacyStorageDebt === [],
            'message' => 'Payment Provider legacy plugin fields are migrated into Core settings',
            'detail' => $legacyStorageDebt === [] ? '' : 'legacy fields: ' . implode(', ', $legacyStorageDebt) . '; repair in /admin/payments/providers or run php scripts/diagnose_payment_providers.php --json --repair',
        ],
        [
            'id' => 'payment.providers.secret_ciphertext',
            'severity' => 'warning',
            'ok' => $secretCiphertextIssues === [],
            'message' => 'Payment Provider encrypted secret payloads can be decrypted with the configured Core key',
            'detail' => $secretCiphertextIssues === [] ? '' : 'unreadable Provider secrets: ' . implode(', ', $secretCiphertextIssues) . '; re-enter Provider secrets in /admin/payments/providers',
        ],
        [
            'id' => 'payment.providers.enabled_checkout',
            'severity' => 'warning',
            'ok' => $enabledCheckout !== [],
            'message' => 'At least one enabled Payment Provider can create checkout payments',
            'detail' => $enabledCheckout === [] ? 'none' : implode(', ', $enabledCheckout),
        ],
        [
            'id' => 'payment.providers.default_checkout',
            'severity' => 'warning',
            'ok' => count($defaultCheckout) === 1,
            'message' => 'Exactly one checkout-capable Payment Provider is marked as default',
            'detail' => $defaultCheckout === [] ? 'none' : implode(', ', $defaultCheckout),
        ],
        [
            'id' => 'payment.providers.manual_payment_ready',
            'severity' => 'warning',
            'ok' => in_array('core.manual-payment', $enabledCheckout, true),
            'message' => 'core.manual-payment is ready for the manual Card Delivery acceptance loop',
            'detail' => in_array('core.manual-payment', $enabledCheckout, true) ? 'ready' : 'not ready',
        ],
        ...cms_readiness_payment_provider_runtime_checks($config, $enabledCheckout, $defaultCheckout),
    ];
}

/** @param list<string> $enabledCheckout @param list<string> $defaultCheckout @return list<array{id:string,severity:string,ok:bool,message:string,detail:string}> */
function cms_readiness_payment_provider_runtime_checks(array $config, array $enabledCheckout, array $defaultCheckout): array
{
    $dsn = (string) cms_readiness_get($config, 'database.dsn', '');
    $supported = str_starts_with($dsn, 'sqlite:');
    if (!$supported) {
        return [[
            'id' => 'payment.providers.runtime_probe',
            'severity' => 'warning',
            'ok' => true,
            'message' => 'Payment Provider runtime probe was skipped',
            'detail' => 'runtime probe is only non-destructive for SQLite databases; run /admin/diagnostics after deployment',
        ]];
    }
    $databasePath = substr($dsn, 7);
    if ($databasePath === '' || $databasePath === ':memory:' || !is_file($databasePath) || !is_readable($databasePath)) {
        return [[
            'id' => 'payment.providers.runtime_probe',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider runtime probe could not copy the configured SQLite database',
            'detail' => 'database file is not readable',
        ]];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cms-readiness-provider-');
    if ($tmp === false) {
        return [[
            'id' => 'payment.providers.runtime_probe',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider runtime probe could not create a temporary database copy',
            'detail' => 'tempnam failed',
        ]];
    }

    try {
        if (!cms_readiness_snapshot_sqlite_database($databasePath, $tmp)) {
            throw new RuntimeException('SQLite database copy failed.');
        }
        $rootPath = dirname(__DIR__);
        require_once $rootPath . '/system/core/Bootstrap/autoload.php';
        \Cms\Core\Payment\PaymentProviderRegistry::clear();
        \Cms\Core\Payment\PaymentProviderRegistry::register(\Cms\Core\Payment\ManualPaymentProvider::PROVIDER_ID, new \Cms\Core\Payment\ManualPaymentProvider());
        \Cms\Core\Payment\PaymentProviderRegistry::register(\Cms\Core\Payment\HostedRedirectPaymentProvider::PROVIDER_ID, new \Cms\Core\Payment\HostedRedirectPaymentProvider());
        if ((bool) cms_readiness_get($config, 'payment.fixture_provider_enabled', false) === true) {
            \Cms\Core\Payment\PaymentProviderRegistry::register(\Cms\Core\Payment\FixturePaymentProvider::PROVIDER_ID, new \Cms\Core\Payment\FixturePaymentProvider());
        }
        $probeConfig = $config;
        $probeConfig['database'] = is_array($probeConfig['database'] ?? null) ? $probeConfig['database'] : [];
        $probeConfig['database']['dsn'] = 'sqlite:' . $tmp;
        $probeConfig['database']['username'] = '';
        $probeConfig['database']['password'] = '';
        $settings = \Cms\Core\Config\Settings::fromArray($probeConfig);
        $pdo = \Cms\Core\Database\ConnectionFactory::make($settings);
        $actualEnabled = array_values(array_map('strval', array_column(
            (new \Cms\Core\Payment\PaymentService(
                $pdo,
                new \Cms\Core\Payment\PaymentRepository($pdo),
                (string) $settings->get('security.encryption_key', ''),
            ))->enabledProviders(),
            'id',
        )));
        sort($actualEnabled);
        $expectedEnabled = array_values(array_map('strval', $enabledCheckout));
        sort($expectedEnabled);
        $expectedDefault = count($defaultCheckout) === 1 ? (string) $defaultCheckout[0] : ($expectedEnabled[0] ?? '');
        try {
            $actualDefault = (new \Cms\Core\Payment\PaymentProviderSelector($pdo, $settings))->defaultProviderId();
        } catch (Throwable $exception) {
            $actualDefault = 'error:' . $exception->getMessage();
        }

        return [
            [
                'id' => 'payment.providers.runtime_enabled_checkout',
                'severity' => 'warning',
                'ok' => $expectedEnabled !== [] && $actualEnabled === $expectedEnabled,
                'message' => 'PaymentService discovers the same enabled checkout Providers as the readiness table scan',
                'detail' => 'expected: ' . implode(', ', $expectedEnabled) . '; actual: ' . implode(', ', $actualEnabled),
            ],
            [
                'id' => 'payment.providers.runtime_default_checkout',
                'severity' => 'warning',
                'ok' => $actualDefault !== '' && $actualDefault === $expectedDefault,
                'message' => 'PaymentProviderSelector resolves the expected default checkout Provider',
                'detail' => 'expected: ' . $expectedDefault . '; actual: ' . $actualDefault,
            ],
        ];
    } catch (Throwable $exception) {
        return [[
            'id' => 'payment.providers.runtime_probe',
            'severity' => 'warning',
            'ok' => false,
            'message' => 'Payment Provider runtime probe failed before production rollout',
            'detail' => $exception->getMessage(),
        ]];
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        foreach ([$tmp . '-wal', $tmp . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
    }
}

function cms_readiness_snapshot_sqlite_database(string $sourcePath, string $targetPath): bool
{
    if (is_file($targetPath) && !@unlink($targetPath)) {
        return false;
    }
    try {
        $source = new PDO('sqlite:' . $sourcePath);
        $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $source->exec('VACUUM INTO ' . $source->quote($targetPath));

        return is_file($targetPath);
    } catch (Throwable) {
    }
    if (!copy($sourcePath, $targetPath)) {
        return false;
    }
    foreach (['-wal', '-shm'] as $suffix) {
        $sidecar = $sourcePath . $suffix;
        if (is_file($sidecar)) {
            @copy($sidecar, $targetPath . $suffix);
        }
    }

    return true;
}

/** @param list<array<string,mixed>> $rows @return list<string> */
function cms_readiness_payment_provider_secret_ciphertext_issues(array $config, array $rows): array
{
    $key = (string) cms_readiness_get($config, 'security.encryption_key', '');
    $issues = [];
    foreach ($rows as $row) {
        $providerId = (string) ($row['provider_id'] ?? '');
        $ciphertext = (string) ($row['secret_config_ciphertext'] ?? '');
        if ($ciphertext === '') {
            continue;
        }
        if (!cms_readiness_decrypt_payment_provider_secrets($ciphertext, $key)) {
            $issues[] = $providerId !== '' ? $providerId : '[unknown]';
        }
    }

    return array_values(array_unique($issues));
}

function cms_readiness_decrypt_payment_provider_secrets(string $payload, string $key): bool
{
    if (!cms_valid_encryption_key($key)) {
        return false;
    }
    $raw = base64_decode($payload, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        return false;
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', trim($key), true), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($plain)) {
        return false;
    }
    try {
        $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    if (!is_array($decoded)) {
        return false;
    }
    foreach ($decoded as $secretKey => $secretValue) {
        if (!is_string($secretKey) || $secretKey === '' || !is_string($secretValue) || $secretValue === '') {
            return false;
        }
    }

    return true;
}

/** @return list<string> */
function cms_readiness_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return [];
    }

    return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows)));
}

/** @param list<string> $columns @param list<array<string,mixed>> $rows @return list<string> */
function cms_readiness_payment_provider_legacy_storage_debt(array $columns, array $rows): array
{
    $legacyColumns = array_values(array_intersect($columns, [
        'enabled',
        'is_enabled',
        'is_default',
        'default_provider',
        'config_json',
        'public_config',
        'settings_json',
        'public_settings_json',
        'instructions',
        'payment_instructions',
        'name',
        'title',
    ]));
    if ($legacyColumns === []) {
        return [];
    }

    $debt = [];
    foreach ($rows as $row) {
        $providerId = (string) ($row['provider_id'] ?? '');
        if ($providerId === '') {
            $providerId = '[unknown]';
        }
        $status = (string) ($row['status'] ?? '');
        $public = cms_readiness_loose_json_object((string) ($row['public_config_json'] ?? '{}'));
        $reasons = [];
        $expectedEnabled = $status === 'enabled';
        foreach (['enabled', 'is_enabled'] as $column) {
            if (array_key_exists($column, $row) && cms_readiness_truthy($row[$column] ?? null) !== $expectedEnabled) {
                $reasons[] = 'enabled';
                break;
            }
        }
        if ((cms_readiness_truthy($row['enabled'] ?? null) || cms_readiness_truthy($row['is_enabled'] ?? null)) && $status !== 'enabled') {
            $reasons[] = 'enabled';
        }
        $expectedDefault = $expectedEnabled && ($public['default_provider'] ?? null) === true;
        foreach (['is_default', 'default_provider'] as $column) {
            if (array_key_exists($column, $row) && cms_readiness_truthy($row[$column] ?? null) !== $expectedDefault) {
                $reasons[] = 'default';
                break;
            }
        }
        if ((cms_readiness_truthy($row['is_default'] ?? null) || cms_readiness_truthy($row['default_provider'] ?? null)) && $status === 'enabled' && ($public['default_provider'] ?? null) !== true) {
            $reasons[] = 'default';
        }
        foreach (['payment_instructions', 'instructions'] as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $legacyInstructions = (string) ($row[$column] ?? '');
            if (($legacyInstructions !== '' && !array_key_exists('instructions', $public)) || ($legacyInstructions === '' && array_key_exists('instructions', $public))) {
                $reasons[] = 'instructions';
                break;
            }
        }
        foreach (['public_config', 'config_json', 'settings_json', 'public_settings_json'] as $column) {
            if (!array_key_exists($column, $row) || !is_string($row[$column]) || trim($row[$column]) === '') {
                continue;
            }
            $legacyPublic = cms_readiness_loose_json_object((string) $row[$column]);
            foreach (['instructions', 'checkout_url', 'checkout_base_url', 'return_url_base', 'default_provider'] as $key) {
                if (array_key_exists($key, $legacyPublic) !== array_key_exists($key, $public)
                    || (array_key_exists($key, $legacyPublic) && array_key_exists($key, $public) && $legacyPublic[$key] !== $public[$key])
                ) {
                    $reasons[] = $column;
                    break 2;
                }
            }
        }
        if ((string) ($row['display_name'] ?? '') === '' && (((string) ($row['name'] ?? '')) !== '' || ((string) ($row['title'] ?? '')) !== '')) {
            $reasons[] = 'display_name';
        }
        if ($reasons !== []) {
            $debt[] = $providerId . ':' . implode('+', array_values(array_unique($reasons)));
        }
    }

    return array_values(array_unique($debt));
}

function cms_readiness_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    return false;
}

/** @return array<string,mixed> */
function cms_readiness_loose_json_object(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $row @return array{0:array<string,mixed>,1:bool} */
function cms_readiness_payment_provider_public_config(array $row): array
{
    $raw = (string) ($row['public_config_json'] ?? '{}');
    if ($raw === '') {
        $raw = '{}';
    }
    if ($raw !== trim($raw)) {
        return [[], false];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [[], false];
    }
    if (!is_array($decoded)) {
        return [[], false];
    }
    foreach ($decoded as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
            return [[], false];
        }
        if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
            return [[], false];
        }
        if (!(is_scalar($value) || $value === null)) {
            return [[], false];
        }
    }

    return [$decoded, true];
}

/** @param array<string,mixed> $public */
function cms_readiness_payment_provider_checkout_available(string $providerId, array $public): bool
{
    if ($providerId === 'core.manual-payment') {
        return true;
    }
    if ($providerId !== 'core.hosted-redirect') {
        return false;
    }
    $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
    $returnUrlBase = (string) ($public['return_url_base'] ?? '');

    return cms_valid_https_url($checkoutUrl)
        && !cms_readiness_url_has_sensitive_query($checkoutUrl)
        && ($returnUrlBase === '' || (cms_valid_https_url($returnUrlBase) && !cms_readiness_url_has_query($returnUrlBase)));
}

function cms_readiness_url_has_query(string $url): bool
{
    $parts = parse_url($url);

    return is_array($parts) && isset($parts['query']) && (string) $parts['query'] !== '';
}

function cms_readiness_url_has_sensitive_query(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['query'])) {
        return false;
    }
    parse_str((string) $parts['query'], $query);
    foreach ($query as $key => $value) {
        if ((string) $key !== 'cms_signature' && preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
            return true;
        }
        if (!is_scalar($value)) {
            return true;
        }
    }

    return false;
}

/** @return array{ok:bool,violations:list<string>} */
function cms_readiness_package_runtime_exclusions(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'violations' => ['package-open-failed']];
    }

    $violations = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_ends_with($name, '.zip')) {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'storage/logs/') && $name !== 'storage/logs/') {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'storage/database/') && $name !== 'storage/database/') {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'storage/recovery/') && $name !== 'storage/recovery/') {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'storage/exports/')) {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'storage/market/') || str_starts_with($name, 'storage/market-server/')) {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'content/uploads/202')) {
            $violations[] = $name;
        }
        if (str_starts_with($name, 'content/plugins/official.payment-fixture/')) {
            $violations[] = $name;
        }
    }
    $zip->close();

    return ['ok' => $violations === [], 'violations' => array_values(array_unique($violations))];
}

/** @return array{sha256_ok:bool,manifest_ok:bool,violations:list<string>} */
function cms_readiness_package_sidecars(string $zipPath): array
{
    $actualHash = hash_file('sha256', $zipPath);
    $actualSize = filesize($zipPath);
    $basename = basename($zipPath);
    $shaPath = $zipPath . '.sha256';
    $manifestPath = str_ends_with($zipPath, '.zip') ? substr($zipPath, 0, -4) . '.manifest.json' : $zipPath . '.manifest.json';
    $violations = [];

    $shaOk = false;
    if (!is_file($shaPath)) {
        $violations[] = 'sha256-sidecar-missing';
    } else {
        $shaText = trim((string) file_get_contents($shaPath));
        $shaOk = $shaText === $actualHash . '  ' . $basename;
        if (!$shaOk) {
            $violations[] = 'sha256-sidecar-mismatch';
        }
    }

    $manifestOk = false;
    if (!is_file($manifestPath)) {
        $violations[] = 'artifact-manifest-missing';
    } else {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifestOk = is_array($manifest)
            && ($manifest['package'] ?? '') === $basename
            && ($manifest['package_sha256'] ?? '') === $actualHash
            && ($manifest['size_bytes'] ?? null) === $actualSize;
        if (!$manifestOk) {
            $violations[] = 'artifact-manifest-mismatch';
        }
    }

    return [
        'sha256_ok' => $shaOk,
        'manifest_ok' => $manifestOk,
        'violations' => array_values(array_unique($violations)),
    ];
}

function cms_redact_readiness_detail(string $detail): string
{
    $detail = preg_replace('/password=([^;\\s]+)/i', 'password=[redacted]', $detail) ?? $detail;
    $detail = preg_replace('/token=([^;\\s]+)/i', 'token=[redacted]', $detail) ?? $detail;
    $detail = preg_replace('/secret=([^;\\s]+)/i', 'secret=[redacted]', $detail) ?? $detail;

    return preg_replace('#/Users/[^\\s,;]+#', '[path]', $detail) ?? $detail;
}

function cms_readiness_cli_help(): string
{
    return <<<'TXT'
Usage: php scripts/validate_production_readiness.php [--json] [--strict]

Options:
  --json      Print machine-readable readiness JSON.
  --strict    Exit non-zero when blocking readiness errors are present.
  --help, -h  Show this help without running environment checks.

Checks cover PHP extensions, install state, HTTPS/security configuration, Core integrity,
Payment Provider storage, writable runtime directories, upload guards and release package sidecars.
TXT;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        echo cms_readiness_cli_help() . PHP_EOL;
        exit(0);
    }

    $root = dirname(__DIR__);
    $json = in_array('--json', $argv, true);
    $strict = in_array('--strict', $argv, true);
    $result = cms_validate_production_readiness($root);

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Production readiness status: ' . $result['status'] . PHP_EOL;
        foreach ($result['checks'] as $check) {
            $mark = $check['ok'] ? 'PASS' : strtoupper($check['severity']);
            echo '[' . $mark . '] ' . $check['message'];
            if ($check['detail'] !== '') {
                echo ' - ' . $check['detail'];
            }
            echo PHP_EOL;
        }
    }

    exit($strict && $result['errors'] > 0 ? 1 : 0);
}

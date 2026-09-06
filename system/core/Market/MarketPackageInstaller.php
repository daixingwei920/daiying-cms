<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use Cms\Core\Plugin\PluginLifecycle;
use PDO;
use ZipArchive;

final class MarketPackageInstaller
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function verifyAndPlan(string $zipPath, InstallAuthorization $authorization): array
    {
        [$manifest, $zipHash] = $this->verify($zipPath, $authorization);
        $target = in_array($manifest->type, ['plugin', 'payment_provider'], true)
            ? $this->rootPath . '/content/plugins/' . $manifest->extensionId
            : $this->rootPath . '/content/themes/' . $manifest->extensionId;

        return [
            'extension_id' => $manifest->extensionId,
            'type' => $manifest->type,
            'version' => $manifest->version,
            'source' => $manifest->source,
            'review_status' => $manifest->reviewStatus,
            'target_dir' => $target,
            'file_count' => count($manifest->files),
            'dependency_count' => count($manifest->dependencies),
            'core_constraint' => $manifest->coreConstraint,
            'php_constraint' => $manifest->phpConstraint,
            'package_sha256' => $zipHash,
            'authorized_until' => $authorization->expiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function install(string $zipPath, InstallAuthorization $authorization, PDO $pdo): array
    {
        $lock = new MarketInstallLock($this->rootPath . '/storage/market/install.lock');
        $lock->acquire();

        try {
            return $this->installWithLock($zipPath, $authorization, $pdo);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function installWithLock(string $zipPath, InstallAuthorization $authorization, PDO $pdo): array
    {
        [$manifest, $zipHash] = $this->verify($zipPath, $authorization);
        $plan = $this->verifyAndPlan($zipPath, $authorization);
        $repo = new MarketInstallRepository($pdo);
        $marketReference = $authorization->marketId !== '' ? $authorization->marketId : $authorization->packageUrl;
        (new ExtensionCompatibilityChecker($this->currentCoreVersion()))->assertCompatible($manifest);
        (new ExtensionDependencyResolver($repo))->assertSatisfied($manifest);

        $repo->recordLog($marketReference, $manifest->extensionId, $manifest->type, 'Installing', $plan);

        $target = (string) $plan['target_dir'];
        $staging = $this->rootPath . '/storage/market/tmp/install-' . bin2hex(random_bytes(8));
        $backup = $this->rootPath . '/storage/market/tmp/backup-' . $manifest->extensionId . '-' . bin2hex(random_bytes(8));

        try {
            $this->extractPackage($zipPath, $manifest, $staging);
            $sourceDir = $staging . '/content/' . (in_array($manifest->type, ['plugin', 'payment_provider'], true) ? 'plugins' : 'themes') . '/' . $manifest->extensionId;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (is_dir($target) && !rename($target, $backup)) {
                throw new MarketException('Unable to backup existing extension.');
            }
            if (!rename($sourceDir, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new MarketException('Unable to move extension into place.');
            }

            $this->removeDirectory($staging);
            if (is_dir($backup)) {
                $this->removeDirectory($backup);
            }

            $result = $plan + [
                'status' => 'Installed',
                'market_id' => $marketReference,
                'installed_at' => gmdate('c'),
            ];
            $repo->recordSource($manifest->extensionId, $manifest->type, $manifest->source, $marketReference, $manifest->version, [
                'package_sha256' => $zipHash,
                'review_status' => $manifest->reviewStatus,
                'status' => $result['status'],
                'dependencies' => array_map(static fn (ExtensionDependency $dependency): array => [
                    'extension_id' => $dependency->extensionId,
                    'type' => $dependency->type,
                    'version' => $dependency->constraint,
                    'optional' => $dependency->optional,
                ], $manifest->dependencies),
            ]);
            if (in_array($manifest->type, ['plugin', 'payment_provider'], true)) {
                $this->registerPluginRecord($pdo, $target, $manifest, $marketReference);
            }
            $repo->recordLog($marketReference, $manifest->extensionId, $manifest->type, 'Installed', $result);

            return $result;
        } catch (\Throwable $exception) {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            if (!is_dir($target) && is_dir($backup)) {
                rename($backup, $target);
            } elseif (is_dir($backup)) {
                $this->removeDirectory($backup);
            }
            $repo->recordLog($marketReference, $manifest->extensionId, $manifest->type, 'Failed', $plan + ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    /** @return array{0: MarketPackageManifest, 1: string} */
    private function verify(string $zipPath, InstallAuthorization $authorization): array
    {
        if ($authorization->isExpired()) {
            throw new MarketException('Install authorization is expired.');
        }

        if (!class_exists(ZipArchive::class)) {
            throw new MarketException('ZipArchive extension is required for market packages.');
        }

        $zipHash = hash_file('sha256', $zipPath);
        if (!is_string($zipHash)) {
            throw new MarketException('Unable to hash market package.');
        }
        if ($authorization->packageSha256 !== '' && !hash_equals($authorization->packageSha256, $zipHash)) {
            throw new MarketException('Market package hash does not match authorization.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new MarketException('Unable to open market package.');
        }

        $manifestJson = $zip->getFromName('market-package.json');
        $decoded = json_decode(is_string($manifestJson) ? $manifestJson : '', true);
        if (is_array($decoded)) {
            $manifest = MarketPackageManifest::fromArray($decoded);
        } else {
            $manifest = $this->manifestFromExtensionPackage($zip);
            if ($manifest === null) {
                $zip->close();
                throw new MarketException('Market package manifest is missing or invalid.');
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/') || $name === 'market-package.json' || $name === 'manifest.json') {
                continue;
            }
            $manifestPath = $this->manifestFilePath($name, $manifest);
            if ($manifestPath === null || !isset($manifest->files[$manifestPath])) {
                $zip->close();
                throw new MarketException('Market package contains undeclared file: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || hash('sha256', $content) !== $manifest->files[$manifestPath]) {
                $zip->close();
                throw new MarketException('Market package file hash mismatch: ' . $name);
            }
        }
        $zip->close();

        return [$manifest, $zipHash];
    }

    private function extractPackage(string $zipPath, MarketPackageManifest $manifest, string $staging): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new MarketException('Unable to open market package.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/') || $name === 'market-package.json' || $name === 'manifest.json') {
                continue;
            }
            $manifestPath = $this->manifestFilePath($name, $manifest);
            if ($manifestPath === null || !isset($manifest->files[$manifestPath])) {
                $zip->close();
                throw new MarketException('Market package contains undeclared file: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                $zip->close();
                throw new MarketException('Unable to read market package file: ' . $name);
            }
            $target = $staging . '/' . $manifestPath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $content);
        }
        $zip->close();
    }

    private function manifestFromExtensionPackage(ZipArchive $zip): ?MarketPackageManifest
    {
        $located = $this->locateExtensionManifest($zip);
        if ($located === null) {
            return null;
        }
        $extension = $located['extension_id'];
        $type = $located['type'];
        $prefix = in_array($type, ['plugin', 'payment_provider'], true) ? 'content/plugins/' . $extension . '/' : 'content/themes/' . $extension . '/';
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/') || $name === 'manifest.json' || $name === 'market-package.json') {
                continue;
            }
            $manifestPath = $this->extensionPackagePath($name, $extension, $type);
            if ($manifestPath === null) {
                throw new MarketException('Market package contains undeclared file: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                throw new MarketException('Unable to read market package file: ' . $name);
            }
            if (!str_starts_with($manifestPath, $prefix)) {
                throw new MarketException('Market package contains unsafe path: ' . $name);
            }
            $files[$manifestPath] = hash('sha256', $content);
        }

        $manifest = $located['manifest'];
        return MarketPackageManifest::fromArray([
            'extension_id' => $extension,
            'type' => $type,
            'version' => (string) ($manifest['version'] ?? ''),
            'source' => ExtensionSource::OFFICIAL_MARKET,
            'review_status' => 'published',
            'core' => $this->coreConstraint($manifest),
            'php' => (string) ($manifest['php'] ?? ''),
            'dependencies' => is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [],
            'files' => $files,
        ]);
    }

    /** @return array{path:string,manifest:array<string,mixed>,extension_id:string,type:string}|null */
    private function locateExtensionManifest(ZipArchive $zip): ?array
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $basename = basename($name);
            if (!in_array($basename, ['plugin.json', 'theme.json'], true) || !$this->allowedExtensionManifestPath($name, $basename)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            $manifest = json_decode(is_string($content) ? $content : '', true);
            if (!is_array($manifest)) {
                continue;
            }
            $type = $basename === 'theme.json' ? 'theme' : (string) ($manifest['package_type'] ?? $manifest['type'] ?? 'plugin');
            if ($type === 'payment-provider') {
                $type = 'payment_provider';
            }
            if (!in_array($type, ['plugin', 'payment_provider', 'theme'], true)) {
                $type = $basename === 'theme.json' ? 'theme' : 'plugin';
            }
            $extension = $basename === 'theme.json'
                ? (string) ($manifest['theme_id'] ?? $manifest['product_id'] ?? '')
                : (string) ($manifest['plugin_id'] ?? $manifest['product_id'] ?? '');
            if ($extension === '') {
                continue;
            }
            $candidates[] = ['path' => $name, 'manifest' => $manifest, 'extension_id' => $extension, 'type' => $type];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => substr_count((string) $a['path'], '/') <=> substr_count((string) $b['path'], '/'));

        return $candidates[0];
    }

    private function allowedExtensionManifestPath(string $path, string $basename): bool
    {
        if (str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '../') || str_contains($path, '/..')) {
            return false;
        }
        if ($basename === 'plugin.json') {
            return $path === 'plugin.json'
                || preg_match('#^[A-Za-z0-9._-]+/plugin\.json$#', $path) === 1
                || preg_match('#^content/plugins/[A-Za-z0-9._-]+/plugin\.json$#', $path) === 1;
        }
        return $path === 'theme.json'
            || preg_match('#^[A-Za-z0-9._-]+/theme\.json$#', $path) === 1
            || preg_match('#^content/themes/[A-Za-z0-9._-]+/theme\.json$#', $path) === 1;
    }

    private function manifestFilePath(string $name, MarketPackageManifest $manifest): ?string
    {
        if (isset($manifest->files[$name])) {
            return $name;
        }

        return $this->extensionPackagePath($name, $manifest->extensionId, $manifest->type);
    }

    private function extensionPackagePath(string $name, string $extensionId, string $type): ?string
    {
        if (str_starts_with($name, '/') || str_contains($name, "\0") || str_contains($name, '../') || str_contains($name, '/..')) {
            return null;
        }
        $base = in_array($type, ['plugin', 'payment_provider'], true) ? 'content/plugins/' : 'content/themes/';
        $rootPrefix = $extensionId . '/';
        $contentPrefix = $base . $extensionId . '/';
        if (str_starts_with($name, $contentPrefix)) {
            return $name;
        }
        if (str_starts_with($name, $rootPrefix)) {
            return $contentPrefix . substr($name, strlen($rootPrefix));
        }
        if (($type === 'theme' && $name === 'theme.json') || ($type !== 'theme' && $name === 'plugin.json')) {
            return $contentPrefix . $name;
        }

        return null;
    }

    /** @param array<string,mixed> $manifest */
    private function coreConstraint(array $manifest): string
    {
        $core = $manifest['core'] ?? '*';
        if (is_array($core) && isset($core['min'])) {
            return '>=' . (string) $core['min'];
        }

        return is_string($core) && $core !== '' ? $core : '*';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function configValue(string $key, mixed $default): mixed
    {
        $configFile = $this->rootPath . '/config/app.php';
        $config = is_file($configFile) ? require $configFile : [];
        if (!is_array($config)) {
            return $default;
        }

        $value = $config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function currentCoreVersion(): string
    {
        $configuredVersion = (string) $this->configValue('app.version', '0.0.0');
        $pointer = $this->rootPath . '/storage/updates/current-release.json';
        if (!is_file($pointer) || !is_readable($pointer)) {
            return $configuredVersion;
        }

        $decoded = json_decode((string) file_get_contents($pointer), true);
        if (!is_array($decoded)) {
            return $configuredVersion;
        }

        $version = trim((string) ($decoded['version'] ?? ''));
        if ($version === '' || preg_match('/^[0-9][A-Za-z0-9._-]*$/', $version) !== 1) {
            return $configuredVersion;
        }

        return $version;
    }

    private function registerPluginRecord(PDO $pdo, string $target, MarketPackageManifest $marketManifest, string $marketReference): void
    {
        $pluginJson = $target . '/plugin.json';
        $manifest = is_file($pluginJson) ? json_decode((string) file_get_contents($pluginJson), true) : null;
        if (!is_array($manifest)) {
            throw new MarketException('Installed market plugin manifest is missing or invalid.');
        }

        $pluginId = (string) ($manifest['plugin_id'] ?? '');
        if ($pluginId !== $marketManifest->extensionId) {
            throw new MarketException('Installed market plugin id does not match market package extension id.');
        }

        $existing = $this->pluginRow($pdo, $pluginId);
        $status = is_array($existing) && (string) ($existing['status'] ?? '') === PluginLifecycle::ENABLED
            ? PluginLifecycle::ENABLED
            : PluginLifecycle::INSTALLED;
        $deps = $manifest['required_plugins'] ?? $manifest['dependencies'] ?? [];
        $prefixes = $manifest['table_prefixes'] ?? ($manifest['database_prefixes'] ?? []);
        if (!is_array($prefixes) && isset($manifest['database_prefix'])) {
            $prefixes = [(string) $manifest['database_prefix']];
        }
        $now = gmdate('c');
        $reviewStatus = in_array($marketManifest->reviewStatus, ['published', 'approved', 'official_trusted'], true)
            ? $marketManifest->reviewStatus
            : 'published';
        $source = $marketManifest->source !== '' && $marketManifest->source !== ExtensionSource::UNKNOWN
            ? $marketManifest->source
            : ExtensionSource::OFFICIAL_MARKET;

        if ($existing !== null) {
            $stmt = $pdo->prepare('UPDATE cms_plugins SET name = :name, version = :version, author = :author, status = :status, trust_level = :trust_level, capabilities_json = :capabilities_json, dependencies_json = :dependencies_json, optional_dependencies_json = :optional_dependencies_json, data_policy_json = :data_policy_json, data_schema_version = :data_schema_version, source = :source, review_status = :review_status, table_prefixes_json = :table_prefixes_json, last_error = NULL, updated_at = :updated_at WHERE plugin_id = :plugin_id');
            $params = [];
        } else {
            $stmt = $pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at, source, review_status, dependencies_json, optional_dependencies_json, data_policy_json, data_schema_version, table_prefixes_json) VALUES (:plugin_id, :name, :version, :author, :status, :trust_level, :capabilities_json, :installed_at, :updated_at, :source, :review_status, :dependencies_json, :optional_dependencies_json, :data_policy_json, :data_schema_version, :table_prefixes_json)');
            $params = [':installed_at' => $now];
        }

        $stmt->execute($params + [
            ':plugin_id' => $pluginId,
            ':name' => (string) ($manifest['name'] ?? $pluginId),
            ':version' => (string) ($manifest['version'] ?? $marketManifest->version),
            ':author' => (string) ($manifest['author'] ?? ''),
            ':status' => $status,
            ':trust_level' => (string) ($manifest['trust_level'] ?? 'api'),
            ':capabilities_json' => json_encode($manifest['capabilities'] ?? [], JSON_UNESCAPED_SLASHES),
            ':dependencies_json' => json_encode(is_array($deps) ? $deps : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':optional_dependencies_json' => json_encode($manifest['optional_dependencies'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':data_policy_json' => json_encode($manifest['data_policy'] ?? ['uninstall' => 'retain'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':data_schema_version' => (string) ($manifest['data_schema_version'] ?? $manifest['version'] ?? $marketManifest->version),
            ':source' => $source,
            ':review_status' => $reviewStatus,
            ':table_prefixes_json' => json_encode(is_array($prefixes) ? array_values(array_map('strval', $prefixes)) : [], JSON_UNESCAPED_SLASHES),
            ':updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function pluginRow(PDO $pdo, string $pluginId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}

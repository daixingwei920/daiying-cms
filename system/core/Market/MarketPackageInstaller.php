<?php

declare(strict_types=1);

namespace Cms\Core\Market;

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
        $target = $manifest->type === 'plugin'
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
        $marketReference = $authorization->packageUrl;
        (new ExtensionCompatibilityChecker((string) $this->configValue('app.version', '0.0.0')))->assertCompatible($manifest);
        (new ExtensionDependencyResolver($repo))->assertSatisfied($manifest);

        $repo->recordLog($marketReference, $manifest->extensionId, $manifest->type, 'Installing', $plan);

        $target = (string) $plan['target_dir'];
        $staging = $this->rootPath . '/storage/market/tmp/install-' . bin2hex(random_bytes(8));
        $backup = $this->rootPath . '/storage/market/tmp/backup-' . $manifest->extensionId . '-' . bin2hex(random_bytes(8));

        try {
            $this->extractPackage($zipPath, $manifest, $staging);
            $sourceDir = $staging . '/content/' . ($manifest->type === 'plugin' ? 'plugins' : 'themes') . '/' . $manifest->extensionId;
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
                'dependencies' => array_map(static fn (ExtensionDependency $dependency): array => [
                    'extension_id' => $dependency->extensionId,
                    'type' => $dependency->type,
                    'version' => $dependency->constraint,
                    'optional' => $dependency->optional,
                ], $manifest->dependencies),
            ]);
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
        if (!is_array($decoded)) {
            $zip->close();
            throw new MarketException('Market package manifest is missing or invalid.');
        }

        $manifest = MarketPackageManifest::fromArray($decoded);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === 'market-package.json') {
                continue;
            }
            if (!isset($manifest->files[$name])) {
                $zip->close();
                throw new MarketException('Market package contains undeclared file: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || hash('sha256', $content) !== $manifest->files[$name]) {
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
            if ($name === 'market-package.json') {
                continue;
            }
            if (!isset($manifest->files[$name])) {
                $zip->close();
                throw new MarketException('Market package contains undeclared file: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                $zip->close();
                throw new MarketException('Unable to read market package file: ' . $name);
            }
            $target = $staging . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $content);
        }
        $zip->close();
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
}

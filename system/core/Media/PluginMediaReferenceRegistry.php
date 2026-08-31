<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use PDO;
use Throwable;

final class PluginMediaReferenceRegistry
{
    /** @var array<string,PluginMediaReferenceProviderInterface> */
    private static array $providers = [];

    public static function register(PluginMediaReferenceProviderInterface $provider): void
    {
        self::$providers[$provider->pluginId()] = $provider;
    }

    /** @return list<PluginMediaReferenceProviderInterface> */
    public static function providers(): array
    {
        return array_values(self::$providers);
    }

    public static function clear(): void
    {
        self::$providers = [];
    }

    public static function loadInstalled(PDO $pdo, string $rootPath): void
    {
        try {
            $stmt = $pdo->query('SELECT plugin_id FROM cms_plugins');
            foreach ($stmt->fetchAll() as $row) {
                $pluginId = (string) ($row['plugin_id'] ?? '');
                if ($pluginId === '') {
                    continue;
                }
                self::loadProvider($pdo, $rootPath, $pluginId);
            }
        } catch (Throwable) {
            return;
        }
    }

    private static function loadProvider(PDO $pdo, string $rootPath, string $pluginId): void
    {
        $manifestPath = rtrim($rootPath, '/') . '/content/plugins/' . $pluginId . '/plugin.json';
        $entryPath = rtrim($rootPath, '/') . '/content/plugins/' . $pluginId . '/plugin.php';
        if (!is_file($manifestPath) || !is_file($entryPath)) {
            return;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['media_reference_provider'])) {
            return;
        }
        $class = (string) $manifest['media_reference_provider'];
        if ($class === '' || str_contains($class, '..') || str_contains($class, "\0")) {
            return;
        }
        if (!class_exists($class)) {
            require_once $entryPath;
        }
        if (!class_exists($class) || !is_subclass_of($class, PluginMediaReferenceProviderInterface::class)) {
            return;
        }
        $provider = new $class($pdo);
        if ($provider instanceof PluginMediaReferenceProviderInterface) {
            self::register($provider);
        }
    }
}

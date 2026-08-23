<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Theme\ThemeManifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class DeveloperPackageBuilder
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return array{package_path:string,package_sha256:string,byte_size:int,extension_id:string,type:string,version:string,file_count:int,manifest:array<string,mixed>} */
    public function build(int $projectId, string $type, string $extensionId, string $version = ''): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new MarketServerException('ZipArchive extension is required to generate market packages.');
        }
        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new MarketServerException('Market package type must be plugin or theme.');
        }

        $sourceDir = $this->sourceDirectory($type, $extensionId);
        $manifest = $this->extensionManifest($type, $sourceDir);
        $actualId = (string) ($manifest['id'] ?? '');
        if ($actualId !== $extensionId) {
            throw new MarketServerException('Extension ID does not match the selected project package source.');
        }

        $version = trim($version) !== '' ? trim($version) : (string) ($manifest['version'] ?? '1.0.0');
        if ($version === '' || strlen($version) > 64 || preg_match('/[\x00-\x1F\x7F]/', $version) === 1) {
            throw new MarketServerException('Package version is invalid.');
        }

        $files = $this->fileHashes($sourceDir, $type, $extensionId);
        if ($files === []) {
            throw new MarketServerException('Extension package source has no files.');
        }

        $marketManifest = [
            'extension_id' => $extensionId,
            'type' => $type,
            'version' => $version,
            'source' => 'developer_center',
            'review_status' => 'developer_generated',
            'core' => (string) ($manifest['core'] ?? '*'),
            'php' => (string) ($manifest['php'] ?? ''),
            'dependencies' => [],
            'files' => $files,
        ];

        $outputDir = $this->rootPath . '/storage/market/generated/project-' . $projectId;
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new MarketServerException('Unable to create market package output directory.');
        }

        $packagePath = $outputDir . '/' . $extensionId . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $version) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new MarketServerException('Unable to create market package.');
        }

        $zip->addFromString('market-package.json', json_encode($marketManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        foreach (array_keys($files) as $entry) {
            $absolute = $this->rootPath . '/' . $entry;
            if (!$zip->addFile($absolute, $entry)) {
                $zip->close();
                throw new MarketServerException('Unable to add extension file to package: ' . $entry);
            }
        }
        $zip->close();

        $sha256 = hash_file('sha256', $packagePath);
        if (!is_string($sha256)) {
            throw new MarketServerException('Unable to hash generated market package.');
        }

        return [
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
            'byte_size' => filesize($packagePath) ?: 0,
            'extension_id' => $extensionId,
            'type' => $type,
            'version' => $version,
            'file_count' => count($files),
            'manifest' => $marketManifest,
        ];
    }

    private function sourceDirectory(string $type, string $extensionId): string
    {
        $valid = $type === 'plugin'
            ? preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $extensionId) === 1
            : preg_match('/^[a-z][a-z0-9_]{2,63}$/', $extensionId) === 1;
        if (!$valid) {
            throw new MarketServerException('Extension ID is invalid.');
        }

        $sourceDir = $this->rootPath . '/content/' . ($type === 'plugin' ? 'plugins' : 'themes') . '/' . $extensionId;
        if (!is_dir($sourceDir)) {
            throw new MarketServerException('Extension package source directory was not found.');
        }

        return $sourceDir;
    }

    /** @return array{id:string,version:string,core:string,php:string} */
    private function extensionManifest(string $type, string $sourceDir): array
    {
        $manifestPath = $sourceDir . '/' . ($type === 'plugin' ? 'plugin.json' : 'theme.json');
        $decoded = json_decode(is_file($manifestPath) ? (string) file_get_contents($manifestPath) : '', true);
        if (!is_array($decoded)) {
            throw new MarketServerException('Extension manifest is missing or invalid.');
        }

        if ($type === 'plugin') {
            $manifest = PluginManifest::fromArray($decoded);
            return [
                'id' => $manifest->id,
                'version' => $manifest->version,
                'core' => $manifest->coreMin,
                'php' => $manifest->phpMin,
            ];
        }

        $manifest = ThemeManifest::fromArray($decoded);
        return [
            'id' => $manifest->id,
            'version' => $manifest->version,
            'core' => $manifest->coreMin,
            'php' => '',
        ];
    }

    /** @return array<string,string> */
    private function fileHashes(string $sourceDir, string $type, string $extensionId): array
    {
        $prefix = 'content/' . ($type === 'plugin' ? 'plugins' : 'themes') . '/' . $extensionId . '/';
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = (string) $item->getPathname();
            if ($item->isDir()) {
                continue;
            }
            if ($item->isLink()) {
                throw new MarketServerException('Extension package source must not contain symlinks.');
            }
            $relative = str_replace('\\', '/', substr($path, strlen($sourceDir) + 1));
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                throw new MarketServerException('Extension package source contains an unsafe path.');
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new MarketServerException('Unable to hash extension file: ' . $relative);
            }
            $files[$prefix . $relative] = $hash;
        }
        ksort($files);

        return $files;
    }
}

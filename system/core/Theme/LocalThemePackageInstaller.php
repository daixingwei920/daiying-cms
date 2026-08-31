<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use ZipArchive;

final class LocalThemePackageInstaller
{
    private const MAX_ZIP_BYTES = 10_485_760;
    private const MAX_FILES = 500;
    private const MAX_EXTRACT_BYTES = 25_000_000;
    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
    ) {
    }

    /** @return array{theme_id:string,name:string,version:string,author:string} */
    public function install(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ThemeException('当前服务器未启用 ZipArchive 扩展，无法安装主题 ZIP。');
        }
        if (!is_file($zipPath)) {
            throw new ThemeException('主题 ZIP 文件不存在。');
        }
        $zipBytes = filesize($zipPath);
        if (!is_int($zipBytes) || $zipBytes > self::MAX_ZIP_BYTES) {
            throw new ThemeException('主题 ZIP 文件超过大小限制。');
        }

        $staging = $this->rootPath . '/storage/runtime/theme-install-' . bin2hex(random_bytes(8));
        $this->ensureDir($staging);
        try {
            $this->safeExtract($zipPath, $staging);
            $root = $this->singleRoot($staging);
            $manifestFile = $root . '/theme.json';
            if (!is_file($manifestFile)) {
                throw new ThemeException('主题包缺少 theme.json。');
            }
            $decoded = json_decode((string) file_get_contents($manifestFile), true);
            if (!is_array($decoded)) {
                throw new ThemeException('主题 Manifest 不是有效 JSON。');
            }
            $manifest = ThemeManifest::fromArray($decoded);
            if (basename($root) !== $manifest->id) {
                throw new ThemeException('主题目录名必须与 theme_id 一致。');
            }
            if (in_array($manifest->id, ['safe'], true)) {
                throw new ThemeException('安全恢复主题不能通过后台覆盖。');
            }
            if (!is_file($root . '/templates/home.php')) {
                throw new ThemeException('主题包缺少 templates/home.php。');
            }
            if (is_dir($this->rootPath . '/content/themes/' . $manifest->id)) {
                throw new ThemeException('同 ID 主题已存在，请先确认升级流程。');
            }

            $target = $this->rootPath . '/content/themes/' . $manifest->id;
            $this->ensureDir(dirname($target));
            if (!rename($root, $target)) {
                throw new ThemeException('主题文件写入失败。');
            }

            $manager = new ThemeManager($this->rootPath . '/content/themes', $this->settings, $this->logger);
            $manager->load($manifest->id);

            return [
                'theme_id' => $manifest->id,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'author' => $manifest->author,
            ];
        } finally {
            $this->removeDir($staging);
        }
    }

    private function safeExtract(string $zipPath, string $staging): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ThemeException('无法打开主题 ZIP。');
        }
        if ($zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new ThemeException('主题 ZIP 文件数量过多。');
        }

        $seen = [];
        $total = 0;
        $realBase = realpath($staging) ?: $staging;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $this->assertZipName($name, $seen);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $unixMode = ($attributes >> 16) & 0xF000;
            if (in_array($unixMode, [0xA000, 0x6000], true)) {
                $zip->close();
                throw new ThemeException('主题 ZIP 不能包含链接或特殊文件。');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_EXTRACT_BYTES) {
                $zip->close();
                throw new ThemeException('主题 ZIP 解压后体积超过限制。');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                $zip->close();
                throw new ThemeException('无法读取主题 ZIP 条目。');
            }
            $target = $staging . '/' . $name;
            $this->ensureDir(dirname($target));
            if (!str_starts_with(realpath(dirname($target)) ?: dirname($target), $realBase)) {
                $zip->close();
                throw new ThemeException('主题 ZIP 路径越界。');
            }
            file_put_contents($target, $content);
        }
        $zip->close();
    }

    /** @param array<string,bool> $seen */
    private function assertZipName(string $name, array &$seen): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) || str_contains($name, '../') || str_contains($name, '/..')) {
            throw new ThemeException('主题 ZIP 包含不安全路径。');
        }
        if (preg_match('/\.zip$/i', $name)) {
            throw new ThemeException('主题 ZIP 不能包含嵌套 ZIP。');
        }
        if (preg_match('/\.(phtml|phar|php[0-9]+|exe|dll|dylib|bin)$/i', $name)) {
            throw new ThemeException('主题 ZIP 不能包含高风险可执行文件。');
        }
        if (str_starts_with($name, 'system/') || str_starts_with($name, 'config/') || str_starts_with($name, 'storage/') || str_starts_with($name, 'public/') || str_starts_with($name, 'content/plugins/') || str_starts_with($name, 'content/uploads/')) {
            throw new ThemeException('主题 ZIP 不能写入受保护目录。');
        }
        if (substr_count($name, '/') > self::MAX_DEPTH) {
            throw new ThemeException('主题 ZIP 目录层级过深。');
        }
        $normalized = function_exists('normalizer_normalize') && class_exists('\Normalizer')
            ? (normalizer_normalize($name, \Normalizer::FORM_C) ?: $name)
            : $name;
        $lower = function_exists('mb_strtolower') ? mb_strtolower($normalized) : strtolower($normalized);
        if (isset($seen[$lower])) {
            throw new ThemeException('主题 ZIP 存在文件名冲突。');
        }
        $seen[$lower] = true;
    }

    private function singleRoot(string $staging): string
    {
        $items = array_values(array_filter(scandir($staging) ?: [], static fn (string $item): bool => $item !== '.' && $item !== '..'));
        if (count($items) !== 1 || !is_dir($staging . '/' . $items[0])) {
            throw new ThemeException('主题 ZIP 必须包含一个单独的主题根目录。');
        }

        return $staging . '/' . $items[0];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ThemeException('无法创建目录。');
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }
        @rmdir($dir);
    }
}

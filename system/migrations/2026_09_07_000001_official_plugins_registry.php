<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_07_000001_official_plugins_registry';
    }

    public function up(\PDO $pdo): void
    {
        $rootPath = defined('CMS_ROOT') ? (string) CMS_ROOT : dirname(__DIR__, 2);
        $target = $rootPath . '/system/official-plugins.php';
        $current = is_file($target) ? require $target : [];
        $registry = is_array($current) ? $current : [];
        $registry = array_replace_recursive($registry, $this->requiredOfficialPlugins());

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create official plugin registry directory.');
        }

        $content = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . 'return ' . var_export($registry, true) . ";\n";

        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to stage official plugin registry.');
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to update official plugin registry.');
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function requiredOfficialPlugins(): array
    {
        return [
            'official.friend-links' => [
                'directory' => 'official.friend-links',
                'package_type' => 'plugin',
                'type' => 'system-plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['friend_links'],
                'table_prefixes' => ['friend_links_'],
            ],
            'official.novel-collector' => [
                'directory' => 'official.novel-collector',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['novel', 'novel_collector'],
                'table_prefixes' => ['novel_', 'novel_collector_'],
            ],
            'official.video-collector' => [
                'directory' => 'official.video-collector',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['video', 'video_collector'],
                'table_prefixes' => ['video_', 'video_collector_'],
            ],
            'official.commerce' => [
                'directory' => 'official.commerce',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['commerce'],
                'table_prefixes' => ['commerce_'],
            ],
        ];
    }
};

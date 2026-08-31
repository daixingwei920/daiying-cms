<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginRiskBoundaryPolicy
{
    /** @param array<string,mixed> $manifest @return array{mode:string,label:string,admin_notice:string,raw_database_access:bool,requires_official_review:bool,allowed_install_source:string} */
    public static function describe(array $manifest, string $source = 'local_unreviewed', string $reviewStatus = 'unreviewed'): array
    {
        $trustLevel = (string) ($manifest['trust_level'] ?? 'api');
        $trusted = $trustLevel === 'trusted_php' && $source === 'bundled_official' && $reviewStatus === 'official_trusted';

        if ($trusted) {
            return [
                'mode' => 'trusted_php',
                'label' => '可信 PHP 插件',
                'admin_notice' => '仅官方随包插件可进入可信 PHP 模式；可获得原生 PHP/数据库能力，必须经过官方审核、签名和更新链路。',
                'raw_database_access' => true,
                'requires_official_review' => true,
                'allowed_install_source' => 'bundled_official',
            ];
        }

        return [
            'mode' => 'restricted_api',
            'label' => '受限 API 插件',
            'admin_notice' => '第三方和本地 ZIP 插件只能使用声明能力与受控 PluginContext；CMS 不承诺硬沙箱执行 PHP，本地安装需要管理员自行承担信任责任。',
            'raw_database_access' => false,
            'requires_official_review' => $source !== 'bundled_official',
            'allowed_install_source' => 'local_unreviewed_or_market_reviewed',
        ];
    }

    public static function assertLocalManifestAllowed(array $manifest): void
    {
        if ((string) ($manifest['trust_level'] ?? 'api') === 'trusted_php') {
            throw new PluginException('Local plugins cannot claim trusted PHP status.');
        }
    }
}

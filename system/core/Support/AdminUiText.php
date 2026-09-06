<?php

declare(strict_types=1);

namespace Cms\Core\Support;

final class AdminUiText
{
    public static function blockType(string $type): string
    {
        return [
            'paragraph' => '段落',
            'heading' => '标题',
            'unordered_list' => '无序列表',
            'ordered_list' => '有序列表',
            'quote' => '引用',
            'code' => '代码',
            'divider' => '分隔线',
            'button' => '按钮',
            'table' => '表格',
            'html' => 'HTML 内容',
            'raw_text' => '纯文本',
            'image' => '图片',
            'gallery' => '图片集',
            'video' => '视频',
            'audio' => '音频',
            'attachment' => '附件',
            'card_delivery' => '自动发卡',
            'tip' => '打赏支持',
        ][$type] ?? $type;
    }

    public static function contentStatus(string $status): string
    {
        return [
            'draft' => '草稿',
            'published' => '已发布',
            'scheduled' => '定时发布',
            'archived' => '已归档',
        ][$status] ?? $status;
    }

    public static function contentType(string $type): string
    {
        return [
            'article' => '文章',
            'page' => '页面',
        ][$type] ?? $type;
    }

    public static function pluginStatus(string $status): string
    {
        return [
            'Installed' => '已安装',
            'Enabled' => '已启用',
            'Disabled' => '已停用',
            'Quarantined' => '已隔离',
            'Update Pending' => '等待更新',
            'Removed' => '代码已删除',
            'Dormant' => '数据保留中',
            'InstallFailedRecoverable' => '安装失败，可恢复',
        ][$status] ?? $status;
    }

    public static function pluginAction(string $targetStatus): string
    {
        return [
            'Enabled' => '启用',
            'Disabled' => '停用',
        ][$targetStatus] ?? self::pluginStatus($targetStatus);
    }

    public static function trustLevel(string $trustLevel): string
    {
        return [
            'trusted_php' => '可信 PHP 插件',
            'api' => '受限 API 插件',
        ][$trustLevel] ?? $trustLevel;
    }

    public static function pluginType(string $pluginId, string $trustLevel, string $source = ''): string
    {
        if ($source === 'local_legacy') {
            return '旧版本地插件';
        }
        if (str_starts_with($pluginId, 'official.payment.')) {
            return '官方支付插件';
        }
        if (str_starts_with($pluginId, 'official.')) {
            return '官方插件';
        }
        if ($trustLevel === 'trusted_php') {
            return '可信插件';
        }
        if ($source === 'local_unreviewed') {
            return '本地插件';
        }
        return '内容扩展';
    }

    public static function pluginName(string $pluginId, string $name): string
    {
        return [
            'official.payment.stripe' => 'Stripe 官方支付插件',
            'official.payment-fixture' => '模拟支付',
            'official.friend-links' => '友情链接',
            'faq_block' => '常见问题区块',
        ][$pluginId] ?? ($name !== '' ? $name : $pluginId);
    }

    public static function capability(string $capability): string
    {
        return [
            'blocks.register' => '注册内容区块',
            'friend_links.view' => '查看友情链接',
            'friend_links.manage' => '管理友情链接',
        ][$capability] ?? $capability;
    }

    public static function pluginSettingsUrl(string $pluginId): string
    {
        return [
            'official.friend-links' => '/admin/friend-links',
        ][$pluginId] ?? '';
    }
}

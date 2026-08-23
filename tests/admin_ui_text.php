<?php

declare(strict_types=1);

use Cms\Core\Support\AdminUiText;

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

function admin_ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

admin_ui_check(AdminUiText::contentType('article') === '文章', 'content article type is displayed in Chinese');
admin_ui_check(AdminUiText::contentType('page') === '页面', 'content page type is displayed in Chinese');
admin_ui_check(AdminUiText::pluginSettingsUrl('official.commerce') === '', 'Commerce plugin settings link is not hard-coded in CMS Core');
admin_ui_check(AdminUiText::pluginSettingsUrl('official.cj-dropshipping') === '', 'CJ plugin settings link is not hard-coded in CMS Core');
admin_ui_check(AdminUiText::pluginSettingsUrl('official.payment-fixture') === '', 'Payment Fixture does not expose a fake settings page');
admin_ui_check(AdminUiText::pluginSettingsUrl('official.friend-links') === '/admin/friend-links', 'Friend Links plugin settings link opens friend link management');
admin_ui_check(AdminUiText::pluginName('official.commerce', '') === 'official.commerce' && AdminUiText::capability('cj.products.import') === 'cj.products.import', 'external business plugin labels fall back to plugin-provided metadata');
admin_ui_check(AdminUiText::capability('friend_links.manage') === '管理友情链接', 'Friend Links capabilities are shown with Chinese labels');

echo "Admin UI text tests passed.\n";

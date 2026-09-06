<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use Cms\Core\Plugin\PluginMenuItem;

final class View
{
    /** @var list<PluginMenuItem> */
    private static array $adminPluginMenus = [];
    /** @var list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    private static array $frontNavigation = [];

    /** @param list<PluginMenuItem> $menus */
    public static function setAdminPluginMenus(array $menus): void
    {
        self::$adminPluginMenus = array_values(array_filter($menus, static fn (mixed $menu): bool => $menu instanceof PluginMenuItem));
    }

    /** @param list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> $items */
    public static function setFrontNavigation(array $items): void
    {
        self::$frontNavigation = $items;
    }

    public static function page(string $title, string $body): string
    {
        $isAdmin = self::isAdminRequest();
        $layoutClass = $isAdmin ? ' class="admin-shell"' : '';
        $adminNav = $isAdmin ? self::adminSidebar() : self::frontHeader();
        $adminTopbar = $isAdmin ? self::adminTopbar($title) : '';
        $mainClass = $isAdmin ? ' class="admin-main" id="admin-main" tabindex="-1"' : '';
        $adminOpen = $isAdmin ? '<a class="admin-skip-link" href="#admin-main">跳到主要内容</a>' . $adminNav . '<div class="admin-workspace">' . $adminTopbar : $adminNav;
        $adminClose = $isAdmin ? '</div>' : '';
        $headAssets = $isAdmin
            ? '<link rel="stylesheet" href="/assets/admin/admin.css?v=' . self::assetVersion() . '">'
            : '<style>' . self::baseCss() . '</style>';

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . self::escape($title) . '</title>' .
            $headAssets .
            '</head><body' . $layoutClass . '>' . $adminOpen . '<main' . $mainClass . '><section class="panel">' . $body . '</section></main>' . $adminClose . self::adminScriptTag($isAdmin) . '</body></html>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function isAdminRequest(): bool
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        return str_starts_with($path, '/admin') && $path !== '/admin/login';
    }

    private static function adminSidebar(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/admin';
        $sections = [
            '工作台' => [
                ['/admin', '总览', 'home'],
                ['/admin/security', 'shield'],
            ],
            '内容' => [
                ['/admin/content', '内容管理', 'content'],
                ['/admin/content/new', '新建内容', 'plus'],
                ['/admin/media', '媒体库', 'media'],
                ['/admin/comments', '评论管理', 'content'],
                ['/admin/ads', '广告统计', 'chart'],
                ['/admin/navigation', '导航菜单', 'menu'],
            ],
            '外观' => [
                ['/admin/themes', '主题管理', 'palette'],
            ],
            '扩展' => [
                ['/admin/modules', '模块管理', 'grid'],
                ['/admin/plugins', '插件管理', 'plugin'],
            ],
            '商业' => [
                ['/admin/card-delivery', '发卡管理', 'card'],
                ['/admin/payments', '支付管理', 'wallet'],
                ['/admin/payments/providers', '支付方式设置', 'settings'],
            ],
            '平台' => [
                ['/admin/settings', '站点设置', 'sliders'],
                ['/admin/transfer', '导入导出', 'transfer'],
                ['/admin/migrations', '旧站迁移', 'transfer'],
                ['/admin/update', '系统更新', 'refresh'],
                ['/admin/recovery', '恢复诊断', 'activity'],
            ],
        ];

        if (str_starts_with($path, '/admin/market')) {
            $sections['应用市场'] = [
                ['/admin/market/plugins', '插件市场', 'store'],
                ['/admin/market/themes', '主题市场', 'palette'],
                ['/admin/market/developer-submit', '开发者提交', 'code'],
                ['/admin/market/submissions', '我的提交', 'content'],
                ['/admin/market/diagnostics', '市场诊断', 'activity'],
            ];
        }

        foreach (self::pluginMenuSections() as $section => $links) {
            $sections[$section] = $links;
        }

        $html = '<aside class="admin-sidebar" id="admin-sidebar"><a class="admin-brand" href="/admin"><span class="admin-brand-mark">D</span><span class="admin-brand-text">Daiying CMS</span></a><nav class="admin-nav" aria-label="后台导航">';
        foreach ($sections as $section => $links) {
            $html .= '<div class="admin-nav-section">' . self::escape($section) . '</div>';
            foreach ($links as $link) {
                [$href, $label] = $link;
                $icon = (string) ($link[2] ?? 'plugin');
                $active = self::isActiveAdminPath($path, $href);
                $class = $active ? ' class="active"' : '';
                $current = $active ? ' aria-current="page"' : '';
                $html .= '<a' . $class . $current . ' title="' . self::escape($label) . '" href="' . self::escape($href) . '"><span class="admin-nav-icon" aria-hidden="true">' . self::icon($icon) . '</span><span class="admin-nav-label">' . self::escape($label) . '</span></a>';
            }
        }
        $html .= '</nav><form class="admin-logout-form" method="post" action="/admin/logout">' . \Cms\Core\Security\CsrfToken::field() . '<button type="submit"><span class="admin-nav-icon" aria-hidden="true">' . self::icon('logout') . '</span><span class="admin-nav-label">退出登录</span></button></form></aside>';
        return $html;
    }

    private static function adminTopbar(string $title): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/admin';
        $crumb = self::adminBreadcrumb($path, $title);

        return '<header class="admin-topbar"><div class="admin-topbar-left">' .
            '<button class="admin-icon-button" type="button" data-admin-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="true" title="收起或展开侧栏">' . self::icon('menu') . '</button>' .
            '<nav class="admin-breadcrumb" aria-label="当前位置">' . $crumb . '</nav></div>' .
            '<div class="admin-topbar-actions"><a class="button admin-button-secondary" href="/" target="_blank" rel="noopener">查看站点</a><a class="button" href="/admin/content/new">快速新建</a><span class="admin-account">管理员</span></div></header>';
    }

    private static function frontHeader(): string
    {
        if (self::$frontNavigation === []) {
            return '';
        }
        $links = '';
        foreach (self::$frontNavigation as $item) {
            if (!($item['enabled'] ?? true)) {
                continue;
            }
            $links .= '<a href="' . self::escape((string) $item['url']) . '">' . self::escape((string) $item['label']) . '</a>';
        }
        if ($links === '') {
            return '';
        }

        return '<header style="background:#fff;border-bottom:1px solid #d8dee8"><nav aria-label="主导航" style="max-width:1120px;margin:0 auto;padding:14px 20px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">' . $links . '</nav></header>';
    }

    private static function isActiveAdminPath(string $path, string $href): bool
    {
        if ($href === '/admin') {
            return $path === '/admin';
        }
        return $path === $href || str_starts_with($path, rtrim($href, '/') . '/');
    }

    /** @return array<string, list<array{0:string,1:string}>> */
    private static function pluginMenuSections(): array
    {
        $sections = [];
        foreach (self::$adminPluginMenus as $menu) {
            $section = AdminUiText::pluginName($menu->pluginId, $menu->pluginId);
            $sections[$section] ??= [];
            $sections[$section][] = [$menu->path, $menu->label, 'plugin'];
        }

        return $sections;
    }

    private static function adminBreadcrumb(string $path, string $title): string
    {
        $top = '工作台';
        if (str_starts_with($path, '/admin/content') || str_starts_with($path, '/admin/media') || str_starts_with($path, '/admin/comments') || str_starts_with($path, '/admin/navigation')) {
            $top = '内容';
        } elseif (str_starts_with($path, '/admin/themes')) {
            $top = '外观';
        } elseif (str_starts_with($path, '/admin/plugins') || str_starts_with($path, '/admin/modules')) {
            $top = '扩展';
        } elseif (str_starts_with($path, '/admin/payments') || str_starts_with($path, '/admin/card-delivery')) {
            $top = '商业';
        } elseif (str_starts_with($path, '/admin/market')) {
            $top = '应用市场';
        } elseif (str_starts_with($path, '/admin/settings') || str_starts_with($path, '/admin/transfer') || str_starts_with($path, '/admin/migrations') || str_starts_with($path, '/admin/update') || str_starts_with($path, '/admin/recovery')) {
            $top = '平台';
        }

        return '<a href="/admin">后台</a><span aria-hidden="true">/</span><span>' . self::escape($top) . '</span><span aria-hidden="true">/</span><strong>' . self::escape($title) . '</strong>';
    }

    private static function baseCss(): string
    {
        return 'body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#f6f7f9;color:#1f2937}' .
            'main{max-width:760px;margin:48px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:24px}' .
            'label{display:block;font-weight:650;margin-top:14px}input,select,textarea{width:100%;box-sizing:border-box;margin-top:6px;padding:10px;border:1px solid #b8c0cc;border-radius:6px}' .
            'td form{margin:0}td input[type=hidden]{display:none;width:auto}' .
            'button,a.button{display:inline-block;margin-top:18px;background:#1f6feb;color:white;border:0;border-radius:6px;padding:10px 14px;text-decoration:none;cursor:pointer}' .
            'table{width:100%;border-collapse:collapse;margin-top:16px}th,td{text-align:left;border-bottom:1px solid #d8dee8;padding:10px}' .
            '.error{background:#fff1f0;border:1px solid #ffccc7;color:#8c1d18;padding:10px;border-radius:6px}.muted{color:#667085}';
    }

    private static function adminScriptTag(bool $isAdmin): string
    {
        return $isAdmin ? '<script src="/assets/admin/admin.js?v=' . self::assetVersion() . '" defer></script>' : '';
    }

    private static function assetVersion(): string
    {
        return '1.2.3-content-editor-ui-20260827';
    }

    private static function icon(string $name): string
    {
        $paths = [
            'activity' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
            'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h5"/>',
            'chart' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v-4"/><path d="M13 15V8"/><path d="M18 15v-7"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'code' => '<path d="m8 9-4 3 4 3"/><path d="m16 9 4 3-4 3"/><path d="m14 5-4 14"/>',
            'content' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
            'grid' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
            'home' => '<path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>',
            'logout' => '<path d="M10 5H5v14h5"/><path d="M14 8l4 4-4 4"/><path d="M18 12H9"/>',
            'media' => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m6 17 4-4 3 3 2-2 3 3"/>',
            'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
            'palette' => '<path d="M12 4a8 8 0 0 0 0 16h1.5a2 2 0 0 0 1.4-3.4l-.3-.3a1.5 1.5 0 0 1 1.1-2.6H17a3 3 0 0 0 3-3A6.7 6.7 0 0 0 12 4Z"/><circle cx="8.5" cy="11" r=".8"/><circle cx="10.5" cy="8" r=".8"/><circle cx="14" cy="8.5" r=".8"/>',
            'plugin' => '<path d="M9 4v4"/><path d="M15 4v4"/><path d="M8 8h8v5a4 4 0 0 1-8 0Z"/><path d="M12 17v3"/>',
            'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'refresh' => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18 9a6 6 0 0 0-10-3L4 10"/><path d="M6 15a6 6 0 0 0 10 3l4-4"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a7 7 0 0 0-1.7-1L14.5 3h-5l-.4 3.1a7 7 0 0 0-1.7 1L5 6.1l-2 3.4L5 11a7 7 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 1.7 1l.4 3.1h5l.4-3.1a7 7 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5c.1-.3.1-.7.1-1Z"/>',
            'shield' => '<path d="M12 3 5 6v5c0 5 3.5 8 7 10 3.5-2 7-5 7-10V6Z"/><path d="m9 12 2 2 4-4"/>',
            'sliders' => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h2"/><path d="M10 17h10"/><circle cx="8" cy="17" r="2"/>',
            'spark' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8Z"/><path d="M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8Z"/>',
            'store' => '<path d="M4 10h16l-1-5H5Z"/><path d="M6 10v10h12V10"/><path d="M9 20v-5h6v5"/>',
            'transfer' => '<path d="M7 7h13"/><path d="m16 4 4 3-4 3"/><path d="M17 17H4"/><path d="m8 14-4 3 4 3"/>',
            'wallet' => '<path d="M4 7h14a2 2 0 0 1 2 2v9H6a2 2 0 0 1-2-2Z"/><path d="M4 7a2 2 0 0 1 2-2h11v4"/><path d="M16 13h4"/>',
        ];
        $path = $paths[$name] ?? $paths['plugin'];

        return '<svg class="admin-svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }
}

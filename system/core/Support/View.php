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
        $mainClass = $isAdmin ? ' class="admin-main"' : '';

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . self::escape($title) . '</title>' .
            '<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7f9;color:#1f2937}' .
            'main{max-width:760px;margin:48px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:24px}' .
            'label{display:block;font-weight:650;margin-top:14px}input,select,textarea{width:100%;box-sizing:border-box;margin-top:6px;padding:10px;border:1px solid #b8c0cc;border-radius:6px}' .
            'td form{margin:0}td input[type=hidden]{display:none;width:auto}' .
            'button,a.button{display:inline-block;margin-top:18px;background:#1f6feb;color:white;border:0;border-radius:6px;padding:10px 14px;text-decoration:none;cursor:pointer}' .
            'table{width:100%;border-collapse:collapse;margin-top:16px}th,td{text-align:left;border-bottom:1px solid #d8dee8;padding:10px}' .
            '.error{background:#fff1f0;border:1px solid #ffccc7;color:#8c1d18;padding:10px;border-radius:6px}.muted{color:#667085}' .
            '.editor-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.editor-header h1{margin:0}.editor-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.editor-actions button,.editor-actions a.button{margin-top:0}.editor-shell{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:22px;align-items:start}.editor-card{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:18px;margin:0 0 16px}.editor-card h2{margin:0 0 12px}.editor-card legend{font-weight:800;padding:0 8px}.block-card{border:1px solid #d8dee8;border-radius:8px;padding:16px;margin:14px 0;background:#fbfcfe}.block-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.block-card-title{font-weight:800}.block-card-actions{display:flex;gap:8px;flex-wrap:wrap}.block-card-actions button{margin-top:0;background:#475569;padding:7px 10px}.editor-side-panel{position:sticky;top:24px}.editor-side-panel .editor-card{margin-bottom:16px}.editor-primary{background:#1f6feb}.editor-secondary{background:#475569}.editor-danger,.admin-danger{background:#b42318}' .
            '.admin-shell{display:flex;min-height:100vh}.admin-sidebar{position:sticky;top:0;width:232px;min-height:100vh;background:#111827;color:#e5e7eb;padding:16px 12px;box-sizing:border-box;flex:0 0 232px;overflow:auto}.admin-brand{display:block;color:#fff;text-decoration:none;font-size:20px;font-weight:800;margin:0 0 16px}.admin-nav-section{margin:14px 0 6px;color:#9ca3af;font-size:11px;font-weight:750;letter-spacing:.04em}.admin-nav a{display:block;color:#d1d5db;text-decoration:none;padding:7px 10px;border-radius:6px;margin:1px 0;font-size:14px;line-height:1.25;font-weight:650}.admin-nav a:hover,.admin-nav a.active{background:#1f6feb;color:#fff}.admin-main{max-width:none;width:100%;margin:0;padding:22px 28px;font-size:14px;line-height:1.45}.admin-main .panel{max-width:1180px;margin:0 auto;padding:18px}.admin-logout-form{margin-top:14px}.admin-logout-form button{width:100%;background:#374151;margin-top:6px}' .
            '.admin-main h1{font-size:26px;line-height:1.2;margin:0 0 12px}.admin-main h2{font-size:19px;line-height:1.25;margin:18px 0 10px}.admin-main h3{font-size:16px;margin:14px 0 8px}.admin-main p{margin:8px 0}.admin-main label{font-size:13px;margin-top:10px}.admin-main input,.admin-main select,.admin-main textarea{font-size:14px;margin-top:4px;padding:7px 9px;border-radius:5px;line-height:1.35}.admin-main input,.admin-main select{min-height:34px}.admin-main textarea{min-height:82px}.admin-main fieldset{border:1px solid #d8dee8;border-radius:6px;margin:10px 0 8px;padding:10px 12px}.admin-main legend{font-size:13px;font-weight:750;padding:0 6px}.admin-main button,.admin-main a.button{font-size:13px;line-height:1.2;margin-top:10px;padding:7px 10px;border-radius:5px}.admin-main td form button{margin-top:0}.admin-main table{font-size:13px;margin-top:10px;display:block;overflow-x:auto}.admin-main th,.admin-main td{padding:7px 8px;vertical-align:top}.admin-main th{font-size:12px;font-weight:750;color:#475467;background:#f8fafc}.admin-main td:first-child,.admin-nowrap{white-space:nowrap}.admin-main pre{font-size:12px;line-height:1.35;margin:0;white-space:pre-wrap}.admin-main details{margin-top:8px}.admin-main details p{margin:6px 0}.admin-main summary{cursor:pointer;font-size:13px;font-weight:700}.admin-badge,.admin-tag{display:inline-flex;align-items:center;min-height:20px;padding:2px 7px;border-radius:999px;font-size:12px;font-weight:750;line-height:1;border:1px solid transparent;white-space:nowrap}.admin-tag{margin:0 4px 4px 0;background:#eef2f6;color:#344054;border-color:#d8dee8}.admin-badge-success{background:#ecfdf3;color:#027a48;border-color:#abefc6}.admin-badge-warning{background:#fffaeb;color:#b54708;border-color:#fedf89}.admin-badge-danger{background:#fef3f2;color:#b42318;border-color:#fecdca}.admin-badge-muted{background:#f2f4f7;color:#475467;border-color:#e4e7ec}' .
            '.admin-main .editor-header{margin-bottom:12px}.admin-main .editor-actions{gap:8px}.admin-main .editor-card{padding:14px;margin-bottom:12px}.admin-main .editor-card h2{margin:0 0 8px}.admin-main .block-card{padding:12px;margin:10px 0}.admin-main .block-card-header{margin-bottom:8px}.admin-main .block-card-actions button{padding:6px 9px}.admin-main .editor-shell{gap:16px;grid-template-columns:minmax(0,1fr) 300px}.admin-main .muted{font-size:13px}' .
            '@media(max-width:980px){.editor-shell{grid-template-columns:1fr}.editor-side-panel{position:static}.editor-header{display:block}.editor-actions{justify-content:flex-start;margin-top:14px}}' .
            '@media(max-width:860px){.admin-shell{display:block}.admin-sidebar{position:relative;width:auto;min-height:0;display:block}.admin-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px}.admin-main{padding:18px 12px}main{margin:24px auto}.admin-main .panel{max-width:none}}</style>' .
            '</head><body' . $layoutClass . '>' . $adminNav . '<main' . $mainClass . '><section class="panel">' . $body . '</section></main></body></html>';
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
            '内容' => [
                ['/admin', '后台首页'],
                ['/admin/content/new', '新建内容'],
                ['/admin/content', '内容管理'],
                ['/admin/media', '媒体库'],
            ],
            '站点' => [
                ['/admin/settings', '站点设置'],
                ['/admin/navigation', '导航菜单'],
                ['/admin/themes', '主题管理'],
                ['/admin/modules', '模块管理'],
                ['/admin/plugins', '插件管理'],
                ['/admin/card-delivery', '发卡管理'],
                ['/admin/payments', '支付管理'],
                ['/admin/transfer', '导入导出'],
                ['/admin/update', 'Core 更新'],
                ['/admin/recovery', '恢复诊断'],
            ],
        ];

        foreach (self::pluginMenuSections() as $section => $links) {
            $sections[$section] = $links;
        }

        $html = '<aside class="admin-sidebar"><a class="admin-brand" href="/admin">管理后台</a><nav class="admin-nav" aria-label="后台导航">';
        foreach ($sections as $section => $links) {
            $html .= '<div class="admin-nav-section">' . self::escape($section) . '</div>';
            foreach ($links as [$href, $label]) {
                $active = self::isActiveAdminPath($path, $href) ? ' class="active"' : '';
                $html .= '<a' . $active . ' href="' . self::escape($href) . '">' . self::escape($label) . '</a>';
            }
        }
        $html .= '</nav><form class="admin-logout-form" method="post" action="/admin/logout">' . \Cms\Core\Security\CsrfToken::field() . '<button type="submit">退出登录</button></form></aside>';
        return $html;
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
            $sections[$section][] = [$menu->path, $menu->label];
        }

        return $sections;
    }
}

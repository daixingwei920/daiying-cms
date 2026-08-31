<?php

declare(strict_types=1);

namespace Official\FriendLinks;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class FriendLinksController
{
    public function __construct(
        private readonly FriendLinkRepository $links,
        private readonly AuditLogger $audit,
    )
    {
    }

    public function adminIndex(Request $request): Response
    {
        $message = trim((string) ($request->query['saved'] ?? '')) === '1' ? '<p class="notice">友情链接已保存。</p>' : '';
        $query = trim((string) ($request->query['q'] ?? ''));
        $requestedStatus = (string) ($request->query['status'] ?? 'all');
        $status = in_array($requestedStatus, ['all', 'enabled', 'disabled'], true) ? $requestedStatus : 'all';
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = 20;
        $total = $this->links->count(false, $query, $status);
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $rows = '';
        foreach ($this->links->list(false, $query, $status, $perPage, $offset) as $link) {
            $id = (int) ($link['id'] ?? 0);
            $url = (string) ($link['url'] ?? '');
            $isEnabled = (string) ($link['status'] ?? '') === 'enabled';
            $rows .= '<tr><td>' . $this->input('links[' . $id . '][name]', (string) ($link['name'] ?? '')) . '</td>' .
                '<td>' . $this->input('links[' . $id . '][url]', $url, 'url') . '<br><a href="' . View::escape($url) . '" target="_blank" rel="noopener noreferrer">打开链接</a></td>' .
                '<td>' . $this->input('links[' . $id . '][description]', (string) ($link['description'] ?? '')) . '</td>' .
                '<td>' . $this->input('links[' . $id . '][sort_order]', (string) ($link['sort_order'] ?? '0'), 'number') . '</td>' .
                '<td><strong>' . ($isEnabled ? '前台显示' : '前台隐藏') . '</strong><br><select name="links[' . $id . '][status]"><option value="enabled"' . ($isEnabled ? ' selected' : '') . '>显示</option><option value="disabled"' . (!$isEnabled ? ' selected' : '') . '>隐藏</option></select></td>' .
                '<td>' . $this->relSelect('links[' . $id . '][rel]', (string) ($link['rel'] ?? 'noopener noreferrer')) . '</td>' .
                '<td><label><input type="checkbox" name="delete[]" value="' . $id . '"> 删除</label><input type="hidden" name="links[' . $id . '][id]" value="' . $id . '"></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">没有找到符合条件的友情链接。</td></tr>';
        }

        $deleteConfirm = 'return !this.querySelector(\'input[name="delete[]"]:checked\') || confirm(\'确认删除选中的友情链接吗？此操作会立即从友链列表移除。\');';
        $body = '<h1>友情链接</h1>' .
            '<p>管理前台友情链接。网址只允许 HTTP/HTTPS，前台外链会自动使用安全打开方式。</p>' .
            $message .
            '<p><a class="button" href="/links" target="_blank" rel="noopener noreferrer">查看前台友情链接</a></p>' .
            '<form method="get" action="/admin/friend-links"><p><label>搜索 <input name="q" value="' . View::escape($query) . '" placeholder="名称、网址或简介"></label> ' .
            '<label>状态 <select name="status"><option value="all"' . ($status === 'all' ? ' selected' : '') . '>全部</option><option value="enabled"' . ($status === 'enabled' ? ' selected' : '') . '>显示</option><option value="disabled"' . ($status === 'disabled' ? ' selected' : '') . '>隐藏</option></select></label> ' .
            '<button type="submit">筛选</button> <a class="button" href="/admin/friend-links">重置</a></p></form>' .
            '<form method="post" action="/admin/friend-links" onsubmit="' . View::escape($deleteConfirm) . '">' . CsrfToken::field() .
            '<table><thead><tr><th>名称</th><th>网址</th><th>简介</th><th>排序</th><th>状态</th><th>关系</th><th>操作</th></tr></thead><tbody>' .
            $rows .
            '<tr><td>' . $this->input('new[name]', '') . '</td><td>' . $this->input('new[url]', '', 'url') . '</td><td>' . $this->input('new[description]', '') . '</td><td>' . $this->input('new[sort_order]', '0', 'number') . '</td><td><select name="new[status]"><option value="enabled">显示</option><option value="disabled">隐藏</option></select></td><td>' . $this->relSelect('new[rel]', 'noopener noreferrer') . '</td><td>新增</td></tr>' .
            '</tbody></table><p>第 ' . $page . ' / ' . $pageCount . ' 页，共 ' . $total . ' 条 ' . $this->pageLink($page - 1, $page, $pageCount, $query, $status, '上一页') . ' ' . $this->pageLink($page + 1, $page, $pageCount, $query, $status, '下一页') . '</p><p><button type="submit">保存友情链接</button></p></form>';

        return Response::html(View::page('友情链接', $body));
    }

    public function adminSave(Request $request): Response
    {
        $context = $request->server['plugin_admin_context'] ?? null;
        if (!is_object($context) || !method_exists($context, 'hasCapability') || !$context->hasCapability('friend_links.manage')) {
            return Response::text('Forbidden', 403);
        }
        $actorId = (int) ($context->authenticatedAdminId ?? 0);

        try {
            $deleted = 0;
            $saved = 0;
            $created = 0;
            $deleteIds = is_array($request->body['delete'] ?? null) ? $request->body['delete'] : [];
            foreach ($deleteIds as $id) {
                $this->links->delete((int) $id);
                $deleted++;
            }

            $links = is_array($request->body['links'] ?? null) ? $request->body['links'] : [];
            foreach ($links as $link) {
                if (is_array($link) && !in_array((string) ($link['id'] ?? ''), array_map('strval', $deleteIds), true)) {
                    $this->links->save($link);
                    $saved++;
                }
            }

            $new = is_array($request->body['new'] ?? null) ? $request->body['new'] : [];
            if (trim((string) ($new['name'] ?? '')) !== '' || trim((string) ($new['url'] ?? '')) !== '') {
                $this->links->save($new);
                $created++;
            }
            $this->audit->record('admin', $actorId, 'friend_links.updated', [
                'created' => $created,
                'updated' => $saved,
                'deleted' => $deleted,
            ]);
        } catch (Throwable $exception) {
            return Response::html(View::page('友情链接', '<h1>友情链接</h1><p>保存失败：' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/friend-links">返回修改</a></p>'), 400);
        }

        return Response::redirect('/admin/friend-links?saved=1');
    }

    public function publicIndex(): Response
    {
        $items = '';
        foreach ($this->links->all(true) as $link) {
            $description = trim((string) ($link['description'] ?? ''));
            $rel = $this->safeRel((string) ($link['rel'] ?? 'noopener noreferrer'));
            $items .= '<li><a href="' . View::escape((string) ($link['url'] ?? '#')) . '" target="_blank" rel="' . View::escape($rel) . '">' . View::escape((string) ($link['name'] ?? '未命名链接')) . '</a>' .
                ($description !== '' ? '<p>' . View::escape($description) . '</p>' : '') . '</li>';
        }
        if ($items === '') {
            $items = '<li class="empty">暂时还没有友情链接。</li>';
        }

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>友情链接</title><meta name="description" content="友情链接，收录站点合作伙伴和推荐网站。">' .
            '<style>body{margin:0;background:#fbfbfc;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.8}.wrap{width:min(860px,100% - 32px);margin:0 auto;padding:42px 0}.card{background:#fff;border:1px solid #d9e0ea;border-radius:8px;padding:24px}a{color:#1f6feb;text-decoration:none}a:hover{text-decoration:underline}li{margin:12px 0}p{color:#667085;margin:.25rem 0 0}.top{border-bottom:1px solid #d9e0ea;background:#fff}.top a{display:inline-block;padding:14px 0;font-weight:700}</style></head>' .
            '<body><header class="top"><div class="wrap"><a href="/">返回首页</a></div></header><main class="wrap"><section class="card"><h1>友情链接</h1><ul>' . $items . '</ul></section></main></body></html>';

        return Response::html($html);
    }

    private function input(string $name, string $value, string $type = 'text'): string
    {
        return '<input type="' . View::escape($type) . '" name="' . View::escape($name) . '" value="' . View::escape($value) . '">';
    }

    private function pageLink(int $target, int $page, int $pageCount, string $query, string $status, string $label): string
    {
        if ($target < 1 || $target > $pageCount || $target === $page) {
            return '<span>' . View::escape($label) . '</span>';
        }
        $params = array_filter([
            'q' => $query,
            'status' => $status === 'all' ? '' : $status,
            'page' => (string) $target,
        ], static fn (string $value): bool => $value !== '');

        return '<a class="button" href="/admin/friend-links?' . View::escape(http_build_query($params)) . '">' . View::escape($label) . '</a>';
    }

    private function relSelect(string $name, string $selected): string
    {
        $items = [
            'noopener noreferrer' => '普通链接',
            'noopener noreferrer nofollow' => '不推荐跟随',
            'noopener noreferrer sponsored' => '赞助链接',
        ];
        $html = '<select name="' . View::escape($name) . '">';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . View::escape($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . View::escape($label) . '</option>';
        }
        return $html . '</select>';
    }

    private function safeRel(string $rel): string
    {
        return match ($rel) {
            'noopener noreferrer nofollow' => 'noopener noreferrer nofollow',
            'noopener noreferrer sponsored' => 'noopener noreferrer sponsored',
            default => 'noopener noreferrer',
        };
    }
}

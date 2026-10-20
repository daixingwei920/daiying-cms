<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\MemberDraftService;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Routing\BasePath;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use PDO;

final class MemberAccessController
{
    public function __construct(private readonly Settings $settings, private readonly string $rootPath)
    {
    }

    public function assignments(): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $admin = $this->owner($pdo);
        if ($admin instanceof Response) {
            return $admin;
        }
        $rows = '';
        foreach ((new MemberRoleService($pdo))->members() as $member) {
            $enabled = (string) ($member['role_id'] ?? '') === 'ai_operator';
            $rows .= '<tr><td>' . (int) $member['id'] . '</td><td>' . View::escape((string) $member['display_name']) . '</td><td>' . View::escape((string) $member['email']) . '</td><td>' . View::escape((string) $member['status']) . '</td><td>' . ($enabled ? 'AI 运营' : '无') . '</td><td>' .
                '<form method="post" action="' . $this->url('/admin/member-access') . '" onsubmit="return confirm(\'确认' . ($enabled ? '撤销该会员的 AI 运营角色' : '授予该会员自有草稿编辑与 SEO 只读权限') . '？\');">' . CsrfToken::field() . '<input type="hidden" name="member_id" value="' . (int) $member['id'] . '"><input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '"><button type="submit">' . ($enabled ? '撤销角色' : '分配 AI 运营角色') . '</button></form></td></tr>';
        }
        $body = '<h1>会员协作权限</h1><p>会员通过 CMS 基础注册功能建立身份。AI 运营角色仅允许编辑本人创建且仍为草稿的文章，并只读查看 SEO 关键词。不能发布、修改他人内容或访问管理员功能。</p>' .
            '<p>分配或撤销角色前，Owner 须在<a href="' . $this->url('/admin/security') . '">后台安全</a>完成重新验证。每次提交只改变所选会员的 AI 运营角色。</p>' .
            '<table><thead><tr><th>ID</th><th>姓名</th><th>邮箱</th><th>状态</th><th>协作角色</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('会员协作权限', $body));
    }

    public function assign(Request $request): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $admin = $this->owner($pdo);
        if ($admin instanceof Response) {
            return $admin;
        }
        if (!CsrfToken::verify($request->input('_csrf')) || !(new AdminSessionService($pdo))->hasRecentReauthentication(600)) {
            return Response::text('需要有效请求校验与最近一次管理员重新验证。', 403);
        }
        $memberId = filter_var($request->input('member_id'), FILTER_VALIDATE_INT);
        $enabled = $request->input('enabled');
        if ($memberId === false || $memberId <= 0 || !in_array($enabled, ['0', '1'], true)) {
            return Response::text('会员或角色无效。', 422);
        }
        try {
            (new MemberRoleService($pdo))->assignOperator($memberId, $admin['id'], $enabled === '1');
            (new AuditLogger($pdo))->record('admin', $admin['id'], 'member.role_assigned', ['member_id' => $memberId, 'role' => $enabled === '1' ? 'ai_operator' : 'none']);
        } catch (\InvalidArgumentException) {
            return Response::text('仅可为有效会员分配角色。', 422);
        }

        return Response::redirect($this->url('/admin/member-access'));
    }

    public function workspace(): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $member = $this->member($pdo, 'content.own_draft');
        if ($member instanceof Response) {
            return $member;
        }
        $drafts = (new MemberDraftService($pdo, $this->rootPath))->drafts($member['id']);
        $rows = '';
        foreach ($drafts as $draft) {
            $rows .= '<tr><td>' . View::escape((string) $draft['title']) . '</td><td>' . View::escape((string) $draft['updated_at']) . '</td><td><a href="' . $this->url('/workspace/drafts/' . (int) $draft['id']) . '">编辑</a></td></tr>';
        }
        $body = '<h1>我的草稿</h1><p><a href="' . $this->url('/workspace/drafts/new') . '">新建草稿</a> · <a href="' . $this->url('/workspace/seo') . '">SEO 只读</a></p>' .
            '<table><thead><tr><th>标题</th><th>更新时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('我的草稿', $body));
    }

    public function newDraft(): Response
    {
        $member = $this->member(ConnectionFactory::make($this->settings), 'content.own_draft');
        if ($member instanceof Response) {
            return $member;
        }

        return Response::html(View::page('新建草稿', $this->draftForm(null)));
    }

    public function editDraft(Request $request): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $member = $this->member($pdo, 'content.own_draft');
        if ($member instanceof Response) {
            return $member;
        }
        $draft = (new MemberDraftService($pdo, $this->rootPath))->draft($member['id'], (int) basename($request->path));

        return $draft === null ? Response::text('草稿不存在。', 404) : Response::html(View::page('编辑草稿', $this->draftForm($draft)));
    }

    public function saveDraft(Request $request): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $member = $this->member($pdo, 'content.own_draft');
        if ($member instanceof Response) {
            return $member;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('请求校验失败。', 403);
        }
        $drafts = new MemberDraftService($pdo, $this->rootPath);
        try {
            if ($request->path !== '/workspace/drafts') {
                $segment = basename($request->path);
                if (!ctype_digit($segment) || (int) $segment <= 0) {
                    return Response::text('草稿路径无效。', 404);
                }
                $id = (int) $segment;
                if (!$drafts->update($member['id'], $id, (string) $request->input('title', ''), (string) $request->input('body', ''))) {
                    return Response::text('草稿不存在或已不再可编辑。', 404);
                }
            } else {
                $id = $drafts->create($member['id'], (string) $request->input('title', ''), (string) $request->input('body', ''));
            }
        } catch (\InvalidArgumentException $exception) {
            return Response::text($exception->getMessage(), 422);
        }

        return Response::redirect($this->url('/workspace/drafts/' . $id));
    }

    public function seo(): Response
    {
        $pdo = ConnectionFactory::make($this->settings);
        $member = $this->member($pdo, 'seo.read');
        if ($member instanceof Response) {
            return $member;
        }
        $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults(), [], $this->rootPath);
        $rows = '';
        foreach (array_slice($repo->seoKeywordCenterRows([]), 0, 100) as $keyword) {
            $rows .= '<tr><td>' . View::escape((string) ($keyword['keyword'] ?? '')) . '</td><td>' . View::escape((string) ($keyword['primary_url'] ?? '')) . '</td><td>' . View::escape((string) ($keyword['status'] ?? '')) . '</td></tr>';
        }

        return Response::html(View::page('SEO 只读', '<h1>SEO 关键词（只读）</h1><p><a href="' . $this->url('/workspace') . '">返回我的草稿</a></p><table><thead><tr><th>关键词</th><th>主要页面</th><th>状态</th></tr></thead><tbody>' . $rows . '</tbody></table>'));
    }

    private function owner(PDO $pdo): array|Response
    {
        $admin = (new AdminAuthenticator($pdo))->user();
        if ($admin === null) {
            return Response::redirect($this->url('/admin/login'));
        }
        $firstId = (int) $pdo->query('SELECT id FROM cms_admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($firstId <= 0 || $admin['id'] !== $firstId || !(new AdminSessionService($pdo))->touchCurrent($admin['id'])) {
            return Response::text('仅 Owner 可管理协作权限。', 403);
        }

        return $admin;
    }

    private function member(PDO $pdo, string $capability): array|Response
    {
        $member = (new FrontUserAuthenticator($pdo))->user();
        if ($member === null) {
            return Response::redirect($this->url('/login?redirect=' . rawurlencode('/workspace')));
        }
        if (!(new MemberRoleService($pdo))->has($member['id'], $capability)) {
            return Response::text('当前会员没有此权限。', 403);
        }

        return $member;
    }

    private function draftForm(?array $draft): string
    {
        $id = (int) ($draft['id'] ?? 0);
        $action = $id > 0 ? '/workspace/drafts/' . $id : '/workspace/drafts';

        return '<h1>' . ($id > 0 ? '编辑我的草稿' : '新建草稿') . '</h1><p>仅保存为草稿。发布由 Owner 在后台审核后执行。</p>' .
            '<form method="post" action="' . $this->url($action) . '">' . CsrfToken::field() .
            '<p><label>标题<br><input name="title" required maxlength="255" value="' . View::escape((string) ($draft['title'] ?? '')) . '"></label></p>' .
            '<p><label>正文<br><textarea name="body" rows="18" maxlength="50000">' . View::escape((string) ($draft['body'] ?? '')) . '</textarea></label></p>' .
            '<button type="submit">保存草稿</button> <a href="' . $this->url('/workspace') . '">返回</a></form>';
    }

    private function url(string $path): string
    {
        return View::escape(BasePath::prefixCurrent($path));
    }
}

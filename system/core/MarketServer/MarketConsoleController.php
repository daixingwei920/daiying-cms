<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Market\LocalObjectStorageAdapter;
use Cms\Core\Market\MarketException;
use Cms\Core\Market\ObjectStorageAdapterFactory;
use Cms\Core\Market\RemoteObjectStorageAdapter;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class MarketConsoleController
{
    private readonly string $rootPath;

    public function __construct(private readonly Settings $settings, ?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
    }

    public function developerCenter(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $projects = $this->repo()->projects();
        } catch (Throwable $exception) {
            return $this->errorPage('开发者中心', $exception);
        }

        $rows = '';
        foreach ($projects as $project) {
            $rows .= '<tr><td>' . (int) $project['id'] . '</td><td>' . View::escape((string) $project['market_id']) .
                '</td><td>' . View::escape((string) $project['name']) . '</td><td>' . View::escape((string) $project['extension_type']) .
                '</td><td>' . View::escape((string) $project['developer_name']) . '</td><td><a class="button" href="/admin/market-server/projects/' .
                (int) $project['id'] . '">版本</a></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无市场项目</td></tr>';

        $body = '<h1>开发者中心</h1><p class="muted">当前阶段用后台管理员代管开发者提交流。</p>' .
            '<form method="post" action="/admin/market-server/projects">' . CsrfToken::field() .
            '<label>开发者 Key<input name="developer_key" required></label>' .
            '<label>开发者名称<input name="developer_name" required></label>' .
            '<label>邮箱<input name="email" type="email" required></label>' .
            '<label>初始密码<input name="password" type="password"></label>' .
            '<label>角色<select name="role"><option value="Owner">Owner</option><option value="Maintainer">Maintainer</option><option value="Developer">Developer</option><option value="Viewer">Viewer</option></select></label>' .
            '<label>Market ID<input name="market_id" placeholder="official:demo_plugin" required></label>' .
            '<label>类型<select name="extension_type"><option value="plugin">Plugin</option><option value="theme">Theme</option></select></label>' .
            '<label>项目名称<input name="name" required></label><button type="submit">创建项目</button></form>' .
            '<h2>项目</h2><table><thead><tr><th>ID</th><th>Market ID</th><th>名称</th><th>类型</th><th>开发者</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('开发者中心', $body));
    }

    public function developerLoginForm(): Response
    {
        $body = '<h1>开发者登录</h1><form method="post" action="/developer/market/login">' . CsrfToken::field() .
            '<label>Developer Key<input name="developer_key" required></label>' .
            '<label>邮箱<input name="email" type="email" required></label>' .
            '<label>密码<input name="password" type="password"></label><button type="submit">登录</button></form>';

        return Response::html(View::page('开发者登录', $body));
    }

    public function developerLogin(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $developer = $this->repo()->developerByCredentials((string) $request->input('developer_key'), (string) $request->input('email'), (string) $request->input('password', ''));
            $_SESSION['market_developer_key'] = $developer->developerKey;

            return Response::redirect('/admin/market-server/developer');
        } catch (Throwable $exception) {
            return $this->errorPage('开发者登录', $exception, 403);
        }
    }

    public function developerLogout(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        unset($_SESSION['market_developer_key']);

        return Response::redirect('/developer/market/login');
    }

    public function createProject(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $repo = $this->repo();
            $developer = $repo->registerDeveloper((string) $request->input('developer_key'), (string) $request->input('developer_name'), (string) $request->input('email'), (string) $request->input('password', ''), (string) $request->input('role', 'Developer'));
            $repo->createProject($developer->id, (string) $request->input('market_id'), (string) $request->input('extension_type'), (string) $request->input('name'));
        } catch (Throwable $exception) {
            return $this->errorPage('开发者中心', $exception, 400);
        }

        return Response::redirect('/admin/market-server/developer');
    }

    public function projectVersions(Request $request): Response
    {
        try {
            $projectId = $this->pathId($request->path);
            $repo = $this->repo();
            $project = $repo->project($projectId);
            $guard = $this->requireAdminOrDeveloper($projectId);
            if ($guard instanceof Response) {
                return $guard;
            }
            $versions = $repo->versionsForProject($projectId);
            $uploads = $repo->uploadsForProject($projectId);
            $members = $repo->projectMembers($projectId);
            $reviews = $repo->reviewsForProject($projectId);
            $billing = $repo->billingSummary($project->marketId);
            $settlements = $repo->settlementsForDeveloper($project->developerId);
        } catch (Throwable $exception) {
            return $this->errorPage('项目版本', $exception, 404);
        }

        $rows = '';
        foreach ($versions as $version) {
            $rows .= '<tr><td>' . (int) $version['id'] . '</td><td>' . View::escape((string) $version['version']) .
                '</td><td>' . View::escape((string) $version['status']) . '</td><td>' . View::escape((string) $version['package_sha256']) .
                '</td><td>' . $this->versionActions((int) $version['id'], (string) $version['status']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无版本</td></tr>';

        $uploadRows = '';
        foreach ($uploads as $upload) {
            $uploadRows .= '<tr><td>' . (int) $upload['id'] . '</td><td>' . View::escape((string) $upload['object_key']) .
                '</td><td>' . View::escape((string) $upload['status']) . '</td><td>' . View::escape((string) $upload['sha256_hash']) .
                '</td><td>' . View::escape((string) ($upload['confirmed_at'] ?? '')) . '</td></tr>';
        }
        $uploadRows = $uploadRows !== '' ? $uploadRows : '<tr><td colspan="5" class="muted">暂无上传确认记录</td></tr>';

        $reviewRows = '';
        foreach ($reviews as $review) {
            $reviewRows .= '<tr><td>' . (int) $review['id'] . '</td><td>' . View::escape((string) $review['version']) .
                '</td><td>' . View::escape((string) $review['decision']) . '</td><td>' . View::escape((string) $review['notes']) .
                '</td><td>' . View::escape((string) $review['created_at']) . '</td></tr>';
        }
        $reviewRows = $reviewRows !== '' ? $reviewRows : '<tr><td colspan="5" class="muted">暂无审核历史</td></tr>';

        $settlementRows = '';
        foreach ($settlements as $settlement) {
            $settlementRows .= '<tr><td>' . (int) $settlement['id'] . '</td><td>' . number_format(((int) $settlement['amount_cents']) / 100, 2) . ' ' . View::escape((string) $settlement['currency']) .
                '</td><td>' . View::escape((string) $settlement['period_start']) . ' - ' . View::escape((string) $settlement['period_end']) .
                '</td><td>' . View::escape((string) $settlement['status']) . '</td></tr>';
        }
        $settlementRows = $settlementRows !== '' ? $settlementRows : '<tr><td colspan="4" class="muted">暂无结算批次</td></tr>';

        $memberRows = '';
        foreach ($members as $member) {
            $memberRows .= '<tr><td>' . View::escape((string) $member['developer_key']) . '</td><td>' . View::escape((string) $member['display_name']) .
                '</td><td>' . $this->memberRoleForm((int) $project->id, (int) $member['id'], (string) $member['role']) . '</td><td>' .
                View::escape((string) $member['status']) . '</td><td>' . $this->memberRemoveForm((int) $project->id, (int) $member['id'], (string) $member['role']) . '</td></tr>';
        }
        $memberRows = $memberRows !== '' ? $memberRows : '<tr><td colspan="5" class="muted">暂无成员</td></tr>';

        $body = '<h1>' . View::escape($project->name) . '</h1><p class="muted">' . View::escape($project->marketId) . '</p>' .
            '<form method="post" action="/admin/market-server/projects/member">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $project->id . '">' .
            '<label>成员 Developer Key<input name="developer_key" required></label>' .
            '<label>项目角色<select name="role"><option value="Maintainer">Maintainer</option><option value="Developer">Developer</option><option value="Viewer">Viewer</option></select></label><button type="submit">添加成员</button></form>' .
            '<form method="post" action="/admin/market-server/upload-presign">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $project->id . '">' .
            '<label>对象 Key<input name="object_key" value="uploads/project-' . $project->id . '/package.zip" required></label>' .
            '<label>Content-Type<input name="content_type" value="application/zip"></label>' .
            '<label>最大字节数<input name="max_bytes" value="52428800"></label><button type="submit">生成上传指令</button></form>' .
            '<form method="post" action="/admin/market-server/packages/generate">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $project->id . '">' .
            '<label>扩展 ID<input name="extension_id" value="' . View::escape($this->suggestedExtensionId($project->marketId)) . '" required></label>' .
            '<label>版本号<input name="version" placeholder="留空时读取扩展 manifest"></label>' .
            '<button type="submit">生成标准包</button></form>' .
            '<form method="post" action="/admin/market-server/upload-confirm">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $project->id . '">' .
            '<label>版本号<input name="version" required></label>' .
            '<label>对象 Key<input name="object_key" required></label>' .
            '<label>本地对象路径<input name="package_path" placeholder="/path/to/uploaded.zip 或 storage/market/remote-objects/..." required></label>' .
            '<label>原始文件名<input name="original_name" value="package.zip"></label>' .
            '<label>Changelog<textarea name="changelog" rows="4"></textarea></label><button type="submit">确认上传并提交版本</button></form>' .
            '<form method="post" action="/admin/market-server/versions">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $project->id . '">' .
            '<label>版本号<input name="version" required></label>' .
            '<label>提交包路径<input name="package_path" required></label>' .
            '<label>Changelog<textarea name="changelog" rows="4"></textarea></label><button type="submit">提交版本</button></form>' .
            '<h2>项目成员</h2><table><thead><tr><th>Developer Key</th><th>名称</th><th>角色</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $memberRows . '</tbody></table>' .
            '<h2>上传确认</h2><table><thead><tr><th>ID</th><th>对象 Key</th><th>状态</th><th>SHA-256</th><th>确认时间</th></tr></thead><tbody>' . $uploadRows . '</tbody></table>' .
            '<h2>版本</h2><table><thead><tr><th>ID</th><th>版本</th><th>状态</th><th>SHA-256</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<h2>审核历史</h2><table><thead><tr><th>ID</th><th>版本</th><th>决定</th><th>备注</th><th>时间</th></tr></thead><tbody>' . $reviewRows . '</tbody></table>' .
            '<h2>销售与结算</h2><p class="muted">项目已支付订单 ' . (int) $billing['payments'] . ' 笔，总额 ' . number_format(((int) $billing['gross_cents']) / 100, 2) . '，税额 ' . number_format(((int) $billing['tax_cents']) / 100, 2) . '，净额 ' . number_format(((int) $billing['net_cents']) / 100, 2) . '。</p>' .
            '<table><thead><tr><th>ID</th><th>金额</th><th>周期</th><th>状态</th></tr></thead><tbody>' . $settlementRows . '</tbody></table>';

        return Response::html(View::page('项目版本', $body));
    }

    public function addProjectMember(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $projectId = (int) $request->input('project_id');
            $this->repo()->addProjectMemberByKey($projectId, (string) $request->input('developer_key'), (string) $request->input('role', 'Developer'));

            return Response::redirect('/admin/market-server/projects/' . $projectId);
        } catch (Throwable $exception) {
            return $this->errorPage('添加项目成员', $exception, 400);
        }
    }

    public function projectMemberAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        $projectId = (int) $request->input('project_id');
        try {
            $repo = $this->repo();
            $action = (string) $request->input('action');
            if ($action === 'update-role') {
                $repo->updateProjectMemberRole((int) $request->input('member_id'), (string) $request->input('role', 'Developer'));
            } elseif ($action === 'remove') {
                $repo->removeProjectMember((int) $request->input('member_id'));
            } else {
                throw new MarketServerException('Unsupported project member action.');
            }

            return Response::redirect('/admin/market-server/projects/' . $projectId);
        } catch (Throwable $exception) {
            return $this->errorPage('项目成员操作', $exception, 400);
        }
    }

    public function downloadAudits(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $marketId = trim((string) $request->input('market_id', ''));
            $repo = $this->repo();
            if ((string) $request->input('format', '') === 'csv') {
                return new Response($repo->downloadAuditCsv($marketId), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="download-audits.csv"',
                ]);
            }
            $pagination = $repo->downloadAuditPage($marketId, (int) $request->input('page', 1), (int) $request->input('per_page', 20));
            $audits = $pagination['items'];
        } catch (Throwable $exception) {
            return $this->errorPage('下载审计', $exception, 400);
        }
        $rows = '';
        foreach ($audits as $audit) {
            $rows .= '<tr><td>' . View::escape((string) $audit['market_id']) . '</td><td>' . View::escape((string) $audit['version']) .
                '</td><td>' . View::escape((string) $audit['site_id']) . '</td><td>' . View::escape((string) $audit['ip_address']) .
                '</td><td>' . View::escape((string) $audit['created_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无下载审计记录</td></tr>';
        $body = '<h1>下载审计</h1><form method="get" action="/admin/market-server/download-audits">' .
            '<label>Market ID<input name="market_id" value="' . View::escape($marketId) . '"></label>' .
            '<label>每页<input name="per_page" value="' . (int) $pagination['per_page'] . '"></label><button type="submit">筛选</button> ' .
            '<a class="button" href="/admin/market-server/download-audits?market_id=' . rawurlencode($marketId) . '&format=csv">导出 CSV</a></form>' .
            '<p class="muted">筛选结果：' . (int) $pagination['total'] . ' 条，第 ' . (int) $pagination['page'] . ' / ' . (int) $pagination['pages'] . ' 页</p>' .
            '<table><thead><tr><th>Market ID</th><th>版本</th><th>站点</th><th>IP</th><th>时间</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><a class="button" href="/admin/market-server/download-audits?market_id=' . rawurlencode($marketId) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . max(1, (int) $pagination['page'] - 1) . '">上一页</a> ' .
            '<a class="button" href="/admin/market-server/download-audits?market_id=' . rawurlencode($marketId) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . min((int) $pagination['pages'], (int) $pagination['page'] + 1) . '">下一页</a></p>';

        return Response::html(View::page('下载审计', $body));
    }

    public function payments(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $marketId = trim((string) $request->input('market_id', ''));
            $repo = $this->repo();
            if ((string) $request->input('settlements_format', '') === 'csv') {
                return new Response($repo->settlementCsv(), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="market-settlements.csv"',
                ]);
            }
            if ((string) $request->input('billing_format', '') === 'csv') {
                return new Response($repo->exportBillingSummaryCsv($marketId, 'Paid', 'admin'), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="market-billing-summary.csv"',
                ]);
            }
            $payments = $repo->payments($marketId);
            $settlements = $repo->settlements();
            $billing = $repo->billingSummary($marketId);
        } catch (Throwable $exception) {
            return $this->errorPage('付款结算', $exception, 400);
        }
        $rows = '';
        foreach ($payments as $payment) {
            $rows .= '<tr><td>' . View::escape((string) $payment['market_id']) . '</td><td>' . View::escape((string) $payment['site_id']) .
                '</td><td>' . number_format(((int) $payment['amount_cents']) / 100, 2) . ' ' . View::escape((string) $payment['currency']) .
                '</td><td>' . View::escape((string) $payment['status']) . '</td><td>' . View::escape((string) $payment['provider']) .
                '</td><td>' . View::escape((string) $payment['updated_at']) . '</td><td><a class="button" href="/admin/market-server/payment-receipt?payment_id=' . (int) $payment['id'] . '">收据</a></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无付款记录</td></tr>';
        $settlementRows = '';
        foreach ($settlements as $settlement) {
            $settlementRows .= '<tr><td>' . (int) $settlement['id'] . '</td><td>' . View::escape((string) $settlement['display_name']) .
                '</td><td>' . number_format(((int) $settlement['amount_cents']) / 100, 2) . ' ' . View::escape((string) $settlement['currency']) .
                '</td><td>' . View::escape((string) $settlement['period_start']) . ' - ' . View::escape((string) $settlement['period_end']) .
                '</td><td>' . View::escape((string) $settlement['status']) . '</td><td>' . $this->settlementActionForm((int) $settlement['id'], (string) $settlement['status']) . '</td></tr>';
        }
        $settlementRows = $settlementRows !== '' ? $settlementRows : '<tr><td colspan="6" class="muted">暂无结算批次</td></tr>';
        $billingRows = '';
        foreach ($billing['by_country'] as $country => $item) {
            $billingRows .= '<tr><td>' . View::escape($country) . '</td><td>' . (int) $item['payments'] .
                '</td><td>' . number_format(((int) $item['gross_cents']) / 100, 2) .
                '</td><td>' . number_format(((int) $item['tax_cents']) / 100, 2) .
                '</td><td>' . number_format(((int) $item['net_cents']) / 100, 2) . '</td></tr>';
        }
        $billingRows = $billingRows !== '' ? $billingRows : '<tr><td colspan="5" class="muted">暂无已支付账单</td></tr>';
        $body = '<h1>付款结算</h1><form method="get" action="/admin/market-server/payments">' .
            '<label>Market ID<input name="market_id" value="' . View::escape($marketId) . '"></label><button type="submit">筛选</button></form>' .
            '<h2>账单税务汇总</h2><p class="muted">已支付付款：' . (int) $billing['payments'] . '，总额 ' . number_format(((int) $billing['gross_cents']) / 100, 2) . '，税额 ' . number_format(((int) $billing['tax_cents']) / 100, 2) . '</p>' .
            '<p><a class="button" href="/admin/market-server/payments?market_id=' . rawurlencode($marketId) . '&billing_format=csv">导出账单汇总 CSV</a> <a class="button" href="/admin/market-server/billing-summary?market_id=' . rawurlencode($marketId) . '">账单汇总 JSON</a></p>' .
            '<table><thead><tr><th>国家</th><th>付款数</th><th>总额</th><th>税额</th><th>净额</th></tr></thead><tbody>' . $billingRows . '</tbody></table>' .
            '<h2>付款记录</h2><table><thead><tr><th>Market ID</th><th>站点</th><th>金额</th><th>状态</th><th>Provider</th><th>更新时间</th><th>收据</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<h2>开发者结算</h2><p><a class="button" href="/admin/market-server/payments?settlements_format=csv">导出结算 CSV</a></p>' .
            '<table><thead><tr><th>ID</th><th>开发者</th><th>金额</th><th>周期</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $settlementRows . '</tbody></table>';

        return Response::html(View::page('付款结算', $body));
    }

    public function operations(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $summary = $this->repo()->operationsSummary();
        } catch (Throwable $exception) {
            return $this->errorPage('市场运营仪表盘', $exception, 400);
        }
        $rows = '';
        foreach ($summary as $label => $value) {
            $rows .= '<tr><td>' . View::escape($label) . '</td><td>' . (int) $value . '</td></tr>';
        }
        $body = '<h1>市场运营仪表盘</h1><table><thead><tr><th>指标</th><th>数量</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><a class="button" href="/admin/market-server/operations-data">图表数据 JSON</a> <a class="button" href="/admin/market-server/review">审核队列</a> <a class="button" href="/admin/market-server/payments">付款结算</a> <a class="button" href="/admin/market-server/licenses">商业授权</a> <a class="button" href="/admin/market-server/subscriptions">订阅同步</a> <a class="button" href="/admin/market-server/license-usage">席位上报</a> <a class="button" href="/admin/market-server/portal-tokens">门户 Token</a> <a class="button" href="/admin/market-server/tax-rules">税务规则</a> <a class="button" href="/admin/market-server/commercial-audits">商业审计</a> <a class="button" href="/admin/market-server/commercial-policy">商业策略</a> <a class="button" href="/admin/market-server/webhook-dead-letters">Webhook 死信</a> <a class="button" href="/admin/market-server/download-audits">下载审计</a> <a class="button" href="/admin/market-server/ai-settings">AI 设置</a></p>';

        return Response::html(View::page('市场运营仪表盘', $body));
    }

    public function operationsData(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['chart' => $this->repo()->operationsChartData(), 'trend' => $this->repo()->operationsTrendData()]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function licenses(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $marketId = trim((string) $request->input('market_id', ''));
            $licenses = $this->repo()->licenses($marketId);
        } catch (Throwable $exception) {
            return $this->errorPage('商业授权', $exception, 400);
        }
        $rows = '';
        foreach ($licenses as $license) {
            $rows .= '<tr><td>' . View::escape((string) $license['market_id']) . '</td><td>' . View::escape((string) $license['site_id']) .
                '</td><td>' . View::escape((string) $license['license_key']) . '</td><td>' . View::escape((string) $license['status']) .
                '</td><td>' . (int) $license['seats'] . '</td><td>' . View::escape((string) ($license['plan_key'] ?? '')) .
                '</td><td>' . ((int) ($license['auto_renew'] ?? 0) === 1 ? '开启' : '关闭') . '</td><td>' . View::escape((string) ($license['expires_at'] ?? '')) .
                '</td><td>' . $this->licenseActionForms((string) $license['license_key']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="9" class="muted">暂无授权</td></tr>';
        $body = '<h1>商业授权</h1><form method="post" action="/admin/market-server/licenses">' . CsrfToken::field() .
            '<label>Market ID<input name="market_id" required></label>' .
            '<label>Site ID<input name="site_id" required></label>' .
            '<label>Seats<input name="seats" value="1"></label>' .
            '<label>Plan Key<input name="plan_key"></label>' .
            '<label><input type="checkbox" name="auto_renew" value="1"> 自动续费</label>' .
            '<label>Expires At<input name="expires_at" placeholder="2027-12-31T00:00:00+00:00"></label><button type="submit">签发授权</button></form>' .
            '<form method="get" action="/admin/market-server/licenses"><label>Market ID<input name="market_id" value="' . View::escape($marketId) . '"></label><button type="submit">筛选</button></form>' .
            '<p><a class="button" href="/admin/market-server/license-audits">授权审计</a> <a class="button" href="/admin/market-server/notification-schedules">通知调度</a></p>' .
            '<table><thead><tr><th>Market ID</th><th>Site ID</th><th>License Key</th><th>状态</th><th>Seats</th><th>Plan</th><th>自动续费</th><th>到期</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('商业授权', $body));
    }

    public function issueLicense(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->issueLicense(
                trim((string) $request->input('market_id')),
                trim((string) $request->input('site_id')),
                (int) $request->input('seats', 1),
                trim((string) $request->input('expires_at', '')),
                trim((string) $request->input('plan_key', '')),
                (string) $request->input('auto_renew', '') === '1'
            );
        } catch (Throwable $exception) {
            return $this->errorPage('商业授权', $exception, 400);
        }

        return Response::redirect('/admin/market-server/licenses');
    }

    public function licenseAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $repo = $this->repo();
            $action = (string) $request->input('action');
            if ($action === 'renew') {
                $repo->renewLicense((string) $request->input('license_key'), (string) $request->input('expires_at'), (int) $request->input('seats', 0));
            } elseif ($action === 'revoke') {
                $repo->revokeLicense((string) $request->input('license_key'));
            } elseif ($action === 'change-plan') {
                $repo->changeLicensePlan((string) $request->input('license_key'), trim((string) $request->input('plan_key')), (int) $request->input('seats', 0));
            } elseif ($action === 'auto-renew-on') {
                $repo->setLicenseAutoRenew((string) $request->input('license_key'), true);
            } elseif ($action === 'auto-renew-off') {
                $repo->setLicenseAutoRenew((string) $request->input('license_key'), false);
            } else {
                throw new MarketServerException('Unsupported license action.');
            }
        } catch (Throwable $exception) {
            return $this->errorPage('商业授权', $exception, 400);
        }

        return Response::redirect('/admin/market-server/licenses');
    }

    public function plans(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $marketId = trim((string) $request->input('market_id', ''));
            $plans = $this->repo()->plans($marketId);
        } catch (Throwable $exception) {
            return $this->errorPage('商业套餐', $exception, 400);
        }
        $rows = '';
        foreach ($plans as $plan) {
            $rows .= '<tr><td>' . View::escape((string) $plan['market_id']) . '</td><td>' . View::escape((string) $plan['plan_key']) .
                '</td><td>' . View::escape((string) $plan['name']) . '</td><td>' . number_format(((int) $plan['price_cents']) / 100, 2) . ' ' . View::escape((string) $plan['currency']) .
                '</td><td>' . View::escape((string) $plan['billing_period']) . '</td><td>' . (int) ($plan['trial_days'] ?? 0) .
                '</td><td>' . View::escape((string) $plan['status']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无商业套餐</td></tr>';
        $body = '<h1>商业套餐</h1><form method="post" action="/admin/market-server/plans">' . CsrfToken::field() .
            '<label>Market ID<input name="market_id" required></label>' .
            '<label>Plan Key<input name="plan_key" required></label>' .
            '<label>名称<input name="name" required></label>' .
            '<label>价格分<input name="price_cents" value="0"></label>' .
            '<label>币种<input name="currency" value="USD"></label>' .
            '<label>周期<select name="billing_period"><option value="one_time">One Time</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label>' .
            '<label>试用天数<input name="trial_days" value="0"></label><button type="submit">创建套餐</button></form>' .
            '<form method="get" action="/admin/market-server/plans"><label>Market ID<input name="market_id" value="' . View::escape($marketId) . '"></label><button type="submit">筛选</button></form>' .
            '<table><thead><tr><th>Market ID</th><th>Plan Key</th><th>名称</th><th>价格</th><th>周期</th><th>试用天数</th><th>状态</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('商业套餐', $body));
    }

    public function createPlan(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->createPlan(
                trim((string) $request->input('market_id')),
                trim((string) $request->input('plan_key')),
                trim((string) $request->input('name')),
                (int) $request->input('price_cents', 0),
                (string) $request->input('currency', 'USD'),
                (string) $request->input('billing_period', 'one_time'),
                (int) $request->input('trial_days', 0)
            );
        } catch (Throwable $exception) {
            return $this->errorPage('商业套餐', $exception, 400);
        }

        return Response::redirect('/admin/market-server/plans');
    }

    public function licenseAudits(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $licenseKey = trim((string) $request->input('license_key', ''));
            $audits = $this->repo()->licenseAudits($licenseKey);
        } catch (Throwable $exception) {
            return $this->errorPage('授权审计', $exception, 400);
        }
        $rows = '';
        foreach ($audits as $audit) {
            $rows .= '<tr><td>' . View::escape((string) $audit['license_key']) . '</td><td>' . View::escape((string) $audit['action']) .
                '</td><td>' . View::escape((string) $audit['actor']) . '</td><td>' . View::escape((string) $audit['payload_json']) .
                '</td><td>' . View::escape((string) $audit['created_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无授权审计</td></tr>';
        $body = '<h1>授权审计</h1><form method="get" action="/admin/market-server/license-audits"><label>License Key<input name="license_key" value="' . View::escape($licenseKey) . '"></label><button type="submit">筛选</button></form>' .
            '<table><thead><tr><th>License Key</th><th>动作</th><th>Actor</th><th>Payload</th><th>时间</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('授权审计', $body));
    }

    public function notificationSchedules(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $schedules = $this->repo()->notificationSchedules();
        } catch (Throwable $exception) {
            return $this->errorPage('通知调度', $exception, 400);
        }
        $rows = '';
        foreach ($schedules as $schedule) {
            $rows .= '<tr><td>' . View::escape((string) $schedule['schedule_key']) . '</td><td>' . View::escape((string) $schedule['channel']) .
                '</td><td>' . (int) $schedule['interval_minutes'] . '</td><td>' . ((int) $schedule['enabled'] === 1 ? '开启' : '关闭') .
                '</td><td>' . View::escape((string) ($schedule['last_run_at'] ?? '')) . '</td><td>' . View::escape((string) $schedule['next_run_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无通知调度</td></tr>';
        $body = '<h1>通知调度</h1><form method="post" action="/admin/market-server/notification-schedules">' . CsrfToken::field() .
            '<label>Schedule Key<input name="schedule_key" required></label>' .
            '<label>Channel<input name="channel" value="webhook"></label>' .
            '<label>间隔分钟<input name="interval_minutes" value="15"></label>' .
            '<label><input type="checkbox" name="enabled" value="1" checked> 启用</label><button type="submit">保存调度</button></form>' .
            '<table><thead><tr><th>Key</th><th>Channel</th><th>间隔</th><th>状态</th><th>上次运行</th><th>下次运行</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('通知调度', $body));
    }

    public function saveNotificationSchedule(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->saveNotificationSchedule(
                trim((string) $request->input('schedule_key')),
                trim((string) $request->input('channel', 'webhook')),
                (int) $request->input('interval_minutes', 15),
                (string) $request->input('enabled', '') === '1'
            );
        } catch (Throwable $exception) {
            return $this->errorPage('通知调度', $exception, 400);
        }

        return Response::redirect('/admin/market-server/notification-schedules');
    }

    public function subscriptions(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();
            $subscriptions = $repo->subscriptions();
            $actionJobs = $repo->subscriptionActionJobs();
            $actionSummary = $repo->subscriptionActionSummary();
        } catch (Throwable $exception) {
            return $this->errorPage('订阅同步', $exception, 400);
        }
        $rows = '';
        foreach ($subscriptions as $subscription) {
            $subscriptionId = (string) $subscription['provider_subscription_id'];
            $rows .= '<tr><td>' . View::escape((string) $subscription['provider']) . '</td><td>' . View::escape($subscriptionId) .
                '</td><td>' . View::escape((string) $subscription['license_key']) . '</td><td>' . View::escape((string) $subscription['plan_key']) .
                '</td><td>' . View::escape((string) $subscription['status']) . '</td><td>' . View::escape((string) ($subscription['current_period_end'] ?? '')) .
                '</td><td>' . ((int) ($subscription['cancel_at_period_end'] ?? 0) === 1 ? '是' : '否') . '</td><td>' . $this->subscriptionActionForms($subscriptionId) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="8" class="muted">暂无订阅同步记录</td></tr>';
        $jobRows = '';
        foreach ($actionJobs as $job) {
            $jobRows .= '<tr><td>' . (int) $job['id'] . '</td><td>' . View::escape((string) $job['provider_subscription_id']) .
                '</td><td>' . View::escape((string) $job['action']) . '</td><td>' . View::escape((string) $job['status']) .
                '</td><td>' . View::escape((string) $job['updated_at']) . '</td></tr>';
        }
        $jobRows = $jobRows !== '' ? $jobRows : '<tr><td colspan="5" class="muted">暂无订阅动作任务</td></tr>';
        $summaryText = [];
        foreach ($actionSummary as $status => $count) {
            $summaryText[] = View::escape($status) . ': ' . (int) $count;
        }
        $body = '<h1>订阅同步</h1><p class="muted">Provider 任务摘要：' . View::escape(implode(' / ', $summaryText)) . '</p><table><thead><tr><th>Provider</th><th>订阅 ID</th><th>License Key</th><th>Plan</th><th>状态</th><th>周期结束</th><th>期末取消</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<h2>Provider 异步任务</h2><table><thead><tr><th>ID</th><th>订阅 ID</th><th>动作</th><th>状态</th><th>更新时间</th></tr></thead><tbody>' . $jobRows . '</tbody></table>';

        return Response::html(View::page('订阅同步', $body));
    }

    public function subscriptionAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $subscriptionId = trim((string) $request->input('subscription_id'));
            $action = (string) $request->input('action');
            if ($action === 'cancel') {
                $this->repo()->cancelSubscription($subscriptionId);
            } elseif ($action === 'restore') {
                $this->repo()->restoreSubscription($subscriptionId);
            } else {
                throw new MarketServerException('Unsupported subscription action.');
            }
        } catch (Throwable $exception) {
            return $this->errorPage('订阅同步', $exception, 400);
        }

        return Response::redirect('/admin/market-server/subscriptions');
    }

    public function licenseUsage(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $usageRows = $this->repo()->licenseUsage();
        } catch (Throwable $exception) {
            return $this->errorPage('席位上报', $exception, 400);
        }
        $rows = '';
        foreach ($usageRows as $usage) {
            $rows .= '<tr><td>' . View::escape((string) $usage['license_key']) . '</td><td>' . View::escape((string) $usage['site_id']) .
                '</td><td>' . (int) $usage['active_seats'] . '</td><td>' . View::escape((string) $usage['payload_json']) .
                '</td><td>' . View::escape((string) $usage['reported_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无席位上报</td></tr>';
        $body = '<h1>席位上报</h1><form method="get" action="/admin/market-server/license-usage-data"><label>License Key<input name="license_key"></label><button type="submit">趋势 JSON</button></form>' .
            '<table><thead><tr><th>License Key</th><th>Site ID</th><th>活跃席位</th><th>Payload</th><th>上报时间</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('席位上报', $body));
    }

    public function licenseUsageData(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['trend' => $this->repo()->licenseUsageTrendData(trim((string) $request->input('license_key', '')))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function portalTokens(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $tokens = $this->repo()->portalTokens(trim((string) $request->input('license_key', '')), trim((string) $request->input('site_id', '')));
        } catch (Throwable $exception) {
            return $this->errorPage('门户 Token', $exception, 400);
        }
        $rows = '';
        foreach ($tokens as $token) {
            $rows .= '<tr><td>' . (int) $token['id'] . '</td><td>' . View::escape((string) $token['license_key']) .
                '</td><td>' . View::escape((string) $token['site_id']) . '</td><td>' . View::escape(substr((string) $token['token_hash'], 0, 12)) .
                '</td><td>' . View::escape((string) $token['expires_at']) . '</td><td>' . $this->portalTokenActionForm((int) $token['id']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无门户 Token</td></tr>';
        $body = '<h1>门户 Token</h1><form method="get" action="/admin/market-server/portal-tokens">' .
            '<label>License Key<input name="license_key"></label><label>Site ID<input name="site_id"></label><button type="submit">筛选</button></form>' .
            '<table><thead><tr><th>ID</th><th>License Key</th><th>Site ID</th><th>Token Hash</th><th>过期时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('门户 Token', $body));
    }

    public function portalTokenAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->revokePortalToken((int) $request->input('token_id'));
        } catch (Throwable $exception) {
            return $this->errorPage('门户 Token', $exception, 400);
        }

        return Response::redirect('/admin/market-server/portal-tokens');
    }

    public function taxRules(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $rules = $this->repo()->taxRules(trim((string) $request->input('country', '')));
        } catch (Throwable $exception) {
            return $this->errorPage('税务规则', $exception, 400);
        }
        $rows = '';
        foreach ($rules as $rule) {
            $rows .= '<tr><td>' . (int) $rule['id'] . '</td><td>' . View::escape((string) $rule['country']) .
                '</td><td>' . number_format(((int) $rule['tax_rate_basis_points']) / 100, 2) . '%</td><td>' .
                View::escape((string) $rule['status']) . '</td><td>' . View::escape((string) $rule['created_at']) . '</td><td>' .
                $this->taxRuleActionForms((int) $rule['id'], (string) $rule['status']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无税务规则</td></tr>';
        $body = '<h1>税务规则</h1><form method="post" action="/admin/market-server/tax-rules">' . CsrfToken::field() .
            '<label>国家代码<input name="country" placeholder="US" required></label>' .
            '<label>税率基点<input name="tax_rate_basis_points" value="825" required></label>' .
            '<label>状态<select name="status"><option value="Active">Active</option><option value="Disabled">Disabled</option></select></label><button type="submit">新增规则</button></form>' .
            '<table><thead><tr><th>ID</th><th>国家</th><th>税率</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('税务规则', $body));
    }

    public function createTaxRule(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->createTaxRule(trim((string) $request->input('country')), (int) $request->input('tax_rate_basis_points'), (string) $request->input('status', 'Active'));
        } catch (Throwable $exception) {
            return $this->errorPage('税务规则', $exception, 400);
        }

        return Response::redirect('/admin/market-server/tax-rules');
    }

    public function taxRuleAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->setTaxRuleStatus((int) $request->input('rule_id'), (string) $request->input('status', 'Active'), 'admin');
        } catch (Throwable $exception) {
            return $this->errorPage('税务规则', $exception, 400);
        }

        return Response::redirect('/admin/market-server/tax-rules');
    }

    public function billingSummary(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['summary' => $this->repo()->billingSummary(trim((string) $request->input('market_id', '')), trim((string) $request->input('status', 'Paid')))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAudits(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $eventType = trim((string) $request->input('event_type', ''));
            $subjectKey = trim((string) $request->input('subject_key', ''));
            $repo = $this->repo();
            if ((string) $request->input('format', '') === 'csv') {
                return new Response($repo->commercialAuditCsv($eventType, $subjectKey, 'admin'), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="commercial-audits.csv"',
                ]);
            }
            $audits = $repo->commercialAudits($eventType, $subjectKey);
            $summary = $repo->commercialAuditSummary();
        } catch (Throwable $exception) {
            return $this->errorPage('商业审计', $exception, 400);
        }
        $summaryText = [];
        foreach ($summary as $eventType => $count) {
            $summaryText[] = View::escape($eventType) . ': ' . (int) $count;
        }
        $rows = '';
        foreach ($audits as $audit) {
            $rows .= '<tr><td>' . View::escape((string) $audit['event_type']) . '</td><td>' . View::escape((string) $audit['subject_key']) .
                '</td><td>' . View::escape((string) $audit['actor']) . '</td><td>' . View::escape((string) $audit['payload_json']) .
                '</td><td>' . View::escape((string) $audit['created_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无商业审计记录</td></tr>';
        $body = '<h1>商业审计</h1><p class="muted">事件摘要：' . View::escape(implode(' / ', $summaryText)) . '</p><p><a class="button" href="/admin/market-server/commercial-audit-policy">审计保留策略</a> <a class="button" href="/admin/market-server/commercial-audits/risk-summary">风险摘要 JSON</a></p><form method="get" action="/admin/market-server/commercial-audits">' .
            '<label>事件类型<input name="event_type"></label><label>Subject<input name="subject_key"></label><button type="submit">筛选</button> <a class="button" href="/admin/market-server/commercial-audits?format=csv">导出 CSV</a></form>' .
            '<table><thead><tr><th>事件</th><th>Subject</th><th>Actor</th><th>Payload</th><th>时间</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('商业审计', $body));
    }

    public function commercialAuditPolicy(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $policy = $this->repo()->commercialAuditPolicy();
        } catch (Throwable $exception) {
            return $this->errorPage('商业审计策略', $exception, 400);
        }
        $body = '<h1>商业审计策略</h1><form method="post" action="/admin/market-server/commercial-audit-policy">' . CsrfToken::field() .
            '<label>保留天数<input name="retention_days" value="' . (int) $policy['retention_days'] . '"></label>' .
            '<label><input type="checkbox" name="audit_exports" value="1"' . ((int) $policy['audit_exports'] === 1 ? ' checked' : '') . '> 记录导出审计</label>' .
            '<label>单次清理上限<input name="purge_limit" value="' . (int) $policy['purge_limit'] . '"></label><button type="submit">保存策略</button></form>';

        return Response::html(View::page('商业审计策略', $body));
    }

    public function commercialAuditDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['audit' => $this->repo()->commercialAudit((int) $request->input('id', 0))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }
    }

    public function commercialAuditExports(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['exports' => $this->repo()->recentCommercialAuditExports((int) $request->input('limit', 10))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditPurgePreview(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['preview' => $this->repo()->commercialAuditPurgePreview(trim((string) $request->input('before', '')), (int) $request->input('limit', 0))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskSummary(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            return Response::json(['risk' => $this->repo()->commercialAuditRiskSummary((int) $request->input('window_hours', 24))]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskNotify(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $sent = $this->repo()->dispatchCommercialAuditRiskNotification((int) $request->input('window_hours', 24), (string) $request->input('minimum_risk_level', 'Medium'), (int) $request->input('cooldown_minutes', 60));

            return Response::json(['status' => 'Completed', 'queued_notifications' => $sent]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskNotifications(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();

            return Response::json([
                'summary' => $repo->commercialAuditRiskNotificationSummary(),
                'notifications' => $repo->recentCommercialAuditRiskNotifications((int) $request->input('limit', 10)),
            ]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskNotificationIncidents(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();
            $limit = (int) $request->input('limit', 10);
            if ((string) $request->input('format', '') === 'csv') {
                return new Response($repo->exportCommercialAuditRiskNotificationIncidentCsv($limit, 'admin'), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="commercial-audit-risk-notification-incidents.csv"',
                ]);
            }

            return Response::json([
                'summary' => $repo->commercialAuditRiskNotificationIncidentSummary(),
                'incidents' => $repo->recentCommercialAuditRiskNotificationIncidents($limit),
            ]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskNotificationIncidentExports(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();
            $limit = (int) $request->input('limit', 10);
            if ((string) $request->input('format', '') === 'csv') {
                return new Response($repo->commercialAuditRiskNotificationIncidentExportHistoryCsv($limit), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="commercial-audit-risk-notification-incident-exports.csv"',
                ]);
            }
            if ((string) $request->input('format', '') === 'summary-csv') {
                return new Response($repo->exportCommercialAuditRiskNotificationIncidentExportActorSummaryCsv('admin'), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="commercial-audit-risk-notification-incident-export-actors.csv"',
                ]);
            }

            return Response::json([
                'summary' => $repo->commercialAuditRiskNotificationIncidentExportActorSummary(),
                'exports' => $repo->recentCommercialAuditRiskNotificationIncidentExports($limit),
            ]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function commercialAuditRiskNotificationIncidentExportActorExports(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();
            $limit = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);
            $actor = (string) $request->input('actor', '');
            $since = (string) $request->input('since', '');
            $until = (string) $request->input('until', '');
            if ((string) $request->input('format', '') === 'csv') {
                return new Response($repo->commercialAuditRiskNotificationIncidentExportActorSummaryExportHistoryCsv($limit, $actor, $since, $until, $offset), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="commercial-audit-risk-notification-incident-export-actor-exports.csv"',
                ]);
            }
            $pagination = $repo->commercialAuditRiskNotificationIncidentExportActorSummaryExportPagination($limit, $actor, $since, $until, $offset);

            return Response::json([
                'filters' => ['actor' => $actor, 'since' => $since, 'until' => $until],
                'pagination' => ['limit' => $pagination['limit'], 'offset' => $pagination['offset'], 'page' => $pagination['page'], 'pages' => $pagination['pages'], 'page_label' => $pagination['page_label'], 'from' => $pagination['from'], 'to' => $pagination['to'], 'range_label' => $pagination['range_label'], 'filter_applied' => $pagination['filter_applied'], 'filter_label' => $pagination['filter_label'], 'filter_keys' => $pagination['filter_keys'], 'filter_values' => $pagination['filter_values'], 'filter_query' => $pagination['filter_query'], 'page_query' => $pagination['page_query'], 'next_page_query' => $pagination['next_page_query'], 'prev_page_query' => $pagination['prev_page_query'], 'first_page_query' => $pagination['first_page_query'], 'last_page_query' => $pagination['last_page_query'], 'page_queries' => $pagination['page_queries'], 'page_offsets' => $pagination['page_offsets'], 'page_availability' => $pagination['page_availability'], 'page_navigation' => $pagination['page_navigation'], 'page_counts' => $pagination['page_counts'], 'page_labels' => $pagination['page_labels'], 'page_flags' => $pagination['page_flags'], 'page_limits' => $pagination['page_limits'], 'page_sort' => $pagination['page_sort'], 'page_export' => $pagination['page_export'], 'page_download' => $pagination['page_download'], 'page_endpoint' => $pagination['page_endpoint'], 'page_auth_redirect' => $pagination['page_auth_redirect'], 'page_cli' => $pagination['page_cli'], 'page_links' => $pagination['page_links'], 'page_parameters' => $pagination['page_parameters'], 'page_defaults' => $pagination['page_defaults'], 'page_validation' => $pagination['page_validation'], 'page_cache' => $pagination['page_cache'], 'page_response' => $pagination['page_response'], 'page_csv_columns' => $pagination['page_csv_columns'], 'page_csv_dialect' => $pagination['page_csv_dialect'], 'page_csv_profile' => $pagination['page_csv_profile'], 'page_csv_schema' => $pagination['page_csv_schema'], 'page_csv_units' => $pagination['page_csv_units'], 'page_csv_labels' => $pagination['page_csv_labels'], 'page_csv_empty_values' => $pagination['page_csv_empty_values'], 'page_csv_safety' => $pagination['page_csv_safety'], 'page_csv_source' => $pagination['page_csv_source'], 'page_csv_row_shape' => $pagination['page_csv_row_shape'], 'page_csv_integrity' => $pagination['page_csv_integrity'], 'page_csv_lifecycle' => $pagination['page_csv_lifecycle'], 'page_csv_delivery' => $pagination['page_csv_delivery'], 'page_csv_scope' => $pagination['page_csv_scope'], 'page_csv_privacy' => $pagination['page_csv_privacy'], 'page_csv_handling' => $pagination['page_csv_handling'], 'page_csv_compliance' => $pagination['page_csv_compliance'], 'page_csv_provenance' => $pagination['page_csv_provenance'], 'page_csv_review' => $pagination['page_csv_review'], 'page_csv_observability' => $pagination['page_csv_observability'], 'page_csv_archive' => $pagination['page_csv_archive'], 'page_csv_custody' => $pagination['page_csv_custody'], 'page_csv_accessibility' => $pagination['page_csv_accessibility'], 'page_csv_localization' => $pagination['page_csv_localization'], 'page_csv_reconciliation' => $pagination['page_csv_reconciliation'], 'page_csv_recovery' => $pagination['page_csv_recovery'], 'page_csv_notification' => $pagination['page_csv_notification'], 'page_csv_actions' => $pagination['page_csv_actions'], 'page_csv_permissions' => $pagination['page_csv_permissions'], 'page_csv_error_handling' => $pagination['page_csv_error_handling'], 'page_csv_availability' => $pagination['page_csv_availability'], 'page_csv_audit_controls' => $pagination['page_csv_audit_controls'], 'page_csv_governance' => $pagination['page_csv_governance'], 'page_csv_attestation' => $pagination['page_csv_attestation'], 'page_csv_disclosure' => $pagination['page_csv_disclosure'], 'page_csv_revocation' => $pagination['page_csv_revocation'], 'page_csv_expiration' => $pagination['page_csv_expiration'], 'page_csv_rotation' => $pagination['page_csv_rotation'], 'page_csv_key_management' => $pagination['page_csv_key_management'], 'page_csv_signature' => $pagination['page_csv_signature'], 'page_csv_verification' => $pagination['page_csv_verification'], 'page_csv_monitoring' => $pagination['page_csv_monitoring'], 'page_csv_incident_response' => $pagination['page_csv_incident_response'], 'page_csv_postmortem' => $pagination['page_csv_postmortem'], 'page_csv_lessons' => $pagination['page_csv_lessons'], 'page_csv_training' => $pagination['page_csv_training'], 'page_csv_drill' => $pagination['page_csv_drill'], 'page_csv_evaluation' => $pagination['page_csv_evaluation'], 'page_csv_remediation' => $pagination['page_csv_remediation'], 'page_csv_exception' => $pagination['page_csv_exception'], 'page_csv_waiver' => $pagination['page_csv_waiver'], 'page_csv_waiver_review' => $pagination['page_csv_waiver_review'], 'page_csv_waiver_closure' => $pagination['page_csv_waiver_closure'], 'page_csv_waiver_notification' => $pagination['page_csv_waiver_notification'], 'page_csv_waiver_acknowledgement' => $pagination['page_csv_waiver_acknowledgement'], 'page_csv_waiver_escalation' => $pagination['page_csv_waiver_escalation'], 'page_csv_waiver_audit' => $pagination['page_csv_waiver_audit'], 'page_csv_waiver_audit_notification' => $pagination['page_csv_waiver_audit_notification'], 'page_csv_waiver_audit_acknowledgement' => $pagination['page_csv_waiver_audit_acknowledgement'], 'page_csv_waiver_audit_escalation' => $pagination['page_csv_waiver_audit_escalation'], 'page_csv_waiver_audit_resolution' => $pagination['page_csv_waiver_audit_resolution'], 'page_csv_waiver_audit_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_notification'], 'page_csv_waiver_audit_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure' => $pagination['page_csv_waiver_audit_resolution_closure'], 'page_csv_waiver_audit_resolution_closure_notification' => $pagination['page_csv_waiver_audit_resolution_closure_notification'], 'page_csv_waiver_audit_resolution_closure_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition' => $pagination['page_csv_waiver_audit_resolution_closure_disposition'], 'page_csv_waiver_audit_resolution_closure_disposition_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement'], 'page_contract' => $pagination['page_contract'], 'page_request' => $pagination['page_request'], 'page_capabilities' => $pagination['page_capabilities'], 'page_security' => $pagination['page_security'], 'page_empty_state' => $pagination['page_empty_state'], 'page_refresh' => $pagination['page_refresh'], 'page_audit' => $pagination['page_audit'], 'page_summary' => $pagination['page_summary'], 'page_window' => $pagination['page_window'], 'page_filter' => $pagination['page_filter'], 'page_diagnostics' => $pagination['page_diagnostics'], 'page_status' => $pagination['page_status'], 'page_status_label' => $pagination['page_status_label'], 'page_state' => $pagination['page_state'], 'active_filter_count' => $pagination['active_filter_count'], 'remaining' => $pagination['remaining'], 'is_empty' => $pagination['is_empty'], 'is_out_of_range' => $pagination['is_out_of_range'], 'has_more' => $pagination['has_more'], 'has_previous' => $pagination['has_previous'], 'next_offset' => $pagination['next_offset'], 'prev_offset' => $pagination['prev_offset'], 'first_offset' => $pagination['first_offset'], 'last_offset' => $pagination['last_offset']],
                'total' => $pagination['total'],
                'exports' => $repo->recentCommercialAuditRiskNotificationIncidentExportActorSummaryExports($limit, $actor, $since, $until, $offset),
            ]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function retryCommercialAuditRiskNotifications(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $retried = $this->repo()->retryFailedCommercialAuditRiskNotifications();

            return Response::json(['status' => 'Completed', 'retried' => $retried]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function saveCommercialAuditPolicy(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->saveCommercialAuditPolicy(
                (int) $request->input('retention_days', 365),
                (string) $request->input('audit_exports', '') === '1',
                (int) $request->input('purge_limit', 500)
            );
        } catch (Throwable $exception) {
            return $this->errorPage('商业审计策略', $exception, 400);
        }

        return Response::redirect('/admin/market-server/commercial-audit-policy');
    }

    public function commercialPolicy(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $policy = $this->repo()->commercialPolicy();
        } catch (Throwable $exception) {
            return $this->errorPage('商业策略', $exception, 400);
        }
        $body = '<h1>商业策略</h1><form method="post" action="/admin/market-server/commercial-policy">' . CsrfToken::field() .
            '<label><input type="checkbox" name="require_license_for_install" value="1"' . ((int) $policy['require_license_for_install'] === 1 ? ' checked' : '') . '> 安装必须校验 license</label>' .
            '<label><input type="checkbox" name="require_license_for_download" value="1"' . ((int) $policy['require_license_for_download'] === 1 ? ' checked' : '') . '> 下载必须校验 license</label>' .
            '<label><input type="checkbox" name="enforce_seat_limits" value="1"' . ((int) $policy['enforce_seat_limits'] === 1 ? ' checked' : '') . '> 强制席位上限</label><button type="submit">保存策略</button></form>';

        return Response::html(View::page('商业策略', $body));
    }

    public function saveCommercialPolicy(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->saveCommercialPolicy(
                (string) $request->input('require_license_for_install', '') === '1',
                (string) $request->input('require_license_for_download', '') === '1',
                (string) $request->input('enforce_seat_limits', '') === '1'
            );
        } catch (Throwable $exception) {
            return $this->errorPage('商业策略', $exception, 400);
        }

        return Response::redirect('/admin/market-server/commercial-policy');
    }

    public function settlementAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->markSettlementStatus((int) $request->input('settlement_id'), (string) $request->input('status', 'Processing'));
        } catch (Throwable $exception) {
            return $this->errorPage('结算状态', $exception, 400);
        }

        return Response::redirect('/admin/market-server/payments');
    }

    public function paymentWebhook(Request $request): Response
    {
        $provider = (string) $request->input('provider', 'generic');
        $payload = (new PaymentProviderAdapterFactory())->make($provider)->normalize($this->paymentWebhookPayload($request));
        $signature = (string) $request->input('signature', (string) $request->input('token', ''));
        $signatureStrategy = (new ProviderPaymentSignatureStrategyFactory(
            (string) $this->settings->get('market.payment_webhook_secret', ''),
            []
        ))->make($provider);
        if (!$signatureStrategy->verify($payload, $signature)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        try {
            $repo = $this->repo();
            $repo->recordPaymentWebhookEvent((string) $payload['event_id'], (string) $payload['provider'], $payload);
            $payment = $repo->applyPaymentWebhook($payload);

            return Response::json(['payment' => $payment]);
        } catch (Throwable $exception) {
            try {
                $this->repo()->recordWebhookDeadLetter((string) ($payload['event_id'] ?? ''), (string) ($payload['provider'] ?? $provider), $payload, $exception->getMessage());
            } catch (Throwable) {
            }
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function paymentReceipt(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $paymentId = (int) $request->input('payment_id', 0);
            if ((string) $request->input('format', '') === 'pdf') {
                return new Response($this->repo()->paymentInvoicePdf($paymentId), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="invoice-' . $paymentId . '.pdf"',
                ]);
            }
            $csv = $this->repo()->paymentReceiptCsv($paymentId);

            return new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="payment-receipt-' . $paymentId . '.csv"',
            ]);
        } catch (Throwable $exception) {
            return $this->errorPage('付款收据', $exception, 400);
        }
    }

    public function webhookDeadLetters(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = $this->repo();
            $letters = $repo->webhookDeadLetters();
            $alertSummary = $repo->webhookDeadLetterAlertSummary();
        } catch (Throwable $exception) {
            return $this->errorPage('Webhook 死信', $exception, 400);
        }
        $rows = '';
        foreach ($letters as $letter) {
            $rows .= '<tr><td>' . (int) $letter['id'] . '</td><td>' . View::escape((string) $letter['event_id']) .
                '</td><td>' . View::escape((string) $letter['provider']) . '</td><td>' . View::escape((string) $letter['status']) .
                '</td><td>' . (int) $letter['retry_count'] . '</td><td>' . View::escape((string) $letter['error_message']) .
                '</td><td>' . $this->webhookDeadLetterActionForm((int) $letter['id']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无 Webhook 死信</td></tr>';
        $summaryText = [];
        foreach ($alertSummary as $status => $count) {
            $summaryText[] = View::escape($status) . ': ' . (int) $count;
        }
        $body = '<h1>Webhook 死信</h1><p class="muted">告警摘要：' . View::escape(implode(' / ', $summaryText)) . '</p><table><thead><tr><th>ID</th><th>Event ID</th><th>Provider</th><th>状态</th><th>重试</th><th>错误</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('Webhook 死信', $body));
    }

    public function webhookDeadLetterAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->retryWebhookDeadLetter((int) $request->input('dead_letter_id'));
        } catch (Throwable $exception) {
            return $this->errorPage('Webhook 死信', $exception, 400);
        }

        return Response::redirect('/admin/market-server/webhook-dead-letters');
    }

    public function aiSettingsForm(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $saved = $this->repo()->aiSettings();
        } catch (Throwable $exception) {
            return $this->errorPage('AI Provider 设置', $exception, 400);
        }
        $provider = (string) ($saved['provider'] ?? $this->settings->get('market.ai_provider', ''));
        $model = (string) ($saved['model'] ?? $this->settings->get('market.ai_model', ''));
        $apiKeyRef = (string) ($saved['api_key_ref'] ?? $this->settings->get('market.ai_api_key_ref', ''));
        $status = (string) ($saved['status'] ?? 'Active');
        $body = '<h1>AI Provider 设置</h1><form method="post" action="/admin/market-server/ai-settings">' . CsrfToken::field() .
            '<label>Provider<input name="provider" value="' . View::escape($provider) . '" placeholder="openai" required></label>' .
            '<label>Model<input name="model" value="' . View::escape($model) . '" placeholder="gpt-5-mini" required></label>' .
            '<label>API Key 引用<input name="api_key_ref" value="' . View::escape($apiKeyRef) . '" placeholder="env:OPENAI_API_KEY"></label>' .
            '<label>状态<select name="status"><option value="Active"' . ($status === 'Active' ? ' selected' : '') . '>Active</option><option value="Disabled"' . ($status === 'Disabled' ? ' selected' : '') . '>Disabled</option></select></label>' .
            '<p class="muted">AI Review 只生成证据、风险建议和人工复核重点；批准、发布、暂停和下架必须由审核员执行。</p>' .
            '<button type="submit">保存</button></form>';

        return Response::html(View::page('AI Provider 设置', $body));
    }

    public function saveAiSettings(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }
        try {
            $this->repo()->saveAiSettings(
                trim((string) $request->input('provider')),
                trim((string) $request->input('model')),
                trim((string) $request->input('api_key_ref', '')),
                (string) $request->input('status', 'Active')
            );
            $this->repo()->saveAiPolicy(false, 0, (int) ($guard['id'] ?? 1));
        } catch (Throwable $exception) {
            return $this->errorPage('AI Provider 设置', $exception, 400);
        }

        return Response::redirect('/admin/market-server/ai-settings');
    }

    public function developerNotifications(): Response
    {
        $developerKey = (string) ($_SESSION['market_developer_key'] ?? '');
        if ($developerKey === '') {
            return Response::redirect('/developer/market/login');
        }
        try {
            $repo = $this->repo();
            $developer = $repo->developerByKey($developerKey);
            $notifications = $repo->notificationsForDeveloper($developer->id);
        } catch (Throwable $exception) {
            return $this->errorPage('开发者通知', $exception, 400);
        }
        $rows = '';
        foreach ($notifications as $notification) {
            $rows .= '<tr><td>' . View::escape((string) $notification['type']) . '</td><td>' . View::escape((string) $notification['title']) .
                '</td><td>' . View::escape((string) $notification['body']) . '</td><td>' . View::escape((string) $notification['created_at']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="4" class="muted">暂无通知</td></tr>';

        return Response::html(View::page('开发者通知', '<h1>开发者通知</h1><table><thead><tr><th>类型</th><th>标题</th><th>内容</th><th>时间</th></tr></thead><tbody>' . $rows . '</tbody></table>'));
    }

    public function presignUpload(Request $request): Response
    {
        $guard = $this->requireAdminOrDeveloper((int) $request->input('project_id'));
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $projectId = (int) $request->input('project_id');
            $repo = $this->repo();
            $repo->project($projectId);
            $developerKey = trim((string) $request->input('developer_key', ''));
            if ($developerKey === '' && is_array($guard) && isset($guard['developer_key'])) {
                $developerKey = (string) $guard['developer_key'];
            }
            if ($developerKey !== '') {
                $repo->assertProjectDeveloperCanSubmit($projectId, $developerKey);
            }
            $key = $this->objectKey((string) $request->input('object_key', 'uploads/project-' . $projectId . '/package.zip'));
            $upload = $this->objectStorage()->presignUpload($key, 600, [
                'content_type' => (string) $request->input('content_type', 'application/zip'),
                'max_bytes' => (int) $request->input('max_bytes', 52428800),
            ]);
            $body = '<h1>上传指令</h1><p><a href="/admin/market-server/projects/' . $projectId . '">返回项目</a></p><pre>' .
                View::escape(json_encode($upload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') . '</pre>';

            return Response::html(View::page('上传指令', $body));
        } catch (Throwable $exception) {
            return $this->errorPage('上传指令', $exception, 400);
        }
    }

    public function confirmUpload(Request $request): Response
    {
        $guard = $this->requireAdminOrDeveloper((int) $request->input('project_id'));
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $projectId = (int) $request->input('project_id');
            $developerKey = trim((string) $request->input('developer_key', ''));
            $repo = $this->repo();
            if ($developerKey === '' && is_array($guard) && isset($guard['developer_key'])) {
                $developerKey = (string) $guard['developer_key'];
            }
            if ($developerKey !== '') {
                $repo->assertProjectDeveloperCanSubmit($projectId, $developerKey);
            }
            $packagePath = $this->packagePath((string) $request->input('package_path'));
            $version = $repo->submitVersionFromUpload(
                $projectId,
                (string) $request->input('version'),
                $this->objectKey((string) $request->input('object_key')),
                $packagePath,
                (string) $request->input('original_name', ''),
                (string) $request->input('changelog', ''),
            );

            return Response::redirect('/admin/market-server/projects/' . $version->projectId);
        } catch (Throwable $exception) {
            return $this->errorPage('确认上传', $exception, 400);
        }
    }

    public function generatePackage(Request $request): Response
    {
        $guard = $this->requireAdminOrDeveloper((int) $request->input('project_id'));
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $projectId = (int) $request->input('project_id');
            $repo = $this->repo();
            $project = $repo->project($projectId);
            if (is_array($guard) && isset($guard['developer_key'])) {
                $repo->assertProjectDeveloperCanSubmit($projectId, (string) $guard['developer_key']);
            }
            $result = (new DeveloperPackageBuilder($this->rootPath))->build(
                $projectId,
                $project->extensionType,
                trim((string) $request->input('extension_id')),
                (string) $request->input('version', ''),
            );

            $body = '<h1>标准包已生成</h1><p><a href="/admin/market-server/projects/' . $projectId . '">返回项目</a></p>' .
                '<table><tbody><tr><th>包路径</th><td><code>' . View::escape($result['package_path']) . '</code></td></tr>' .
                '<tr><th>SHA-256</th><td><code>' . View::escape($result['package_sha256']) . '</code></td></tr>' .
                '<tr><th>文件数</th><td>' . (int) $result['file_count'] . '</td></tr></tbody></table>' .
                '<form method="post" action="/admin/market-server/versions">' . CsrfToken::field() .
                '<input type="hidden" name="project_id" value="' . $projectId . '">' .
                '<input type="hidden" name="version" value="' . View::escape($result['version']) . '">' .
                '<input type="hidden" name="package_path" value="' . View::escape($result['package_path']) . '">' .
                '<label>Changelog<textarea name="changelog" rows="4"></textarea></label><button type="submit">提交该版本审核</button></form>';

            return Response::html(View::page('标准包已生成', $body));
        } catch (Throwable $exception) {
            return $this->errorPage('生成标准包', $exception, 400);
        }
    }

    public function uploadWebhook(Request $request): Response
    {
        $secret = (string) $this->settings->get('market.upload_webhook_secret', '');
        $payload = $this->webhookPayload($request);
        $signature = (string) $request->input('signature', (string) $request->input('token', ''));
        if (!(new WebhookSignatureVerifier($secret))->verify($payload, $signature)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $this->repo()->recordWebhookEvent((string) $payload['event_id'], $payload);
            $upload = $this->repo()->confirmUploadWebhook(
                (int) $payload['project_id'],
                $this->objectKey((string) $payload['object_key']),
                $this->packagePath((string) $payload['package_path']),
                (string) $payload['sha256'],
                (int) ($payload['byte_size'] ?? 0),
            );

            return Response::json(['upload' => $upload]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function submitVersion(Request $request): Response
    {
        $guard = $this->requireAdminOrDeveloper((int) $request->input('project_id'));
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $projectId = (int) $request->input('project_id');
            $repo = $this->repo();
            if (is_array($guard) && isset($guard['developer_key'])) {
                $repo->assertProjectDeveloperCanSubmit($projectId, (string) $guard['developer_key']);
            }
            $version = $repo->submitVersion($projectId, (string) $request->input('version'), (string) $request->input('package_path'), (string) $request->input('changelog', ''));
            return Response::redirect('/admin/market-server/projects/' . $version->projectId);
        } catch (Throwable $exception) {
            return $this->errorPage('提交版本', $exception, 400);
        }
    }

    public function versionAction(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $repo = $this->repo();
            $version = $repo->version((int) $request->input('version_id'));
            $guard = $this->requireAdminOrDeveloper($version->projectId);
            if ($guard instanceof Response) {
                return $guard;
            }
            $action = (string) $request->input('action');
            if ($action === 'withdraw') {
                if (is_array($guard) && isset($guard['developer_key'])) {
                    $repo->assertProjectDeveloperCanSubmit($version->projectId, (string) $guard['developer_key']);
                }
                $repo->withdrawVersion($version->id, (int) $guard['id'], (string) $request->input('notes', ''));
            } elseif ($action === 'unpublish') {
                $admin = $this->requireAdmin();
                if ($admin instanceof Response) {
                    return $admin;
                }
                $repo->unpublishVersion($version->id, (int) $admin['id'], (string) $request->input('notes', ''));
            } elseif ($action === 'deprecate') {
                $admin = $this->requireAdmin();
                if ($admin instanceof Response) {
                    return $admin;
                }
                $repo->deprecateVersion($version->id, (int) $admin['id'], (string) $request->input('notes', ''));
            }

            return Response::redirect('/admin/market-server/projects/' . $version->projectId);
        } catch (Throwable $exception) {
            return $this->errorPage('版本操作', $exception, 400);
        }
    }

    public function reviewQueue(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $filters = [
                'status' => (string) $request->input('status', ''),
                'type' => (string) $request->input('type', ''),
                'q' => trim((string) $request->input('q', '')),
            ];
            $evidenceVersionId = (int) $request->input('evidence_version_id', 0);
            $detailVersionId = (int) $request->input('detail_version_id', 0);
            $pagination = $this->repo()->reviewQueuePage($filters, (int) $request->input('page', 1), (int) $request->input('per_page', 20));
            $queue = $pagination['items'];
            $evidencePanel = $evidenceVersionId > 0 ? $this->aiEvidencePanel($evidenceVersionId) : '';
            $detailPanel = $detailVersionId > 0 ? $this->reviewDetailPanel($detailVersionId) : '';
        } catch (Throwable $exception) {
            return $this->errorPage('审核队列', $exception);
        }

        $rows = '';
        foreach ($queue as $item) {
            $aggregate = $this->repo()->aiRiskAggregate((int) $item['id']);
            $rows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape((string) $item['name']) .
                '</td><td>' . View::escape((string) $item['version']) . '</td><td>' . View::escape((string) $item['status']) .
                '</td><td>' . View::escape((string) $item['developer_name']) . '</td><td>' . View::escape($aggregate['risk_level'] . ' / ' . $aggregate['decision_suggestion'] . ' / ' . $aggregate['evidence_count']) .
                '</td><td><a class="button" href="/admin/market-server/review?detail_version_id=' . (int) $item['id'] . '">详情</a> ' . $this->reviewActions((int) $item['id'], (string) $item['status']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无待处理版本</td></tr>';

        $body = '<h1>审核队列</h1><form method="get" action="/admin/market-server/review">' .
            '<label>搜索<input name="q" value="' . View::escape($filters['q']) . '"></label>' .
            '<label>状态<input name="status" value="' . View::escape($filters['status']) . '"></label>' .
            '<label>类型<select name="type"><option value="">全部</option><option value="plugin"' . ($filters['type'] === 'plugin' ? ' selected' : '') . '>Plugin</option><option value="theme"' . ($filters['type'] === 'theme' ? ' selected' : '') . '>Theme</option></select></label>' .
            '<label>AI 证据版本 ID<input name="evidence_version_id" value="' . ($evidenceVersionId > 0 ? $evidenceVersionId : '') . '"></label>' .
            '<label>每页<input name="per_page" value="' . (int) $pagination['per_page'] . '"></label><button type="submit">筛选</button></form>' .
            '<form method="post" action="/admin/market-server/review/batch">' . CsrfToken::field() .
            '<label>版本 ID（逗号分隔）<input name="version_ids" placeholder="12,13,14"></label>' .
            '<label>批量操作<select name="action"><option value="scan">扫描</option><option value="approve">批准</option><option value="return">退回修改</option><option value="reject">拒绝</option><option value="publish">发布</option><option value="suspend">暂停/下架</option><option value="remove">移除/废弃</option></select></label>' .
            '<label>审核备注<textarea name="notes" rows="3"></textarea></label><button type="submit">执行批量操作</button></form>' .
            '<form method="post" action="/admin/market-server/review/scan-queue">' . CsrfToken::field() .
            '<label>扫描数量<input name="limit" value="25"></label><button type="submit">处理扫描队列</button></form>' .
            '<p class="muted">筛选结果：' . (int) $pagination['total'] . ' 条，第 ' . (int) $pagination['page'] . ' / ' . (int) $pagination['pages'] . ' 页</p>' .
            '<table><thead><tr><th>ID</th><th>项目</th><th>版本</th><th>状态</th><th>开发者</th><th>AI 风险/建议/证据</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><a class="button" href="/admin/market-server/review?q=' . rawurlencode($filters['q']) . '&status=' . rawurlencode($filters['status']) . '&type=' . rawurlencode($filters['type']) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . max(1, (int) $pagination['page'] - 1) . '">上一页</a> ' .
            '<a class="button" href="/admin/market-server/review?q=' . rawurlencode($filters['q']) . '&status=' . rawurlencode($filters['status']) . '&type=' . rawurlencode($filters['type']) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . min((int) $pagination['pages'], (int) $pagination['page'] + 1) . '">下一页</a></p>' .
            $detailPanel . $evidencePanel;

        return Response::html(View::page('审核队列', $body));
    }

    public function reviewBatchAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $ids = array_map('intval', preg_split('/[\s,]+/', (string) $request->input('version_ids', ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $this->repo()->batchReview(
                $ids,
                (int) $guard['id'],
                (string) $request->input('action'),
                (string) $request->input('notes', ''),
                new PackageScanner(),
                new PackageSigner((string) $this->settings->get('market.signing_private_key', ''))
            );
        } catch (Throwable $exception) {
            return $this->errorPage('批量审核', $exception, 400);
        }

        return Response::redirect('/admin/market-server/review');
    }

    public function reviewScanQueue(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $result = $this->repo()->processScanQueue(new PackageScanner(), (int) $request->input('limit', 25));
            $query = http_build_query([
                'scan_processed' => (string) $result['processed'],
                'scan_passed' => (string) $result['passed'],
                'scan_failed' => (string) $result['failed'],
            ]);

            return Response::redirect('/admin/market-server/review?' . $query);
        } catch (Throwable $exception) {
            return $this->errorPage('扫描队列', $exception, 400);
        }
    }

    public function reviewAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $repo = $this->repo();
            $action = (string) $request->input('action');
            $versionId = (int) $request->input('version_id');
            if ($action === 'scan') {
                $repo->runScan($versionId, new PackageScanner());
            } elseif ($action === 'ai_review') {
                (new AiReviewOrchestrator(new MockAiReviewClient(), new MockAiReviewClient()))->review($repo, $versionId, new PackageScanner());
            } elseif (in_array($action, ['approve', 'reject', 'return'], true)) {
                $repo->recordReview($versionId, (int) $guard['id'], $action, (string) $request->input('notes', ''));
            } elseif ($action === 'publish') {
                $repo->publishVersion($versionId, $repo->signatureForVersion($versionId, new PackageSigner((string) $this->settings->get('market.signing_private_key', ''))));
            } elseif ($action === 'suspend') {
                $repo->unpublishVersion($versionId, (int) $guard['id'], (string) $request->input('notes', ''));
            } elseif ($action === 'remove') {
                $repo->deprecateVersion($versionId, (int) $guard['id'], (string) $request->input('notes', ''));
            } else {
                throw new MarketServerException('Unsupported review action.');
            }
        } catch (Throwable $exception) {
            return $this->errorPage('审核队列', $exception, 400);
        }

        return Response::redirect('/admin/market-server/review');
    }

    private function reviewActions(int $versionId, string $status): string
    {
        $forms = [];
        if ($status === ReviewState::SUBMITTED) {
            $forms[] = $this->actionForm($versionId, 'scan', '扫描') . $this->actionForm($versionId, 'ai_review', 'AI 审核');
        }
        if (in_array($status, [ReviewState::NEEDS_REVIEW, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_SECURITY_REVIEW], true)) {
            $forms[] = $this->actionForm($versionId, 'ai_review', 'AI 重试');
            $forms[] = $this->actionForm($versionId, 'approve', '批准') . $this->actionForm($versionId, 'return', '退回修改') . $this->actionForm($versionId, 'reject', '拒绝');
        }
        if (in_array($status, [ReviewState::AI_REVIEW_FAILED, ReviewState::CODEX_REVIEW_FAILED, ReviewState::NEEDS_FIX], true)) {
            $forms[] = $this->actionForm($versionId, 'ai_review', 'AI 重试') . $this->actionForm($versionId, 'reject', '拒绝');
        }
        if ($status === ReviewState::APPROVED) {
            $forms[] = $this->actionForm($versionId, 'publish', '发布') . $this->actionForm($versionId, 'reject', '拒绝');
        }
        if ($status === ReviewState::PUBLISHED) {
            $forms[] = $this->actionForm($versionId, 'suspend', '暂停/下架') . $this->actionForm($versionId, 'remove', '移除/废弃');
        }

        return implode(' ', $forms);
    }

    private function reviewDetailPanel(int $versionId): string
    {
        $repo = $this->repo();
        $version = $repo->version($versionId);
        $project = $repo->project($version->projectId);
        $manifest = $this->zipJson($version->packagePath, 'market-package.json');
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $findings = $repo->latestScanFindings($versionId);
        $aggregate = $repo->aiRiskAggregate($versionId);
        $reviews = array_values(array_filter($repo->reviewsForProject($version->projectId), static fn (array $review): bool => (int) ($review['version_id'] ?? 0) === $versionId));
        $diffRows = $this->packageDiffRows($version, $files);

        $findingRows = '';
        foreach ($findings as $finding) {
            $findingRows .= '<tr><td>' . View::escape((string) ($finding['severity'] ?? '')) . '</td><td>' . View::escape((string) ($finding['code'] ?? '')) .
                '</td><td>' . View::escape((string) ($finding['path'] ?? '')) . '</td><td>' . View::escape((string) ($finding['message'] ?? '')) . '</td></tr>';
        }
        $findingRows = $findingRows !== '' ? $findingRows : '<tr><td colspan="4" class="muted">未发现扫描风险</td></tr>';

        $reviewRows = '';
        foreach ($reviews as $review) {
            $reviewRows .= '<tr><td>' . View::escape((string) $review['decision']) . '</td><td>' . View::escape((string) $review['notes']) . '</td><td>' . View::escape((string) $review['created_at']) . '</td></tr>';
        }
        $reviewRows = $reviewRows !== '' ? $reviewRows : '<tr><td colspan="3" class="muted">暂无人工审核记录</td></tr>';

        $fileRows = '';
        foreach (array_slice($files, 0, 30, true) as $path => $hash) {
            $fileRows .= '<tr><td>' . View::escape((string) $path) . '</td><td><code>' . View::escape((string) $hash) . '</code></td></tr>';
        }
        $fileRows = $fileRows !== '' ? $fileRows : '<tr><td colspan="2" class="muted">Manifest 未声明文件</td></tr>';

        return '<section><h2>审核详情</h2><p class="muted">项目：' . View::escape($project->marketId) . '；版本：' . View::escape($version->version) . '；状态：' . View::escape($version->status) . '</p>' .
            '<h3>包 Manifest</h3><table><tbody><tr><th>扩展 ID</th><td>' . View::escape((string) ($manifest['extension_id'] ?? '')) . '</td></tr><tr><th>类型</th><td>' . View::escape((string) ($manifest['type'] ?? '')) . '</td></tr><tr><th>文件数</th><td>' . count($files) . '</td></tr><tr><th>提交 SHA-256</th><td><code>' . View::escape($version->packageSha256) . '</code></td></tr></tbody></table>' .
            '<h3>Manifest 文件哈希</h3><table><thead><tr><th>路径</th><th>SHA-256</th></tr></thead><tbody>' . $fileRows . '</tbody></table>' .
            '<h3>扫描报告</h3><table><thead><tr><th>级别</th><th>代码</th><th>路径</th><th>说明</th></tr></thead><tbody>' . $findingRows . '</tbody></table>' .
            '<h3>沙箱报告</h3><p class="muted">静态沙箱扫描完成；审核后台不会执行扩展 PHP。高风险调用、受保护路径、嵌套 ZIP 和二进制文件会进入扫描报告。</p>' .
            '<h3>版本差异</h3><table><thead><tr><th>状态</th><th>路径</th></tr></thead><tbody>' . $diffRows . '</tbody></table>' .
            '<h3>审核历史</h3><table><thead><tr><th>决定</th><th>备注</th><th>时间</th></tr></thead><tbody>' . $reviewRows . '</tbody></table>' .
            '<h3>风险标记</h3><p class="muted">AI 风险/建议/证据：' . View::escape($aggregate['risk_level'] . ' / ' . $aggregate['decision_suggestion'] . ' / ' . $aggregate['evidence_count']) . '</p></section>';
    }

    /** @return array<string,mixed> */
    private function zipJson(string $zipPath, string $entry): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        $json = $zip->getFromName($entry);
        $zip->close();
        $decoded = json_decode(is_string($json) ? $json : '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,string> $files */
    private function packageDiffRows(MarketVersion $version, array $files): string
    {
        $previous = null;
        foreach ($this->repo()->versionsForProject($version->projectId) as $candidate) {
            if ((int) ($candidate['id'] ?? 0) < $version->id) {
                $previous = $candidate;
                break;
            }
        }
        if (!is_array($previous)) {
            return '<tr><td colspan="2" class="muted">首个版本，没有可比较的上一版</td></tr>';
        }

        $previousManifest = $this->zipJson((string) $previous['package_path'], 'market-package.json');
        $previousFiles = is_array($previousManifest['files'] ?? null) ? $previousManifest['files'] : [];
        $paths = array_unique(array_merge(array_keys($previousFiles), array_keys($files)));
        sort($paths);
        $rows = '';
        foreach ($paths as $path) {
            $old = (string) ($previousFiles[$path] ?? '');
            $new = (string) ($files[$path] ?? '');
            if ($old === $new) {
                continue;
            }
            $status = $old === '' ? '新增' : ($new === '' ? '删除' : '修改');
            $rows .= '<tr><td>' . View::escape($status) . '</td><td>' . View::escape((string) $path) . '</td></tr>';
        }

        return $rows !== '' ? $rows : '<tr><td colspan="2" class="muted">与上一版相比没有文件差异</td></tr>';
    }

    private function aiEvidencePanel(int $versionId): string
    {
        $repo = $this->repo();
        $tasks = $repo->aiReviewTasks($versionId);
        $evidence = $repo->aiReviewEvidence($versionId);
        $aggregate = $repo->aiRiskAggregate($versionId);
        $taskRows = '';
        foreach ($tasks as $task) {
            $taskRows .= '<tr><td>' . (int) $task['id'] . '</td><td>' . View::escape((string) $task['review_type']) . '</td><td>' . View::escape((string) $task['status']) .
                '</td><td>' . View::escape((string) $task['provider']) . '</td><td>' . View::escape((string) $task['model']) . '</td><td>' . View::escape((string) $task['request_id']) . '</td></tr>';
        }
        $taskRows = $taskRows !== '' ? $taskRows : '<tr><td colspan="6" class="muted">暂无 AI 任务</td></tr>';
        $evidenceRows = '';
        foreach ($evidence as $item) {
            $focus = json_decode((string) ($item['manual_review_focus_json'] ?? '[]'), true);
            $evidenceRows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape((string) $item['review_type']) . '</td><td>' . View::escape((string) $item['risk_level']) .
                '</td><td>' . View::escape((string) $item['decision_suggestion']) . '</td><td>' . View::escape(is_array($focus) ? implode('；', array_map('strval', $focus)) : '') .
                '</td><td><pre>' . View::escape((string) $item['output_json']) . '</pre></td></tr>';
        }
        $evidenceRows = $evidenceRows !== '' ? $evidenceRows : '<tr><td colspan="6" class="muted">暂无 AI 证据</td></tr>';

        return '<h2>AI 审核证据</h2><p class="muted">版本 #' . $versionId . '；综合风险：' . View::escape($aggregate['risk_level']) . '；建议：' . View::escape($aggregate['decision_suggestion']) . '；证据数：' . (int) $aggregate['evidence_count'] . '</p>' .
            '<h3>任务</h3><table><thead><tr><th>ID</th><th>类型</th><th>状态</th><th>Provider</th><th>Model</th><th>Request ID</th></tr></thead><tbody>' . $taskRows . '</tbody></table>' .
            '<h3>证据</h3><table><thead><tr><th>ID</th><th>类型</th><th>风险</th><th>建议</th><th>人工复核重点</th><th>输出 JSON</th></tr></thead><tbody>' . $evidenceRows . '</tbody></table>';
    }

    private function versionActions(int $versionId, string $status): string
    {
        if ($status === ReviewState::PUBLISHED) {
            return $this->versionActionForm($versionId, 'deprecate', '废弃') . ' ' . $this->versionActionForm($versionId, 'unpublish', '下架');
        }
        if (in_array($status, [ReviewState::SUBMITTED, ReviewState::SCANNING, ReviewState::NEEDS_REVIEW, ReviewState::APPROVED], true)) {
            return $this->versionActionForm($versionId, 'withdraw', '撤回');
        }

        return '';
    }

    private function versionActionForm(int $versionId, string $action, string $label): string
    {
        return '<form method="post" action="/admin/market-server/versions/action">' . CsrfToken::field() .
            '<input type="hidden" name="version_id" value="' . $versionId . '">' .
            '<input type="hidden" name="action" value="' . View::escape($action) . '">' .
            '<button type="submit">' . View::escape($label) . '</button></form>';
    }

    private function memberRoleForm(int $projectId, int $memberId, string $role): string
    {
        $options = '';
        foreach (['Owner', 'Maintainer', 'Developer', 'Viewer'] as $candidate) {
            $options .= '<option value="' . $candidate . '"' . ($role === $candidate ? ' selected' : '') . '>' . $candidate . '</option>';
        }

        return '<form method="post" action="/admin/market-server/projects/member-action">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $projectId . '">' .
            '<input type="hidden" name="member_id" value="' . $memberId . '">' .
            '<input type="hidden" name="action" value="update-role">' .
            '<select name="role">' . $options . '</select><button type="submit">更新</button></form>';
    }

    private function memberRemoveForm(int $projectId, int $memberId, string $role): string
    {
        if ($role === 'Owner') {
            return '';
        }

        return '<form method="post" action="/admin/market-server/projects/member-action">' . CsrfToken::field() .
            '<input type="hidden" name="project_id" value="' . $projectId . '">' .
            '<input type="hidden" name="member_id" value="' . $memberId . '">' .
            '<input type="hidden" name="action" value="remove"><button type="submit">移除</button></form>';
    }

    private function settlementActionForm(int $settlementId, string $status): string
    {
        if ($status === 'Paid') {
            return '';
        }
        $forms = [];
        foreach (['Processing' => '处理中', 'Paid' => '已打款', 'Failed' => '失败'] as $target => $label) {
            $forms[] = '<form method="post" action="/admin/market-server/settlements/action">' . CsrfToken::field() .
                '<input type="hidden" name="settlement_id" value="' . $settlementId . '">' .
                '<input type="hidden" name="status" value="' . $target . '">' .
                '<button type="submit">' . $label . '</button></form>';
        }

        return implode(' ', $forms);
    }

    private function licenseActionForms(string $licenseKey): string
    {
        return '<form method="post" action="/admin/market-server/licenses/action">' . CsrfToken::field() .
            '<input type="hidden" name="license_key" value="' . View::escape($licenseKey) . '">' .
            '<input type="hidden" name="action" value="renew">' .
            '<input name="expires_at" placeholder="2028-01-01T00:00:00+00:00">' .
            '<input name="seats" value="0"><button type="submit">续费</button></form> ' .
            '<form method="post" action="/admin/market-server/licenses/action">' . CsrfToken::field() .
            '<input type="hidden" name="license_key" value="' . View::escape($licenseKey) . '">' .
            '<input type="hidden" name="action" value="change-plan">' .
            '<input name="plan_key" placeholder="pro">' .
            '<input name="seats" value="0"><button type="submit">改套餐</button></form> ' .
            '<form method="post" action="/admin/market-server/licenses/action">' . CsrfToken::field() .
            '<input type="hidden" name="license_key" value="' . View::escape($licenseKey) . '">' .
            '<input type="hidden" name="action" value="auto-renew-on"><button type="submit">开自动续费</button></form> ' .
            '<form method="post" action="/admin/market-server/licenses/action">' . CsrfToken::field() .
            '<input type="hidden" name="license_key" value="' . View::escape($licenseKey) . '">' .
            '<input type="hidden" name="action" value="auto-renew-off"><button type="submit">关自动续费</button></form> ' .
            '<form method="post" action="/admin/market-server/licenses/action">' . CsrfToken::field() .
            '<input type="hidden" name="license_key" value="' . View::escape($licenseKey) . '">' .
            '<input type="hidden" name="action" value="revoke"><button type="submit">吊销</button></form>';
    }

    private function subscriptionActionForms(string $subscriptionId): string
    {
        return '<form method="post" action="/admin/market-server/subscriptions/action">' . CsrfToken::field() .
            '<input type="hidden" name="subscription_id" value="' . View::escape($subscriptionId) . '">' .
            '<input type="hidden" name="action" value="cancel"><button type="submit">取消</button></form> ' .
            '<form method="post" action="/admin/market-server/subscriptions/action">' . CsrfToken::field() .
            '<input type="hidden" name="subscription_id" value="' . View::escape($subscriptionId) . '">' .
            '<input type="hidden" name="action" value="restore"><button type="submit">恢复</button></form>';
    }

    private function webhookDeadLetterActionForm(int $deadLetterId): string
    {
        return '<form method="post" action="/admin/market-server/webhook-dead-letters/action">' . CsrfToken::field() .
            '<input type="hidden" name="dead_letter_id" value="' . $deadLetterId . '">' .
            '<button type="submit">重试</button></form>';
    }

    private function portalTokenActionForm(int $tokenId): string
    {
        return '<form method="post" action="/admin/market-server/portal-tokens/action">' . CsrfToken::field() .
            '<input type="hidden" name="token_id" value="' . $tokenId . '">' .
            '<button type="submit">撤销</button></form>';
    }

    private function taxRuleActionForms(int $ruleId, string $status): string
    {
        $target = $status === 'Active' ? 'Disabled' : 'Active';
        $label = $target === 'Active' ? '启用' : '禁用';

        return '<form method="post" action="/admin/market-server/tax-rules/action">' . CsrfToken::field() .
            '<input type="hidden" name="rule_id" value="' . $ruleId . '">' .
            '<input type="hidden" name="status" value="' . $target . '">' .
            '<button type="submit">' . $label . '</button></form>';
    }

    private function actionForm(int $versionId, string $action, string $label): string
    {
        return '<form method="post" action="/admin/market-server/review/action">' . CsrfToken::field() .
            '<input type="hidden" name="version_id" value="' . $versionId . '">' .
            '<input type="hidden" name="action" value="' . View::escape($action) . '">' .
            '<button type="submit">' . View::escape($label) . '</button></form>';
    }

    private function pathId(string $path): int
    {
        $parts = explode('/', trim($path, '/'));
        $id = (int) end($parts);
        if ($id <= 0) {
            throw new MarketServerException('Invalid market project id.');
        }

        return $id;
    }

    private function repo(): MarketServerRepository
    {
        return new MarketServerRepository(ConnectionFactory::make($this->settings));
    }

    private function objectStorage(): LocalObjectStorageAdapter|RemoteObjectStorageAdapter
    {
        $root = dirname(__DIR__, 3);
        $adapter = (new ObjectStorageAdapterFactory($this->settings, $root))->make();
        if (!$adapter instanceof LocalObjectStorageAdapter && !$adapter instanceof RemoteObjectStorageAdapter) {
            throw new MarketException('Configured object storage adapter cannot be used by this console without an SDK client.');
        }

        return $adapter;
    }

    private function objectKey(string $key): string
    {
        $key = ltrim(trim($key), '/');
        if ($key === '' || str_contains($key, '..') || !preg_match('/^[A-Za-z0-9._\/-]+$/', $key)) {
            throw new MarketException('Invalid object key.');
        }

        return $key;
    }

    private function packagePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new MarketServerException('Submitted package path is required.');
        }
        if (!str_starts_with($path, '/')) {
            $path = $this->rootPath . '/' . ltrim($path, '/');
        }

        return $path;
    }

    private function suggestedExtensionId(string $marketId): string
    {
        $candidate = str_contains($marketId, ':') ? substr($marketId, (int) strrpos($marketId, ':') + 1) : $marketId;
        $candidate = preg_replace('/[^A-Za-z0-9._-]/', '_', strtolower((string) $candidate)) ?: '';

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function webhookPayload(Request $request): array
    {
        return [
            'event_id' => (string) $request->input('event_id'),
            'project_id' => (int) $request->input('project_id'),
            'object_key' => (string) $request->input('object_key'),
            'package_path' => (string) $request->input('package_path'),
            'sha256' => (string) $request->input('sha256'),
            'byte_size' => (int) $request->input('byte_size', 0),
        ];
    }

    /** @return array<string, mixed> */
    private function paymentWebhookPayload(Request $request): array
    {
        return [
            'id' => (string) $request->input('id', ''),
            'event_id' => (string) $request->input('event_id'),
            'payment_id' => (int) $request->input('payment_id', 0),
            'market_id' => (string) $request->input('market_id'),
            'site_id' => (string) $request->input('site_id'),
            'amount_cents' => (int) $request->input('amount_cents', 0),
            'currency' => (string) $request->input('currency', 'USD'),
            'provider' => (string) $request->input('provider', 'webhook'),
            'provider_reference' => (string) $request->input('provider_reference', ''),
            'status' => (string) $request->input('status', 'pending'),
            'data' => $request->input('data', []),
        ];
    }

    /** @return array{id:int,email:string,display_name:string}|array{id:int,developer_key:string,email:string,display_name:string}|Response */
    private function requireAdminOrDeveloper(int $projectId): array|Response
    {
        $admin = $this->requireAdmin();
        if (is_array($admin)) {
            return $admin;
        }
        $developerKey = (string) ($_SESSION['market_developer_key'] ?? '');
        if ($developerKey === '') {
            return $admin;
        }
        try {
            $repo = $this->repo();
            $repo->assertProjectDeveloper($projectId, $developerKey);
            $developer = $repo->developerByKey($developerKey);

            return ['id' => $developer->id, 'developer_key' => $developer->developerKey, 'email' => $developer->email, 'display_name' => $developer->displayName];
        } catch (Throwable) {
            return Response::redirect('/developer/market/login');
        }
    }

    /** @return array{id:int,email:string,display_name:string}|Response */
    private function requireAdmin(): array|Response
    {
        try {
            $user = (new AdminAuthenticator(ConnectionFactory::make($this->settings)))->user();
        } catch (Throwable) {
            return Response::redirect('/admin/login');
        }

        return $user === null || $user['id'] <= 0 ? Response::redirect('/admin/login') : $user;
    }

    private function errorPage(string $title, Throwable $exception, int $status = 500): Response
    {
        return Response::html(View::page($title, '<h1>' . View::escape($title) . '</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), $status);
    }
}

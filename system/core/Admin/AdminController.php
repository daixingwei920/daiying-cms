<?php

declare(strict_types=1);

namespace Cms\Core\Admin;

use Cms\Core\Advertising\AdRenderer;
use Cms\Core\Advertising\AdRepository;
use Cms\Core\CardDelivery\CardDeliveryException;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\CardDelivery\CardDeliveryService;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Auth\AdminAccountRecoveryService;
use Cms\Core\Auth\AdminMfaService;
use Cms\Core\Auth\AdminPasskeyService;
use Cms\Core\Auth\AdminSessionService;
use Cms\Core\Audit\AuditLogger;
use Cms\Core\Comment\CommentRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Export\OfficialExportContentImporter;
use Cms\Core\ExternalMigration\ExternalMigrationService;
use Cms\Core\ExternalMigration\DaiyingMigrationPackageBuilder;
use Cms\Core\ExternalMigration\MigrationException;
use Cms\Core\ExternalMigration\MigrationRepository;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Import\ImportException;
use Cms\Core\Import\ImportService;
use Cms\Core\Import\WordPressImporter;
use Cms\Core\Import\ZBlogImporter;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Market\HttpMarketClient;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\CommercialLicenseStore;
use Cms\Core\Market\OfflineMarketClient;
use Cms\Core\Market\MarketApiClientInterface;
use Cms\Core\Market\MarketInstallRepository;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Market\RemotePackageDownloader;
use Cms\Core\Market\ReviewSubmissionClient;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Navigation\NavigationBuilder;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Support\CurrencyRegistry;
use Cms\Core\Support\Money;
use Cms\Core\Support\SystemHealthDoctor;
use Cms\Core\Support\SystemHealthService;
use Cms\Core\Support\View;
use Cms\Core\SiteVault\SiteVaultService;
use Cms\Core\Theme\LocalThemePackageInstaller;
use Cms\Core\Theme\ThemeManager;
use Cms\Core\Timeline\SiteTimelineService;
use Cms\Core\UrlMapping\UrlMappingRepository;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateServerClient;
use Cms\Core\Update\UpdateService;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Support\AdminUiText;
use Cms\Core\Payment\PaymentAttemptDiagnosticsRepository;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentEntitlementService;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderConfigurationInterface;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use PDO;
use Throwable;

final class AdminController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly FileLogger $logger,
        private readonly ?string $rootPath = null,
    ) {
    }

    public function loginForm(): Response
    {
        return Response::html(View::page('管理员登录', $this->loginHtml()));
    }

    public function login(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('管理员登录', $this->loginHtml('CSRF 校验失败，请刷新页面重试。')), 400);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $auth = new AdminAuthenticator($pdo);
            $email = trim((string) $request->input('email', ''));
            $ip = (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0');

            $user = $auth->verifyCredentials($email, (string) $request->input('password', ''), $ip);
            if ($user === null) {
                return Response::html(View::page('管理员登录', $this->loginHtml('登录失败，或尝试次数过多。')), 401);
            }

            $mfa = new AdminMfaService($pdo);
            $passkeys = new AdminPasskeyService($pdo, $this->settings);
            if ($mfa->isEnabled((int) $user['id']) || $passkeys->hasPasskey((int) $user['id'])) {
                $_SESSION['admin_mfa_pending'] = [
                    'id' => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'display_name' => (string) $user['display_name'],
                    'ip' => $ip,
                    'issued_at' => time(),
                ];
                (new AuditLogger($pdo))->record('admin', (int) $user['id'], 'admin.login_mfa_challenge', ['email' => $email, 'ip' => $ip]);

                return Response::redirect('/admin/mfa');
            }

            $auth->loginUser($user);
            (new AdminSessionService($pdo))->recordLogin((int) ($user['id'] ?? 0), $ip, (string) ($request->server['HTTP_USER_AGENT'] ?? ''), 'password');
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'admin.login', ['email' => $email, 'ip' => $ip, 'mfa' => false]);
        } catch (Throwable $exception) {
            $this->logger->error('Admin login failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('管理员登录', $this->loginHtml('登录服务暂不可用。')), 500);
        }

        return Response::redirect('/admin');
    }

    public function forgotPasswordForm(): Response
    {
        return Response::html(View::page('找回管理员密码', $this->forgotPasswordHtml()));
    }

    public function forgotPassword(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('找回管理员密码', $this->forgotPasswordHtml('CSRF 校验失败，请刷新页面重试。')), 403);
        }

        $notice = '如果该邮箱属于管理员账号，系统会发送一封密码重置邮件。';
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $email = trim((string) $request->input('email', ''));
            $token = (new AdminAccountRecoveryService($pdo))->requestReset($email, (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0'));
            if ($token !== null) {
                $this->sendPasswordResetMail($email, $token);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Admin password reset request failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::html(View::page('找回管理员密码', $this->forgotPasswordHtml('', $notice)));
    }

    public function resetPasswordForm(Request $request): Response
    {
        return Response::html(View::page('重置管理员密码', $this->resetPasswordHtml((string) ($request->query['token'] ?? ''))));
    }

    public function resetPassword(Request $request): Response
    {
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('重置管理员密码', $this->resetPasswordHtml((string) $request->input('token', ''), 'CSRF 校验失败，请刷新页面重试。')), 403);
        }
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirm', '');
        if ($password !== $confirm || strlen($password) < 10) {
            return Response::html(View::page('重置管理员密码', $this->resetPasswordHtml((string) $request->input('token', ''), '两次密码不一致，或密码长度不足 10 位。')), 422);
        }
        try {
            $ok = (new AdminAccountRecoveryService(ConnectionFactory::make($this->settings)))->resetPassword((string) $request->input('token', ''), $password, (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0'));
            if (!$ok) {
                return Response::html(View::page('重置管理员密码', $this->resetPasswordHtml('', '重置链接无效、已过期或已使用。')), 400);
            }
            unset($_SESSION['admin_user'], $_SESSION['admin_mfa_pending'], $_SESSION['admin_reauthenticated_at']);
            SessionManager::regenerate();
            return Response::html(View::page('重置管理员密码', '<h1>重置管理员密码</h1><p class="admin-badge admin-badge-success">密码已重置。原有后台会话已失效，请重新登录并完成 MFA。</p><p><a class="button" href="/admin/login">返回登录</a></p>'));
        } catch (Throwable $exception) {
            $this->logger->error('Admin password reset failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('重置管理员密码', $this->resetPasswordHtml('', '密码重置服务暂不可用。')), 500);
        }
    }

    public function mfaChallengeForm(): Response
    {
        $pending = $this->mfaPendingUser();
        if ($pending === null) {
            return Response::redirect('/admin/login');
        }

        return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml()));
    }

    public function mfaChallenge(Request $request): Response
    {
        $pending = $this->mfaPendingUser();
        if ($pending === null) {
            return Response::redirect('/admin/login');
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml('二次验证必须通过 POST 请求提交。')), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml('CSRF 校验失败，请刷新页面重试。')), 403);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $mfa = new AdminMfaService($pdo);
            $adminId = (int) $pending['id'];
            if (!$mfa->verifyChallenge($adminId, (string) $request->input('mfa_code', ''))) {
                (new AuditLogger($pdo))->record('admin', $adminId, 'admin.login_mfa_failed', ['email' => (string) $pending['email'], 'ip' => (string) $pending['ip']]);
                return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml('二次验证码无效，请重新输入。')), 401);
            }
            $user = [
                'id' => $adminId,
                'email' => (string) $pending['email'],
                'display_name' => (string) $pending['display_name'],
            ];
            (new AdminAuthenticator($pdo))->loginUser($user);
            (new AdminSessionService($pdo))->recordLogin($adminId, (string) $pending['ip'], (string) ($request->server['HTTP_USER_AGENT'] ?? ''), 'totp_or_recovery');
            (new AuditLogger($pdo))->record('admin', $adminId, 'admin.login', ['email' => (string) $pending['email'], 'ip' => (string) $pending['ip'], 'mfa' => true]);

            return Response::redirect('/admin');
        } catch (Throwable $exception) {
            $this->logger->error('Admin MFA challenge failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml('二次验证服务暂不可用。')), 500);
        }
    }

    public function mfaPasskeyOptions(): Response
    {
        $pending = $this->mfaPendingUser();
        if ($pending === null) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }
        if (!CsrfToken::verify($_POST['_csrf'] ?? null)) {
            return Response::json(['error' => 'csrf'], 403);
        }
        try {
            return Response::json((new AdminPasskeyService(ConnectionFactory::make($this->settings), $this->settings))->authenticationOptions((int) $pending['id']));
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function mfaPasskeyVerify(Request $request): Response
    {
        $pending = $this->mfaPendingUser();
        if ($pending === null) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['error' => 'csrf'], 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            if (!(new AdminPasskeyService($pdo, $this->settings))->verifyAuthentication((int) $pending['id'], $request->body)) {
                (new AuditLogger($pdo))->record('admin', (int) $pending['id'], 'admin.login_passkey_failed', ['email' => (string) $pending['email'], 'ip' => (string) $pending['ip']]);
                return Response::json(['error' => 'passkey_failed'], 401);
            }
            $user = [
                'id' => (int) $pending['id'],
                'email' => (string) $pending['email'],
                'display_name' => (string) $pending['display_name'],
            ];
            (new AdminAuthenticator($pdo))->loginUser($user);
            (new AdminSessionService($pdo))->recordLogin((int) $pending['id'], (string) $pending['ip'], (string) ($request->server['HTTP_USER_AGENT'] ?? ''), 'passkey');
            (new AuditLogger($pdo))->record('admin', (int) $pending['id'], 'admin.login', ['email' => (string) $pending['email'], 'ip' => (string) $pending['ip'], 'mfa' => 'passkey']);

            return Response::json(['ok' => true, 'redirect' => '/admin']);
        } catch (Throwable $exception) {
            $this->logger->error('Admin passkey challenge failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::json(['error' => 'passkey_service_unavailable'], 500);
        }
    }

    public function logout(): Response
    {
        if (!CsrfToken::verify($_POST['_csrf'] ?? null)) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $auth = new AdminAuthenticator($pdo);
            $user = $auth->user();
            if ($user !== null) {
                (new AdminSessionService($pdo))->revokeCurrent((int) $user['id']);
                (new AuditLogger($pdo))->record('admin', $user['id'], 'admin.logout');
            }
            $auth->logout();
        } catch (Throwable $exception) {
            $this->logger->error('Admin logout failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::redirect('/admin/login');
    }

    public function dashboard(): Response
    {
        try {
            $auth = new AdminAuthenticator(ConnectionFactory::make($this->settings));
            $user = $auth->user();
        } catch (Throwable $exception) {
            $this->logger->error('Admin dashboard failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/login');
        }

        if ($user === null || $user['id'] <= 0) {
            return Response::redirect('/admin/login');
        }

        $quickLinks = [
            '<a class="button" href="/admin/content/new">新建内容</a>',
            '<a class="button admin-button-secondary" href="/admin/media">上传媒体</a>',
            '<a class="button admin-button-secondary" href="/admin/ads">广告统计</a>',
            '<a class="button admin-button-secondary" href="/admin/card-delivery">发卡管理</a>',
            '<a class="button admin-button-secondary" href="/admin/payments/providers">支付 Provider</a>',
            '<a class="button admin-button-secondary" href="/admin/recovery">恢复诊断</a>',
        ];
        $systemLinks = [
            '<a class="button admin-button-secondary" href="/admin/settings">站点设置</a>',
            '<a class="button admin-button-secondary" href="/admin/security">后台安全</a>',
            '<a class="button admin-button-secondary" href="/admin/themes">主题</a>',
            '<a class="button admin-button-secondary" href="/admin/plugins">插件</a>',
            '<a class="button admin-button-secondary" href="/admin/transfer">导入导出</a>',
            '<a class="button admin-button-secondary" href="/admin/update">系统更新</a>',
            '<a class="button admin-button-secondary" href="/admin/system-health">系统健康</a>',
            '<a class="button admin-button-secondary" href="/admin/health-doctor">网站医生</a>',
            '<a class="button admin-button-secondary" href="/admin/site-vault">网站保险箱</a>',
            '<a class="button admin-button-secondary" href="/admin/site-timeline">站点时光机</a>',
        ];
        if ((bool) $this->settings->get('market.enabled', false)) {
            $marketLinks = [
                '<a class="button admin-button-secondary" href="/admin/market/plugins">插件市场</a>',
                '<a class="button admin-button-secondary" href="/admin/market/themes">主题市场</a>',
            ];
            if ((bool) $this->settings->get('market.developer_mode', false)) {
                $marketLinks[] = '<a class="button admin-button-secondary" href="/admin/market/developer-submit">开发者中心</a>';
            }
            array_push($systemLinks, ...$marketLinks);
        }

        $metrics = [];
        $recentRows = '';
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $metrics = [
                ['内容', $this->adminTableCount($pdo, 'cms_contents'), '文章与页面'],
                ['评论', $this->adminTableCount($pdo, 'cms_comments', "status = 'pending'"), '待审核'],
                ['媒体', $this->adminTableCount($pdo, 'cms_media', "status <> 'Deleted'"), '可用文件'],
                ['插件', $this->adminTableCount($pdo, 'cms_plugins'), '已发现扩展'],
                ['支付', $this->adminTableCount($pdo, 'cms_payments'), 'Core 记录'],
            ];
            foreach ((new ContentRepository($pdo, ContentTypeRegistry::defaults()))->latest(6) as $item) {
                $recentRows .= '<tr><td>' . View::escape((string) $item['title']) . '</td><td>' . View::escape(AdminUiText::contentType((string) $item['content_type'])) . '</td><td>' . View::escape(AdminUiText::contentStatus((string) $item['status'])) . '</td><td><a class="button admin-button-secondary" href="/admin/content/edit/' . (int) $item['id'] . '">编辑</a></td></tr>';
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Admin dashboard metrics unavailable', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $metrics = [
                ['内容', 0, '文章与页面'],
                ['评论', 0, '待审核'],
                ['媒体', 0, '可用文件'],
                ['插件', 0, '已发现扩展'],
                ['支付', 0, 'Core 记录'],
            ];
        }

        $metricHtml = '';
        foreach ($metrics as [$label, $value, $caption]) {
            $metricHtml .= '<section class="admin-dashboard-card"><span class="muted">' . View::escape((string) $label) . '</span><strong>' . (int) $value . '</strong><p class="muted">' . View::escape((string) $caption) . '</p></section>';
        }
        $recentRows = $recentRows !== '' ? $recentRows : '<tr><td colspan="4" class="muted">暂无最近内容。</td></tr>';

        $body = '<div class="admin-page-header"><div><h1>管理后台</h1><p class="muted">当前登录：' . View::escape($user['display_name']) . '</p></div><div class="admin-action-row">' . implode(' ', $quickLinks) . '</div></div>' .
            '<div class="admin-dashboard-grid">' . $metricHtml . '</div>' .
            '<div class="admin-dashboard-columns"><section class="admin-card"><h2>最近内容</h2><table><thead><tr><th>标题</th><th>类型</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $recentRows . '</tbody></table></section>' .
            '<section class="admin-card"><h2>系统入口</h2><p class="muted">常用设置、扩展、更新和恢复入口集中在这里。</p><div class="admin-action-row">' . implode(' ', $systemLinks) . '</div>' .
            '<h3>运行状态</h3><p><span class="admin-badge admin-badge-success">后台可用</span> <span class="admin-badge admin-badge-muted">PHP ' . View::escape(PHP_VERSION) . '</span></p>' .
            '<form method="post" action="/admin/logout">' . CsrfToken::field() . '<button class="admin-button-secondary" type="submit">退出登录</button></form></section></div>';

        return Response::html(View::page('管理后台', $body));
    }

    private function adminTableCount(PDO $pdo, string $table, string $where = ''): int
    {
        if (!preg_match('/\A[a-z0-9_]+\z/i', $table)) {
            return 0;
        }
        try {
            $sql = 'SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : '');
            return max(0, (int) $pdo->query($sql)->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    public function commentIndex(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = new CommentRepository(ConnectionFactory::make($this->settings));
            $status = (string) ($request->query['status'] ?? 'pending');
            if (!in_array($status, ['pending', 'approved', 'spam', 'trash', 'all'], true)) {
                $status = 'pending';
            }
            return Response::html(View::page('评论管理', $this->commentIndexHtml($repo, $status)));
        } catch (Throwable $exception) {
            $this->logger->error('Comment admin failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('评论管理', '<h1>评论管理</h1><p class="error">评论管理暂不可用。</p>'), 500);
        }
    }

    public function commentAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::text('评论操作必须通过 POST 请求提交。', 405)->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('请求校验失败，请刷新页面后重试。', 403);
        }

        try {
            $repo = new CommentRepository(ConnectionFactory::make($this->settings));
            $id = (int) $request->input('id', 0);
            $action = (string) $request->input('action', '');
            if ($action === 'delete') {
                $repo->delete($id);
            } elseif (in_array($action, ['pending', 'approved', 'spam', 'trash'], true)) {
                $repo->setStatus($id, $action);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Comment admin action failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::redirect('/admin/comments?status=' . rawurlencode((string) $request->input('return_status', 'pending')));
    }

    private function commentIndexHtml(CommentRepository $repo, string $status): string
    {
        $tabs = '';
        foreach (['pending' => '待审核', 'approved' => '已通过', 'spam' => '垃圾', 'trash' => '回收站', 'all' => '全部'] as $value => $label) {
            $class = $status === $value ? ' admin-badge admin-badge-success' : ' admin-badge admin-badge-muted';
            $tabs .= '<a class="' . trim($class) . '" href="/admin/comments?status=' . $value . '">' . $label . ' ' . ($value === 'all' ? $repo->count() : $repo->count($value)) . '</a> ';
        }

        $rows = '';
        foreach ($repo->adminList($status === 'all' ? '' : $status) as $comment) {
            $contentType = (string) ($comment['content_type'] ?? 'article');
            $slug = (string) ($comment['content_slug'] ?? '');
            $url = $slug !== '' ? (($contentType === 'article' ? '/articles/' : '/') . rawurlencode($slug)) : '#';
            $rows .= '<tr><td><strong>' . View::escape((string) $comment['author_name']) . '</strong><br><span class="muted">' . View::escape((string) ($comment['author_email'] ?? '')) . '</span></td>' .
                '<td>' . nl2br(View::escape((string) $comment['body'])) . '<br><span class="muted">' . View::escape((string) $comment['created_at']) . '</span></td>' .
                '<td><a href="' . View::escape($url) . '" target="_blank" rel="noopener">' . View::escape((string) ($comment['content_title'] ?? '内容')) . '</a></td>' .
                '<td>' . View::escape((string) $comment['status']) . '</td><td class="admin-action-row">' .
                $this->commentActionForm((int) $comment['id'], 'approved', '通过', $status) .
                $this->commentActionForm((int) $comment['id'], 'pending', '待审', $status) .
                $this->commentActionForm((int) $comment['id'], 'spam', '垃圾', $status) .
                $this->commentActionForm((int) $comment['id'], 'trash', '回收站', $status) .
                $this->commentActionForm((int) $comment['id'], 'delete', '删除', $status, true) .
                '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无评论。</td></tr>';

        return '<div class="admin-page-header"><div><h1>评论管理</h1><p class="muted">审核前台游客和会员提交的评论。</p></div></div>' .
            '<p>' . $tabs . '</p><table><thead><tr><th>作者</th><th>评论</th><th>内容</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function commentActionForm(int $id, string $action, string $label, string $returnStatus, bool $danger = false): string
    {
        return '<form method="post" action="/admin/comments/action">' . CsrfToken::field() .
            '<input type="hidden" name="id" value="' . $id . '">' .
            '<input type="hidden" name="action" value="' . View::escape($action) . '">' .
            '<input type="hidden" name="return_status" value="' . View::escape($returnStatus) . '">' .
            '<button class="' . ($danger ? 'admin-danger' : 'admin-button-secondary') . '" type="submit">' . View::escape($label) . '</button></form>';
    }

    public function siteSettings(?Request $request = null): Response
    {
        $request ??= new Request('GET', '/admin/settings');
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $notice = '';
        if (($request->query['saved'] ?? '') === '1') {
            $notice = '<p class="admin-badge admin-badge-success">站点设置已保存</p>';
        }

        return Response::html(View::page('站点设置', $this->siteSettingsForm($notice)));
    }

    public function adminSecurity(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $mfa = new AdminMfaService($pdo);
            $adminId = (int) ($guard['id'] ?? 0);
            if (!$mfa->isEnabled($adminId) && !isset($_SESSION['admin_mfa_setup'])) {
                $_SESSION['admin_mfa_setup'] = [
                    'secret' => AdminMfaService::generateSecret(),
                    'recovery_codes' => AdminMfaService::generateRecoveryCodes(),
                ];
            }

            $message = ($request->query['mfa_disabled'] ?? '') === '1'
                ? '<p class="admin-badge admin-badge-success">MFA 已停用。</p>'
                : '';
            if (($request->query['reauth'] ?? '') === 'ok') {
                $message .= '<p class="admin-badge admin-badge-success">身份已重新验证，10 分钟内可执行敏感操作。</p>';
            } elseif (($request->query['reauth'] ?? '') === 'failed') {
                $message .= '<p class="error">管理员密码无效，重认证失败。</p>';
            } elseif (($request->query['sessions'] ?? '') === 'revoked') {
                $message .= '<p class="admin-badge admin-badge-success">其他后台会话已退出。</p>';
            }

            return Response::html(View::page('后台安全', $this->adminSecurityHtml($mfa, $adminId, $message)));
        } catch (Throwable $exception) {
            $this->logger->error('Admin security page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">后台安全设置暂不可用。</p>'), 500);
        }
    }

    public function adminSecurityReauthenticate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('请求校验失败，请刷新页面后重试。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $auth = new AdminAuthenticator($pdo);
            $user = $auth->verifyCredentials((string) ($guard['email'] ?? ''), (string) $request->input('password', ''), (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0'));
            if ($user === null || (int) $user['id'] !== (int) ($guard['id'] ?? 0)) {
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'admin.reauthentication_failed');
                return Response::redirect('/admin/security?reauth=failed');
            }
            (new AdminSessionService($pdo))->markReauthenticated((int) $guard['id']);
            return Response::redirect('/admin/security?reauth=ok');
        } catch (Throwable $exception) {
            $this->logger->error('Admin reauthentication failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/security?reauth=error');
        }
    }

    public function adminSecurityLogoutOtherSessions(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('请求校验失败，请刷新页面后重试。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $count = (new AdminSessionService($pdo))->revokeOtherSessions((int) $guard['id']);
            (new AuditLogger($pdo))->record('admin', (int) $guard['id'], 'admin.other_sessions_revoked', ['count' => $count]);
            return Response::redirect('/admin/security?sessions=revoked');
        } catch (Throwable $exception) {
            $this->logger->error('Admin session revocation failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/security?sessions=error');
        }
    }

    public function systemHealth(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $checks = (new SystemHealthService($this->root(), $this->settings))->checks();
            return Response::html(View::page('系统健康', $this->systemHealthHtml($checks)));
        } catch (Throwable $exception) {
            $this->logger->error('System health page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('系统健康', '<h1>系统健康</h1><p class="error">系统健康检查暂不可用。</p>'), 500);
        }
    }

    public function healthDoctor(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $doctor = (new SystemHealthDoctor($this->root(), $this->settings, $pdo))->diagnose();
            $rows = '';
            foreach ($doctor['checks'] as $check) {
                $status = (string) ($check['status'] ?? 'WARNING');
                $rows .= '<tr><td>' . View::escape((string) ($check['id'] ?? 'unknown')) . '</td><td>' . $this->adminBadge($status, $this->statusTone($status)) . '</td><td>' . View::escape((string) ($check['message'] ?? '')) . '</td><td>' . View::escape((string) ($check['remediation'] ?? '')) . '</td></tr>';
            }
            $body = '<h1>网站医生</h1><p class="muted">检测 Security、Performance、Data Integrity、SEO、Plugin、Theme、Update、Backup 和 Environment。</p>' .
                '<p>' . $this->adminBadge((string) $doctor['status'], $this->statusTone((string) $doctor['status'])) . ' 健康评分：' . (int) $doctor['score'] . '</p>' .
                '<table><thead><tr><th>检查项</th><th>状态</th><th>说明</th><th>处理建议</th></tr></thead><tbody>' . $rows . '</tbody></table>';
            return Response::html(View::page('网站医生', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Health Doctor page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('网站医生', '<h1>网站医生</h1><p class="error">网站医生暂不可用。</p>'), 500);
        }
    }

    public function siteVault(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $notice = '';
            if ($request->method === 'POST') {
                if (!CsrfToken::verify($request->input('_csrf'))) {
                    return Response::text('请求校验失败，请刷新页面后重试。', 403);
                }
                $path = $this->root() . '/storage/recovery/site-vault-' . gmdate('YmdHis') . '.daiying-site';
                (new SiteVaultService($this->root(), $pdo, new SiteTimelineService($pdo)))->export($path, (int) $guard['id']);
                $notice = '<p class="admin-badge admin-badge-success">Site Vault 已导出：' . View::escape(basename($path)) . '</p>';
            }
            $files = glob($this->root() . '/storage/recovery/site-vault-*.daiying-site') ?: [];
            rsort($files);
            $rows = '';
            foreach ($files as $file) {
                $rows .= '<tr><td>' . View::escape(basename($file)) . '</td><td><code>' . View::escape((string) hash_file('sha256', $file)) . '</code></td><td>' . View::escape(gmdate('c', (int) filemtime($file))) . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="3" class="muted">暂无 Site Vault 包。</td></tr>';
            $body = '<h1>网站保险箱</h1><p class="muted">导出完整可迁移 Daiying Site Package，包含数据库、内容、媒体、主题、插件状态、配置和完整性 manifest。</p>' .
                $notice .
                '<form method="post">' . CsrfToken::field() . '<button class="button" type="submit">导出当前网站 Site Vault</button></form>' .
                '<table><thead><tr><th>包</th><th>SHA256</th><th>时间</th></tr></thead><tbody>' . $rows . '</tbody></table>';
            return Response::html(View::page('网站保险箱', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Site Vault page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('网站保险箱', '<h1>网站保险箱</h1><p class="error">Site Vault 暂不可用。</p>'), 500);
        }
    }

    public function siteTimeline(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $events = (new SiteTimelineService(ConnectionFactory::make($this->settings)))->recent(100);
            $rows = '';
            foreach ($events as $event) {
                $rows .= '<tr><td>' . View::escape((string) ($event['created_at'] ?? '')) . '</td><td>' . View::escape((string) ($event['actor_type'] ?? '')) . '</td><td>' . View::escape((string) ($event['operation'] ?? '')) . '</td><td>' . View::escape((string) ($event['target_type'] ?? '') . ':' . (string) ($event['target_id'] ?? '')) . '</td><td>' . View::escape((string) ($event['recoverability'] ?? '')) . '</td><td>' . View::escape((string) ($event['related_snapshot_id'] ?? '')) . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无站点时光机记录。</td></tr>';
            $body = '<h1>站点时光机</h1><p class="muted">记录内容、插件、主题、配置、更新、迁移和 Site Vault 恢复等站点级变化。</p>' .
                '<table><thead><tr><th>时间</th><th>操作者</th><th>操作</th><th>对象</th><th>可恢复性</th><th>关联快照</th></tr></thead><tbody>' . $rows . '</tbody></table>';
            return Response::html(View::page('站点时光机', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Site Timeline page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('站点时光机', '<h1>站点时光机</h1><p class="error">站点时光机暂不可用。</p>'), 500);
        }
    }

    public function adminSecurityEnableMfa(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">启用 MFA 必须通过 POST 请求提交。</p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p>'), 403);
        }
        $setup = $_SESSION['admin_mfa_setup'] ?? null;
        if (!is_array($setup)) {
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">MFA 设置已过期，请刷新页面重新开始。</p>'), 400);
        }
        $secret = (string) ($setup['secret'] ?? '');
        $codes = array_values(array_filter($setup['recovery_codes'] ?? [], 'is_string'));
        try {
            if (!AdminMfaService::verifyTotp($secret, (string) $request->input('mfa_code', ''))) {
                $mfa = new AdminMfaService(ConnectionFactory::make($this->settings));
                return Response::html(View::page('后台安全', $this->adminSecurityHtml($mfa, (int) ($guard['id'] ?? 0), '<p class="error">验证码无效，MFA 未启用。</p>')), 422);
            }
            $pdo = ConnectionFactory::make($this->settings);
            (new AdminMfaService($pdo))->enableTotp((int) ($guard['id'] ?? 0), $secret, $codes);
            unset($_SESSION['admin_mfa_setup']);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'admin.mfa_enabled');
            $htmlCodes = '<ul><li>' . implode('</li><li>', array_map([View::class, 'escape'], $codes)) . '</li></ul>';

            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="admin-badge admin-badge-success">MFA 已启用，请立即保存这些恢复码。</p>' . $htmlCodes . '<p><a class="button" href="/admin/security">返回后台安全</a></p>'));
        } catch (Throwable $exception) {
            $this->logger->error('Admin MFA enable failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">MFA 启用失败，请稍后重试。</p>'), 500);
        }
    }

    public function adminSecurityDisableMfa(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">停用 MFA 必须通过 POST 请求提交。</p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p>'), 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $auth = new AdminAuthenticator($pdo);
            $user = $auth->verifyCredentials((string) ($guard['email'] ?? ''), (string) $request->input('password', ''), (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0'));
            if ($user === null || (int) $user['id'] !== (int) ($guard['id'] ?? 0)) {
                return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">管理员密码无效，MFA 未停用。</p><p><a class="button" href="/admin/security">返回后台安全</a></p>'), 401);
            }
            (new AdminMfaService($pdo))->disable((int) ($guard['id'] ?? 0));
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'admin.mfa_disabled');

            return Response::redirect('/admin/security?mfa_disabled=1');
        } catch (Throwable $exception) {
            $this->logger->error('Admin MFA disable failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">MFA 停用失败，请稍后重试。</p>'), 500);
        }
    }

    public function adminPasskeyOptions(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }
        if (!CsrfToken::verify($_POST['_csrf'] ?? null)) {
            return Response::json(['error' => 'csrf'], 403);
        }
        try {
            return Response::json((new AdminPasskeyService(ConnectionFactory::make($this->settings), $this->settings))->registrationOptions((int) $guard['id'], (string) $guard['email'], (string) $guard['display_name']));
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function adminPasskeyRegister(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['error' => 'csrf'], 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            (new AdminPasskeyService($pdo, $this->settings))->register((int) $guard['id'], $request->body, (string) $request->input('label', 'Passkey'));
            (new AuditLogger($pdo))->record('admin', (int) $guard['id'], 'admin.passkey_registered');
            return Response::json(['ok' => true]);
        } catch (Throwable $exception) {
            $this->logger->error('Admin passkey register failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::json(['error' => $exception->getMessage()], 400);
        }
    }

    public function adminPasskeyDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('请求校验失败，请刷新页面后重试。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            (new AdminPasskeyService($pdo, $this->settings))->delete((int) $guard['id'], (int) $request->input('id', 0));
            (new AuditLogger($pdo))->record('admin', (int) $guard['id'], 'admin.passkey_deleted');
        } catch (Throwable $exception) {
            $this->logger->error('Admin passkey delete failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::redirect('/admin/security');
    }

    public function siteSettingsSave(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('站点设置', $this->siteSettingsForm('<p class="error">站点设置保存必须通过 POST 请求提交。</p>')), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('站点设置', $this->siteSettingsForm('<p class="error">CSRF 校验失败，请刷新页面重试。</p>')), 403);
        }

        try {
            $input = $this->siteSettingsInput($request);
            $brandUploads = $this->siteBrandUploads((int) ($guard['id'] ?? 0));
            if ($brandUploads['site_logo_url'] !== '') {
                $input['site_logo_url'] = $brandUploads['site_logo_url'];
            }
            if ($brandUploads['site_favicon_url'] !== '') {
                $input['site_favicon_url'] = $brandUploads['site_favicon_url'];
            }
            $this->writeConfig($this->root(), static function (array $config) use ($input): array {
                $config['site'] = is_array($config['site'] ?? null) ? $config['site'] : [];
                $config['site']['name'] = $input['site_name'];
                $config['site']['url'] = $input['site_url'];
                $config['site']['logo_url'] = $input['site_logo_url'];
                $config['site']['favicon_url'] = $input['site_favicon_url'];
                $config['payment'] = is_array($config['payment'] ?? null) ? $config['payment'] : [];
                $config['payment']['default_currency'] = $input['default_currency'];
                $config['seo'] = is_array($config['seo'] ?? null) ? $config['seo'] : [];
                $config['seo']['robots_index'] = $input['robots_index'];
                $config['market'] = is_array($config['market'] ?? null) ? $config['market'] : [];
                $config['market']['enabled'] = $input['market_enabled'];
                $config['market']['developer_mode'] = $input['developer_mode'] && $input['market_enabled'];
                $config['market']['server_url'] = $input['market_server_url'];
                if ($input['market_site_token'] !== '') {
                    $config['market']['site_token'] = $input['market_site_token'];
                }

                return $config;
            });
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'site.settings_saved', [
                'robots_index' => $input['robots_index'],
                'market_enabled' => $input['market_enabled'],
                'developer_mode' => $input['developer_mode'] && $input['market_enabled'],
                'market_server_url' => $input['market_server_url'],
            ]);

            return Response::redirect('/admin/settings?saved=1');
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('站点设置', $this->siteSettingsForm('<p class="error">' . View::escape($exception->getMessage()) . '</p>')), 422);
        } catch (Throwable $exception) {
            $this->logger->error('Site settings save failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('站点设置', $this->siteSettingsForm('<p class="error">站点设置保存失败，请检查配置目录权限后重试。</p>')), 500);
        }
    }

    private function siteSettingsForm(string $message = ''): string
    {
        $siteName = (string) $this->settings->get('site.name', 'PHP CMS');
        $siteUrl = (string) $this->settings->get('site.url', '');
        $siteLogoUrl = (string) $this->settings->get('site.logo_url', '');
        $siteFaviconUrl = (string) $this->settings->get('site.favicon_url', '');
        $robotsIndex = (bool) $this->settings->get('seo.robots_index', true);
        $marketEnabled = (bool) $this->settings->get('market.enabled', false);
        $developerMode = (bool) $this->settings->get('market.developer_mode', false) && $marketEnabled;
        $marketServerUrl = (string) $this->settings->get('market.server_url', (string) $this->settings->get('updates.server_url', ''));
        $marketSiteTokenConfigured = (string) $this->settings->get('market.site_token', '') !== '';
        $defaultCurrency = $this->siteDefaultCurrency();

        return '<h1>站点设置</h1>' . $message .
            '<p class="muted">这里保存 CMS Core 站点身份和基础 SEO 抓取策略。关闭索引后，前台页面会输出 noindex,nofollow，robots.txt 会禁止抓取，sitemap.xml 不列出公开内容。</p>' .
            '<form method="post" action="/admin/settings" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>站点名称<input name="site_name" maxlength="120" value="' . View::escape($siteName) . '" required></label>' .
            '<label>站点 URL<input name="site_url" value="' . View::escape($siteUrl) . '" placeholder="https://example.com"></label>' .
            '<p class="muted">站点 URL 用于 canonical、robots.txt 和 sitemap.xml。留空时前台会回退到本地基准地址。</p>' .
            '<section class="card"><h2>站点品牌</h2>' .
            '<p class="muted">Logo 和 Favicon 是 CMS 全局基础设置。主题可以留空并自动使用这里的地址，也可以在主题设置里单独覆盖。</p>' .
            ($siteLogoUrl !== '' ? '<p><img src="' . View::escape($siteLogoUrl) . '" alt="" style="max-width:180px;max-height:72px;border:1px solid #d8dee4;border-radius:8px;background:#fff;padding:8px"></p>' : '') .
            '<label>Logo 图片地址<input name="site_logo_url" value="' . View::escape($siteLogoUrl) . '" placeholder="/media/1/logo.png"></label>' .
            '<label>上传 Logo<input type="file" name="site_logo_file" accept="image/avif,image/gif,image/jpeg,image/png,image/webp"></label>' .
            ($siteFaviconUrl !== '' ? '<p><img src="' . View::escape($siteFaviconUrl) . '" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #d8dee4;border-radius:8px;background:#fff;padding:6px"></p>' : '') .
            '<label>Favicon 地址<input name="site_favicon_url" value="' . View::escape($siteFaviconUrl) . '" placeholder="/media/2/favicon.png"></label>' .
            '<label>上传 Favicon<input type="file" name="site_favicon_file" accept="image/avif,image/gif,image/jpeg,image/png,image/x-icon,image/vnd.microsoft.icon,image/webp"></label>' .
            '<p class="muted">上传后会进入媒体库并自动保存为可访问的 /media/ID/文件名 地址。</p></section>' .
            $this->currencySelect('default_currency', $defaultCurrency, '默认币种') .
            '<p class="muted">新建发卡商品、付费内容和扩展商品默认使用该币种，单个商品仍可单独修改。</p>' .
            '<label><input type="checkbox" name="robots_index" value="1"' . ($robotsIndex ? ' checked' : '') . '> 允许搜索引擎索引本站</label>' .
            '<section class="card"><h2>官方市场</h2>' .
            '<p class="muted">这里连接 Daiying 官方更新服务器。CMS 只负责查看市场、安装插件、激活授权和提交审核。</p>' .
            '<label class="checkbox-row"><input type="checkbox" name="market_enabled" value="1"' . ($marketEnabled ? ' checked' : '') . '> 启用插件市场和主题市场</label>' .
            '<label class="checkbox-row"><input type="checkbox" name="developer_mode" value="1"' . ($developerMode ? ' checked' : '') . '> 启用开发者中心</label>' .
            '<label>官方市场地址<input name="market_server_url" value="' . View::escape($marketServerUrl) . '" placeholder="https://updates.daiyingcms.com"></label>' .
            '<p class="muted">通常填写：https://updates.daiyingcms.com</p>' .
            '<label>市场站点 Token（可选）<input name="market_site_token" type="password" autocomplete="off" placeholder="' . ($marketSiteTokenConfigured ? '已配置，留空则保留' : '无授权市场可留空') . '"></label>' .
            '<p class="muted">没有授权 Token 可以先留空；需要提交开发者项目或安装授权包时再填写。</p></section>' .
            '<button type="submit">保存站点设置</button></form>' .
            '<p><a class="button" href="/admin">返回后台首页</a></p>';
    }

    /** @return array{site_name:string,site_url:string,site_logo_url:string,site_favicon_url:string,default_currency:string,robots_index:bool,market_enabled:bool,developer_mode:bool,market_server_url:string,market_site_token:string} */
    private function siteSettingsInput(Request $request): array
    {
        $siteName = trim((string) $request->input('site_name', ''));
        if ($siteName === '' || strlen($siteName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $siteName) === 1) {
            throw new \InvalidArgumentException('站点名称不能为空，且不能包含控制字符。');
        }

        $siteUrl = rtrim(trim((string) $request->input('site_url', '')), '/');
        if ($siteUrl !== '') {
            if (strlen($siteUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $siteUrl) === 1) {
                throw new \InvalidArgumentException('站点 URL 格式无效。');
            }
            $parts = parse_url($siteUrl);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new \InvalidArgumentException('站点 URL 只允许 http 或 https 完整地址。');
            }
        }

        $siteLogoUrl = $this->siteBrandUrl((string) $request->input('site_logo_url', ''), false);
        $siteFaviconUrl = $this->siteBrandUrl((string) $request->input('site_favicon_url', ''), true);

        $marketServerUrl = rtrim(trim((string) $request->input('market_server_url', (string) $this->settings->get('market.server_url', (string) $this->settings->get('updates.server_url', '')))), '/');
        if ($marketServerUrl !== '') {
            if (strlen($marketServerUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $marketServerUrl) === 1) {
                throw new \InvalidArgumentException('官方市场地址格式无效。');
            }
            $marketParts = parse_url($marketServerUrl);
            $marketScheme = is_array($marketParts) ? strtolower((string) ($marketParts['scheme'] ?? '')) : '';
            $marketHost = is_array($marketParts) ? (string) ($marketParts['host'] ?? '') : '';
            if (!in_array($marketScheme, ['http', 'https'], true) || $marketHost === '') {
                throw new \InvalidArgumentException('官方市场地址只允许 http 或 https 完整地址。');
            }
        }

        $marketSiteToken = trim((string) $request->input('market_site_token', ''));
        if (strlen($marketSiteToken) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $marketSiteToken) === 1) {
            throw new \InvalidArgumentException('市场站点 Token 格式无效。');
        }

        return [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'site_logo_url' => $siteLogoUrl,
            'site_favicon_url' => $siteFaviconUrl,
            'default_currency' => $this->normalizeAdminCurrency((string) $request->input('default_currency', 'USD')),
            'robots_index' => (string) $request->input('robots_index', '') === '1',
            'market_enabled' => (string) $request->input('market_enabled', '') === '1',
            'developer_mode' => (string) $request->input('developer_mode', '') === '1',
            'market_server_url' => $marketServerUrl,
            'market_site_token' => $marketSiteToken,
        ];
    }

    public function contentIndex(?Request $request = null): Response
    {
        $request ??= new Request('GET', '/admin/content');
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $perPage = max(1, min(100, (int) ($request->query['per_page'] ?? 50)));
            $total = $repo->adminCount();
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $items = $repo->adminList($page, $perPage);
        } catch (Throwable $exception) {
            $this->logger->error('Content index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('内容列表', '<h1>内容列表</h1><p class="error">内容服务暂不可用。</p>'), 500);
        }

        $notice = '';
        if (($request->query['deleted'] ?? '') === '1') {
            $notice = '<p class="muted">删除成功。</p>';
        } elseif (($request->query['delete_failed'] ?? '') === '1') {
            $notice = '<p class="error">删除失败，请稍后重试。</p>';
        }
        $canDeleteContent = $this->adminHasCapability('content.delete');

        $rows = '';
        foreach ($items as $item) {
            $meta = json_decode((string) ($item['meta_json'] ?? '{}'), true) ?: [];
            $token = (string) ($meta['preview_token'] ?? '');
            $preview = '/preview/' . (int) $item['id'] . ($token !== '' ? '?token=' . rawurlencode($token) : '');
            $front = $this->contentPublicPath((string) $item['content_type'], (string) $item['slug']);
            $view = $front !== '' ? ' <a class="button" href="' . View::escape($front) . '">查看</a>' : '';
            $delete = $canDeleteContent
                ? '<form method="post" action="/admin/content/delete/' . (int) $item['id'] . '" style="display:inline" onsubmit="return confirm(\'确定要删除这篇内容吗？此操作不可撤销。\');">' .
                    CsrfToken::field() . '<button class="admin-danger" type="submit">删除</button></form>'
                : '';
            $status = (string) $item['status'];
            $tone = $status === 'published' ? 'success' : ($status === 'scheduled' ? 'warning' : 'muted');
            $rows .= '<tr><td>' . (int) $item['id'] . '</td><td><span class="admin-tag">' . View::escape(AdminUiText::contentType((string) $item['content_type'])) .
                '</span></td><td><strong>' . View::escape((string) $item['title']) . '</strong><br><code>' . View::escape((string) $item['slug']) . '</code></td><td>' .
                '<span class="admin-badge admin-badge-' . $tone . '">' . View::escape($this->contentStatusLabel($status)) . '</span></td><td><div class="admin-action-row"><a class="button" href="/admin/content/edit/' . (int) $item['id'] . '">编辑</a>' . $view . ' <a class="button admin-button-secondary" href="' . View::escape($preview) . '">预览</a> ' . $delete . '</div></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无内容</td></tr>';
        $pager = $this->adminContentPager($page, $totalPages, $perPage, $total);

        $body = '<div class="admin-page-header"><div><h1>内容管理</h1><p class="muted">文章、页面和区块内容统一在这里管理。</p></div><div class="admin-action-row"><a class="button" href="/admin/content/new">新建内容</a> <a class="button admin-button-secondary" href="/admin/categories">分类管理</a> <a class="button admin-button-secondary" href="/admin/navigation">设置前台导航</a></div></div>' .
            $notice . '<div class="admin-list-toolbar"><span class="muted">共 ' . (int) $total . ' 条内容，第 ' . (int) $page . ' / ' . (int) $totalPages . ' 页，每页 ' . (int) $perPage . ' 条。</span></div>' .
            '<table><thead><tr><th>ID</th><th>类型</th><th>标题</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' . $pager;

        return Response::html(View::page('内容管理', $body));
    }

    private function adminContentPager(int $page, int $totalPages, int $perPage, int $total): string
    {
        if ($total <= $perPage) {
            return '';
        }
        $prev = $page > 1
            ? '<a class="button admin-button-secondary" href="/admin/content?page=' . ($page - 1) . '&per_page=' . $perPage . '">上一页</a>'
            : '<span class="button admin-button-secondary" aria-disabled="true">上一页</span>';
        $next = $page < $totalPages
            ? '<a class="button admin-button-secondary" href="/admin/content?page=' . ($page + 1) . '&per_page=' . $perPage . '">下一页</a>'
            : '<span class="button admin-button-secondary" aria-disabled="true">下一页</span>';

        return '<div class="admin-pagination">' . $prev . '<span class="muted">第 ' . $page . ' / ' . $totalPages . ' 页</span>' . $next . '</div>';
    }

    public function contentCreate(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html(View::page('新建内容', $this->contentForm()));
    }

    public function contentStore(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('新建内容', $this->contentForm('CSRF 校验失败，请刷新页面重试。')), 400);
        }

        $input = $this->contentInput($request);
        if (($input['action'] ?? '') !== 'save') {
            return Response::html(View::page('新建内容', $this->contentForm('', $input)));
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $id = $repo->create($input['type'], $input['title'], $input['slug'], $input['blocks'], $input['status'], $input['meta'], $input['categories'], $input['tags']);
            $user = (new AdminAuthenticator($pdo))->user();
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'content.created', ['content_id' => $id, 'type' => $input['type']]);
        } catch (Throwable $exception) {
            $this->logger->error('Content create failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('新建内容', $this->contentForm('保存失败：' . $exception->getMessage(), $input)), 422);
        }

        return Response::redirect('/admin/content');
    }

    public function contentEdit(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        try {
            $repo = new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
            $item = $repo->find((int) basename($request->path));
            if ($item === null) {
                return Response::text('内容不存在。', 404);
            }
        } catch (Throwable $exception) {
            return Response::html(View::page('编辑内容', '<h1>编辑内容</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 500);
        }

        return Response::html(View::page('编辑内容', $this->contentForm('', $this->contentFormData($item), (int) $item['id'])));
    }

    public function contentUpdate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('编辑内容', $this->contentForm('CSRF 校验失败，请刷新页面重试。')), 400);
        }
        $id = $this->pathSegmentInt($request->path, 3);
        $input = $this->contentInput($request);
        if (($input['action'] ?? '') !== 'save') {
            return Response::html(View::page('编辑内容', $this->contentForm('', $input, $id)));
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $repo->update($id, $input['type'], $input['title'], $input['slug'], $input['blocks'], $input['status'], $input['meta'], $input['categories'], $input['tags']);
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'content.updated', ['content_id' => $id, 'type' => $input['type']]);
        } catch (Throwable $exception) {
            $this->logger->error('Content update failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('编辑内容', $this->contentForm('保存失败：' . $exception->getMessage(), $input, $id)), 422);
        }

        return Response::redirect('/admin/content');
    }

    public function contentDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('删除内容', '<h1>删除内容</h1><p class="error">删除内容必须通过 POST 请求提交。</p><p><a class="button" href="/admin/content">返回内容管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!$this->adminHasCapability('content.delete')) {
            return Response::html(View::page('删除内容', '<h1>删除内容</h1><p class="error">当前管理员没有删除内容权限。</p><p><a class="button" href="/admin/content">返回内容管理</a></p>'), 403);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('删除内容', '<h1>删除内容</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p><p><a class="button" href="/admin/content">返回内容管理</a></p>'), 403);
        }
        $id = $this->pathSegmentInt($request->path, 3);
        if ($id <= 0) {
            return Response::html(View::page('删除内容', '<h1>删除内容</h1><p class="error">内容路径无效。</p><p><a class="button" href="/admin/content">返回内容管理</a></p>'), 400);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $repo->delete($id);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'content.deleted', ['content_id' => $id]);
        } catch (Throwable $exception) {
            $this->logger->error('Content delete failed', ['source' => 'Core', 'content_id' => $id, 'error' => $exception->getMessage()]);
            return Response::html(View::page('删除内容', '<h1>删除内容</h1><p class="error">删除失败，请稍后重试。</p><p><a class="button" href="/admin/content">返回内容管理</a></p>'), 400);
        }

        return Response::redirect('/admin/content?deleted=1');
    }

    public function categoryIndex(?Request $request = null): Response
    {
        $request ??= new Request('GET', '/admin/categories');
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $repo = new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
            $categories = $repo->terms('category');
        } catch (Throwable $exception) {
            $this->logger->error('Category index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('分类管理', '<h1>分类管理</h1><p class="error">分类服务暂不可用。</p>'), 500);
        }

        $notice = match ((string) ($request->query['notice'] ?? '')) {
            'created' => '<p class="muted">分类已创建。</p>',
            'updated' => '<p class="muted">分类已保存。</p>',
            'deleted' => '<p class="muted">分类已删除。</p>',
            default => '',
        };

        return Response::html(View::page('分类管理', $this->categoryIndexHtml($categories, $notice)));
    }

    public function categoryStore(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('分类管理', $this->categoryIndexHtml($this->categoryRows(), '<p class="error">CSRF 校验失败，请刷新页面重试。</p>')), 403);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $id = $repo->saveTerm('category', (string) $request->input('name', ''), (string) $request->input('slug', ''));
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'category.created', ['category_id' => $id]);
        } catch (Throwable $exception) {
            $this->logger->error('Category create failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('分类管理', $this->categoryIndexHtml($this->categoryRows(), '<p class="error">保存失败：' . View::escape($exception->getMessage()) . '</p>')), 422);
        }

        return Response::redirect('/admin/categories?notice=created');
    }

    public function categoryEdit(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $id = $this->pathSegmentInt($request->path, 3);

        try {
            $repo = new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
            $category = $repo->termById($id);
            if ($category === null || $category['taxonomy'] !== 'category') {
                return Response::html(View::page('编辑分类', '<h1>编辑分类</h1><p class="error">分类不存在。</p><p><a class="button" href="/admin/categories">返回分类管理</a></p>'), 404);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Category edit failed', ['source' => 'Core', 'category_id' => $id, 'error' => $exception->getMessage()]);
            return Response::html(View::page('编辑分类', '<h1>编辑分类</h1><p class="error">分类服务暂不可用。</p>'), 500);
        }

        return Response::html(View::page('编辑分类', $this->categoryEditHtml($category)));
    }

    public function categoryUpdate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('编辑分类', '<h1>编辑分类</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p><p><a class="button" href="/admin/categories">返回分类管理</a></p>'), 403);
        }
        $id = $this->pathSegmentInt($request->path, 3);

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $repo->saveTerm('category', (string) $request->input('name', ''), (string) $request->input('slug', ''), $id);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'category.updated', ['category_id' => $id]);
        } catch (Throwable $exception) {
            $this->logger->error('Category update failed', ['source' => 'Core', 'category_id' => $id, 'error' => $exception->getMessage()]);
            $category = ['id' => $id, 'name' => (string) $request->input('name', ''), 'slug' => (string) $request->input('slug', ''), 'content_count' => 0];
            return Response::html(View::page('编辑分类', $this->categoryEditHtml($category, '保存失败：' . $exception->getMessage())), 422);
        }

        return Response::redirect('/admin/categories?notice=updated');
    }

    public function categoryDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('分类管理', '<h1>分类管理</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p><p><a class="button" href="/admin/categories">返回分类管理</a></p>'), 403);
        }
        $id = $this->pathSegmentInt($request->path, 3);

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
            $repo->deleteTerm($id, 'category');
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'category.deleted', ['category_id' => $id]);
        } catch (Throwable $exception) {
            $this->logger->error('Category delete failed', ['source' => 'Core', 'category_id' => $id, 'error' => $exception->getMessage()]);
            return Response::html(View::page('分类管理', $this->categoryIndexHtml($this->categoryRows(), '<p class="error">删除失败：' . View::escape($exception->getMessage()) . '</p>')), 422);
        }

        return Response::redirect('/admin/categories?notice=deleted');
    }

    public function cardDeliveryIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $repo = new CardDeliveryRepository(ConnectionFactory::make($this->settings), (string) $this->settings->get('security.encryption_key', ''));
            $rows = '';
            foreach ($repo->products() as $product) {
                $rows .= '<tr><td>' . (int) $product['id'] . '</td><td>' . View::escape((string) $product['name']) . '</td><td>' . View::escape($this->moneyLabel($product['price_minor'] ?? 0, (string) ($product['currency'] ?? 'USD'))) . '</td><td>' . View::escape((string) $product['status']) . '</td><td>' . (int) ($product['available_count'] ?? 0) . '</td><td>' . (int) ($product['delivered_count'] ?? 0) . '</td><td><a class="button" href="/admin/card-delivery/edit/' . (int) $product['id'] . '">编辑/库存</a></td></tr>';
            }
            $deliveryRows = '';
            foreach ($repo->deliveries(50) as $delivery) {
                $deliveryRows .= '<tr><td>' . (int) $delivery['id'] . '</td><td>' . View::escape((string) ($delivery['product_name'] ?? '')) . '</td><td>' . View::escape((string) $delivery['order_id']) . '</td><td>' . View::escape((string) $delivery['status']) . '</td><td>' . View::escape((string) ($delivery['delivered_at'] ?? '')) . '</td></tr>';
            }
            $orderRows = '';
            foreach ($repo->orders(100) as $order) {
                $orderId = (int) $order['id'];
                $paymentId = $order['payment_id'] === null ? 0 : (int) $order['payment_id'];
                $status = (string) ($order['status'] ?? '');
                $statusLabel = View::escape($status);
                if ($status === 'out_of_stock') {
                    $statusLabel .= '<br><strong class="error">库存不足，补库存后重试发卡。</strong>';
                } elseif ($status === 'manual_review') {
                    $statusLabel .= '<br><strong class="error">需要人工处理。</strong>';
                }
                $retry = in_array($status, ['paid', 'out_of_stock', 'manual_review'], true)
                    ? '<form method="post" action="/admin/card-delivery/orders/' . $orderId . '/fulfill" style="display:inline">' . CsrfToken::field() . '<button type="submit">重试发卡</button></form>'
                    : '<span class="muted">等待支付</span>';
                $paymentLink = $paymentId > 0 ? '<a href="/admin/payments/' . $paymentId . '">#' . $paymentId . '</a>' : '<span class="muted">未绑定</span>';
                $orderRows .= '<tr><td>' . $orderId . '</td><td>' . View::escape((string) ($order['product_name'] ?? '')) . '</td><td>' . (int) ($order['quantity'] ?? 1) . '</td><td>' . View::escape($this->moneyLabel($order['amount_minor'] ?? 0, (string) ($order['currency'] ?? 'USD'))) . '</td><td>' . $statusLabel . '</td><td>' . $paymentLink . '</td><td>' . View::escape((string) ($order['paid_at'] ?? '')) . '</td><td>' . $retry . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无发卡商品</td></tr>';
            $deliveryRows = $deliveryRows !== '' ? $deliveryRows : '<tr><td colspan="5" class="muted">暂无发卡记录</td></tr>';
            $orderRows = $orderRows !== '' ? $orderRows : '<tr><td colspan="8" class="muted">暂无发卡订单</td></tr>';
            $body = '<h1>发卡管理</h1><p><a class="button" href="/admin/card-delivery/new">新建发卡商品</a></p>' .
                '<section class="editor-card"><h2>发卡商品</h2><table><thead><tr><th>ID</th><th>商品</th><th>售价</th><th>状态</th><th>库存</th><th>已售</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table></section>' .
                '<section class="editor-card"><h2>发卡订单</h2><table><thead><tr><th>ID</th><th>商品</th><th>数量</th><th>金额</th><th>状态</th><th>支付</th><th>支付时间</th><th>操作</th></tr></thead><tbody>' . $orderRows . '</tbody></table></section>' .
                '<section class="editor-card"><h2>发卡记录</h2><table><thead><tr><th>ID</th><th>商品</th><th>订单</th><th>状态</th><th>发放时间</th></tr></thead><tbody>' . $deliveryRows . '</tbody></table></section>';

            return Response::html(View::page('发卡管理', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Card delivery index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('发卡管理', '<h1>发卡管理</h1><p class="error">发卡服务暂不可用。</p>'), 500);
        }
    }

    public function cardDeliveryCreate(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html(View::page('新建发卡商品', $this->cardDeliveryForm()));
    }

    public function cardDeliveryStore(Request $request): Response
    {
        return $this->saveCardDeliveryProduct($request, null);
    }

    public function cardDeliveryEdit(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $id = (int) basename($request->path);
        try {
            $repo = new CardDeliveryRepository(ConnectionFactory::make($this->settings), (string) $this->settings->get('security.encryption_key', ''));
            $product = $repo->product($id);
            if ($product === null) {
                return Response::text('发卡商品不存在。', 404);
            }

            return Response::html(View::page('编辑发卡商品', $this->cardDeliveryForm('', $product, $repo->inventory($id))));
        } catch (Throwable $exception) {
            $this->logger->error('Card delivery edit failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('编辑发卡商品', '<p class="error">发卡商品暂不可用。</p>'), 500);
        }
    }

    public function cardDeliveryUpdate(Request $request): Response
    {
        return $this->saveCardDeliveryProduct($request, $this->pathSegmentInt($request->path, 3));
    }

    public function cardDeliveryInventoryImport(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $id = $this->pathSegmentInt($request->path, 3);
        if ($request->method !== 'POST') {
            return Response::html(View::page('导入卡密', '<h1>导入卡密</h1><p class="error">库存导入必须通过 POST 请求提交。</p><p><a class="button" href="/admin/card-delivery/edit/' . max(1, $id) . '">返回发卡商品</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $count = (new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', '')))->importInventory($id, (string) $request->input('secrets_text', ''));
            $user = (new AdminAuthenticator($pdo))->user();
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'card_delivery.inventory_imported', ['product_id' => $id, 'count' => $count]);
            return Response::redirect('/admin/card-delivery/edit/' . $id);
        } catch (Throwable $exception) {
            $this->logger->error('Card inventory import failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('导入卡密', '<p class="error">导入失败：' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/card-delivery/edit/' . $id . '">返回</a></p>'), 422);
        }
    }

    public function cardDeliveryInventoryDisable(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $id = $this->pathSegmentInt($request->path, 4);
        $productId = (int) $request->input('product_id', 0);
        if ($request->method !== 'POST') {
            return Response::html(View::page('禁用卡密', '<h1>禁用卡密</h1><p class="error">库存禁用必须通过 POST 请求提交。</p><p><a class="button" href="/admin/card-delivery/edit/' . max(1, $productId) . '">返回发卡商品</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            (new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', '')))->disableInventory($id);
            $user = (new AdminAuthenticator($pdo))->user();
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'card_delivery.inventory_disabled', ['inventory_id' => $id, 'product_id' => $productId]);
        } catch (Throwable $exception) {
            $this->logger->error('Card inventory disable failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::redirect('/admin/card-delivery/edit/' . max(1, $productId));
    }

    public function cardDeliveryOrderFulfill(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('重试发卡', '<h1>重试发卡</h1><p class="error">重试发卡必须通过 POST 请求提交。</p><p><a class="button" href="/admin/card-delivery">返回发卡管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $orderId = $this->pathSegmentInt($request->path, 3);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', ''));
            $order = $repo->order($orderId);
            if ($order === null) {
                throw new CardDeliveryException('发卡订单不存在。');
            }
            $paymentId = $order['payment_id'] === null ? 0 : (int) $order['payment_id'];
            $paymentRepo = new PaymentRepository($pdo);
            $payment = $paymentId > 0 ? $paymentRepo->payment($paymentId) : null;
            $subjectId = 'order:' . $orderId;
            if (!is_array($payment)
                || (string) ($payment['subject_type'] ?? '') !== 'card_delivery_order'
                || (string) ($payment['subject_id'] ?? '') !== $subjectId
                || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)
            ) {
                throw new CardDeliveryException('发卡订单支付尚未确认，不能手动发卡。');
            }
            $trusted = $paymentRepo->trustedStatus('card_delivery_order', $subjectId, (string) ($payment['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') !== 'paid') {
                throw new CardDeliveryException('发卡订单净支付状态不是已支付，不能手动发卡。');
            }
            $repo->markOrderPaid($orderId, $paymentId);
            $delivery = (new CardDeliveryService($pdo, $this->settings))->deliverPaidOrder(
                (int) $order['product_id'],
                (string) $orderId,
                (string) ($payment['remote_id'] ?? $paymentId),
                (int) ($order['quantity'] ?? 1),
            );
            $repo->markOrderFulfilled(
                $orderId,
                (string) ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'out_of_stock',
                isset($delivery['id']) ? (int) $delivery['id'] : null,
                $this->cardDeliveryDeliveryIds($delivery),
            );
            $user = (new AdminAuthenticator($pdo))->user();
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'card_delivery.order_fulfilled', ['order_id' => $orderId, 'delivery_status' => (string) ($delivery['status'] ?? '')]);
        } catch (Throwable $exception) {
            $this->logger->error('Card order manual fulfill failed', ['source' => 'Core', 'order_id' => $orderId, 'error' => $exception->getMessage()]);
        }

        return Response::redirect('/admin/card-delivery');
    }

    public function mediaIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ((string) ($_GET['source'] ?? 'local') === 'cloudreve') {
            return $this->remoteMediaIndex('cloudreve');
        }

        try {
            $library = $this->mediaLibrary();
            $items = $library->list([
                'type' => (string) ($_GET['type'] ?? ''),
                'filename' => (string) ($_GET['filename'] ?? ''),
                'status' => (string) ($_GET['status'] ?? ''),
            ]);
            $rows = '';
            $cards = '';
            foreach ($items as $item) {
                $mediaUrl = '/media/' . (int) $item['id'];
                $type = (string) $item['media_type'];
                $thumb = $type === 'image'
                    ? '<img class="admin-media-thumb" src="' . View::escape($mediaUrl) . '" alt="">'
                    : '<div class="admin-media-thumb">' . View::escape(strtoupper($type !== '' ? substr($type, 0, 3) : 'FILE')) . '</div>';
                $statusTone = (string) $item['status'] === 'Active' ? 'success' : 'muted';
                $cards .= '<article class="admin-media-card">' . $thumb . '<h3><a href="/admin/media/detail/' . (int) $item['id'] . '">' . View::escape((string) $item['original_name']) . '</a></h3>' .
                    '<p><span class="admin-tag">' . View::escape($type) . '</span> <span class="admin-badge admin-badge-' . $statusTone . '">' . View::escape((string) $item['status']) . '</span></p>' .
                    '<p class="muted">' . View::escape(number_format(((int) $item['byte_size']) / 1024, 1) . ' KB') . '</p><p><a class="button admin-button-secondary" href="' . View::escape($mediaUrl) . '" target="_blank" rel="noopener">打开</a></p></article>';
                $rows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape((string) $item['media_type']) .
                    '</td><td><a href="/admin/media/detail/' . (int) $item['id'] . '">' . View::escape((string) $item['original_name']) . '</a></td><td>' .
                    View::escape(number_format(((int) $item['byte_size']) / 1024, 1) . ' KB') . '</td><td>' .
                    View::escape((string) $item['status']) . '</td><td>' . View::escape((string) $item['created_at']) . '</td><td><a href="' .
                    View::escape($mediaUrl) . '" target="_blank" rel="noopener">打开</a> <code>' . View::escape($mediaUrl) . '</code></td></tr>';
            }
        } catch (Throwable $exception) {
            $this->logger->error('Media index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('媒体库', '<h1>媒体库</h1><p class="error">媒体服务暂不可用。</p>'), 500);
        }

        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无媒体</td></tr>';
        $cards = $cards !== '' ? $cards : '<div class="admin-empty">暂无媒体文件</div>';
        $body = '<div class="admin-page-header"><div><h1>媒体库</h1><p class="muted">集中管理图片、音频、视频和附件。</p></div></div>' .
            '<div class="admin-tabs" role="tablist"><span class="active">本地媒体</span><a href="/admin/media?source=cloudreve">Cloudreve</a></div>' .
            '<form class="admin-filter-bar" method="get" action="/admin/media"><label>类型<select name="type"><option value="">全部</option><option value="image">图片</option><option value="audio">音频</option><option value="video">视频</option><option value="attachment">附件</option></select></label><label>文件名<input name="filename" value="' . View::escape((string) ($_GET['filename'] ?? '')) . '"></label><label>状态<select name="status"><option value="">全部</option><option value="Active">可用</option><option value="Deleted">已删除</option></select></label><button type="submit">筛选</button></form>' .
            '<section class="admin-card"><form method="post" action="/admin/media/upload" enctype="multipart/form-data" id="media-upload">' . CsrfToken::field() .
            '<label>上传文件<input type="file" name="media_files[]" multiple></label><progress id="media-progress" max="100" value="0"></progress><p class="muted" id="media-error">支持图片、音频、视频、PDF、TXT、ZIP 和 Office 附件。</p><button type="submit">上传</button></form></section>' .
            '<script>document.getElementById("media-upload").addEventListener("submit",function(){document.getElementById("media-progress").value=15;});</script>' .
            '<div class="admin-tabs" role="tablist"><span class="active">网格</span><span>列表</span></div><div class="admin-media-grid">' . $cards . '</div>' .
            '<table><thead><tr><th>ID</th><th>类型</th><th>文件名</th><th>大小</th><th>状态</th><th>上传时间</th><th>URL</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('媒体库', $body));
    }

    private function remoteMediaIndex(string $providerId): Response
    {
        $provider = \Cms\Core\Media\RemoteMediaProviderRegistry::get($providerId);
        if ($provider === null) {
            return Response::html(View::page('媒体库', '<div class="admin-page-header"><div><h1>媒体库</h1><p class="error">Cloudreve 媒体来源暂不可用，请确认插件已启用并完成授权。</p></div></div><p><a class="button" href="/admin/media">返回本地媒体</a></p>'), 503);
        }

        $path = (string) ($_GET['path'] ?? 'cloudreve://my/');
        $query = trim((string) ($_GET['q'] ?? ''));
        try {
            $result = $query !== '' ? $provider->search($query, $path, ['page_size' => 50]) : $provider->list($path, ['page_size' => 50]);
            $rows = '';
            foreach ($result['items'] as $item) {
                if (!$item instanceof \Cms\Core\Media\MediaProviderItem) {
                    continue;
                }
                $action = $item->type === 'folder'
                    ? '<a class="button admin-button-secondary" href="/admin/media?source=cloudreve&amp;path=' . View::escape(rawurlencode($item->path)) . '">打开</a>'
                    : '<form method="post" action="/admin/media/provider/select" style="display:inline">' . CsrfToken::field() .
                        '<input type="hidden" name="provider" value="' . View::escape($providerId) . '">' .
                        '<input type="hidden" name="id" value="' . View::escape($item->id) . '">' .
                        '<input type="hidden" name="path" value="' . View::escape($item->path) . '">' .
                        '<input type="hidden" name="mode" value="reference">' .
                        '<input type="hidden" name="return_to" value="/admin/media">' .
                        '<button type="submit">引用到媒体库</button></form>';
                $rows .= '<tr><td>' . View::escape($item->name) . '</td><td>' . View::escape($item->type) . '</td><td>' . View::escape($item->mimeType) . '</td><td>' . View::escape(number_format($item->byteSize / 1024, 1) . ' KB') . '</td><td><code>' . View::escape($item->path) . '</code></td><td>' . $action . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">Cloudreve 当前目录没有媒体文件。</td></tr>';
        } catch (Throwable $exception) {
            $this->logger->error('Cloudreve media page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('媒体库', '<div class="admin-page-header"><div><h1>媒体库</h1><p class="error">Cloudreve 暂不可用，请检查授权或网络连接。</p></div></div><p><a class="button" href="/admin/media">返回本地媒体</a></p>'), 503);
        }

        $body = '<div class="admin-page-header"><div><h1>媒体库</h1><p class="muted">浏览 Cloudreve 文件，按需引用到 CMS 媒体库。</p></div></div>' .
            '<div class="admin-tabs" role="tablist"><a href="/admin/media">本地媒体</a><span class="active">Cloudreve</span></div>' .
            '<form class="admin-filter-bar" method="get" action="/admin/media"><input type="hidden" name="source" value="cloudreve"><label>目录<input name="path" value="' . View::escape($path) . '"></label><label>搜索<input name="q" value="' . View::escape($query) . '"></label><button type="submit">读取</button></form>' .
            '<table><thead><tr><th>名称</th><th>类型</th><th>MIME</th><th>大小</th><th>路径</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('媒体库 - Cloudreve', $body));
    }

    public function mediaUpload(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($_POST['_csrf'] ?? null)) {
            return Response::text('无权执行此操作。', 403);
        }
        $returnTo = $this->safeExtensionReturn((string) ($_POST['return_to'] ?? ''), false);
        try {
            $files = $this->uploadedFiles($_FILES['media_files'] ?? []);
            if (count($files) === 0) {
                throw new MediaException('No files selected.');
            }
            if (count($files) > $this->mediaLimit('max_files', 10)) {
                throw new MediaException('Too many files.');
            }
            $total = array_sum(array_map(static fn (array $file): int => (int) ($file['size'] ?? 0), $files));
            if ($total > $this->mediaLimit('max_total_bytes', 104857600)) {
                throw new MediaException('Total upload size exceeds limit.');
            }
            $library = $this->mediaLibrary();
            $uploadedIds = [];
            foreach ($files as $file) {
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    throw new MediaException('Upload failed.');
                }
                $uploadedIds[] = $library->uploadLocalFile((string) $file['tmp_name'], (string) $file['name'], (int) ($guard['id'] ?? 0));
            }
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'media.uploaded', [
                'count' => count(array_unique($uploadedIds)),
                'total_bytes' => $total,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Media upload failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('媒体上传失败', '<h1>媒体上传失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/media">返回媒体库</a></p>'), 400);
        }

        return Response::redirect('/admin/media');
    }

    public function mediaProviderList(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $provider = \Cms\Core\Media\RemoteMediaProviderRegistry::get((string) $request->input('provider', ''));
        if ($provider === null) {
            return Response::json(['ok' => false, 'message' => '媒体来源暂不可用，请确认插件已启用。'], 404);
        }

        try {
            $query = trim((string) $request->input('q', ''));
            $path = (string) $request->input('path', '');
            $options = [
                'page' => max(0, (int) $request->input('page', 0)),
                'page_size' => max(1, min(100, (int) $request->input('page_size', 50))),
            ];
            $result = $query !== '' ? $provider->search($query, $path, $options) : $provider->list($path, $options);
            $items = array_map(static fn (\Cms\Core\Media\MediaProviderItem $item): array => $item->toArray(), $result['items']);

            return Response::json([
                'ok' => true,
                'provider' => $provider->id(),
                'label' => $provider->label(),
                'items' => $items,
                'pagination' => $result['pagination'] ?? [],
                'parent' => $result['parent'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Remote media provider list failed', ['source' => 'Core', 'provider' => $provider->id(), 'error' => $exception->getMessage()]);
            return Response::json(['ok' => false, 'message' => '远程媒体暂不可用，请检查授权或网络连接。'], 503);
        }
    }

    public function mediaProviderSelect(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['ok' => false, 'message' => '请求校验失败，请刷新页面后重试。'], 403);
        }
        $provider = \Cms\Core\Media\RemoteMediaProviderRegistry::get((string) $request->input('provider', ''));
        if ($provider === null) {
            return Response::json(['ok' => false, 'message' => '媒体来源暂不可用，请确认插件已启用。'], 404);
        }

        try {
            $item = $provider->get((string) $request->input('id', ''), (string) $request->input('path', ''));
            $mode = (string) $request->input('mode', 'reference');
            if ($mode === 'import') {
                $mediaId = $this->importRemoteMedia($provider, $item, (int) ($guard['id'] ?? 0));
            } else {
                $mediaId = $this->mediaLibrary()->registerRemoteReference($item, (int) ($guard['id'] ?? 0));
            }
            $media = $this->mediaLibrary()->find($mediaId);
            if ($media === null) {
                throw new MediaException('媒体登记失败。');
            }
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'media.remote_selected', [
                'media_id' => $mediaId,
                'provider' => $provider->id(),
                'mode' => $mode === 'import' ? 'import' : 'reference',
            ]);
            $returnTo = $this->safeExtensionReturn((string) $request->input('return_to', ''), false);
            if ($returnTo !== '') {
                return Response::redirect($returnTo);
            }

            return Response::json(['ok' => true, 'media' => $this->mediaPickerItem($media), 'mode' => $mode === 'import' ? 'import' : 'reference']);
        } catch (Throwable $exception) {
            $this->logger->error('Remote media provider select failed', ['source' => 'Core', 'provider' => $provider->id(), 'error' => $exception->getMessage()]);
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    public function mediaProviderUpload(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['ok' => false, 'message' => '请求校验失败，请刷新页面后重试。'], 403);
        }
        $provider = \Cms\Core\Media\RemoteMediaProviderRegistry::get((string) $request->input('provider', ''));
        if ($provider === null) {
            return Response::json(['ok' => false, 'message' => '媒体来源暂不可用，请确认插件已启用。'], 404);
        }

        try {
            $files = $this->uploadedFiles($_FILES['media_files'] ?? []);
            if ($files === []) {
                throw new MediaException('No files selected.');
            }
            $file = $files[0];
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new MediaException('Upload failed.');
            }
            if ((int) ($file['size'] ?? 0) > $this->mediaLimit('max_file_bytes', 52428800)) {
                throw new MediaException('Upload file exceeds size limit.');
            }
            $item = $provider->upload((string) $file['tmp_name'], (string) $file['name'], (string) $request->input('path', ''), (string) ($file['type'] ?? 'application/octet-stream'));
            $mediaId = $this->mediaLibrary()->registerRemoteReference($item, (int) ($guard['id'] ?? 0));
            $media = $this->mediaLibrary()->find($mediaId);
            if ($media === null) {
                throw new MediaException('媒体登记失败。');
            }

            return Response::json(['ok' => true, 'media' => $this->mediaPickerItem($media)]);
        } catch (Throwable $exception) {
            $this->logger->error('Remote media provider upload failed', ['source' => 'Core', 'provider' => $provider->id(), 'error' => $exception->getMessage()]);
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    public function mediaDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        $id = (int) basename($request->path);
        $media = $this->mediaLibrary()->find($id);
        if ($media === null) {
            return Response::text('媒体文件不存在。', 404);
        }
        $refs = $this->mediaLibrary()->references($id);
        $refHtml = '';
        foreach ($refs as $ref) {
            $url = $ref['content_type'] === 'article' ? '/articles/' . $ref['slug'] : '/' . $ref['slug'];
            $refHtml .= '<li>' . View::escape((string) $ref['title']) . ' <code>' . View::escape((string) $ref['block_type']) . ':' . View::escape((string) $ref['field_name']) . '</code> <a href="' . View::escape($url) . '">查看</a></li>';
        }
        $refHtml = $refHtml !== '' ? '<ul>' . $refHtml . '</ul>' : '<p class="muted">暂无内容引用。</p>';
        $mediaUrl = '/media/' . $id;
        $body = '<h1>媒体详情</h1><p><a class="button" href="' . View::escape($mediaUrl) . '" target="_blank" rel="noopener">打开媒体</a> <code>' . View::escape($mediaUrl) . '</code></p>' .
            '<form method="post" action="/admin/media/detail/' . $id . '">' . CsrfToken::field() .
            '<label>标题<input name="title" value="' . View::escape((string) ($media['title'] ?? '')) . '"></label>' .
            '<label>说明<textarea name="description" rows="3">' . View::escape((string) ($media['description'] ?? '')) . '</textarea></label>' .
            '<label>Alt<input name="alt_text" value="' . View::escape((string) ($media['alt_text'] ?? '')) . '"></label>' .
            '<button type="submit" name="action" value="save">保存</button> <button class="admin-danger" type="submit" name="action" value="mark_deleted" onclick="return confirm(\'确定要标记删除这个媒体文件吗？正在被内容引用的媒体不会被删除。\');">标记删除</button> <button class="admin-danger" type="submit" name="action" value="hard_delete" onclick="return confirm(\'确定要永久删除这个媒体文件吗？此操作不可撤销。\');">永久删除</button></form>' .
            '<h2>引用</h2>' . $refHtml;

        return Response::html(View::page('媒体详情', $body));
    }

    public function mediaUpdate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('媒体操作失败', '<h1>媒体操作失败</h1><p class="error">媒体操作必须通过 POST 请求提交。</p><p><a class="button" href="/admin/media">返回媒体库</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = (int) basename($request->path);
        try {
            $library = $this->mediaLibrary();
            if ($library->find($id) === null) {
                throw new MediaException('媒体文件不存在。');
            }
            $action = (string) $request->input('action', 'save');
            if ($action === 'mark_deleted') {
                $this->assertMediaNotReferenced($library, $id);
                $library->markDeleted($id);
                (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'media.mark_deleted', ['media_id' => $id]);
            } elseif ($action === 'hard_delete') {
                $library->hardDelete($id);
                (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'media.hard_deleted', ['media_id' => $id]);
            } else {
                $library->updateMeta($id, [
                    'title' => (string) $request->input('title', ''),
                    'description' => (string) $request->input('description', ''),
                    'alt_text' => (string) $request->input('alt_text', ''),
                ]);
                (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'media.updated', ['media_id' => $id]);
            }
        } catch (Throwable $exception) {
            return Response::html(View::page('媒体操作失败', '<h1>媒体操作失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
        }

        return Response::redirect('/admin/media');
    }

    public function adIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $repo = new AdRepository(ConnectionFactory::make($this->settings));
            return Response::html(View::page('广告统计', $this->adForm($repo)));
        } catch (Throwable $exception) {
            $this->logger->error('Ad admin failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('广告统计', '<h1>广告统计</h1><p class="error">广告模块暂不可用。</p>'), 500);
        }
    }

    public function adSave(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('广告统计', '<h1>广告统计</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p>'), 400);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new AdRepository($pdo);
            $repo->replaceSlots($this->adSlotInput($request));
            $repo->saveAdsTxt((string) $request->input('ads_txt', ''));
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'ads.settings_saved');
        } catch (Throwable $exception) {
            $this->logger->error('Ad settings save failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('广告统计保存失败', '<h1>广告统计保存失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/ads">返回广告统计</a></p>'), 422);
        }

        return Response::redirect('/admin/ads?saved=1');
    }

    private function assertMediaNotReferenced(MediaLibrary $library, int $id): void
    {
        if ($library->references($id) !== []) {
            throw new MediaException('媒体仍被内容引用，请先从内容中移除引用后再删除。');
        }
    }

    public function navigationIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html(View::page('导航菜单', $this->navigationForm()));
    }

    public function navigationSave(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('导航菜单', $this->navigationForm('导航保存必须通过 POST 请求提交。')), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('导航菜单', $this->navigationForm('CSRF 校验失败，请刷新页面重试。')), 403);
        }

        try {
            $items = NavigationBuilder::sanitizeForSave($request->input('navigation', []));
            $delete = $request->input('nav_delete', null);
            if ($delete !== null && ctype_digit((string) $delete)) {
                unset($items[(int) $delete]);
                $items = array_values($items);
            }
            $quick = $this->navigationQuickItem($request);
            if ($quick !== null) {
                $items[] = $quick;
            }
            $this->writeConfig($this->root(), static function (array $config) use ($items): array {
                $config['navigation'] = is_array($config['navigation'] ?? null) ? $config['navigation'] : [];
                $config['navigation']['primary'] = $items;
                return $config;
            });
            $pdo = ConnectionFactory::make($this->settings);
            (new AuditLogger($pdo))->record('admin', $this->adminActorId(), 'navigation.update', ['items' => count($items)]);
        } catch (Throwable $exception) {
            $this->logger->error('Navigation save failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('导航菜单', $this->navigationForm('导航保存失败，请稍后重试。')), 400);
        }

        return Response::redirect('/admin/navigation?saved=1');
    }

    public function themeIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = $this->root();
        $manager = new ThemeManager($root . '/content/themes', $this->settings, $this->logger);
        $active = $manager->activeThemeId();
        $enabledPlugins = [];
        try {
            $enabledPlugins = $this->enabledPluginIds(ConnectionFactory::make($this->settings));
        } catch (Throwable) {
            $enabledPlugins = [];
        }
        $cards = '';
        foreach ($this->themeRows($root, $manager, $enabledPlugins) as $row) {
            $settingsUrl = '/admin/themes/' . rawurlencode($row['id']) . '/settings';
            $activate = $row['valid'] && $row['usable'] && !$row['current']
                ? '<form method="post" action="/admin/themes/activate">' . CsrfToken::field() .
                    '<input type="hidden" name="theme_id" value="' . View::escape($row['id']) . '"><button type="submit">启用</button></form>'
                : '';
            $delete = $row['valid'] && !$row['current'] && !in_array($row['id'], ['default', 'safe'], true)
                ? '<form method="post" action="/admin/themes/delete" data-confirm="确定要删除这个主题吗？此操作不可撤销。">' . CsrfToken::field() .
                    '<input type="hidden" name="theme_id" value="' . View::escape($row['id']) . '"><button class="admin-danger" type="submit">删除</button></form>'
                : '';
            $tone = $row['current'] ? 'success' : ($row['usable'] ? 'muted' : 'danger');
            $initial = $row['name'] !== ''
                ? (function_exists('mb_substr') ? mb_substr($row['name'], 0, 1, 'UTF-8') : substr($row['name'], 0, 1))
                : 'T';
            $cards .= '<article class="admin-theme-card admin-theme-card-v2">' .
                '<div class="admin-theme-preview" aria-label="主题预览"><span>' . View::escape($initial) . '</span></div>' .
                '<div class="admin-theme-card-body"><div class="admin-theme-card-title"><h2>' . View::escape($row['name']) . '</h2><span class="admin-badge admin-badge-' . $tone . '">' . View::escape($row['current'] ? '当前启用' : ($row['usable'] ? '未启用' : '不可用')) . '</span></div>' .
                '<p class="muted">' . View::escape((string) ($row['description'] !== '' ? $row['description'] : ($row['reason'] !== '' ? $row['reason'] : '清单与依赖检查通过。'))) . '</p>' .
                '<dl class="admin-theme-meta"><div><dt>版本</dt><dd>v' . View::escape($row['version'] !== '' ? $row['version'] : '-') . '</dd></div><div><dt>作者</dt><dd>' . View::escape($row['author'] !== '' ? $row['author'] : '-') . '</dd></div><div><dt>兼容</dt><dd>' . View::escape($row['compatible'] ? '兼容' : '不兼容') . '</dd></div></dl>' .
                '<div class="admin-theme-actions">' .
                ($row['valid'] ? '<a class="button" href="' . View::escape($settingsUrl) . '">' . View::escape($row['current'] ? '自定义' : '主题设置') . '</a>' : '') .
                '<a class="button admin-button-secondary" href="/" target="_blank" rel="noopener">实时预览</a>' .
                '<a class="button admin-button-secondary" href="' . View::escape($settingsUrl) . '#theme-details">查看详情</a>' .
                $activate . $delete .
                '</div></div></article>';
        }

        $warning = '';
        try {
            $manager->assertUsable($active, $enabledPlugins);
        } catch (Throwable $exception) {
            $warning = '<p class="error">当前主题不可用，前台会自动使用安全主题渲染。原因：' . View::escape($exception->getMessage()) . '</p>';
        }

        $notice = '';
        if (($this->requestQueryFlag('settings_saved'))) {
            $notice = '<div class="admin-notice admin-notice-success">主题设置已保存。</div>';
        } elseif (($this->requestQueryFlag('settings_reset'))) {
            $notice = '<div class="admin-notice admin-notice-success">已恢复主题默认设置。</div>';
        } elseif (($this->requestQueryFlag('deleted'))) {
            $notice = '<div class="admin-notice admin-notice-success">主题已删除。</div>';
        } elseif (($this->requestQueryFlag('installed'))) {
            $notice = '<div class="admin-notice admin-notice-success">主题已安装。</div>';
        }

        $body = '<div class="admin-page-header"><div><h1>主题管理</h1><p class="muted">查看已安装主题、启用主题、进入独立主题设置页。主题元数据和用户设置已分离。</p></div></div>' .
            $notice .
            $warning .
            '<section class="admin-card"><form method="post" action="/admin/themes/local-install" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>上传主题 ZIP 安装<input type="file" name="theme_zip" accept=".zip" required></label><button type="submit">上传并安装主题</button></form></section>' .
            '<p class="muted">主题包必须包含单独主题目录和 theme.json，只会写入 content/themes/{theme_id}。</p>' .
            '<div class="admin-theme-grid admin-theme-grid-v2">' . ($cards !== '' ? $cards : '<div class="admin-empty">暂无主题</div>') . '</div>';

        return Response::html(View::page('主题', $body));
    }

    public function themeSettings(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $themeId = $this->themeIdFromSettingsPath($request->path);
        $root = $this->root();
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $manager = new ThemeManager($root . '/content/themes', $this->settings, $this->logger);
            $runtime = $manager->load($themeId);
            $enabledPlugins = $this->enabledPluginIds($pdo);
            $status = $manager->describe($themeId, $runtime->manifest, $enabledPlugins);
        } catch (Throwable $exception) {
            return Response::html(View::page('主题设置', '<h1>主题设置</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 404);
        }

        $schema = $this->themeSettingsSchema($runtime->manifest->settingsSchema);
        $fields = $this->themeSettingsSections($themeId, $schema);
        $notice = '';
        if ($this->requestQueryFlag('saved')) {
            $notice = '<div class="admin-notice admin-notice-success">主题设置已保存。</div>';
        } elseif ($this->requestQueryFlag('reset')) {
            $notice = '<div class="admin-notice admin-notice-success">已恢复主题默认设置。</div>';
        }
        $details = $this->themeDetailsPanel([
            'id' => $themeId,
            'name' => $runtime->manifest->name,
            'version' => $runtime->manifest->version,
            'author' => $runtime->manifest->author,
            'description' => $this->themeManifestDescription($runtime->manifest->settingsSchema),
            'compatible' => (bool) $status['compatible'],
            'reason' => (string) $status['reason'],
            'required_plugins' => $runtime->manifest->requiredPlugins,
            'current' => (bool) $status['current'],
        ], $runtime->manifest->settingsSchema);
        $body = '<div class="admin-page-header admin-theme-settings-header"><div><p class="admin-kicker">主题设置</p><h1>' . View::escape($runtime->manifest->name) . '</h1><p class="muted">' . View::escape($runtime->manifest->author) . ' · v' . View::escape($runtime->manifest->version) . '</p></div><div class="admin-action-row"><a class="button admin-button-secondary" href="/" target="_blank" rel="noopener">实时预览</a><button form="theme-settings-form" type="submit">保存设置</button></div></div>' .
            $notice .
            '<form id="theme-settings-form" method="post" action="/admin/themes/settings" enctype="multipart/form-data" class="admin-theme-settings-form" data-unsaved-warning="有未保存的更改">' . CsrfToken::field() .
            '<input type="hidden" name="theme_id" value="' . View::escape($themeId) . '">' .
            '<div class="admin-theme-settings-layout"><aside class="admin-theme-settings-nav">' . $this->themeSettingsNav($schema) . '<a href="#theme-details">主题详情</a></aside><div class="admin-theme-settings-main">' .
            ($fields !== '' ? $fields : '<section class="admin-card"><h2>主题设置</h2><p class="muted">此主题未提供可视化主题设置。</p></section>') .
            $details .
            '<section class="admin-card admin-theme-reset-card"><h2>高级操作</h2><p class="muted">恢复默认设置会清除当前主题的自定义配置，不会删除主题文件。</p><button class="admin-danger" form="theme-reset-form" type="submit">恢复主题默认设置</button></section>' .
            '</div></div></form>' .
            '<form id="theme-reset-form" method="post" action="/admin/themes/reset" data-confirm="此操作会清除当前主题的自定义设置并恢复默认值，是否继续？">' . CsrfToken::field() . '<input type="hidden" name="theme_id" value="' . View::escape($themeId) . '"></form>';

        return Response::html(View::page('主题设置', $body));
    }

    public function themeLocalInstall(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if ($request->method !== 'POST') {
            return Response::html(View::page('主题切换失败', '<h1>主题切换失败</h1><p class="error">主题切换必须通过 POST 请求提交。</p><p><a class="button" href="/admin/themes">返回主题</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $file = $_FILES['theme_zip'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return Response::html(View::page('主题安装失败', '<h1>主题安装失败</h1><p class="error">请选择有效的主题 ZIP 文件。</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 400);
        }

        try {
            $result = (new LocalThemePackageInstaller($this->root(), $this->settings, $this->logger))->install((string) $file['tmp_name']);
            $pdo = ConnectionFactory::make($this->settings);
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'theme.installed', $result);
        } catch (Throwable $exception) {
            $this->logger->error('Theme ZIP install failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('主题安装失败', '<h1>主题安装失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 400);
        }

        return Response::redirect('/admin/themes?installed=1');
    }

    public function themeActivate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if ($request->method !== 'POST') {
            return Response::html(View::page('主题切换失败', '<h1>主题切换失败</h1><p class="error">主题切换必须通过 POST 请求提交。</p><p><a class="button" href="/admin/themes">返回主题</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $themeId = (string) $request->input('theme_id', '');
        $root = $this->root();
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $manager = new ThemeManager($root . '/content/themes', $this->settings, $this->logger);
            $manager->assertUsable($themeId, $this->enabledPluginIds($pdo));
            $oldTheme = $manager->activeThemeId();
            $this->writeThemeConfig($root, static function (array $items) use ($themeId): array {
                $items['theme']['active'] = $themeId;
                $items['theme']['settings'][$themeId] = $items['theme']['settings'][$themeId] ?? [];
                return $items;
            });
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'theme.activated', ['from' => $oldTheme, 'to' => $themeId]);
        } catch (Throwable $exception) {
            $this->logger->error('Theme activation failed', ['source' => 'Core', 'theme_id' => $themeId, 'error' => $exception->getMessage()]);
            return Response::html(View::page('主题切换失败', '<h1>主题切换失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题</a></p>'), 400);
        }

        return Response::redirect('/admin/themes');
    }

    public function themeSettingsSave(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if ($request->method !== 'POST') {
            return Response::html(View::page('主题设置保存失败', '<h1>主题设置保存失败</h1><p class="error">主题设置保存必须通过 POST 请求提交。</p><p><a class="button" href="/admin/themes">返回主题</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $themeId = (string) $request->input('theme_id', '');
        $root = $this->root();
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $manager = new ThemeManager($root . '/content/themes', $this->settings, $this->logger);
            $runtime = $manager->load($themeId);
            $schema = $this->themeSettingsSchema($runtime->manifest->settingsSchema, true);
            $current = $this->settings->get('theme.settings.' . $themeId, []);
            $input = $request->input('settings', []);
            $input = is_array($input) ? $input : [];
            foreach ($this->themeUploadedAssetUrls($schema, (int) ($guard['id'] ?? 0)) as $field => $url) {
                $input[$field] = $url;
            }
            $settings = $this->sanitizeThemeSettings($schema, $input, is_array($current) ? $current : []);
            $this->writeThemeConfig($root, static function (array $items) use ($themeId, $settings): array {
                $items['theme'] = is_array($items['theme'] ?? null) ? $items['theme'] : [];
                $items['theme']['settings'] = is_array($items['theme']['settings'] ?? null) ? $items['theme']['settings'] : [];
                $items['theme']['settings'][$themeId] = $settings;
                return $items;
            });
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'theme.settings_saved', ['theme_id' => $themeId]);
        } catch (Throwable $exception) {
            $this->logger->error('Theme settings save failed', ['source' => 'Core', 'theme_id' => $themeId, 'error' => $exception->getMessage()]);
            $safeThemeId = preg_match('/^[a-z][a-z0-9_]{2,63}$/', $themeId) === 1 ? rawurlencode($themeId) : '';
            $back = $safeThemeId !== '' ? '/admin/themes/' . $safeThemeId . '/settings' : '/admin/themes';
            return Response::html(View::page('主题设置保存失败', '<h1>主题设置保存失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="' . View::escape($back) . '">返回主题设置</a></p>'), 400);
        }

        return Response::redirect('/admin/themes/' . rawurlencode($themeId) . '/settings?saved=1');
    }

    public function themeSettingsReset(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('恢复主题默认设置', '<h1>恢复主题默认设置</h1><p class="error">恢复默认设置必须通过 POST 请求提交。</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $themeId = (string) $request->input('theme_id', '');
        try {
            (new ThemeManager($this->root() . '/content/themes', $this->settings, $this->logger))->load($themeId);
            $this->writeThemeConfig($this->root(), static function (array $items) use ($themeId): array {
                if (isset($items['theme']['settings']) && is_array($items['theme']['settings'])) {
                    unset($items['theme']['settings'][$themeId]);
                }
                return $items;
            });
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', $guard['id'] ?? null, 'theme.settings_reset', ['theme_id' => $themeId]);
        } catch (Throwable $exception) {
            $this->logger->error('Theme settings reset failed', ['source' => 'Core', 'theme_id' => $themeId, 'error' => $exception->getMessage()]);
            return Response::html(View::page('恢复主题默认设置', '<h1>恢复主题默认设置</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 400);
        }

        return Response::redirect('/admin/themes/' . rawurlencode($themeId) . '/settings?reset=1');
    }

    public function themeDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('删除主题', '<h1>删除主题</h1><p class="error">删除主题必须通过 POST 请求提交。</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $themeId = (string) $request->input('theme_id', '');
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $themeId) || in_array($themeId, ['default', 'safe'], true)) {
            return Response::html(View::page('删除主题', '<h1>删除主题</h1><p class="error">主题 ID 无效或系统主题不可删除。</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 400);
        }

        try {
            $manager = new ThemeManager($this->root() . '/content/themes', $this->settings, $this->logger);
            if ($themeId === $manager->activeThemeId()) {
                throw new \RuntimeException('当前启用的主题不能删除，请先切换到其他主题。');
            }
            $dir = $this->root() . '/content/themes/' . $themeId;
            if (!is_dir($dir)) {
                throw new \RuntimeException('主题不存在。');
            }
            $manager->load($themeId);
            $this->removeThemeDirectory($dir);
            $this->writeThemeConfig($this->root(), static function (array $items) use ($themeId): array {
                if (isset($items['theme']['settings']) && is_array($items['theme']['settings'])) {
                    unset($items['theme']['settings'][$themeId]);
                }
                return $items;
            });
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', $guard['id'] ?? null, 'theme.deleted', ['theme_id' => $themeId]);
        } catch (Throwable $exception) {
            $this->logger->error('Theme delete failed', ['source' => 'Core', 'theme_id' => $themeId, 'error' => $exception->getMessage()]);
            return Response::html(View::page('删除主题', '<h1>删除主题</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题管理</a></p>'), 400);
        }

        return Response::redirect('/admin/themes?deleted=1');
    }

    public function pluginIndex(): Response
    {
        return $this->extensionIndex('plugin');
    }

    public function moduleIndex(): Response
    {
        return $this->extensionIndex('module');
    }

    private function extensionIndex(string $scope): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $isModule = $scope === 'module';
        $title = $isModule ? '模块管理' : '插件管理';
        $itemLabel = $isModule ? '模块' : '插件';
        $emptyLabel = $isModule ? '暂无模块' : '暂无插件';
        $returnTo = $isModule ? '/admin/modules' : '/admin/plugins';
        $detailFrom = $isModule ? 'modules' : 'plugins';

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $root = $this->root();
            $manager = new PluginManager($root . '/content/plugins', $pdo, $this->logger, new EventDispatcher(), new BlockRegistry(), null, new OfficialPluginRegistry($root));
            $manager->syncDiscovered();
            $manifests = $manager->discover();
            $stmt = $pdo->query('SELECT plugin_id, name, version, status, trust_level, capabilities_json, source, dependencies_json, last_error FROM cms_plugins ORDER BY plugin_id');
            $rows = '';
            $cards = '';
            foreach ($stmt->fetchAll() as $row) {
                $pluginId = (string) $row['plugin_id'];
                $manifest = $manifests[$pluginId] ?? null;
                $status = (string) $row['status'];
                $next = $status === PluginLifecycle::ENABLED ? PluginLifecycle::DISABLED : PluginLifecycle::ENABLED;
                $capabilities = json_decode((string) ($row['capabilities_json'] ?? '[]'), true) ?: [];
                if ($this->isContentModule($capabilities, $pluginId, $manifest, $row) !== $isModule) {
                    continue;
                }
                $capabilityCount = count($capabilities);
                $dependencyWarning = $this->pluginDependencyWarning($pdo, $pluginId, json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: []);
                $statusLabel = AdminUiText::pluginStatus($status) . ($dependencyWarning !== '' ? '<br><span class="error">' . View::escape($dependencyWarning) . '</span>' : '');
                $settingsUrl = AdminUiText::pluginSettingsUrl($pluginId);
                $settingsLink = $settingsUrl === '' || $dependencyWarning !== '' ? '' : ' <a class="button" href="' . View::escape($settingsUrl) . '">设置</a>';
                $rows .= '<tr><td>' . View::escape(AdminUiText::pluginName($pluginId, (string) ($manifest?->name ?? $row['name'] ?? ''))) .
                    '</td><td>' . $statusLabel . '</td><td>' . View::escape(AdminUiText::pluginType($pluginId, (string) $row['trust_level'], (string) ($row['source'] ?? ''))) .
                    '</td><td>' . View::escape((string) ($manifest?->version ?? $row['version'] ?? '')) .
                    '</td><td><span class="muted">权限：' . $capabilityCount . ' 项</span> <a class="button" href="/admin/plugins/detail?id=' . rawurlencode($pluginId) . '&from=' . $detailFrom . '#permissions">查看权限</a> <a class="button" href="/admin/plugins/detail?id=' . rawurlencode($pluginId) . '&from=' . $detailFrom . '">详情</a>' . $settingsLink .
                    '<form method="post" action="/admin/plugins/status">' . CsrfToken::field() .
                    '<input type="hidden" name="plugin_id" value="' . View::escape($pluginId) . '">' .
                    '<input type="hidden" name="status" value="' . View::escape($next) . '">' .
                    '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
                    '<button type="submit">' . View::escape(AdminUiText::pluginAction($next)) . '</button></form></td></tr>';
                $statusTone = $status === PluginLifecycle::ENABLED ? 'success' : 'muted';
                $cards .= '<article class="admin-extension-card"><h3>' . View::escape(AdminUiText::pluginName($pluginId, (string) ($manifest?->name ?? $row['name'] ?? ''))) . '</h3>' .
                    '<p><code>' . View::escape($pluginId) . '</code></p><p><span class="admin-badge admin-badge-' . $statusTone . '">' . View::escape(AdminUiText::pluginStatus($status)) . '</span> <span class="admin-tag">' . View::escape(AdminUiText::pluginType($pluginId, (string) $row['trust_level'], (string) ($row['source'] ?? ''))) . '</span></p>' .
                    '<p class="muted">版本 ' . View::escape((string) ($manifest?->version ?? $row['version'] ?? '')) . ' · 权限 ' . $capabilityCount . ' 项</p><div class="admin-action-row"><a class="button admin-button-secondary" href="/admin/plugins/detail?id=' . rawurlencode($pluginId) . '&from=' . $detailFrom . '">详情</a>' . $settingsLink .
                    '</div><p class="muted">启用、停用和危险操作在下方完整表格或详情页中执行。</p></article>';
            }
        } catch (Throwable $exception) {
            $this->logger->error('Plugin index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page($title, '<h1>' . View::escape($title) . '</h1><p class="error">扩展服务暂不可用，请先运行迁移。</p>'), 500);
        }

        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">' . View::escape($emptyLabel) . '</td></tr>';
        $body = '<div class="admin-page-header"><div><h1>' . View::escape($title) . '</h1><p class="muted">停用只会停止' . View::escape($itemLabel) . '运行，不会删除文件、配置或业务数据。</p></div></div>' .
            '<section class="admin-card"><form method="post" action="/admin/plugins/local-preview" enctype="multipart/form-data">' . CsrfToken::field() .
            '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
            '<label>上传 ZIP 安装<input type="file" name="plugin_zip" accept=".zip" required></label><button type="submit">上传并扫描</button></form></section>' .
            '<p class="muted">本地插件由管理员自行承担信任责任，安装前会先进行安全扫描和预检。</p>' .
            '<div class="admin-extension-grid">' . ($cards !== '' ? $cards : '<div class="admin-empty">' . View::escape($emptyLabel) . '</div>') . '</div>' .
            '<table><thead><tr><th>' . View::escape($itemLabel) . '</th><th>状态</th><th>类型</th><th>版本</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page($title, $body));
    }

    public function pluginDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $pluginId = trim((string) $request->input('id', ''));
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $root = $this->root();
            $manager = new PluginManager($root . '/content/plugins', $pdo, $this->logger, new EventDispatcher(), new BlockRegistry(), null, new OfficialPluginRegistry($root));
            $manager->syncDiscovered();
            $manifest = $manager->discover()[$pluginId] ?? null;
            $stmt = $pdo->prepare('SELECT * FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
            $stmt->execute([':plugin_id' => $pluginId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return Response::html(View::page('插件详情', '<h1>插件详情</h1><p class="error">插件不存在。</p><p><a class="button" href="/admin/plugins">返回插件管理</a></p>'), 404);
            }
            $preview = (new LocalPluginPackageInstaller($root, $pdo))->purgePreview($pluginId);
        } catch (Throwable $exception) {
            $this->logger->error('Plugin detail failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('插件详情', '<h1>插件详情</h1><p class="error">插件详情暂不可用。</p><p><a class="button" href="/admin/plugins">返回插件管理</a></p>'), 500);
        }

        $capabilities = json_decode((string) ($row['capabilities_json'] ?? '[]'), true) ?: [];
        $dependencies = json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: [];
        $dependencyWarning = $this->pluginDependencyWarning($pdo, $pluginId, $dependencies);
        $returnTo = $this->safeExtensionReturn((string) $request->input('from', ''), $this->isContentModule($capabilities, $pluginId, $manifest, $row));
        $returnLabel = $returnTo === '/admin/modules' ? '返回模块管理' : '返回插件管理';
        $permissionRows = '';
        foreach ($capabilities as $capability) {
            $permissionRows .= '<tr><td>' . View::escape(AdminUiText::capability((string) $capability)) . '</td><td><code>' . View::escape((string) $capability) . '</code></td></tr>';
        }
        $permissionRows = $permissionRows !== '' ? $permissionRows : '<tr><td colspan="2" class="muted">无声明权限</td></tr>';

        $tables = [];
        foreach (($preview['plugin_owned_tables'] ?? []) as $table) {
            if (is_scalar($table) && (string) $table !== '') {
                $tables[] = (string) $table;
            }
        }
        $impact = [
            '将删除的数据记录：' . (int) ($preview['plugin_data_records'] ?? 0),
            '将删除的任务记录：' . (int) ($preview['plugin_tasks'] ?? 0),
            '将删除的插件设置：' . (int) ($preview['plugin_settings'] ?? 0),
            '将删除的媒体引用：' . (int) ($preview['plugin_media_references'] ?? 0),
            '涉及内容数量：' . (int) ($preview['plugin_block_content_count'] ?? 0),
            '依赖该插件的其他插件：' . count($preview['dependents'] ?? []),
            '原始内容区块数据：默认保留',
        ];
        $impactHtml = '';
        foreach ($impact as $line) {
            $impactHtml .= '<li>' . View::escape($line) . '</li>';
        }
        $impactHtml .= '<li>将删除的数据表：' . View::escape($tables === [] ? '未发现插件专属表' : implode(', ', array_values(array_unique($tables)))) . '</li>';

        $settingsUrl = AdminUiText::pluginSettingsUrl($pluginId);
        $settingsLink = $settingsUrl === '' ? '' : '<a class="button" href="' . View::escape($settingsUrl) . '">打开设置</a> ';
        $status = (string) ($row['status'] ?? '');
        $next = $status === PluginLifecycle::ENABLED ? PluginLifecycle::DISABLED : PluginLifecycle::ENABLED;
        $body = '<h1>' . View::escape(AdminUiText::pluginName($pluginId, (string) ($row['name'] ?? ''))) . '</h1>' .
            '<p><a class="button" href="' . View::escape($returnTo) . '">' . View::escape($returnLabel) . '</a> ' . $settingsLink . '</p>' .
            '<table><tbody>' .
            '<tr><th>插件名称</th><td>' . View::escape((string) ($manifest?->name ?? $row['name'] ?? '')) . '</td></tr>' .
            '<tr><th>插件版本</th><td>' . View::escape((string) ($manifest?->version ?? $row['version'] ?? '')) . '</td></tr>' .
            '<tr><th>插件作者</th><td>' . View::escape((string) ($manifest?->author ?? $row['author'] ?? '')) . '</td></tr>' .
            '<tr><th>插件 ID</th><td><code>' . View::escape($pluginId) . '</code></td></tr>' .
            '<tr><th>插件类型</th><td>' . View::escape(AdminUiText::pluginType($pluginId, (string) ($row['trust_level'] ?? ''), (string) ($row['source'] ?? ''))) . '</td></tr>' .
            '<tr><th>运行模式</th><td>' . View::escape(AdminUiText::trustLevel((string) ($row['trust_level'] ?? ''))) . ' <span class="muted">(<code>' . View::escape((string) ($row['trust_level'] ?? '')) . '</code>)</span></td></tr>' .
            '<tr><th>权限数量</th><td>' . count($capabilities) . ' 项</td></tr>' .
            '<tr><th>安装时间</th><td>' . View::escape((string) ($row['installed_at'] ?? '')) . '</td></tr>' .
            '<tr><th>启用状态</th><td>' . View::escape(AdminUiText::pluginStatus($status)) . '</td></tr>' .
            '<tr><th>依赖关系</th><td>' . View::escape($this->pluginDependencySummary($dependencies)) . ($dependencyWarning !== '' ? '<br><span class="error">' . View::escape($dependencyWarning) . '</span>' : '') . '<br><span class="muted">高级信息：<code>' . View::escape((string) ($row['dependencies_json'] ?? '[]')) . '</code></span></td></tr>' .
            '<tr><th>数据状态</th><td>' . View::escape(implode('；', $impact)) . '</td></tr>' .
            '</tbody></table>' .
            '<h2 id="permissions">权限</h2><table><thead><tr><th>说明</th><th>内部权限 key</th></tr></thead><tbody>' . $permissionRows . '</tbody></table>' .
            '<h2>日常操作</h2><p class="muted">停用插件会停止插件运行，但保留插件文件、配置和业务数据，可随时重新启用。</p>' .
            '<form method="post" action="/admin/plugins/status">' . CsrfToken::field() .
            '<input type="hidden" name="plugin_id" value="' . View::escape($pluginId) . '">' .
            '<input type="hidden" name="status" value="' . View::escape($next) . '">' .
            '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
            '<button type="submit">' . View::escape(AdminUiText::pluginAction($next)) . '</button></form>' .
            '<h2>卸载插件</h2><p class="muted">删除插件程序文件，但默认保留该插件产生的业务数据。以后重新安装兼容版本插件时，应能够重新识别已有数据。</p>' .
            '<form method="post" action="/admin/plugins/uninstall" onsubmit="return confirm(\'将卸载插件程序文件，但保留业务数据。是否继续？\')">' . CsrfToken::field() .
            '<input type="hidden" name="plugin_id" value="' . View::escape($pluginId) . '"><input type="hidden" name="return_to" value="' . View::escape($returnTo) . '"><button type="submit">卸载插件</button></form>' .
            '<h2>危险操作</h2><p class="error">此操作将永久删除该插件产生的数据，并且无法恢复。卸载插件本身并不会删除这些数据。建议先导出或备份。</p>' .
            '<ul>' . $impactHtml . '</ul>' .
            '<form method="post" action="/admin/plugins/purge" onsubmit="return confirm(\'危险操作：将永久删除该插件数据且无法恢复。确认继续？\')">' . CsrfToken::field() .
            '<input type="hidden" name="plugin_id" value="' . View::escape($pluginId) . '">' .
            '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
            '<label>请输入插件 ID 确认永久删除数据<input name="confirm_plugin_id" placeholder="' . View::escape($pluginId) . '" required></label>' .
            '<button type="submit">永久删除数据</button></form>';

        return Response::html(View::page('插件详情', $body));
    }

    public function pluginStatus(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if ($request->method !== 'POST') {
            return Response::html(
                View::page(
                    '插件状态变更失败',
                    '<h1>插件状态变更失败</h1><p class="error">插件状态变更必须通过 POST 请求提交。</p><p><a class="button" href="/admin/plugins">返回插件管理</a></p>'
                ),
                405
            )->withHeaders([
                'Allow' => 'POST',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $pluginId = (string) $request->input('plugin_id', '');
        $status = (string) $request->input('status', '');
        $returnTo = $this->safeExtensionReturn((string) $request->input('return_to', ''), false);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $user = (new AdminAuthenticator($pdo))->user();
            $lifecycle = new LocalPluginPackageInstaller($this->root(), $pdo);
            if ($status === PluginLifecycle::ENABLED) {
                $lifecycle->enable($pluginId, (int) ($user['id'] ?? 0));
            } elseif ($status === PluginLifecycle::DISABLED) {
                $lifecycle->disableWithDependents($pluginId, (int) ($user['id'] ?? 0), true);
            } else {
                (new PluginManager($this->root() . '/content/plugins', $pdo, $this->logger, new EventDispatcher(), new BlockRegistry()))->setStatus($pluginId, $status);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Plugin status change failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::text('插件状态变更失败。', 500);
        }

        return Response::redirect($returnTo);
    }

    public function pluginLocalPreview(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($_POST['_csrf'] ?? null)) {
            return Response::text('无权执行此操作。', 403);
        }
        $returnTo = $this->safeExtensionReturn((string) ($_POST['return_to'] ?? ''), false);
        try {
            $file = $_FILES['plugin_zip'] ?? [];
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('ZIP 上传失败。');
            }
            if ((int) ($file['size'] ?? 0) > 10485760) {
                throw new \RuntimeException('ZIP 文件超过大小限制。');
            }
            $tmpDir = $this->root() . '/storage/plugin-installs/uploads';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            $tmp = $tmpDir . '/plugin-' . bin2hex(random_bytes(8)) . '.zip';
            if (!move_uploaded_file((string) $file['tmp_name'], $tmp) && !rename((string) $file['tmp_name'], $tmp)) {
                throw new \RuntimeException('无法保存上传 ZIP。');
            }
            $plan = (new LocalPluginPackageInstaller($this->root(), ConnectionFactory::make($this->settings)))->preview($tmp, (int) ($guard['id'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('Local plugin preview failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('插件安装预检失败', '<h1>插件安装预检失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="' . View::escape($returnTo) . '">返回</a></p>'), 400);
        }

        $scan = $plan['scan'];
        $riskBoundary = is_array($plan['risk_boundary'] ?? null) ? $plan['risk_boundary'] : [];
        $body = '<h1>本地插件安装预检</h1><p class="error">本地安装、未经官方市场审核。本地插件由管理员自行承担信任责任。</p>' .
            '<ul><li>包名：' . View::escape((string) $plan['name']) . '</li><li>插件 ID：' . View::escape((string) $plan['plugin_id']) .
            '</li><li>版本：' . View::escape((string) $plan['version']) . '</li><li>作者：' . View::escape((string) $plan['author']) .
            '</li><li>权限：' . View::escape(implode(', ', $plan['capabilities'])) . '</li><li>依赖：' . View::escape(json_encode($plan['required_plugins'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) .
            '</li><li>运行边界：' . View::escape((string) ($riskBoundary['label'] ?? '受限 API 插件')) . ' - ' . View::escape((string) ($riskBoundary['admin_notice'] ?? '第三方插件只能使用声明能力与受控 API。')) .
            '</li><li>兼容性：' . View::escape((string) $plan['compatibility']) . '</li><li>扫描：' . View::escape((string) $scan['status']) . '</li></ul>' .
            '<pre>' . View::escape(json_encode($scan['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>' .
            '<form method="post" action="/admin/plugins/local-install">' . CsrfToken::field() .
            '<input type="hidden" name="token" value="' . View::escape((string) $plan['token']) . '">' .
            '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
            '<label><input type="checkbox" name="allow_review" value="1"> 我确认理解 needs_review 风险</label>' .
            '<label><input type="checkbox" name="enable" value="1"> 安装后启用</label><button type="submit">确认安装</button></form>';

        return Response::html(View::page('本地插件安装预检', $body));
    }

    public function pluginLocalInstall(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $returnTo = $this->safeExtensionReturn((string) $request->input('return_to', ''), false);
        try {
            (new LocalPluginPackageInstaller($this->root(), ConnectionFactory::make($this->settings)))->install(
                (string) $request->input('token', ''),
                (int) ($guard['id'] ?? 0),
                (string) $request->input('enable', '') === '1',
                (string) $request->input('allow_review', '') === '1',
            );
        } catch (Throwable $exception) {
            return Response::html(View::page('本地插件安装失败', '<h1>本地插件安装失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="' . View::escape($returnTo) . '">返回</a></p>'), 400);
        }

        return Response::redirect($returnTo);
    }

    public function pluginUninstall(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $returnTo = $this->safeExtensionReturn((string) $request->input('return_to', ''), false);
        try {
            (new LocalPluginPackageInstaller($this->root(), ConnectionFactory::make($this->settings)))->uninstallCode((string) $request->input('plugin_id', ''), (int) ($guard['id'] ?? 0));
        } catch (Throwable $exception) {
            return Response::text($exception->getMessage(), 400);
        }
        return Response::redirect($returnTo);
    }

    public function pluginPurge(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $returnTo = $this->safeExtensionReturn((string) $request->input('return_to', ''), false);
        try {
            $pluginId = (string) $request->input('plugin_id', '');
            $confirmation = (string) $request->input('confirmation', '');
            if ($confirmation === '' && (string) $request->input('confirm_plugin_id', '') === $pluginId) {
                $confirmation = 'PURGE ' . $pluginId;
            }
            (new LocalPluginPackageInstaller($this->root(), ConnectionFactory::make($this->settings)))->purge($pluginId, (int) ($guard['id'] ?? 0), $confirmation);
        } catch (Throwable $exception) {
            return Response::text($exception->getMessage(), 400);
        }
        return Response::redirect($returnTo);
    }

    public function transferIndex(?Request $request = null): Response
    {
        $request ??= new Request('GET', '/admin/transfer');
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = $this->root();
        $exports = glob($root . '/storage/exports/cms-export-*.zip') ?: [];
        sort($exports);
        $items = '';
        foreach ($exports as $file) {
            $items .= $this->transferExportRow($file);
        }
        $items = $items !== ''
            ? '<table><thead><tr><th>文件</th><th>创建时间</th><th>媒体文件</th><th>支付账本</th><th>恢复命令</th><th>操作</th></tr></thead><tbody>' . $items . '</tbody></table>'
            : '<p class="muted">暂无导出包</p>';
        try {
            $urlMappings = $this->transferUrlMappingTable(new UrlMappingRepository(ConnectionFactory::make($this->settings)));
        } catch (Throwable $exception) {
            $this->logger->error('URL mapping list failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $urlMappings = '<p class="error">URL Mapping 暂不可用。</p>';
        }

        $notice = '';
        if ((string) ($request->query['deleted'] ?? '') === '1') {
            $notice = '<p class="muted">导出包已删除。</p>';
        } elseif ((string) ($request->query['delete_failed'] ?? '') === '1') {
            $notice = '<p class="error">导出包删除失败，请稍后重试。</p>';
        } elseif ((string) ($request->query['preflight'] ?? '') === '1') {
            $notice = '<p class="muted">导出包预检通过，manifest、checksums、内容数据、媒体文件与支付账本可读取。</p>';
        } elseif ((string) ($request->query['preflight_failed'] ?? '') === '1') {
            $notice = '<p class="error">' . View::escape($this->transferFailureNotice('导出包预检失败', (string) ($request->query['reason'] ?? ''))) . '</p>';
        } elseif ((string) ($request->query['content_restored'] ?? '') === '1') {
            $created = max(0, (int) ($request->query['created'] ?? 0));
            $updated = max(0, (int) ($request->query['updated'] ?? 0));
            $mappings = max(0, (int) ($request->query['mappings'] ?? 0));
            $notice = '<p class="muted">内容数据恢复完成：新建 ' . $created . ' 条，更新 ' . $updated . ' 条，URL Mapping 新增 ' . $mappings . ' 条。</p>';
        } elseif ((string) ($request->query['content_restore_failed'] ?? '') === '1') {
            $notice = '<p class="error">' . View::escape($this->transferFailureNotice('内容数据恢复失败', (string) ($request->query['reason'] ?? ''))) . '</p>';
        } elseif ((string) ($request->query['url_mapping_deleted'] ?? '') === '1') {
            $notice = '<p class="muted">URL Mapping 已删除。</p>';
        } elseif ((string) ($request->query['url_mapping_delete_failed'] ?? '') === '1') {
            $notice = '<p class="error">URL Mapping 删除失败，请稍后重试。</p>';
        } elseif ((string) ($request->query['imported'] ?? '') !== '') {
            $notice = '<p class="muted">导入成功，已创建 ' . (int) $request->query['imported'] . ' 篇草稿内容。</p>';
        } elseif ((string) ($request->query['import_failed'] ?? '') === '1') {
            $notice = '<p class="error">导入失败，请检查格式后重试。</p>';
        }

        $body = '<h1>导入导出</h1><p class="muted">官方导出包包含 manifest、内容、用户、media、url-map、extensions、payments 和 checksums。</p>' .
            '<form method="post" action="/admin/transfer/export">' . CsrfToken::field() . '<button type="submit">创建官方导出包</button></form>' .
            $notice .
            '<section class="editor-card"><h2>导入内容</h2><p class="muted">支持 WordPress XML 与 Z-Blog JSON。导入内容会先保存为草稿 Article，并记录原始 URL Mapping。</p>' .
            '<form method="post" action="/admin/transfer/import">' . CsrfToken::field() .
            '<label>导入数据<textarea name="import_payload" rows="8" placeholder="粘贴 WordPress XML 或 Z-Blog JSON"></textarea></label>' .
            '<button type="submit">导入为草稿</button></form></section>' .
            '<section class="editor-card"><h2>URL Mapping</h2><p class="muted">导入旧站内容后，旧 URL 会在这里映射到新内容地址；删除映射只影响跳转，不会删除文章、页面、媒体、支付或发卡数据。</p>' .
            $urlMappings . '</section>' .
            '<h2>导出包</h2>' . $items .
            '<p class="muted">恢复前先执行预检；内容数据恢复只写入内容、媒体元数据和 URL Mapping，不恢复支付账本、发卡商品、库存或订单。</p>';

        return Response::html(View::page('导入导出', $body));
    }

    private function transferUrlMappingTable(UrlMappingRepository $repo): string
    {
        $rows = '';
        foreach ($repo->recent(20) as $mapping) {
            $id = (int) ($mapping['id'] ?? 0);
            $delete = $id > 0
                ? '<form method="post" action="/admin/transfer/url-mappings/' . $id . '/delete" style="display:inline" onsubmit="return confirm(\'确定要删除这条 URL Mapping 吗？此操作不可撤销，但不会删除内容。\');">' .
                    CsrfToken::field() . '<button class="admin-danger" type="submit">删除</button></form>'
                : '<span class="muted">不可操作</span>';
            $rows .= '<tr><td>' . View::escape((string) ($mapping['source_url'] ?? '')) . '</td><td>' .
                View::escape((string) ($mapping['target_url'] ?? '')) . '</td><td>' .
                View::escape((string) ($mapping['status_code'] ?? '')) . '</td><td>' .
                View::escape((string) ($mapping['source_platform'] ?? '')) . '</td><td>' .
                View::escape((string) ($mapping['created_at'] ?? '')) . '</td><td>' . $delete . '</td></tr>';
        }
        if ($rows === '') {
            return '<p class="muted">暂无 URL Mapping。</p>';
        }

        return '<p class="muted">共 ' . $repo->count() . ' 条，显示最近 20 条。</p>' .
            '<table><thead><tr><th>旧 URL</th><th>新 URL</th><th>状态码</th><th>来源</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function transferExportRow(string $file): string
    {
        $name = basename($file);
        $createdAt = is_file($file) ? gmdate('Y-m-d H:i:s', (int) filemtime($file)) . ' UTC' : '未知';
        $mediaLabel = '无法读取';
        $paymentLabel = '无法读取';
        try {
            $reader = new ExportPackageReader();
            $mediaFiles = $reader->mediaFiles($file);
            $mediaBytes = array_sum(array_map(static fn (array $item): int => (int) ($item['byte_size'] ?? 0), $mediaFiles));
            $mediaLabel = count($mediaFiles) . ' 个 / ' . $this->bytesLabel($mediaBytes);
            $summary = $reader->paymentLedgerSummary($file);
            $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
            $paymentLabel = '支付 ' . (int) ($counts['payments'] ?? 0)
                . '，退款 ' . (int) ($counts['refunds'] ?? 0)
                . '，Provider ' . (int) ($counts['provider_settings'] ?? 0);
        } catch (Throwable) {
            $mediaLabel = '无法读取';
            $paymentLabel = '无法读取';
        }
        $commands = '<code>' . View::escape($this->transferCliCommand('preflight-content-data', $file)) . '</code><br>' .
            '<code>' . View::escape($this->transferCliCommand('import-content-data', $file)) . '</code><br>' .
            '<code>' . View::escape($this->transferCliCommand('preflight-media-files', $file)) . '</code><br>' .
            '<code>' . View::escape($this->transferCliCommand('import-media-files', $file)) . '</code><br>' .
            '<code>' . View::escape($this->transferCliCommand('preflight-payment-ledger', $file)) . '</code><br>' .
            '<code>' . View::escape($this->transferCliCommand('import-payment-ledger', $file)) . '</code>';
        $download = '<a class="button" href="/admin/transfer/download/' . rawurlencode($name) . '">下载</a>';
        $preflight = '<form method="post" action="/admin/transfer/preflight/' . rawurlencode($name) . '" style="display:inline">' .
            CsrfToken::field() . '<button type="submit">预检</button></form>';
        $restoreContent = '<form method="post" action="/admin/transfer/restore-content/' . rawurlencode($name) . '" style="display:inline" onsubmit="return confirm(\'确定要从这个官方导出包恢复内容数据吗？这会新建或更新内容、媒体元数据和 URL Mapping，但不会恢复支付或发卡数据。\');">' .
            CsrfToken::field() . '<button type="submit">恢复内容数据</button></form>';
        $delete = '<form method="post" action="/admin/transfer/delete/' . rawurlencode($name) . '" style="display:inline" onsubmit="return confirm(\'确定要删除这个导出包吗？此操作不可撤销。\');">' .
            CsrfToken::field() . '<button class="admin-danger" type="submit">删除</button></form>';

        return '<tr><td>' . View::escape($name) . '</td><td>' . View::escape($createdAt) . '</td><td>' . View::escape($mediaLabel) . '</td><td>' . View::escape($paymentLabel) . '</td><td>' . $commands . '</td><td>' . $download . ' ' . $preflight . ' ' . $restoreContent . ' ' . $delete . '</td></tr>';
    }

    private function transferCliCommand(string $command, string $file): string
    {
        return 'php cli.php ' . $command . ' ' . $this->shellArg($file);
    }

    private function shellArg(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private function bytesLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }

    public function transferDownload(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $realPath = $this->exportPackagePathFromRequest($request);
        if ($realPath === '') {
            return Response::text('导出包不存在。', 404);
        }

        $body = file_get_contents($realPath);
        if (!is_string($body)) {
            return Response::text('导出包暂不可下载。', 500);
        }

        return new Response($body, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="' . basename($realPath) . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function transferDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $realPath = $this->exportPackagePathFromRequest($request);
        if ($realPath === '') {
            return Response::text('导出包不存在。', 404);
        }

        try {
            if (!unlink($realPath)) {
                return Response::redirect('/admin/transfer?delete_failed=1');
            }
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'transfer.export_deleted', ['package_name' => basename($realPath)]);
        } catch (Throwable $exception) {
            $this->logger->error('Export package delete failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/transfer?delete_failed=1');
        }

        return Response::redirect('/admin/transfer?deleted=1');
    }

    public function transferPreflight(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $realPath = $this->exportPackagePathFromRequest($request);
        if ($realPath === '') {
            return Response::text('导出包不存在。', 404);
        }

        try {
            $reader = new ExportPackageReader();
            $manifest = $reader->verifyPackage($realPath);
            $reader->mediaFiles($realPath);
            $reader->paymentLedger($realPath);
            (new OfficialExportContentImporter(ConnectionFactory::make($this->settings)))->preflight($realPath);
            if (($manifest['platform_id'] ?? '') !== 'php-cms' || ($manifest['export_schema_version'] ?? '') !== ExportPackageBuilder::EXPORT_SCHEMA_VERSION) {
                return Response::redirect('/admin/transfer?preflight_failed=1');
            }
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'transfer.export_preflighted', [
                'package_name' => basename($realPath),
                'package_sha256' => hash_file('sha256', $realPath),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Export package preflight failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/transfer?preflight_failed=1' . $this->transferFailureReasonQuery($exception));
        }

        return Response::redirect('/admin/transfer?preflight=1');
    }

    public function transferRestoreContent(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $realPath = $this->exportPackagePathFromRequest($request);
        if ($realPath === '') {
            return Response::text('导出包不存在。', 404);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $result = (new OfficialExportContentImporter($pdo))->importPackage($realPath);
            $created = (int) ($result['content']['created'] ?? 0);
            $updated = (int) ($result['content']['updated'] ?? 0);
            $mappings = (int) ($result['url_mappings']['created'] ?? 0);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'transfer.content_data_restored', [
                'package_name' => basename($realPath),
                'package_sha256' => hash_file('sha256', $realPath),
                'content_created' => $created,
                'content_updated' => $updated,
                'url_mappings_created' => $mappings,
            ]);

            return Response::redirect('/admin/transfer?content_restored=1&created=' . $created . '&updated=' . $updated . '&mappings=' . $mappings);
        } catch (Throwable $exception) {
            $this->logger->error('Export content data restore failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/transfer?content_restore_failed=1' . $this->transferFailureReasonQuery($exception));
        }
    }

    private function transferFailureNotice(string $prefix, string $reason): string
    {
        if ($reason === 'url_mapping') {
            return $prefix . '：URL Mapping 数据校验失败，请重新创建导出包或先执行预检。';
        }

        return $prefix . '，请重新创建导出包或查看日志。';
    }

    private function transferFailureReasonQuery(Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'URL mapping')
            || str_contains($exception->getMessage(), 'URL Mapping')
            ? '&reason=url_mapping'
            : '';
    }

    public function transferUrlMappingDelete(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $parts = explode('/', trim($request->path, '/'));
        $id = (int) ($parts[count($parts) - 2] ?? 0);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            if (!(new UrlMappingRepository($pdo))->deleteById($id)) {
                return Response::redirect('/admin/transfer?url_mapping_delete_failed=1');
            }
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'transfer.url_mapping_deleted', ['id' => $id]);
        } catch (Throwable $exception) {
            $this->logger->error('URL mapping delete failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/transfer?url_mapping_delete_failed=1');
        }

        return Response::redirect('/admin/transfer?url_mapping_deleted=1');
    }

    private function exportPackagePathFromRequest(Request $request): string
    {
        $name = rawurldecode(basename($request->path));
        if (!$this->safeExportPackageName($name)) {
            return '';
        }

        $exportsDir = $this->root() . '/storage/exports';
        $path = $exportsDir . '/' . $name;
        $realDir = realpath($exportsDir);
        $realPath = realpath($path);
        if (!is_string($realDir) || !is_string($realPath) || !str_starts_with($realPath, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            return '';
        }

        return $realPath;
    }

    private function safeExportPackageName(string $name): bool
    {
        return preg_match('/^cms-export-[A-Za-z0-9._-]{1,160}\.zip$/', $name) === 1
            && !str_contains($name, '..')
            && !str_contains($name, '/')
            && !str_contains($name, '\\');
    }

    public function transferExport(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $root = $this->root();
            $pdo = ConnectionFactory::make($this->settings);
            (new ExportPackageBuilder($root, $pdo, (string) $this->settings->get('app.version', '0.0.0')))->build('admin');
        } catch (Throwable $exception) {
            $this->logger->error('Export package failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::text('导出失败。', 500);
        }

        return Response::redirect('/admin/transfer');
    }

    public function transferImport(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $payload = trim((string) $request->input('import_payload', ''));
        if ($payload === '' || strlen($payload) > 5242880) {
            return Response::html(View::page('导入内容', '<h1>导入内容</h1><p class="error">导入失败：内容为空或超过 5MB。</p><p><a class="button" href="/admin/transfer">返回导入导出</a></p>'), 422);
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $importer = new ImportService(
                new ContentRepository($pdo, ContentTypeRegistry::defaults()),
                new UrlMappingRepository($pdo),
                [new WordPressImporter(), new ZBlogImporter()],
            );
            $count = $importer->import($payload);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'transfer.content_imported', ['count' => $count]);

            return Response::redirect('/admin/transfer?imported=' . $count);
        } catch (ImportException $exception) {
            $this->logger->error('Content import rejected', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('导入内容', '<h1>导入内容</h1><p class="error">导入失败：暂不支持这个导入格式。</p><p><a class="button" href="/admin/transfer">返回导入导出</a></p>'), 422);
        } catch (Throwable $exception) {
            $this->logger->error('Content import failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('导入内容', '<h1>导入内容</h1><p class="error">导入失败，请稍后重试。</p><p><a class="button" href="/admin/transfer">返回导入导出</a></p>'), 500);
        }
    }

    public function externalMigrationIndex(?Request $request = null): Response
    {
        $request ??= new Request('GET', '/admin/migrations');
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new MigrationRepository($pdo);
            $jobs = $repo->recentJobs(20);
        } catch (Throwable $exception) {
            $this->logger->error('External migration list failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $jobs = [];
        }

        $notice = $this->externalMigrationNotice($request);
        $rows = '';
        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $scan = is_array($job['scan'] ?? null) ? $job['scan'] : [];
            $counts = is_array($scan['counts'] ?? null) ? $scan['counts'] : [];
            $report = is_array($job['report'] ?? null) ? $job['report'] : [];
            $resultSummary = '<span class="muted">未执行</span>';
            if ($report !== []) {
                if (array_key_exists('deleted', $report)) {
                    $resultSummary = '回滚删除 ' . (int) ($report['deleted'] ?? 0) . '，跳过 ' . (int) ($report['skipped'] ?? 0);
                } else {
                    $resultSummary = '创建 ' . (int) ($report['created'] ?? 0) .
                        '，更新 ' . (int) ($report['updated'] ?? 0) .
                        '，跳过 ' . (int) ($report['skipped'] ?? 0) .
                        '，失败 ' . (int) ($report['failed'] ?? 0);
                }
            }
            $uploadPath = $this->externalMigrationStoredPath((string) ($job['source_sha256'] ?? ''), (string) ($job['source_filename'] ?? ''));
            $canRun = $id > 0 && $uploadPath !== '';
            $actions = $canRun
                ? '<form method="post" action="/admin/migrations/dry-run/' . $id . '" style="display:inline">' . CsrfToken::field() . '<button type="submit">试运行</button></form> ' .
                    '<form method="post" action="/admin/migrations/run/' . $id . '" style="display:inline" onsubmit="return confirm(\'确定开始旧站迁移吗？建议先执行试运行。\');">' . CsrfToken::field() .
                    '<select name="strategy"><option value="skip">已迁移则跳过</option><option value="update">已迁移则更新</option><option value="duplicate">始终新建副本</option></select> <button type="submit">执行迁移</button></form> ' .
                    '<form method="post" action="/admin/migrations/resume/' . $id . '" style="display:inline">' . CsrfToken::field() . '<button type="submit">继续</button></form> ' .
                    '<form method="post" action="/admin/migrations/retry-failed/' . $id . '" style="display:inline">' . CsrfToken::field() . '<button type="submit">重试失败项</button></form> ' .
                    '<form method="post" action="/admin/migrations/rollback/' . $id . '" style="display:inline" onsubmit="return confirm(\'确定回滚这个迁移任务新建的内容吗？媒体文件和其他业务数据不会删除。\');">' . CsrfToken::field() . '<button type="submit" class="danger">回滚</button></form>'
                : '<span class="muted">源文件不可用</span>';
            $rows .= '<tr><td>#' . $id . '</td><td>' . View::escape((string) ($job['source_system'] ?? '')) . '</td><td>' .
                View::escape((string) ($job['source_version'] ?? '')) . '</td><td>' .
                View::escape((string) ($job['status'] ?? '')) . '</td><td>内容 ' . (int) ($counts['contents'] ?? 0) .
                '，媒体 ' . (int) ($counts['media'] ?? 0) . '，跳转 ' . (int) ($counts['redirects'] ?? 0) . '</td><td>' .
                $resultSummary . '</td><td>' .
                View::escape((string) ($job['created_at'] ?? '')) . '</td><td>' . $actions . '</td></tr>';
        }
        $jobTable = $rows !== ''
            ? '<table><thead><tr><th>ID</th><th>来源</th><th>版本</th><th>状态</th><th>扫描结果</th><th>执行结果</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            : '<p class="muted">暂无旧站迁移任务。</p>';

        $body = '<div class="admin-page-header"><div><h1>旧站迁移</h1><p class="muted">用于把 Z-BlogPHP、WordPress、Typecho、Emlog、Halo 和 Daiying 迁移包导入为 CMS 内容。远程媒体默认不抓取；如需迁移图片，请使用迁移包内置 media 目录。</p></div></div>' .
            $notice .
            '<section class="editor-card"><h2>上传并扫描</h2><p class="muted">扫描只解析文件，不写入内容；SQL dump 不会执行，只读取允许数据表的 INSERT。支持 SQL、JSON、WordPress WXR XML 和 Daiying Migration Package ZIP。</p>' .
            '<form method="post" action="/admin/migrations/scan" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>来源系统<select name="source"><option value="">自动识别</option><option value="zblogphp">Z-BlogPHP</option><option value="wordpress">WordPress</option><option value="typecho">Typecho</option><option value="emlog">Emlog</option><option value="halo">Halo</option></select></label>' .
            '<label>迁移文件<input type="file" name="migration_file" accept=".sql,.json,.xml,.zip"></label>' .
            '<label>服务器文件路径（可选）<input name="source_path" placeholder="/path/to/zblog.sql"></label>' .
            '<button type="submit">扫描迁移文件</button></form></section>' .
            '<section class="editor-card"><h2>Daiying 迁移包</h2><p class="muted">把当前站点导出为标准 Migration Package V1，可用于另一套 Daiying CMS 导入。</p>' .
            '<form method="post" action="/admin/migrations/export-package">' . CsrfToken::field() . '<button type="submit">导出 Daiying 迁移包</button></form></section>' .
            '<section class="editor-card"><h2>迁移任务</h2>' . $jobTable . '</section>';

        return Response::html(View::page('旧站迁移', $body));
    }

    public function externalMigrationScan(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $input = $this->externalMigrationInputFile($request);
            $storedPath = $this->storeExternalMigrationFile($input['path'], $input['name']);
            $service = $this->externalMigrationService();
            $result = $service->scanFile($storedPath, (string) $request->input('source', ''));
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'external_migration.scanned', [
                'job_id' => (int) $result['job_id'],
                'adapter_id' => (string) $result['adapter_id'],
            ]);

            return Response::redirect('/admin/migrations?scanned=1&job_id=' . (int) $result['job_id']);
        } catch (MigrationException $exception) {
            $this->logger->warning('External migration scan rejected', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?scan_failed=1&reason=' . rawurlencode($exception->getMessage()));
        } catch (Throwable $exception) {
            $this->logger->error('External migration scan failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?scan_failed=1');
        }
    }

    public function externalMigrationDryRun(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = $this->idFromTail($request->path);
        try {
            $job = (new MigrationRepository(ConnectionFactory::make($this->settings)))->findJob($id);
            $path = is_array($job) ? $this->externalMigrationStoredPath((string) ($job['source_sha256'] ?? ''), (string) ($job['source_filename'] ?? '')) : '';
            if ($path === '') {
                throw new MigrationException('迁移源文件不可用。');
            }
            $result = $this->externalMigrationService()->dryRunFile($id, $path, (string) ($job['adapter_id'] ?? ''));

            return Response::redirect('/admin/migrations?dry_run=1&contents=' . (int) ($result['contents'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('External migration dry run failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?dry_run_failed=1');
        }
    }

    public function externalMigrationRun(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = $this->idFromTail($request->path);
        try {
            $job = (new MigrationRepository(ConnectionFactory::make($this->settings)))->findJob($id);
            $path = is_array($job) ? $this->externalMigrationStoredPath((string) ($job['source_sha256'] ?? ''), (string) ($job['source_filename'] ?? '')) : '';
            if ($path === '') {
                throw new MigrationException('迁移源文件不可用。');
            }
            $report = $this->externalMigrationService()->migrateFile($id, $path, (string) ($job['adapter_id'] ?? ''), (string) $request->input('strategy', 'skip'));
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'external_migration.completed', [
                'job_id' => $id,
                'created' => (int) ($report['created'] ?? 0),
                'failed' => (int) ($report['failed'] ?? 0),
            ]);

            return Response::redirect('/admin/migrations?migrated=1&created=' . (int) ($report['created'] ?? 0) . '&failed=' . (int) ($report['failed'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('External migration failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?migrate_failed=1');
        }
    }

    public function externalMigrationResume(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = $this->idFromTail($request->path);
        try {
            $job = (new MigrationRepository(ConnectionFactory::make($this->settings)))->findJob($id);
            $path = is_array($job) ? $this->externalMigrationStoredPath((string) ($job['source_sha256'] ?? ''), (string) ($job['source_filename'] ?? '')) : '';
            if ($path === '') {
                throw new MigrationException('迁移源文件不可用。');
            }
            $report = $this->externalMigrationService()->resumeFile($id, $path, (string) ($job['adapter_id'] ?? ''), (string) ($job['strategy'] ?? 'skip'));

            return Response::redirect('/admin/migrations?migrated=1&created=' . (int) ($report['created'] ?? 0) . '&failed=' . (int) ($report['failed'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('External migration resume failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?migrate_failed=1');
        }
    }

    public function externalMigrationRetryFailed(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = $this->idFromTail($request->path);
        try {
            $job = (new MigrationRepository(ConnectionFactory::make($this->settings)))->findJob($id);
            $path = is_array($job) ? $this->externalMigrationStoredPath((string) ($job['source_sha256'] ?? ''), (string) ($job['source_filename'] ?? '')) : '';
            if ($path === '') {
                throw new MigrationException('迁移源文件不可用。');
            }
            $report = $this->externalMigrationService()->retryFailedFile($id, $path, (string) ($job['adapter_id'] ?? ''));

            return Response::redirect('/admin/migrations?migrated=1&created=' . (int) ($report['created'] ?? 0) . '&failed=' . (int) ($report['failed'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('External migration retry failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?migrate_failed=1');
        }
    }

    public function externalMigrationRollback(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $id = $this->idFromTail($request->path);
        try {
            $report = $this->externalMigrationService()->rollback($id);

            return Response::redirect('/admin/migrations?rolled_back=1&deleted=' . (int) ($report['deleted'] ?? 0));
        } catch (Throwable $exception) {
            $this->logger->error('External migration rollback failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?rollback_failed=1');
        }
    }

    public function externalMigrationExportPackage(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $dir = $this->root() . '/storage/exports';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = 'daiying-migration-package-' . gmdate('YmdHis') . '.zip';
            (new DaiyingMigrationPackageBuilder($this->root(), $pdo))->build($dir . '/' . $name);

            return Response::redirect('/admin/migrations?exported=1&file=' . rawurlencode($name));
        } catch (Throwable $exception) {
            $this->logger->error('External migration package export failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/migrations?export_failed=1');
        }
    }

    private function externalMigrationService(): ExternalMigrationService
    {
        $pdo = ConnectionFactory::make($this->settings);
        return new ExternalMigrationService(
            $pdo,
            new ContentRepository($pdo, ContentTypeRegistry::defaults()),
            new UrlMappingRepository($pdo),
            new MigrationRepository($pdo),
            ExternalMigrationService::defaultAdapters(),
            new MediaLibrary($pdo, $this->root() . '/content/uploads', (array) $this->settings->get('media', [])),
        );
    }

    /** @return array{path:string,name:string} */
    private function externalMigrationInputFile(Request $request): array
    {
        $path = trim((string) $request->input('source_path', ''));
        if ($path !== '') {
            return ['path' => $path, 'name' => basename($path)];
        }
        $file = $_FILES['migration_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            throw new MigrationException('请上传迁移文件，或填写服务器文件路径。');
        }

        return [
            'path' => (string) $file['tmp_name'],
            'name' => is_string($file['name'] ?? null) ? (string) $file['name'] : basename((string) $file['tmp_name']),
        ];
    }

    private function storeExternalMigrationFile(string $path, string $filename = ''): string
    {
        $realPath = realpath($path);
        if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
            throw new MigrationException('迁移文件不存在或不可读取。');
        }
        $name = basename($filename !== '' ? $filename : $path);
        $extension = $this->externalMigrationExtension($name, $realPath);
        if (!in_array($extension, ['sql', 'json', 'xml', 'zip'], true)) {
            throw new MigrationException('迁移文件仅支持 SQL、JSON、XML 或 ZIP。请确认上传的是旧站导出的数据库 SQL、WordPress WXR XML、JSON 数据或 Daiying 迁移包 ZIP。');
        }
        $hash = hash_file('sha256', $realPath);
        if (!is_string($hash) || $hash === '') {
            throw new MigrationException('迁移文件校验失败。');
        }
        $dir = $this->root() . '/storage/migrations/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $target = $dir . '/' . $hash . '.' . $extension;
        if (!is_file($target) && !copy($realPath, $target)) {
            throw new MigrationException('迁移文件保存失败。');
        }

        return $target;
    }

    private function externalMigrationExtension(string $filename, string $realPath): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['sql', 'json', 'xml', 'zip'], true)) {
            return $extension;
        }

        $handle = fopen($realPath, 'rb');
        if (!is_resource($handle)) {
            return '';
        }
        $sample = (string) fread($handle, 8192);
        fclose($handle);
        $trimmed = ltrim($sample, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($sample, "PK\x03\x04")) {
            return 'zip';
        }
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE || preg_match('/^\s*[\{\[]/u', $trimmed) === 1) {
                return 'json';
            }
        }
        if (preg_match('/^<\?xml\b|^<rss\b|^<feed\b|^<channel\b/i', $trimmed) === 1) {
            return 'xml';
        }
        if (preg_match('/\bINSERT\s+INTO\b|\bCREATE\s+TABLE\b|\bLOCK\s+TABLES\b|^\s*--\s|\bDROP\s+TABLE\b/i', $trimmed) === 1) {
            return 'sql';
        }

        return '';
    }

    private function externalMigrationStoredPath(string $sha256, string $filename): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return '';
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['sql', 'json', 'xml', 'zip'], true)) {
            return '';
        }
        $path = $this->root() . '/storage/migrations/uploads/' . $sha256 . '.' . $extension;

        return is_file($path) ? $path : '';
    }

    private function externalMigrationNotice(Request $request): string
    {
        if ((string) ($request->query['scanned'] ?? '') === '1') {
            return '<p class="muted">迁移文件扫描完成，任务 #' . (int) ($request->query['job_id'] ?? 0) . ' 已创建。</p>';
        }
        if ((string) ($request->query['dry_run'] ?? '') === '1') {
            return '<p class="muted">试运行完成：预计迁移内容 ' . (int) ($request->query['contents'] ?? 0) . ' 条。</p>';
        }
        if ((string) ($request->query['migrated'] ?? '') === '1') {
            return '<p class="muted">迁移完成：新建 ' . (int) ($request->query['created'] ?? 0) . ' 条，失败 ' . (int) ($request->query['failed'] ?? 0) . ' 条。</p>';
        }
        if ((string) ($request->query['rolled_back'] ?? '') === '1') {
            return '<p class="muted">迁移回滚完成：删除本任务新建内容 ' . (int) ($request->query['deleted'] ?? 0) . ' 条。</p>';
        }
        if ((string) ($request->query['exported'] ?? '') === '1') {
            return '<p class="muted">Daiying 迁移包已生成：storage/exports/' . View::escape((string) ($request->query['file'] ?? '')) . '</p>';
        }
        if ((string) ($request->query['scan_failed'] ?? '') === '1') {
            $reason = trim((string) ($request->query['reason'] ?? ''));
            return '<p class="error">扫描失败：' . View::escape($reason !== '' ? $reason : '请检查迁移文件格式。') . '</p>';
        }
        if ((string) ($request->query['dry_run_failed'] ?? '') === '1') {
            return '<p class="error">试运行失败，请检查迁移文件或查看日志。</p>';
        }
        if ((string) ($request->query['migrate_failed'] ?? '') === '1') {
            return '<p class="error">迁移失败，请检查任务状态或查看日志。</p>';
        }
        if ((string) ($request->query['rollback_failed'] ?? '') === '1') {
            return '<p class="error">回滚失败，请检查任务状态或查看日志。</p>';
        }
        if ((string) ($request->query['export_failed'] ?? '') === '1') {
            return '<p class="error">迁移包导出失败，请检查 ZipArchive 扩展和 storage/exports 写入权限。</p>';
        }

        return '';
    }

    private function idFromTail(string $path): int
    {
        $parts = explode('/', trim($path, '/'));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (preg_match('/^[1-9][0-9]*$/', $parts[$i]) === 1) {
                return (int) $parts[$i];
            }
        }

        return 0;
    }

    public function paymentsIndex(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentRepository($pdo);
            $filters = [
                'q' => $this->paymentQueryString($request, 'q'),
                'status' => $this->paymentQueryString($request, 'status'),
                'provider_id' => $this->paymentQueryString($request, 'provider_id'),
                'subject_type' => $this->paymentQueryString($request, 'subject_type'),
                'currency' => $this->paymentQueryString($request, 'currency'),
                'created_from' => $this->paymentQueryString($request, 'created_from'),
                'created_to' => $this->paymentQueryString($request, 'created_to'),
            ];
            $result = $repo->searchPayments($filters + [
                'page' => $request->input('page', 1),
                'per_page' => 25,
            ]);
            $summary = $repo->paymentSummary($filters);
            $diagnostics = (new PaymentAttemptDiagnosticsRepository($pdo))->recentFailures(10);
        } catch (PaymentException $exception) {
            $this->logger->error('Core payment index rejected invalid filters', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('支付管理', '<h1>支付管理</h1><p class="error">支付筛选条件无效。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 400);
        } catch (Throwable $exception) {
            $this->logger->error('Core payment index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('支付管理', '<h1>支付管理</h1><p class="error">支付服务暂不可用。</p>'), 500);
        }

        $rows = '';
        foreach ($result['items'] as $payment) {
            $rows .= '<tr><td>' . (int) $payment['id'] . '</td><td>' . View::escape((string) $payment['subject_type']) . '<br><span class="muted">' . View::escape((string) $payment['subject_id']) . '</span></td><td>' .
                View::escape((string) $payment['provider_id']) . '</td><td>' . View::escape($this->paymentStatusLabel((string) $payment['status'])) . '</td><td>' .
                View::escape($this->moneyLabel($payment['amount_minor'], (string) $payment['currency'])) . '</td><td>' .
                View::escape($this->paymentTimestampLabel($payment['created_at'] ?? null)) . '</td><td><a class="button" href="/admin/payments/' . (int) $payment['id'] . '">详情</a></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无支付记录</td></tr>';

        $providers = PaymentProviderRegistry::ids();
        $providerText = $providers === [] ? '当前无可用支付 Provider' : implode(', ', $providers);
        $body = '<h1>支付管理</h1><p class="muted">CMS Core 维护可信支付状态。支付 Provider 只负责返回已验证结果，资金不经过平台账户。</p>' .
            '<p class="muted">已注册 Provider：' . View::escape($providerText) . ' <a class="button" href="/admin/payments/providers">Provider 设置</a></p>' .
            '<form method="post" action="/admin/payments/authorizations/expire">' . CsrfToken::field() . '<button type="submit">标记过期授权</button></form>' .
            '<form method="post" action="/admin/payments/entitlements/expire">' . CsrfToken::field() . '<button type="submit">标记过期权益</button></form>' .
            '<form method="get" action="/admin/payments"><label>搜索<input name="q" value="' . View::escape((string) $request->input('q', '')) . '" placeholder="Subject、远端单号、参考号或幂等键"></label>' .
            '<label>状态<select name="status"><option value="">全部</option>' . $this->paymentStatusOptions((string) $request->input('status', '')) . '</select></label>' .
            '<label>Provider<input name="provider_id" value="' . View::escape((string) $request->input('provider_id', '')) . '"></label>' .
            '<label>Subject Type<input name="subject_type" value="' . View::escape((string) $request->input('subject_type', '')) . '"></label>' .
            '<label>币种<input name="currency" value="' . View::escape((string) $request->input('currency', '')) . '" placeholder="USD"></label>' .
            '<label>创建起始<input name="created_from" value="' . View::escape((string) $request->input('created_from', '')) . '" placeholder="YYYY-MM-DD"></label>' .
            '<label>创建截止<input name="created_to" value="' . View::escape((string) $request->input('created_to', '')) . '" placeholder="YYYY-MM-DD"></label><button type="submit">筛选</button></form>' .
            $this->paymentSummaryHtml($summary) .
            $this->paymentDiagnosticsHtml($diagnostics ?? []) .
            '<p><a class="button" href="/admin/payments/export.csv?' . View::escape(http_build_query($filters)) . '">导出当前筛选 CSV</a></p>' .
            '<table><thead><tr><th>ID</th><th>Subject</th><th>Provider</th><th>状态</th><th>金额</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p class="muted">第 ' . (int) $result['page'] . ' / ' . (int) $result['pages'] . ' 页，共 ' . (int) $result['total'] . ' 条。</p>';

        return Response::html(View::page('支付管理', $body));
    }

    public function paymentsExport(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentRepository($pdo);
            $filters = [
                'q' => $this->paymentQueryString($request, 'q'),
                'status' => $this->paymentQueryString($request, 'status'),
                'provider_id' => $this->paymentQueryString($request, 'provider_id'),
                'subject_type' => $this->paymentQueryString($request, 'subject_type'),
                'currency' => $this->paymentQueryString($request, 'currency'),
                'created_from' => $this->paymentQueryString($request, 'created_from'),
                'created_to' => $this->paymentQueryString($request, 'created_to'),
            ];
            $rows = $repo->exportPayments($filters);
            $csv = $this->paymentCsv($rows);
            (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.export.csv', [
                'filters' => $this->paymentExportAuditFilters($filters),
                'row_count' => count($rows),
                'format' => 'csv',
            ]);
        } catch (PaymentException $exception) {
            $this->logger->error('Core payment export rejected invalid filters', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $message = $exception->getMessage() === 'Payment export row is invalid.' ? '支付导出数据无效。' : '支付导出筛选条件无效。';
            return Response::text($message, 400);
        } catch (Throwable $exception) {
            $this->logger->error('Core payment export failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::text('支付导出失败。', 500);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cms-payments-' . gmdate('Ymd-His') . '.csv"',
        ]);
    }

    public function paymentAuthorizationsExpire(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return $this->paymentAdminNoStore(Response::html(View::page('标记过期授权失败', '<h1>标记过期授权失败</h1><p class="error">标记过期授权必须通过 POST 请求提交。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']));
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $count = (new PaymentService($pdo, new PaymentRepository($pdo)))->expirePaymentAuthorizations();
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.authorization.expired_marked', ['count' => $count]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->logger->error('Core payment authorization expiry failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('标记过期授权失败', '<h1>标记过期授权失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments'));
    }

    public function paymentEntitlementsExpire(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return $this->paymentAdminNoStore(Response::html(View::page('标记过期权益失败', '<h1>标记过期权益失败</h1><p class="error">标记过期权益必须通过 POST 请求提交。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']));
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $count = (new PaymentService($pdo, new PaymentRepository($pdo)))->expirePaymentEntitlements();
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.entitlement.expired_marked', ['count' => $count]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->logger->error('Core payment entitlement expiry failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('标记过期权益失败', '<h1>标记过期权益失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments'));
    }

    public function paymentProviders(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = $this->paymentProviderSettings();
            $saved = [];
            foreach ($repo->all() as $setting) {
                $saved[(string) $setting['provider_id']] = $setting;
            }
            $ids = array_values(array_filter(array_unique(array_merge(PaymentProviderRegistry::ids(), array_keys($saved))), fn (string $id): bool => $this->paymentProviderVisibleInAdmin($id)));
            sort($ids);
            $selectedProviderId = $this->paymentProviderSelectedId($request, $ids);
            $notice = '';
            if ((string) ($request->query['repaired'] ?? '') === '1') {
                $notice = '<p class="muted">Provider 存储已修复，请重新确认状态。</p>';
            }
            $rows = '';
            foreach ($ids as $providerId) {
                $provider = PaymentProviderRegistry::get($providerId);
                $setting = $saved[$providerId] ?? null;
                [$public, $publicError] = $this->paymentProviderPublicConfig($setting);
                $publicDisplay = $publicError !== '' ? ['_error' => '公共配置不可用'] : $public;
                $masked = $this->paymentProviderMaskedSecrets($providerId, $setting, $repo);
                $diagnostics = $this->paymentProviderDiagnostics($providerId, $provider, $setting, $public, $publicError, $repo);
                $defaultLabel = $this->paymentProviderDefaultLabel($provider, $setting, $public);
                $configured = $this->paymentProviderConfigured($providerId, $setting, $public, $publicError, $repo);
                $enabled = (string) ($setting['status'] ?? '') === 'enabled';
                [$legacySyncLabel, $legacySyncTone] = $this->paymentProviderLegacyStorageSync($setting, $public);
                $capabilities = $provider !== null ? $this->adminTags($this->paymentProviderCapabilityLabels($provider->capabilities())) : $this->adminBadge('未注册', 'warning');
                $defaultCell = $defaultLabel === '无效'
                    ? View::escape($defaultLabel)
                    : $this->adminBadge($defaultLabel, $defaultLabel === '是' ? 'success' : 'muted');
                $rows .= '<tr><td>' . View::escape($this->paymentProviderFriendlyName($providerId)) . '<br><code class="admin-nowrap">' . View::escape($providerId) . '</code></td><td>' . View::escape($this->paymentProviderDisplayNameLabel($provider, $setting, $providerId)) .
                    '</td><td>' . $this->adminBadge($configured ? '已配置' : '未配置', $configured ? 'success' : 'warning') .
                    '</td><td>' . $this->adminBadge($enabled ? '启用' : '禁用', $enabled ? 'success' : 'muted') .
                    '</td><td>' . $defaultCell .
                    '</td><td>' . $this->adminBadge($legacySyncLabel, $legacySyncTone) .
                    '</td><td>' . View::escape($diagnostics) . '</td><td>' . $capabilities .
                    '</td><td><details><summary>查看</summary><pre>' . View::escape(json_encode($publicDisplay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details></td><td><details><summary>查看</summary><pre>' . View::escape(json_encode($masked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details></td>' .
                    '<td><a class="button" href="/admin/payments/providers?provider_id=' . rawurlencode($providerId) . '#provider-form">配置 / 编辑</a></td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="11" class="muted">暂无支付 Provider。</td></tr>';
            $form = $this->paymentProviderForm($selectedProviderId, $saved[$selectedProviderId] ?? null, $repo);
            $chainCheck = $this->paymentProviderChainCheck($pdo);
            $methodCards = $this->paymentProviderMethodCards(array_values(array_unique(array_merge($ids, $this->paymentProviderKnownOfficialIds()))), $saved, $repo);
            $officialPluginNotice = $this->paymentProviderOfficialPluginNotice();
            $integrationGuide = $this->paymentProviderIntegrationGuide();
            if ((string) ($request->query['saved'] ?? '') === '1') {
                $notice = $this->paymentProviderSaveNotice($selectedProviderId, $saved[$selectedProviderId] ?? null, $pdo, $repo);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Core payment provider settings page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置', '<h1>支付 Provider 设置</h1><p class="error">支付 Provider 设置暂不可用。</p>'), 500));
        }

        $repairForm = '<form method="post" action="/admin/payments/providers/repair-storage" onsubmit="return confirm(\'确定要修复支付 Provider 存储吗？此操作会清理旧重复配置行。\');">' .
            CsrfToken::field() .
            '<button class="button" type="submit">修复 Provider 存储</button></form>';

        $body = '<div class="admin-page-header"><div><h1>支付方式设置</h1><p class="muted">先选择要使用的支付方式，再在下方填写对应资料。普通管理员不用猜 Provider ID 或 JSON。</p></div><p><a class="button admin-button-secondary" href="/admin/payments">返回支付管理</a></p></div>' .
            $notice .
            $officialPluginNotice .
            $form .
            '<section class="admin-card"><h2>当前可用支付方式</h2><p class="muted">绿色表示可以直接配置并用于发卡、付费内容、付费下载等 Core 支付链路。</p>' . $methodCards . '</section>' .
            '<section class="admin-card"><h2>常见支付平台要填什么</h2><p class="muted">如果你使用的是第三方聚合收银台，通常选择“通用跳转支付”；如果是官方微信/支付宝直连，需要后续安装对应直连 Provider/插件。</p>' . $integrationGuide . '</section>' .
            $repairForm .
            '<h2>高级状态表</h2><p class="muted">这里用于诊断真实保存状态和支付服务发现结果。日常只需要看上面的支付方式卡片和下面的配置表单。</p>' .
            '<table><thead><tr><th>支付方式 / Provider</th><th>显示名称</th><th>必要配置</th><th>启用状态</th><th>默认</th><th>旧字段同步</th><th>诊断</th><th>能力</th><th>公共配置</th><th>密钥掩码</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            $chainCheck;

        return $this->paymentAdminNoStore(Response::html(View::page('支付方式设置', $body)));
    }

    private function paymentProviderOfficialPluginNotice(): string
    {
        foreach ($this->paymentProviderKnownOfficialIds() as $providerId) {
            if (PaymentProviderRegistry::get($providerId) !== null) {
                return '';
            }
        }

        return '<p class="muted">尚未安装支付方式。CMS 已保留支付管理、回调、订单状态和 Provider 扩展接口；如需 Stripe、PayPal、微信支付或支付宝，请先安装对应官方支付插件。</p>';
    }

    public function paymentProviderRepairStorage(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 存储修复失败', '<h1>支付 Provider 存储修复失败</h1><p class="error">Provider 存储修复必须通过 POST 请求提交。</p><p><a class="button" href="/admin/payments/providers">返回 Provider 设置</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']));
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 存储修复失败', '<h1>支付 Provider 存储修复失败</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p><p><a class="button" href="/admin/payments/providers">返回 Provider 设置</a></p>'), 403));
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentProviderSettingsRepository($pdo, (string) $this->settings->get('security.encryption_key', ''));
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $repo->all();
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.provider_settings.storage_repaired', []);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->logger->error('Core payment provider settings storage repair failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 存储修复失败', '<h1>支付 Provider 存储修复失败</h1><p class="error">Provider 存储修复失败，请查看后台日志。</p><p><a class="button" href="/admin/payments/providers">返回 Provider 设置</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/providers?repaired=1'));
    }

    /** @param list<string> $ids @param array<string,array<string,mixed>> $saved */
    private function paymentProviderMethodCards(array $ids, array $saved, PaymentProviderSettingsRepository $repo): string
    {
        $cards = '';
        foreach ($ids as $providerId) {
            $provider = PaymentProviderRegistry::get($providerId);
            $setting = $saved[$providerId] ?? null;
            [$public, $publicError] = $this->paymentProviderPublicConfig($setting);
            $configured = $this->paymentProviderConfigured($providerId, $setting, $public, $publicError, $repo);
            $enabled = (string) ($setting['status'] ?? '') === 'enabled';
            $default = $this->paymentProviderDefaultLabel($provider, $setting, $public) === '是';
            $installed = $provider !== null;
            $action = $installed
                ? '<p><a class="button" href="/admin/payments/providers?provider_id=' . rawurlencode($providerId) . '#provider-form">配置这个支付方式</a></p>'
                : '<p class="muted">未安装：请先在插件管理安装并启用 ' . View::escape($this->paymentProviderOfficialPluginId($providerId)) . '。</p>';
            $cards .= '<article class="admin-card"><h3>' . View::escape($this->paymentProviderFriendlyName($providerId)) . '</h3>' .
                '<p class="muted">' . View::escape($this->paymentProviderFriendlySummary($providerId)) . '</p>' .
                '<p>' . $this->adminBadge($installed ? '已安装' : '未安装', $installed ? 'success' : 'warning') . ' ' .
                $this->adminBadge($configured ? '已配置' : '未配置', $configured ? 'success' : 'warning') . ' ' .
                $this->adminBadge($enabled ? '已启用' : '未启用', $enabled ? 'success' : 'muted') . ' ' .
                $this->adminBadge($default ? '默认支付' : '非默认', $default ? 'success' : 'muted') . '</p>' .
                $action .
                '<details><summary>需要准备什么</summary>' . $this->paymentProviderSetupList($providerId) . '</details></article>';
        }

        return '<div class="admin-theme-grid-v2">' . $cards . '</div>';
    }

    /** @return list<string> */
    private function paymentProviderKnownOfficialIds(): array
    {
        return ['official.payment.stripe', 'official.payment.paypal', 'official.payment.wechatpay', 'official.payment.alipay'];
    }

    private function paymentProviderOfficialPluginId(string $providerId): string
    {
        return match ($providerId) {
            'official.payment.stripe' => 'official.payment.stripe',
            'official.payment.paypal' => 'official.payment.paypal',
            'official.payment.wechatpay' => 'official.payment.wechatpay',
            'official.payment.alipay' => 'official.payment.alipay',
            default => $providerId,
        };
    }

    private function paymentProviderIntegrationGuide(): string
    {
        $items = [
            ['人工转账 / 客服确认', '选择：人工确认付款', '收款说明、客服联系方式、付款后如何备注订单。', '不需要平台后台，不需要回调地址。管理员确认收款后点“捕获支付”。'],
            ['易支付 / 码支付 / 聚合收银台', '选择：通用跳转支付', '平台收银台地址、本站回跳域名、Checkout 签名密钥、Webhook 密钥。', '平台后台回调地址填：https://你的域名/payment/webhooks/core.hosted-redirect'],
            ['微信支付官方直连', '需要：微信支付直连 Provider/插件', 'AppID、商户号 MCH ID、API v3 密钥、商户证书序列号、商户私钥、支付通知密钥。', '微信商户平台通知地址通常填：https://你的域名/payment/webhooks/微信ProviderID'],
            ['支付宝官方直连', '需要：支付宝直连 Provider/插件', 'App ID、应用私钥、支付宝公钥、网关地址、签名方式、异步通知密钥。', '支付宝开放平台异步通知地址通常填：https://你的域名/payment/webhooks/支付宝ProviderID'],
            ['Stripe', '需要：Stripe Provider/插件', 'Publishable Key、Secret Key、Webhook Signing Secret、币种和结算国家。', 'Stripe Webhook Endpoint 填：https://你的域名/payment/webhooks/stripe'],
            ['PayPal', '需要：PayPal Provider/插件', 'Client ID、Client Secret、Webhook ID、Sandbox/Live 环境。', 'PayPal Webhook URL 填：https://你的域名/payment/webhooks/paypal'],
        ];
        $rows = '';
        foreach ($items as [$name, $choice, $fields, $callback]) {
            $rows .= '<tr><th>' . View::escape($name) . '</th><td>' . View::escape($choice) . '</td><td>' . View::escape($fields) . '</td><td>' . View::escape($callback) . '</td></tr>';
        }

        return '<table><thead><tr><th>支付类型</th><th>后台选择</th><th>需要准备</th><th>回调 / 通知地址</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p class="muted">如果通用跳转支付资料没填完整，诊断会提示“托管跳转收银台 URL 未配置”或“Webhook 密钥未配置”。</p>';
    }

    private function paymentProviderFriendlyName(string $providerId): string
    {
        return match ($providerId) {
            ManualPaymentProvider::PROVIDER_ID => '人工确认付款',
            HostedRedirectPaymentProvider::PROVIDER_ID => '通用跳转支付',
            'official.payment.stripe' => 'Stripe',
            'official.payment.paypal' => 'PayPal',
            'official.payment.wechatpay' => '微信支付',
            'official.payment.alipay' => '支付宝',
            'core.fixture-payment' => '测试模拟支付',
            default => $providerId,
        };
    }

    private function paymentProviderFriendlySummary(string $providerId): string
    {
        return match ($providerId) {
            ManualPaymentProvider::PROVIDER_ID => '适合先跑通发卡闭环、线下转账、客服确认、手工收款。',
            HostedRedirectPaymentProvider::PROVIDER_ID => '适合接第三方聚合支付、易支付、码支付或你自己的托管收银台。',
            'official.payment.stripe' => 'Stripe 官方 Checkout Provider，支持 Test Mode、Webhook 验签和退款。',
            'official.payment.paypal' => 'PayPal 官方 Checkout Provider，支持 Sandbox/Live、Webhook 验证和退款。',
            'official.payment.wechatpay' => '微信支付 API v3 官方 Provider，支持 Native 网站支付、Notify 验签解密和退款。',
            'official.payment.alipay' => '支付宝官方 Page Pay Provider，支持 Sandbox/Production、异步通知验签和退款。',
            'core.fixture-payment' => '仅用于开发测试，正式环境不要启用。',
            default => '第三方支付 Provider，按该 Provider 文档填写配置。',
        };
    }

    /** @param list<string> $capabilities @return list<string> */
    private function paymentProviderCapabilityLabels(array $capabilities): array
    {
        $labels = [
            'payment.create' => '可用于 Core 支付（创建支付）',
            'payment.capture' => '确认收款',
            'payment.cancel' => '取消支付',
            'payment.refund' => '退款',
            'payment.status' => '同步状态',
        ];

        return array_values(array_map(static fn (string $capability): string => isset($labels[$capability]) ? $labels[$capability] . ' ' . $capability : $capability, $capabilities));
    }

    private function paymentProviderSetupList(string $providerId): string
    {
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            return '<ul><li>状态：选择“启用”。</li><li>默认支付：如果当前只用这一种支付，就勾选。</li><li>付款说明：写给买家看的付款方式、客服、备注要求。</li><li>回调地址：不需要。</li></ul>';
        }
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            return '<ul><li>支付平台收银台地址：第三方平台提供的 HTTPS 支付创建/收银台地址。</li><li>本站回跳域名：你的 CMS 域名，例如 https://www.example.com，不带路径和问号。</li><li>Checkout 签名密钥：CMS 跳转到平台时用于签名，可选但建议填。</li><li>Webhook 密钥：平台通知 CMS 支付成功时验签使用，建议填写。</li><li>平台后台通知地址：https://你的域名/payment/webhooks/core.hosted-redirect</li></ul>';
        }
        if ($providerId === 'official.payment.stripe') {
            return '<ul><li>Publishable Key：Stripe 后台开发者密钥中的 pk_test_ 或 pk_live_。</li><li>Secret Key：Stripe 后台开发者密钥中的 sk_test_ 或 sk_live_，只保存在密钥区。</li><li>Webhook Signing Secret：Stripe Webhook Endpoint 的 whsec_ 开头密钥。</li><li>Mode：测试环境选 test，正式收款选 live。</li><li>Webhook URL：https://你的域名/payment/webhooks/official.payment.stripe</li></ul>';
        }
        if ($providerId === 'official.payment.paypal') {
            return '<ul><li>Client ID / Client Secret：PayPal Developer App 中的 API 凭据。</li><li>Environment：测试选 Sandbox，正式收款选 Live。</li><li>Webhook ID：PayPal 后台创建 Webhook 后得到的 ID。</li><li>Webhook URL：https://你的域名/payment/webhooks/official.payment.paypal</li><li>付款成功必须依赖 PayPal Webhook，return URL 只负责用户体验。</li></ul>';
        }
        if ($providerId === 'official.payment.wechatpay') {
            return '<ul><li>App ID、Mch ID、商户证书序列号来自微信商户平台。</li><li>API v3 Key、商户私钥进入密钥区，不会明文回显。</li><li>平台证书/平台公钥建议填单行 base64 DER 公钥，Notify 会用它验签。</li><li>Notify URL：https://你的域名/payment/webhooks/official.payment.wechatpay</li><li>当前网站支付模式优先使用 Native，后续可扩展 JSAPI/H5/App。</li></ul>';
        }
        if ($providerId === 'official.payment.alipay') {
            return '<ul><li>App ID、支付宝公钥来自支付宝开放平台应用。</li><li>App Private Key 进入密钥区，不会明文回显。</li><li>Gateway Mode：测试选 Sandbox，正式选 Production。</li><li>Notify URL：https://你的域名/payment/webhooks/official.payment.alipay</li><li>支付成功必须依赖异步 notify 验签，return URL 不作为 paid 依据。</li></ul>';
        }

        return '<ul><li>按该 Provider 文档填写公开配置和密钥。</li><li>密钥不要写入公共 JSON。</li><li>Webhook 地址格式：/payment/webhooks/' . View::escape($providerId) . '</li></ul>';
    }

    private function paymentProviderChainCheck(PDO $pdo): string
    {
        $storageText = '不可用';
        try {
            $storage = (new PaymentProviderSettingsRepository($pdo, (string) $this->settings->get('security.encryption_key', '')))->storageDiagnostics();
            $duplicates = $storage['duplicate_provider_ids'] !== []
                ? implode(', ', $storage['duplicate_provider_ids'])
                : '无';
            $legacyColumns = $storage['legacy_columns'] !== []
                ? implode(', ', $storage['legacy_columns'])
                : '无旧字段';
            $storageText = '配置行：' . (int) $storage['row_count'] . '；重复 Provider：' . $duplicates . '；旧字段镜像：' . $legacyColumns;
        } catch (Throwable) {
            $storageText = 'Provider 配置存储暂不可用';
        }

        $enabledIds = [];
        $enabledError = '';
        try {
            foreach ($this->paymentService($pdo)->enabledProviders() as $provider) {
                $providerId = (string) ($provider['id'] ?? '');
                if ($providerId !== '') {
                    $enabledIds[] = $providerId;
                }
            }
        } catch (Throwable $exception) {
            $enabledError = $this->paymentProviderInternalDiagnosticLabel($exception);
        }

        $defaultProviderId = '';
        $defaultError = '';
        try {
            $defaultProviderId = (new PaymentProviderSelector($pdo, $this->settings))->defaultProviderId();
        } catch (Throwable $exception) {
            $defaultError = $this->paymentProviderInternalDiagnosticLabel($exception);
        }

        $enabledText = $enabledIds !== [] ? implode(', ', $enabledIds) : '无';
        $defaultText = $defaultProviderId !== '' ? $defaultProviderId : '无';
        $ready = $enabledIds !== [] && $defaultProviderId !== '' && $enabledError === '' && $defaultError === '';
        $status = $ready ? $this->adminBadge('链路可用', 'success') : $this->adminBadge('链路不可用', 'warning');
        $rows = '<tr><th>Provider 配置存储</th><td>' . View::escape($storageText) . '</td></tr>' .
            '<tr><th>PaymentService 可用 Provider</th><td>' . View::escape($enabledText) . '</td></tr>' .
            '<tr><th>Card Delivery 默认 Provider</th><td>' . View::escape($defaultText) . '</td></tr>';
        if ($enabledError !== '') {
            $rows .= '<tr><th>PaymentService 错误</th><td>' . View::escape($enabledError) . '</td></tr>';
        }
        if ($defaultError !== '') {
            $rows .= '<tr><th>默认 Provider 错误</th><td>' . View::escape($defaultError) . '</td></tr>';
        }

        return '<section><h2>支付链路自检 ' . $status . '</h2>' .
            '<p class="muted">这里直接调用 Core PaymentService 和 Card Delivery 使用的 Provider Selector；保存后若这里仍不可用，请先使用“修复 Provider 存储”。</p>' .
            '<table><tbody>' . $rows . '</tbody></table></section>';
    }

    /** @param array<string,mixed>|null $setting */
    private function paymentProviderSaveNotice(string $providerId, ?array $setting, PDO $pdo, PaymentProviderSettingsRepository $repo): string
    {
        if ($setting === null) {
            return '<p class="error">保存回读失败：Provider 配置没有写入数据库，请检查数据库权限或查看后台日志。</p>';
        }

        [$public, $publicError] = $this->paymentProviderPublicConfig($setting);
        $provider = PaymentProviderRegistry::get($providerId);
        $configured = $this->paymentProviderConfigured($providerId, $setting, $public, $publicError, $repo);
        $enabled = (string) ($setting['status'] ?? '') === 'enabled';
        $defaultLabel = $this->paymentProviderDefaultLabel($provider, $setting, $public);
        $enabledIds = [];
        $eligibilityError = '';
        try {
            $enabledIds = array_column($this->paymentService($pdo)->enabledProviders(), 'id');
        } catch (Throwable $exception) {
            $eligibilityError = $this->paymentProviderInternalDiagnosticLabel($exception);
        }
        $discoverable = in_array($providerId, $enabledIds, true);
        if ($publicError !== '') {
            return '<p class="error">保存回读失败：公共配置无法读取，请重新保存 Provider 配置。</p>';
        }
        if (!$configured) {
            return '<p class="error">保存回读失败：必要配置仍不完整，请检查表单字段。</p>';
        }
        if ($enabled && !$discoverable) {
            $extra = $eligibilityError !== '' ? ' 错误：' . $eligibilityError : '';
            return '<p class="error">保存回读失败：Provider 已写入启用，但 PaymentService 仍无法发现。请点击“修复 Provider 存储”后重试。' . View::escape($extra) . '</p>';
        }

        return '<p class="muted">Provider 配置已保存。回读状态：' .
            View::escape($configured ? '已配置' : '未配置') . ' / ' .
            View::escape($enabled ? '启用' : '禁用') . ' / 默认：' .
            View::escape($defaultLabel) . ' / PaymentService：' .
            View::escape($discoverable ? '可发现' : '未启用') . '</p>';
    }

    private function paymentProviderInternalDiagnosticLabel(Throwable $exception): string
    {
        if ($exception instanceof PaymentException) {
            return match ($exception->getMessage()) {
                'No enabled payment provider is available.' => '没有已启用且可创建支付的 Provider。',
                'Default payment provider configuration is ambiguous.' => '默认 Provider 配置不唯一。',
                default => $exception->getMessage(),
            };
        }

        return '支付 Provider 自检暂不可用。';
    }

    /** @param array<string,mixed>|null $setting @return array{0:array<string,mixed>,1:string} */
    private function paymentProviderPublicConfig(?array $setting): array
    {
        if ($setting === null) {
            return [[], ''];
        }
        $raw = (string) ($setting['public_config_json'] ?? '');
        if ($raw === '') {
            return [[], ''];
        }
        if ($raw !== trim($raw)) {
            return [[], '公共配置 JSON 不是规范格式'];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], '公共配置 JSON 无法解析'];
        }
        if (!is_array($decoded)) {
            return [[], '公共配置 JSON 无法解析'];
        }
        try {
            $canonicalJson = $raw === '{}' && $decoded === []
                ? '{}'
                : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], '公共配置 JSON 无法解析'];
        }
        if (!is_string($canonicalJson) || $raw !== $canonicalJson) {
            return [[], '公共配置 JSON 不是规范格式'];
        }
        $safe = $this->paymentProviderSafePublicConfig($decoded);
        if ($safe === null) {
            return [[], '公共配置包含不安全字段'];
        }

        return [$safe, ''];
    }

    /** @return array<string,mixed> */
    private function parsePaymentProviderPublicConfigJson(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        if ($raw !== trim($raw)) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        try {
            $canonicalJson = $raw === '{}' && $decoded === []
                ? '{}'
                : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        if (!is_string($canonicalJson) || $raw !== $canonicalJson) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $config @return array<string,mixed>|null */
    private function paymentProviderSafePublicConfig(array $config): ?array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                return null;
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                return null;
            }
            if (!(is_scalar($value) || $value === null)) {
                return null;
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                return null;
            }
            if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                return null;
            }
            if (is_string($value) && $this->paymentProviderPublicValueContainsSecret($value)) {
                return null;
            }
            if (str_contains(strtolower($key), 'url') && is_string($value) && !$this->paymentProviderSafeUrlValue($value)) {
                return null;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @param array<string,mixed>|null $setting @return array<string,string> */
    private function paymentProviderMaskedSecrets(string $providerId, ?array $setting, PaymentProviderSettingsRepository $repo): array
    {
        if ($setting === null) {
            return [];
        }
        try {
            return $repo->maskedSecrets($providerId);
        } catch (PaymentException) {
            return ['_error' => '密钥不可用'];
        }
    }

    /** @param array<string,mixed>|null $setting @param array<string,mixed> $public */
    private function paymentProviderDefaultLabel(?object $provider, ?array $setting, array $public): string
    {
        if (!array_key_exists('default_provider', $public) || ($public['default_provider'] ?? null) === false || ($public['default_provider'] ?? null) === null) {
            return '否';
        }
        if (($public['default_provider'] ?? null) !== true) {
            return '无效';
        }
        if ($setting === null || (string) ($setting['status'] ?? '') !== 'enabled') {
            return '无效';
        }
        $capabilities = $provider !== null && is_callable([$provider, 'capabilities']) ? $provider->capabilities() : [];
        if (!in_array('payment.create', $capabilities, true)) {
            return '无效';
        }
        if ($provider !== null && is_callable([$provider, 'providerId']) && $provider->providerId() === HostedRedirectPaymentProvider::PROVIDER_ID && $this->hostedRedirectProviderDiagnostics($public) !== []) {
            return '无效';
        }

        return '是';
    }

    /** @param array<string,mixed>|null $setting @param array<string,mixed> $public */
    private function paymentProviderDiagnostics(string $providerId, ?object $provider, ?array $setting, array $public, string $publicError, PaymentProviderSettingsRepository $repo): string
    {
        $messages = [];
        if ($setting === null) {
            $messages[] = '未配置';
        }
        if ($publicError !== '') {
            $messages[] = $publicError;
        }
        if ($provider === null) {
            $messages[] = '未注册，不能启用或接收回调';
        }

        $status = (string) ($setting['status'] ?? '');
        if ($status === 'enabled' && $provider !== null) {
            $capabilities = is_callable([$provider, 'capabilities']) ? $provider->capabilities() : [];
            if (!in_array('payment.create', $capabilities, true)) {
                $messages[] = '缺少 payment.create，不能用于前台收款';
            }
            if (($public['default_provider'] ?? null) === true && !in_array('payment.create', $capabilities, true)) {
                $messages[] = '默认 Provider 缺少收款能力';
            }
            if (!in_array('payment.status', $capabilities, true)) {
                $messages[] = '不支持状态同步';
            }
            if (!in_array('payment.refund', $capabilities, true)) {
                $messages[] = '不支持 Core 退款';
            }
            if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
                foreach ($this->hostedRedirectProviderDiagnostics($public) as $message) {
                    $messages[] = $message;
                }
            }
            if ($provider instanceof PaymentProviderConfigurationInterface) {
                try {
                    foreach ($provider->diagnostics($public, $repo->maskedSecrets($providerId)) as $message) {
                        $messages[] = $message;
                    }
                } catch (PaymentException) {
                    $messages[] = '密钥无法读取';
                }
            }

            try {
                $secrets = $repo->secrets($providerId);
                if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
                    if (!array_key_exists('webhook_secret', $secrets) || trim((string) $secrets['webhook_secret']) === '') {
                        $messages[] = 'Webhook 密钥未配置';
                    }
                }
            } catch (PaymentException) {
                $messages[] = '密钥无法解密';
            }
        }

        if (array_key_exists('default_provider', $public) && !in_array($public['default_provider'], [true, false, null], true)) {
            $messages[] = '默认标记不是 Core 布尔值';
        }
        if ($status !== 'enabled' && ($public['default_provider'] ?? null) === true) {
            $messages[] = '默认标记已忽略，Provider 未启用';
        }

        return $messages !== [] ? implode('；', $messages) : '可用于 Core 支付';
    }

    /** @param array<string,mixed>|null $setting @param array<string,mixed> $public @return array{0:string,1:string} */
    private function paymentProviderLegacyStorageSync(?array $setting, array $public): array
    {
        if ($setting === null) {
            return ['无旧字段', 'muted'];
        }
        $legacyColumns = array_values(array_filter([
            'enabled',
            'is_enabled',
            'is_default',
            'default_provider',
            'config_json',
            'public_config',
            'settings_json',
            'public_settings_json',
            'instructions',
            'payment_instructions',
        ], static fn (string $column): bool => array_key_exists($column, $setting)));
        if ($legacyColumns === []) {
            return ['无旧字段', 'muted'];
        }

        $issues = [];
        $expectedEnabled = (string) ($setting['status'] ?? '') === 'enabled';
        foreach (['enabled', 'is_enabled'] as $column) {
            if (array_key_exists($column, $setting) && $this->paymentProviderLegacyTruthy($setting[$column] ?? null) !== $expectedEnabled) {
                $issues[] = '启用';
                break;
            }
        }
        $expectedDefault = $expectedEnabled && ($public['default_provider'] ?? null) === true;
        foreach (['is_default', 'default_provider'] as $column) {
            if (array_key_exists($column, $setting) && $this->paymentProviderLegacyTruthy($setting[$column] ?? null) !== $expectedDefault) {
                $issues[] = '默认';
                break;
            }
        }
        foreach (['payment_instructions', 'instructions'] as $column) {
            if (!array_key_exists($column, $setting)) {
                continue;
            }
            $legacyInstructions = (string) ($setting[$column] ?? '');
            if (($legacyInstructions === '') !== !array_key_exists('instructions', $public)) {
                $issues[] = '付款说明';
                break;
            }
        }
        foreach (['public_config', 'config_json', 'settings_json', 'public_settings_json'] as $column) {
            if (!array_key_exists($column, $setting) || !is_string($setting[$column]) || trim($setting[$column]) === '') {
                continue;
            }
            try {
                $legacyPublic = json_decode((string) $setting[$column], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $issues[] = '配置 JSON';
                break;
            }
            if (!is_array($legacyPublic)) {
                $issues[] = '配置 JSON';
                break;
            }
            foreach (['instructions', 'checkout_url', 'checkout_base_url', 'return_url_base', 'default_provider'] as $key) {
                if (array_key_exists($key, $legacyPublic) !== array_key_exists($key, $public)
                    || (array_key_exists($key, $legacyPublic) && array_key_exists($key, $public) && $legacyPublic[$key] !== $public[$key])
                ) {
                    $issues[] = '配置 JSON';
                    break 2;
                }
            }
        }

        return $issues === []
            ? ['已同步', 'success']
            : ['需修复：' . implode('/', array_values(array_unique($issues))), 'warning'];
    }

    private function paymentProviderLegacyTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }

        return false;
    }

    /** @param array<string,mixed> $public @return list<string> */
    private function hostedRedirectProviderDiagnostics(array $public): array
    {
        $messages = [];
        $checkoutUrlValue = $public['checkout_url'] ?? $public['checkout_base_url'] ?? '';
        if (!is_string($checkoutUrlValue)) {
            $messages[] = '托管跳转收银台 URL 必须是字符串';
            $checkoutUrl = '';
        } else {
            $checkoutUrl = $checkoutUrlValue;
        }
        if ($checkoutUrl === '') {
            $messages[] = '托管跳转收银台 URL 未配置';
        } elseif (!$this->paymentProviderHttpsUrl($checkoutUrl)) {
            $messages[] = '托管跳转收银台 URL 必须使用 HTTPS';
        } elseif ($this->paymentProviderUrlHasSensitiveQuery($checkoutUrl)) {
            $messages[] = '托管跳转收银台 URL 不能包含敏感查询参数';
        }
        $returnUrlBaseValue = $public['return_url_base'] ?? '';
        if (!is_string($returnUrlBaseValue)) {
            $messages[] = '托管跳转回跳域名必须是字符串';
            $returnUrlBase = '';
        } else {
            $returnUrlBase = $returnUrlBaseValue;
        }
        if ($returnUrlBase !== '' && !$this->paymentProviderHttpsUrl($returnUrlBase)) {
            $messages[] = '托管跳转回跳域名必须使用 HTTPS';
        } elseif ($returnUrlBase !== '' && $this->paymentProviderUrlHasQuery($returnUrlBase)) {
            $messages[] = '托管跳转回跳域名不能包含查询参数';
        } elseif ($returnUrlBase !== '' && $this->paymentProviderUrlHasSensitiveQuery($returnUrlBase)) {
            $messages[] = '托管跳转回跳域名不能包含敏感查询参数';
        }

        return $messages;
    }

    private function paymentProviderHttpsUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function paymentProviderSafeUrlValue(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if ($url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if ($this->paymentProviderPublicValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }

        return !$this->paymentProviderUrlHasSensitiveQuery($url);
    }

    private function paymentProviderUrlHasSensitiveQuery(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return false;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                return true;
            }
            if (!is_scalar($value)) {
                return true;
            }
            if ($this->paymentProviderPublicValueContainsSecret(rawurldecode((string) $value))) {
                return true;
            }
        }

        return false;
    }

    private function paymentProviderUrlHasQuery(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['query']);
    }

    private function paymentProviderPublicValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    /** @param array<string,mixed>|null $setting */
    private function paymentProviderDisplayNameLabel(?object $provider, ?array $setting, string $providerId): string
    {
        $stored = is_array($setting) ? (string) ($setting['display_name'] ?? '') : '';
        if (
            $stored !== ''
            && $stored === trim($stored)
            && strlen($stored) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $stored) !== 1
            && !$this->paymentProviderPublicValueContainsSecret($stored)
        ) {
            return $stored;
        }

        $fallback = $provider !== null && is_callable([$provider, 'displayName'])
            ? (string) $provider->displayName()
            : $providerId;
        if (
            $fallback !== ''
            && $fallback === trim($fallback)
            && strlen($fallback) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $fallback) !== 1
            && !$this->paymentProviderPublicValueContainsSecret($fallback)
        ) {
            return $fallback;
        }

        return $providerId;
    }

    /** @param list<string> $ids */
    private function paymentProviderSelectedId(Request $request, array $ids): string
    {
        $requested = (string) ($request->query['provider_id'] ?? '');
        if ($requested !== '') {
            try {
                $requested = PaymentProviderRegistry::normalize($requested);
            } catch (Throwable) {
                $requested = '';
            }
            if (in_array($requested, $ids, true)) {
                return $requested;
            }
        }
        if (in_array(ManualPaymentProvider::PROVIDER_ID, $ids, true)) {
            return ManualPaymentProvider::PROVIDER_ID;
        }

        return $ids[0] ?? ManualPaymentProvider::PROVIDER_ID;
    }

    private function paymentProviderVisibleInAdmin(string $providerId): bool
    {
        if ($providerId !== 'core.fixture-payment') {
            return true;
        }

        return $this->runtimeAllowsFixtures();
    }

    private function runtimeAllowsFixtures(): bool
    {
        $env = getenv('APP_ENV');
        $env = is_string($env) && $env !== '' ? $env : (string) $this->settings->get('app.env', '');
        $env = strtolower($env);
        if ($env === '') {
            return true;
        }

        return in_array($env, ['development', 'dev', 'testing', 'test', 'local'], true);
    }

    /** @param array<string,mixed>|null $setting @param array<string,mixed> $public */
    private function paymentProviderConfigured(string $providerId, ?array $setting, array $public, string $publicError, PaymentProviderSettingsRepository $repo): bool
    {
        if ($setting === null || $publicError !== '') {
            return false;
        }
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            return true;
        }
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            return $this->hostedRedirectProviderDiagnostics($public) === [];
        }
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider instanceof PaymentProviderConfigurationInterface) {
            try {
                return $provider->isConfigured($public, $repo->maskedSecrets($providerId));
            } catch (PaymentException) {
                return false;
            }
        }
        try {
            return $public !== [] || $repo->maskedSecrets($providerId) !== [];
        } catch (PaymentException) {
            return $public !== [];
        }
    }

    /** @param array<string,mixed>|null $setting */
    private function paymentProviderForm(string $providerId, ?array $setting, PaymentProviderSettingsRepository $repo): string
    {
        $provider = PaymentProviderRegistry::get($providerId);
        [$public, $publicError] = $this->paymentProviderPublicConfig($setting);
        if ($publicError !== '') {
            $public = [];
        }
        $status = (string) ($setting['status'] ?? 'disabled');
        $displayName = $this->paymentProviderDisplayNameLabel($provider, $setting, $providerId);
        $default = ($public['default_provider'] ?? null) === true;
        $secretKeys = [];
        try {
            $secretKeys = array_keys($repo->maskedSecrets($providerId));
        } catch (PaymentException) {
            $secretKeys = ['_error'];
        }
        $fields = $this->paymentProviderSchemaFields($providerId, $public, $secretKeys);
        $advanced = $this->paymentProviderAdvancedJson($public, $providerId);
        $checked = $default ? ' checked' : '';
        $statusOptions = '<option value="enabled"' . ($status === 'enabled' ? ' selected' : '') . '>启用</option><option value="disabled"' . ($status !== 'enabled' ? ' selected' : '') . '>停用</option>';

        return '<section class="admin-card" id="provider-form"><h2>配置支付方式：' . View::escape($this->paymentProviderFriendlyName($providerId)) . '</h2>' .
            '<p class="muted">配置 Provider：' . View::escape($providerId) . '。技术 ID 会自动带入，日常只需要填写下面的中文字段。</p>' .
            '<form method="post" action="/admin/payments/providers/save">' . CsrfToken::field() .
            '<input type="hidden" name="provider_settings_form" value="1">' .
            '<input type="hidden" name="provider_id" value="' . View::escape($providerId) . '">' .
            '<label>支付方式名称<input name="display_name" value="' . View::escape($displayName) . '" placeholder="' . View::escape($this->paymentProviderFriendlyName($providerId)) . '"></label>' .
            '<label>状态<select name="status">' . $statusOptions . '</select></label>' .
            '<label><input type="checkbox" name="default_provider" value="1"' . $checked . '> 设为默认支付方式 <span class="muted">（设为默认 Provider）</span></label>' .
            $fields .
            '<details><summary>高级公共 JSON（可选）</summary><p class="muted">普通配置无需填写；这里只放非密钥字段，会与上方字段合并。密钥、Token、密码不要写在这里。</p><textarea name="public_config_json" rows="3">' . View::escape($advanced) . '</textarea></details>' .
            '<button type="submit">保存支付方式</button></form></section>';
    }

    /** @param array<string,mixed> $public @param list<string> $secretKeys */
    private function paymentProviderSchemaFields(string $providerId, array $public, array $secretKeys): string
    {
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            $instructions = (string) ($public['instructions'] ?? '请按站点说明完成线下付款，管理员确认后自动发卡。');
            return '<fieldset><legend>买家付款说明</legend>' .
                '<p class="muted">用于线下转账、客服收款、人工确认。买家下单后会看到这里的说明；管理员确认收款后，在支付详情里点“捕获支付”，系统会自动发卡。</p>' .
                '<label>前台显示给买家的付款说明<textarea name="manual_instructions" rows="3" placeholder="例如：请添加客服微信 xxx，备注订单号；管理员确认收款后系统自动发卡。">' . View::escape($instructions) . '</textarea></label>' .
                '<p class="muted">平台地址：不需要填写。回调地址：不需要填写。</p></fieldset>';
        }
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            $checkout = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
            $return = (string) ($public['return_url_base'] ?? '');
            $checkoutSecretHint = in_array('checkout_secret', $secretKeys, true) ? '已配置，留空则保留' : '可选，至少 16 个字符';
            $webhookSecretHint = in_array('webhook_secret', $secretKeys, true) ? '已配置，留空则保留' : '建议配置，用于验签';
            $webhookUrl = rtrim((string) $this->settings->get('site.url', ''), '/') . '/payment/webhooks/' . $providerId;
            if (!str_starts_with($webhookUrl, 'http')) {
                $webhookUrl = '/payment/webhooks/' . $providerId;
            }
            return '<fieldset><legend>第三方支付平台资料</legend>' .
                '<p class="muted">适用于易支付、码支付、聚合支付、你自己的托管收银台。官方微信/支付宝直连需要专用 Provider/插件。</p>' .
                '<label>支付平台收银台地址<input name="hosted_checkout_url" value="' . View::escape($checkout) . '" placeholder="https://pay.example.com/checkout"></label>' .
                '<p class="muted">填写第三方平台给你的“创建支付 / 收银台 / 网关”HTTPS 地址。不要把密钥放到 URL 问号参数里。</p>' .
                '<label>本站回跳域名<input name="hosted_return_url_base" value="' . View::escape($return) . '" placeholder="https://你的域名"></label>' .
                '<p class="muted">只填域名，例如 https://www.example.com，不要带 /path、?query 或 #fragment。</p>' .
                '<label>Checkout 签名密钥<input name="hosted_checkout_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($checkoutSecretHint) . '"></label>' .
                '<label>Webhook 通知密钥<input name="hosted_webhook_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($webhookSecretHint) . '"></label>' .
                '<p class="muted">支付平台后台的“异步通知 / Webhook / 回调地址”填写：<code>' . View::escape($webhookUrl) . '</code></p></fieldset>';
        }
        if ($providerId === 'official.payment.stripe') {
            $publishableKey = (string) ($public['publishable_key'] ?? '');
            $mode = (string) ($public['mode'] ?? 'test');
            $currency = (string) ($public['currency'] ?? 'USD');
            $country = (string) ($public['country'] ?? '');
            $returnUrlBase = (string) ($public['return_url_base'] ?? '');
            $transport = (string) ($public['transport'] ?? '');
            $secretKeyHint = in_array('secret_key', $secretKeys, true) ? '已配置，留空则保留' : 'sk_test_ 或 sk_live_ 开头';
            $webhookSecretHint = in_array('webhook_secret', $secretKeys, true) ? '已配置，留空则保留' : 'whsec_ 开头';
            $secretClear = in_array('secret_key', $secretKeys, true)
                ? '<label><input type="checkbox" name="stripe_clear_secret_key" value="1" onclick="if(this.checked&&!confirm(\'确定要清除已保存的 Stripe Secret Key 吗？清除后必须重新填写才能启用 Stripe。\'))this.checked=false;"> 清除已保存 Secret Key</label>'
                : '';
            $webhookClear = in_array('webhook_secret', $secretKeys, true)
                ? '<label><input type="checkbox" name="stripe_clear_webhook_secret" value="1" onclick="if(this.checked&&!confirm(\'确定要清除已保存的 Stripe Webhook Signing Secret 吗？清除后 Webhook 将无法验签。\'))this.checked=false;"> 清除已保存 Webhook Signing Secret</label>'
                : '';
            $webhookUrl = rtrim((string) $this->settings->get('site.url', ''), '/') . '/payment/webhooks/official.payment.stripe';
            if (!str_starts_with($webhookUrl, 'http')) {
                $webhookUrl = '/payment/webhooks/official.payment.stripe';
            }
            return '<fieldset><legend>Stripe 官方直连资料</legend>' .
                '<p class="muted">Stripe 使用 Checkout Session。浏览器回跳不会直接标记已支付，必须通过 Stripe-Signature 验证通过的 Webhook 才能进入 trusted paid。</p>' .
                '<label>Publishable Key<input name="stripe_publishable_key" value="' . View::escape($publishableKey) . '" placeholder="pk_test_..."></label>' .
                '<label>Secret Key<input name="stripe_secret_key" type="password" autocomplete="new-password" placeholder="' . View::escape($secretKeyHint) . '"></label>' .
                $secretClear .
                '<label>Webhook Signing Secret<input name="stripe_webhook_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($webhookSecretHint) . '"></label>' .
                $webhookClear .
                '<label>Test / Live Mode<select name="stripe_mode"><option value="test"' . ($mode !== 'live' ? ' selected' : '') . '>Test Mode 测试模式</option><option value="live"' . ($mode === 'live' ? ' selected' : '') . '>Live Mode 正式模式</option></select></label>' .
                $this->currencySelectForProvider('stripe_currency', $currency, ['USD', 'CNY', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'SGD']) .
                '<label>默认结算国家<input name="stripe_country" value="' . View::escape($country) . '" placeholder="US"></label>' .
                '<label>本站回跳域名<input name="stripe_return_url_base" value="' . View::escape($returnUrlBase) . '" placeholder="https://你的域名"></label>' .
                '<p class="muted">Stripe 后台 Webhook Endpoint URL 填：<code>' . View::escape($webhookUrl) . '</code></p>' .
                ($transport === 'fake' ? '<input type="hidden" name="stripe_transport" value="fake">' : '') .
                '</fieldset>';
        }
        if ($providerId === 'official.payment.paypal') {
            $environment = (string) ($public['environment'] ?? 'sandbox');
            $currency = (string) ($public['currency'] ?? 'USD');
            $returnUrlBase = (string) ($public['return_url_base'] ?? '');
            $webhookId = (string) ($public['webhook_id'] ?? '');
            $transport = (string) ($public['transport'] ?? '');
            $clientIdHint = in_array('client_id', $secretKeys, true) ? '已配置，留空则保留' : 'PayPal Client ID';
            $clientSecretHint = in_array('client_secret', $secretKeys, true) ? '已配置，留空则保留' : 'PayPal Client Secret';
            $webhookUrl = rtrim((string) $this->settings->get('site.url', ''), '/') . '/payment/webhooks/official.payment.paypal';
            if (!str_starts_with($webhookUrl, 'http')) {
                $webhookUrl = '/payment/webhooks/official.payment.paypal';
            }
            return '<fieldset><legend>PayPal 官方 Checkout 资料</legend>' .
                '<p class="muted">PayPal 使用 Checkout Order。浏览器 return URL 不会直接标记已支付，必须通过 PayPal Webhook 验证后进入 trusted paid。</p>' .
                '<label>Client ID<input name="paypal_client_id" type="password" autocomplete="new-password" placeholder="' . View::escape($clientIdHint) . '"></label>' .
                '<label>Client Secret<input name="paypal_client_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($clientSecretHint) . '"></label>' .
                '<label>Environment<select name="paypal_environment"><option value="sandbox"' . ($environment !== 'live' ? ' selected' : '') . '>Sandbox 测试环境</option><option value="live"' . ($environment === 'live' ? ' selected' : '') . '>Live 正式环境</option></select></label>' .
                '<label>Webhook ID<input name="paypal_webhook_id" value="' . View::escape($webhookId) . '" placeholder="PayPal Webhook ID"></label>' .
                $this->currencySelectForProvider('paypal_currency', $currency, ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'SGD']) .
                '<label>本站回跳域名<input name="paypal_return_url_base" value="' . View::escape($returnUrlBase) . '" placeholder="https://你的域名"></label>' .
                '<p class="muted">PayPal 后台 Webhook URL 填：<code>' . View::escape($webhookUrl) . '</code></p>' .
                ($transport === 'fake' ? '<input type="hidden" name="paypal_transport" value="fake">' : '') .
                '</fieldset>';
        }
        if ($providerId === 'official.payment.wechatpay') {
            $currency = (string) ($public['currency'] ?? 'CNY');
            $mode = (string) ($public['payment_mode'] ?? 'native');
            $notifyUrl = (string) ($public['notify_url'] ?? (rtrim((string) $this->settings->get('site.url', ''), '/') . '/payment/webhooks/official.payment.wechatpay'));
            $transport = (string) ($public['transport'] ?? '');
            $secretHints = [
                'api_v3_key' => in_array('api_v3_key', $secretKeys, true) ? '已配置，留空则保留' : '32 位 API v3 Key',
                'merchant_private_key' => in_array('merchant_private_key', $secretKeys, true) ? '已配置，留空则保留' : '商户私钥，建议粘贴单行 base64 DER 或 PEM',
            ];
            return '<fieldset><legend>微信支付 API v3 资料</legend>' .
                '<p class="muted">当前网站支付使用 Native 模式。Notify 需要微信支付时间戳、随机串、签名、证书序列号和 AES-GCM resource 解密全部通过后才会进入 trusted paid。</p>' .
                '<label>App ID<input name="wechatpay_app_id" value="' . View::escape((string) ($public['app_id'] ?? '')) . '" placeholder="wx..."></label>' .
                '<label>Mch ID 商户号<input name="wechatpay_mch_id" value="' . View::escape((string) ($public['mch_id'] ?? '')) . '" placeholder="商户号"></label>' .
                '<label>商户证书序列号<input name="wechatpay_merchant_serial" value="' . View::escape((string) ($public['merchant_serial'] ?? '')) . '" placeholder="Merchant Certificate Serial Number"></label>' .
                '<label>平台证书序列号<input name="wechatpay_platform_serial" value="' . View::escape((string) ($public['platform_serial'] ?? '')) . '" placeholder="Wechatpay-Serial"></label>' .
                '<label>平台证书 / 平台公钥<input name="wechatpay_platform_public_key" value="' . View::escape((string) ($public['platform_public_key'] ?? '')) . '" placeholder="单行 base64 DER 公钥，或 PEM"></label>' .
                '<label>API v3 Key<input name="wechatpay_api_v3_key" type="password" autocomplete="new-password" placeholder="' . View::escape($secretHints['api_v3_key']) . '"></label>' .
                '<label>商户私钥<input name="wechatpay_merchant_private_key" type="password" autocomplete="new-password" placeholder="' . View::escape($secretHints['merchant_private_key']) . '"></label>' .
                '<label>支付模式<select name="wechatpay_payment_mode"><option value="native"' . ($mode !== 'h5' ? ' selected' : '') . '>Native 网站扫码支付</option><option value="h5"' . ($mode === 'h5' ? ' selected' : '') . '>H5 预留</option></select></label>' .
                $this->currencySelectForProvider('wechatpay_currency', $currency, ['CNY']) .
                '<label>Notify URL<input name="wechatpay_notify_url" value="' . View::escape($notifyUrl) . '" placeholder="https://你的域名/payment/webhooks/official.payment.wechatpay"></label>' .
                ($transport === 'fake' ? '<input type="hidden" name="wechatpay_transport" value="fake">' : '') .
                '</fieldset>';
        }
        if ($providerId === 'official.payment.alipay') {
            $mode = (string) ($public['gateway_mode'] ?? 'sandbox');
            $currency = (string) ($public['currency'] ?? 'CNY');
            $signType = (string) ($public['sign_type'] ?? 'RSA2');
            $charset = (string) ($public['charset'] ?? 'utf-8');
            $notifyUrl = (string) ($public['notify_url'] ?? (rtrim((string) $this->settings->get('site.url', ''), '/') . '/payment/webhooks/official.payment.alipay'));
            $privateHint = in_array('app_private_key', $secretKeys, true) ? '已配置，留空则保留' : '应用私钥，建议粘贴单行 base64 DER 或 PEM';
            $transport = (string) ($public['transport'] ?? '');
            return '<fieldset><legend>支付宝官方 Page Pay 资料</legend>' .
                '<p class="muted">支付宝 return URL 只负责用户回跳体验；只有异步 notify RSA2 验签、App ID、商户、金额和订单号核对通过后才会进入 trusted paid。</p>' .
                '<label>App ID<input name="alipay_app_id" value="' . View::escape((string) ($public['app_id'] ?? '')) . '" placeholder="支付宝开放平台 App ID"></label>' .
                '<label>Seller ID / Merchant（可选）<input name="alipay_seller_id" value="' . View::escape((string) ($public['seller_id'] ?? '')) . '" placeholder="收款账号或卖家 ID"></label>' .
                '<label>App Private Key<input name="alipay_app_private_key" type="password" autocomplete="new-password" placeholder="' . View::escape($privateHint) . '"></label>' .
                '<label>Alipay Public Key<input name="alipay_public_key" value="' . View::escape((string) ($public['alipay_public_key'] ?? '')) . '" placeholder="单行 base64 DER 公钥，或 PEM"></label>' .
                '<label>Gateway Mode<select name="alipay_gateway_mode"><option value="sandbox"' . ($mode !== 'production' ? ' selected' : '') . '>Sandbox 测试环境</option><option value="production"' . ($mode === 'production' ? ' selected' : '') . '>Production 正式环境</option></select></label>' .
                '<label>Sign Type<input name="alipay_sign_type" value="' . View::escape($signType) . '" placeholder="RSA2"></label>' .
                '<label>Charset<input name="alipay_charset" value="' . View::escape($charset) . '" placeholder="utf-8"></label>' .
                $this->currencySelectForProvider('alipay_currency', $currency, ['CNY']) .
                '<label>Return URL<input name="alipay_return_url" value="' . View::escape((string) ($public['return_url'] ?? '')) . '" placeholder="https://你的域名/payment/return"></label>' .
                '<label>Notify URL<input name="alipay_notify_url" value="' . View::escape($notifyUrl) . '" placeholder="https://你的域名/payment/webhooks/official.payment.alipay"></label>' .
                ($transport === 'fake' ? '<input type="hidden" name="alipay_transport" value="fake">' : '') .
                '</fieldset>';
        }

        return '<fieldset><legend>第三方 Provider 配置</legend><p class="muted">该支付方式由第三方 Provider 提供，请按它的文档填写。密钥一行一个 KEY=VALUE，留空会保留已有密钥。</p><label>密钥配置<textarea name="secrets_text" rows="4"></textarea></label><p class="muted">Webhook 地址：<code>/payment/webhooks/' . View::escape($providerId) . '</code></p></fieldset>';
    }

    /** @param array<string,mixed> $public */
    private function paymentProviderAdvancedJson(array $public, string $providerId): string
    {
        unset($public['default_provider']);
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            unset($public['instructions']);
        }
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            unset($public['checkout_url'], $public['checkout_base_url'], $public['return_url_base']);
        }
        if ($providerId === 'official.payment.stripe') {
            unset($public['publishable_key'], $public['mode'], $public['currency'], $public['country'], $public['return_url_base'], $public['success_url'], $public['cancel_url'], $public['transport']);
        }
        if ($providerId === 'official.payment.paypal') {
            unset($public['environment'], $public['currency'], $public['return_url_base'], $public['webhook_id'], $public['transport']);
        }
        if ($providerId === 'official.payment.wechatpay') {
            unset($public['app_id'], $public['mch_id'], $public['merchant_serial'], $public['platform_serial'], $public['platform_public_key'], $public['payment_mode'], $public['currency'], $public['notify_url'], $public['return_url'], $public['transport']);
        }
        if ($providerId === 'official.payment.alipay') {
            unset($public['app_id'], $public['seller_id'], $public['alipay_public_key'], $public['gateway_mode'], $public['return_url'], $public['notify_url'], $public['sign_type'], $public['charset'], $public['currency'], $public['transport']);
        }
        return $this->paymentProviderJson($public);
    }

    /** @param array<string,mixed> $config */
    private function paymentProviderJson(array $config): string
    {
        try {
            return json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }

    public function paymentProviderSave(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置失败', '<h1>支付 Provider 设置失败</h1><p class="error">Provider 配置保存必须通过 POST 请求提交。</p><p>' . $this->paymentProviderSettingsBackLink('') . '</p>'), 405)
                ->withHeaders(['Allow' => 'POST']));
        }
        $returnProviderId = $this->paymentProviderReturnId($request);
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置失败', '<h1>支付 Provider 设置失败</h1><p class="error">CSRF 校验失败，请刷新页面重试。</p><p>' . $this->paymentProviderSettingsBackLink($returnProviderId) . '</p>'), 403));
        }

        try {
            $this->assertPaymentProviderSaveRequestComplete($request);
            $providerId = $this->paymentProviderIdInput($this->paymentBodyString($request, 'provider_id'));
            $returnProviderId = $providerId;
            $status = $this->paymentProviderStatusFromRequest($request);
            if ($status === 'enabled' && PaymentProviderRegistry::get($providerId) === null) {
                throw new PaymentException('Payment provider must be registered before it can be enabled.');
            }
            if (!$this->paymentProviderVisibleInAdmin($providerId)) {
                throw new PaymentException('Payment provider is not available in this environment.');
            }
            $public = $this->paymentProviderPublicConfigFromRequest($request, $providerId);
            if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID && $status === 'enabled') {
                $this->assertHostedRedirectProviderConfig($public);
            }
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentProviderSettingsRepository(
                $pdo,
                (string) $this->settings->get('security.encryption_key', ''),
            );
            $repo->storageDiagnostics();
            $makeDefault = $this->paymentProviderDefaultFromRequest($request);
            if ($makeDefault && $status !== 'enabled') {
                throw new PaymentException('Default payment provider must be enabled.');
            }
            $provider = PaymentProviderRegistry::get($providerId);
            if ($makeDefault && ($provider === null || !in_array('payment.create', $provider->capabilities(), true))) {
                throw new PaymentException('Default payment provider must support checkout.');
            }
            if ($status === 'enabled' && $provider instanceof PaymentProviderConfigurationInterface) {
                $previewSecrets = $this->paymentProviderSecretsPreview($request, $providerId);
                if (!$provider->isConfigured($public, $previewSecrets)) {
                    throw new PaymentException(implode('；', $provider->diagnostics($public, $previewSecrets)));
                }
            }
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $repo->save(
                    $providerId,
                    $this->paymentProviderDisplayNameFromRequest($request),
                    $status,
                    $public,
                    $this->paymentProviderSecretsFromRequest($request, $providerId),
                );
                if ($makeDefault) {
                    $repo->setDefaultProvider($providerId);
                }
                $this->assertPaymentProviderSavePersisted($repo, $providerId, $status, $makeDefault);
                $this->assertPaymentProviderSaveEligible($pdo, $providerId, $status, $makeDefault);
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.provider_settings.saved', ['provider_id' => $providerId, 'default_provider' => $makeDefault]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            if (!$alreadyInTransaction) {
                $this->assertPaymentProviderSaveCommitted($providerId, $status, $makeDefault);
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置失败', '<h1>支付 Provider 设置失败</h1><p class="error">' . View::escape($this->paymentProviderSaveErrorMessage($exception)) . '</p><p>' . $this->paymentProviderSettingsBackLink($returnProviderId) . '</p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment provider settings save failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置失败', '<h1>支付 Provider 设置失败</h1><p class="error">支付 Provider 设置暂不可用。</p><p>' . $this->paymentProviderSettingsBackLink($returnProviderId) . '</p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/providers?provider_id=' . rawurlencode($providerId) . '&saved=1'));
    }

    private function assertPaymentProviderSaveCommitted(string $providerId, string $status, bool $makeDefault): void
    {
        $repo = new PaymentProviderSettingsRepository(
            ConnectionFactory::make($this->settings),
            (string) $this->settings->get('security.encryption_key', ''),
        );
        $this->assertPaymentProviderSavePersisted($repo, $providerId, $status, $makeDefault);
        $this->assertPaymentProviderSaveEligible(ConnectionFactory::make($this->settings), $providerId, $status, $makeDefault);
    }

    private function paymentProviderReturnId(Request $request): string
    {
        try {
            return $this->paymentProviderIdInput($this->paymentBodyString($request, 'provider_id'));
        } catch (Throwable) {
            return '';
        }
    }

    private function paymentProviderSettingsBackLink(string $providerId): string
    {
        $href = '/admin/payments/providers';
        if ($providerId !== '') {
            $href .= '?provider_id=' . rawurlencode($providerId) . '#provider-form';
        }

        return '<a class="button" href="' . View::escape($href) . '">返回 Provider 设置</a>';
    }

    private function assertPaymentProviderSaveRequestComplete(Request $request): void
    {
        foreach (['provider_id'] as $key) {
            if (!array_key_exists($key, $request->body)) {
                throw new PaymentException('Payment provider settings form submission is incomplete.');
            }
        }
        if (!$this->paymentProviderAnyBodyKeyExists($request, ['display_name', 'name', 'title'])) {
            throw new PaymentException('Payment provider settings form submission is incomplete.');
        }
        if (!$this->paymentProviderAnyBodyKeyExists($request, ['public_config_json', 'config_json', 'public_config', 'settings_json', 'public_settings_json'])) {
            throw new PaymentException('Payment provider settings form submission is incomplete.');
        }
        if (!array_key_exists('status', $request->body) && !array_key_exists('enabled', $request->body) && !array_key_exists('is_enabled', $request->body)) {
            throw new PaymentException('Payment provider settings form submission is incomplete.');
        }
        if (array_key_exists('provider_settings_form', $request->body) && $this->paymentBodyString($request, 'provider_settings_form') !== '1') {
            throw new PaymentException('Payment provider settings form submission is incomplete.');
        }
    }

    private function assertPaymentProviderSavePersisted(PaymentProviderSettingsRepository $repo, string $providerId, string $status, bool $makeDefault): void
    {
        $setting = $repo->setting($providerId);
        if ($setting === null) {
            throw new PaymentException('Payment provider setting was not persisted.');
        }
        if ((string) ($setting['status'] ?? '') !== $status) {
            throw new PaymentException('Payment provider setting status was not persisted.');
        }
        [$public, $publicError] = $this->paymentProviderPublicConfig($setting);
        if ($publicError !== '') {
            throw new PaymentException('Payment provider saved public config is invalid.');
        }
        if ($makeDefault && ($public['default_provider'] ?? null) !== true) {
            throw new PaymentException('Payment provider default flag was not persisted.');
        }
    }

    private function assertPaymentProviderSaveEligible(PDO $pdo, string $providerId, string $status, bool $makeDefault): void
    {
        if ($status !== 'enabled') {
            return;
        }
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider === null || !in_array('payment.create', $provider->capabilities(), true)) {
            return;
        }
        $enabledIds = array_column(
            (new PaymentService(
                $pdo,
                new PaymentRepository($pdo),
                (string) $this->settings->get('security.encryption_key', ''),
            ))->enabledProviders(),
            'id',
        );
        if (!in_array($providerId, $enabledIds, true)) {
            throw new PaymentException('Payment provider enabled eligibility was not persisted.');
        }
        if ($makeDefault && (new PaymentProviderSelector($pdo, $this->settings))->defaultProviderId() !== $providerId) {
            throw new PaymentException('Payment provider default selector was not persisted.');
        }
    }

    private function paymentProviderSaveErrorMessage(PaymentException $exception): string
    {
        return match ($exception->getMessage()) {
            'Payment provider secret encryption key is not configured.' => '支付密钥未配置，无法保存 Provider 密钥配置。请先配置 security.encryption_key，或清空密钥字段后再保存。',
            'Payment provider must be registered before it can be enabled.' => '该支付 Provider 尚未注册，不能启用。',
            'Payment provider is not available in this environment.' => '当前运行环境不可配置该支付 Provider。',
            'Default payment provider must be enabled.' => '默认支付 Provider 必须先设为启用。',
            'Default payment provider must support checkout.' => '默认支付 Provider 必须支持创建支付。',
            'Payment provider setting was not persisted.' => '保存失败：Provider 配置没有写入数据库。',
            'Payment provider setting status was not persisted.' => '保存失败：Provider 启用状态没有写入数据库。',
            'Payment provider default flag was not persisted.' => '保存失败：默认 Provider 标记没有写入数据库。',
            'Payment provider enabled eligibility was not persisted.' => '保存失败：Provider 已写入但支付服务仍无法发现，请先点击“修复 Provider 存储”后重试。',
            'Payment provider default selector was not persisted.' => '保存失败：默认 Provider 已写入但支付选择器仍未生效，请先点击“修复 Provider 存储”后重试。',
            'Payment provider saved public config is invalid.' => '保存失败：写入后的公共配置无法读取。',
            'Payment provider settings form submission is incomplete.' => '保存失败：后台没有收到完整的 Provider 表单字段，请刷新页面后重新保存。',
            'Payment provider display name is invalid.' => '保存失败：Provider 显示名称无效。',
            'Payment provider id is invalid.' => '保存失败：Provider ID 无效。',
            'Payment provider setting status is invalid.' => '保存失败：Provider 启用状态无效。',
            'Payment provider public config JSON is invalid.' => '保存失败：公共配置 JSON 无效，请检查高级配置。',
            'Payment provider public config key is invalid.' => '保存失败：公共配置字段名无效。',
            'Payment provider public config value is invalid.' => '保存失败：公共配置字段值无效。',
            'Payment provider public config URL is invalid.' => '保存失败：公共配置 URL 无效。',
            'Payment provider public config cannot contain secrets.' => '保存失败：公共配置不能包含密钥、Token 或密码。',
            'Payment provider default marker is invalid.' => '保存失败：默认 Provider 标记无效。',
            'Payment provider return URL base is invalid.' => '保存失败：回跳 URL 配置无效。',
            'Payment provider secret text is invalid.' => '保存失败：密钥配置格式无效，请使用一行一个 KEY=VALUE。',
            'Payment provider secret ciphertext is invalid.' => '保存失败：已保存的 Provider 密钥配置已损坏，请重新填写密钥后保存。',
            'Unable to decrypt payment provider secrets.' => '保存失败：已保存的 Provider 密钥配置无法解密，请重新填写密钥后保存。',
            'Payment provider secret payload is invalid.' => '保存失败：Provider 密钥配置无效，请重新填写密钥后保存。',
            default => preg_match('/\p{Han}/u', $exception->getMessage()) === 1
                ? '保存失败：' . $exception->getMessage()
                : '保存失败：Provider 配置无效，请检查表单字段后重试。',
        };
    }

    /** @param array<string,mixed> $public */
    private function assertHostedRedirectProviderConfig(array $public): void
    {
        $errors = $this->hostedRedirectProviderDiagnostics($public);
        if ($errors !== []) {
            throw new PaymentException(implode('; ', $errors));
        }
    }

    public function paymentDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = $this->pathId($request->path);
        if ($id <= 0) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付详情', '<h1>支付详情</h1><p class="error">支付记录路径无效。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 400));
        }
        try {
            $repo = new PaymentRepository(ConnectionFactory::make($this->settings));
            $payment = $repo->payment($id);
            if ($payment === null) {
                return $this->paymentAdminNoStore(Response::html(View::page('支付详情', '<h1>支付详情</h1><p class="error">支付记录不存在。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 404));
            }
            $refunds = $repo->refundsForPayment($id);
            $authorizations = $repo->authorizationsForPayment($id);
            $authorizationEvents = $repo->authorizationEventsForPayment($id);
            $entitlements = $repo->entitlementsForPayment($id);
            $webhooks = $repo->webhookReceiptsForPayment($id, 10);
            $trustedStatus = $repo->trustedStatus(
                (string) ($payment['subject_type'] ?? ''),
                (string) ($payment['subject_id'] ?? ''),
                (string) ($payment['currency'] ?? '')
            );
        } catch (Throwable $exception) {
            $this->logger->error('Core payment detail failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付详情', '<h1>支付详情</h1><p class="error">支付服务暂不可用。</p>'), 500));
        }

        $refundRows = '';
        foreach ($refunds as $refund) {
            $refundRows .= '<tr><td>' . View::escape($this->paymentDetailIdLabel($refund['id'] ?? null)) . '</td><td>' . View::escape($this->paymentRefundStatusLabel((string) $refund['status'])) . '</td><td>' .
                View::escape($this->moneyLabel($refund['amount_minor'], (string) $refund['currency'])) . '</td><td>' . View::escape((string) $refund['reason']) . '</td><td>' .
                View::escape($this->paymentTimestampLabel($refund['created_at'] ?? null)) . '</td></tr>';
        }
        $refundRows = $refundRows !== '' ? $refundRows : '<tr><td colspan="5" class="muted">暂无退款</td></tr>';

        $authorizationRows = '';
        foreach ($authorizations as $authorization) {
            $authorizationId = $this->storedPositiveInt($authorization['id'] ?? null);
            $revoke = (string) ($authorization['status'] ?? '') === 'active' && $authorizationId !== null
                ? '<form method="post" action="/admin/payments/' . $id . '/authorizations/' . $authorizationId . '/revoke" style="display:inline">' . CsrfToken::field() . '<button type="submit">撤销授权</button></form>'
                : '<span class="muted">不可操作</span>';
            $authorizationRows .= '<tr><td>' . View::escape($this->paymentDetailIdLabel($authorization['id'] ?? null)) . '</td><td>' .
                View::escape((string) $authorization['subject_type'] . ' / ' . (string) $authorization['subject_id']) . '</td><td>' .
                View::escape($this->paymentAuthorizationStatusLabel((string) ($authorization['status'] ?? ''))) . '</td><td>' .
                View::escape($this->paymentAuthorizationUsageLabel($authorization['used_count'] ?? null, $authorization['max_uses'] ?? null)) . '</td><td>' .
                View::escape($this->paymentOptionalTimestampLabel($authorization['expires_at'] ?? null)) . '</td><td>' . $revoke . '</td></tr>';
        }
        $authorizationRows = $authorizationRows !== '' ? $authorizationRows : '<tr><td colspan="6" class="muted">暂无授权记录</td></tr>';

        $authorizationEventRows = '';
        foreach ($authorizationEvents as $event) {
            $metadata = $this->paymentDisplayMetadata((string) ($event['metadata_json'] ?? '{}'));
            $authorizationEventRows .= '<tr><td>' . View::escape($this->paymentDetailIdLabel($event['id'] ?? null)) . '</td><td>' .
                View::escape($this->paymentDetailIdLabel($event['authorization_id'] ?? null)) . '</td><td>' .
                View::escape($this->paymentAuthorizationEventTypeLabel((string) ($event['event_type'] ?? ''))) . '</td><td><code>' .
                View::escape(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code></td><td>' .
                View::escape($this->paymentTimestampLabel($event['created_at'] ?? null)) . '</td></tr>';
        }
        $authorizationEventRows = $authorizationEventRows !== '' ? $authorizationEventRows : '<tr><td colspan="5" class="muted">暂无授权事件</td></tr>';

        $entitlementRows = '';
        foreach ($entitlements as $entitlement) {
            $entitlementId = $this->storedPositiveInt($entitlement['id'] ?? null);
            $entitlementAction = (string) ($entitlement['status'] ?? '') === 'active' && $entitlementId !== null
                ? '<form method="post" action="/admin/payments/' . $id . '/entitlements/' . $entitlementId . '/revoke" style="display:inline">' . CsrfToken::field() . '<button type="submit">撤销权益</button></form>'
                : '<span class="muted">不可操作</span>';
            $entitlementRows .= '<tr><td>' . View::escape($this->paymentDetailIdLabel($entitlement['id'] ?? null)) . '</td><td>' .
                View::escape((string) $entitlement['principal_type'] . ' / ' . (string) $entitlement['principal_id']) . '</td><td>' .
                View::escape((string) $entitlement['subject_type'] . ' / ' . (string) $entitlement['subject_id']) . '</td><td>' .
                View::escape($this->paymentEntitlementStatusLabel((string) ($entitlement['status'] ?? ''))) . '</td><td>' .
                View::escape($this->paymentOptionalTimestampLabel($entitlement['expires_at'] ?? null)) . '</td><td>' . $entitlementAction . '</td></tr>';
        }
        $entitlementRows = $entitlementRows !== '' ? $entitlementRows : '<tr><td colspan="6" class="muted">暂无会员权益</td></tr>';

        $webhookRows = '';
        foreach ($webhooks as $receipt) {
            $receiptMeta = json_decode((string) ($receipt['metadata_json'] ?? '{}'), true) ?: [];
            $diagnostic = $this->webhookDiagnostic((string) ($receiptMeta['failure_error'] ?? ''));
            $payloadSize = $this->webhookTracePayloadSize($receiptMeta['payload_size'] ?? null);
            $contentType = $this->webhookTraceContentType((string) ($receiptMeta['content_type'] ?? ''));
            $signatureTimestamp = $this->webhookTraceTimestamp((string) ($receiptMeta['webhook_timestamp'] ?? ''));
            $sourceHash = $this->webhookTraceHash((string) ($receiptMeta['source_ip_hash'] ?? ''));
            $trace = $payloadSize !== null ? 'payload=' . $payloadSize . 'B' : '';
            if ($contentType !== '') {
                $trace .= ($trace !== '' ? ' ' : '') . 'type=' . $contentType;
            }
            if ($signatureTimestamp !== '') {
                $trace .= ($trace !== '' ? ' ' : '') . 'ts=' . $signatureTimestamp;
            }
            if ($sourceHash !== '') {
                $trace .= ($trace !== '' ? ' ' : '') . 'src=' . substr($sourceHash, 0, 12);
            }
            $receiptId = $this->storedPositiveInt($receipt['id'] ?? null);
            $receiptStatus = (string) ($receipt['status'] ?? '');
            $webhookAction = !in_array($receiptStatus, ['received', 'failed'], true) || $receiptId === null
                ? '<span class="muted">不可操作</span>'
                : '<form method="post" action="/admin/payments/' . $id . '/webhooks/' . $receiptId . '/status" style="display:inline">' . CsrfToken::field() . '<button name="status" value="processed" type="submit">标记已处理</button> <button name="status" value="ignored" type="submit">忽略</button></form>';
            $webhookRows .= '<tr><td>' . View::escape($this->paymentDetailIdLabel($receipt['id'] ?? null)) . '</td><td>' . View::escape((string) $receipt['external_event_id']) . '</td><td>' .
                View::escape($this->paymentWebhookReceiptStatusLabel((string) ($receipt['status'] ?? ''))) . '</td><td>' . View::escape($this->paymentOptionalTimestampLabel($receipt['processed_at'] ?? null)) . '</td><td>' .
                View::escape($this->paymentTimestampLabel($receipt['received_at'] ?? null)) . '</td><td><code>' . View::escape($trace) . '</code></td><td>' . View::escape($diagnostic) . '</td><td>' . $webhookAction . '</td></tr>';
        }
        $webhookRows = $webhookRows !== '' ? $webhookRows : '<tr><td colspan="8" class="muted">暂无 Webhook 收据</td></tr>';

        $metadata = $this->paymentDisplayMetadata((string) ($payment['metadata_json'] ?? '{}'));
        $paymentCurrency = (string) ($payment['currency'] ?? $this->siteDefaultCurrency());
        $refundForm = in_array((string) $payment['status'], ['paid', 'partially_refunded'], true)
            ? '<h2>发起退款</h2><form method="post" action="/admin/payments/' . $id . '/refund">' . CsrfToken::field() .
                '<label>退款金额<input name="amount" inputmode="decimal" required placeholder="' . View::escape($this->moneyInputValue($payment['amount_minor'] ?? 0, $paymentCurrency)) . '"></label>' .
                '<p class="muted">币种：' . View::escape($paymentCurrency) . '。请填写正常金额，例如 1.00。</p>' .
                '<label>原因<input name="reason" value="admin refund"></label>' .
                '<label>幂等键<input name="idempotency_key" value="refund-' . $id . '-' . bin2hex(random_bytes(4)) . '" required></label>' .
                '<button type="submit">提交退款</button></form>'
            : '<p class="muted">当前支付状态不可退款。</p>';
        $lifecycleActions = '<h2>Provider 操作</h2><div class="actions">' .
            (in_array((string) $payment['status'], ['pending', 'authorized'], true)
                ? '<form method="post" action="/admin/payments/' . $id . '/capture" style="display:inline">' . CsrfToken::field() . '<input type="hidden" name="idempotency_key" value="capture-' . $id . '-' . bin2hex(random_bytes(4)) . '"><button type="submit">捕获支付</button></form> ' .
                    '<form method="post" action="/admin/payments/' . $id . '/cancel" style="display:inline">' . CsrfToken::field() . '<input type="hidden" name="idempotency_key" value="cancel-' . $id . '-' . bin2hex(random_bytes(4)) . '"><button type="submit">取消支付</button></form> '
                : '') .
            '<form method="post" action="/admin/payments/' . $id . '/sync" style="display:inline">' . CsrfToken::field() . '<button type="submit">同步 Provider 状态</button></form></div>';

        $body = '<h1>支付详情 #' . $id . '</h1><p><a class="button" href="/admin/payments">返回支付管理</a></p>' .
            '<table><tbody><tr><th>Subject</th><td>' . View::escape($this->paymentDisplayLedgerText((string) $payment['subject_type']) . ' / ' . $this->paymentDisplayLedgerText((string) $payment['subject_id'])) . '</td></tr>' .
            '<tr><th>Provider</th><td>' . View::escape($this->paymentDisplayLedgerText((string) $payment['provider_id'])) . '</td></tr>' .
            '<tr><th>远端单号</th><td>' . View::escape($this->paymentDisplayLedgerText((string) ($payment['remote_id'] ?? ''))) . '</td></tr>' .
            '<tr><th>状态</th><td>' . View::escape($this->paymentStatusLabel((string) $payment['status'])) . '</td></tr>' .
            '<tr><th>金额</th><td>' . View::escape($this->moneyLabel($payment['amount_minor'], (string) $payment['currency'])) . '</td></tr>' .
            '<tr><th>幂等键</th><td>' . View::escape($this->paymentDisplayLedgerText((string) $payment['idempotency_key'])) . '</td></tr></tbody></table>' .
            $this->trustedPaymentStatusHtml($trustedStatus) .
            $this->manualPaymentSummaryHtml($payment, $metadata) .
            '<h2>元数据</h2><pre>' . View::escape(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>' .
            $lifecycleActions .
            $refundForm .
            '<h2>授权记录</h2><table><thead><tr><th>ID</th><th>Subject</th><th>状态</th><th>使用次数</th><th>过期时间</th><th>操作</th></tr></thead><tbody>' . $authorizationRows . '</tbody></table>' .
            '<h2>授权事件</h2><table><thead><tr><th>ID</th><th>授权 ID</th><th>事件</th><th>元数据</th><th>时间</th></tr></thead><tbody>' . $authorizationEventRows . '</tbody></table>' .
            '<h2>会员权益</h2><table><thead><tr><th>ID</th><th>Principal</th><th>Subject</th><th>状态</th><th>过期时间</th><th>操作</th></tr></thead><tbody>' . $entitlementRows . '</tbody></table>' .
            '<h2>退款记录</h2><table><thead><tr><th>ID</th><th>状态</th><th>金额</th><th>原因</th><th>创建时间</th></tr></thead><tbody>' . $refundRows . '</tbody></table>' .
            '<h2>最近 Webhook 收据</h2><table><thead><tr><th>ID</th><th>事件</th><th>状态</th><th>处理时间</th><th>收到时间</th><th>摘要</th><th>诊断</th><th>操作</th></tr></thead><tbody>' . $webhookRows . '</tbody></table>';

        return $this->paymentAdminNoStore(Response::html(View::page('支付详情', $body)));
    }

    private function paymentAdminNoStore(Response $response): Response
    {
        return $response->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function webhookTraceContentType(string $contentType): string
    {
        if ($contentType === '' || $contentType !== trim($contentType) || strlen($contentType) > 120 || preg_match('/[\x00-\x1F\x7F]/', $contentType) === 1) {
            return '';
        }

        return $contentType;
    }

    private function webhookTracePayloadSize(mixed $payloadSize): ?int
    {
        if (!is_int($payloadSize) || $payloadSize < 0) {
            return null;
        }

        return $payloadSize;
    }

    private function webhookTraceTimestamp(string $timestamp): string
    {
        if ($timestamp === '' || $timestamp !== trim($timestamp) || strlen($timestamp) > 32 || preg_match('/^[1-9][0-9]*$/', $timestamp) !== 1) {
            return '';
        }

        return $timestamp;
    }

    private function webhookTraceHash(string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return '';
        }

        return $hash;
    }

    private function webhookDiagnostic(string $diagnostic): string
    {
        if (
            $diagnostic === ''
            || $diagnostic !== trim($diagnostic)
            || strlen($diagnostic) > 240
            || preg_match('/[\x00-\x1F\x7F]/', $diagnostic) === 1
            || preg_match('/^\s*[\[{]/', $diagnostic) === 1
            || $this->paymentDisplayValueContainsSecret($diagnostic)
        ) {
            return '';
        }

        return $diagnostic;
    }

    /** @return array<string,mixed> */
    private function paymentDisplayMetadata(string $metadataJson): array
    {
        $metadata = json_decode($metadataJson, true);
        if (!is_array($metadata)) {
            return [];
        }

        return $this->redactedPaymentDisplayMetadata($metadata);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function redactedPaymentDisplayMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $name = strtolower((string) $key);
            if ($name === 'manual_reference' && (!is_string($value) || !$this->paymentDisplayReferenceCanonical($value))) {
                continue;
            }
            if ($name === 'manual_instructions' && (!is_string($value) || $this->manualPaymentInstructions([$key => $value]) === '')) {
                continue;
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private|email|phone|address/', $name) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (str_contains($name, 'url') && is_string($value)) {
                $safe[$key] = $this->redactedPaymentDisplayUrl($value);
                continue;
            }
            if (is_string($value) && $this->paymentDisplayValueContainsSecret($value)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function paymentDisplayValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function paymentDisplayLedgerText(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($value !== trim($value) || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '[invalid]';
        }
        if ($this->paymentDisplayValueContainsSecret($value)) {
            return '[redacted]';
        }

        return $value;
    }

    private function redactedPaymentDisplayUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        if ($this->paymentDisplayValueContainsSecret((string) ($parts['path'] ?? ''))) {
            return '[redacted]';
        }
        if (!isset($parts['query'])) {
            return $this->paymentDisplayUrlWithoutFragment($parts);
        }

        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            $query[$key] = $this->redactedPaymentDisplayQueryValue((string) $key, $value);
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= (string) $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= (string) $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= (string) ($parts['path'] ?? '');
        $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $rebuilt;
    }

    private function redactedPaymentDisplayQueryValue(string $key, mixed $value): mixed
    {
        $name = strtolower($key);
        if (preg_match('/claim|token|signature|secret|authorization|auth|key|password|private/', $name) === 1) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->redactedPaymentDisplayQueryValue((string) $childKey, $childValue);
            }

            return $safe;
        }
        if (is_scalar($value) && $this->paymentDisplayValueContainsSecret((string) $value)) {
            return '[redacted]';
        }

        return $value;
    }

    /** @param array<string,mixed> $parts */
    private function paymentDisplayUrlWithoutFragment(array $parts): string
    {
        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= (string) $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= (string) $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= (string) ($parts['path'] ?? '');

        return $rebuilt;
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $metadata */
    private function manualPaymentSummaryHtml(array $payment, array $metadata): string
    {
        $manualReference = $this->manualPaymentReference($payment, $metadata);
        $manualInstructions = $this->manualPaymentInstructions($metadata);
        $isManual = (string) ($payment['provider_id'] ?? '') === 'core.manual-payment'
            || $manualReference !== ''
            || $manualInstructions !== '';
        if (!$isManual) {
            return '';
        }

        $status = (string) ($payment['status'] ?? '');
        $statusNote = in_array($status, ['pending', 'authorized'], true)
            ? '等待管理员核对到账后点击“捕获支付”。同步状态不会把人工支付自动确认为已支付。'
            : '此人工支付已离开待确认状态。';

        return '<h2>人工确认信息</h2><table><tbody>' .
            '<tr><th>支付参考号</th><td>' . View::escape($manualReference !== '' ? $manualReference : '未提供') . '</td></tr>' .
            '<tr><th>付款说明</th><td>' . nl2br(View::escape($manualInstructions !== '' ? $manualInstructions : '未提供'), false) . '</td></tr>' .
            '<tr><th>确认提示</th><td>' . View::escape($statusNote) . '</td></tr>' .
            '</tbody></table>';
    }

    /** @param array<string,mixed> $metadata */
    private function manualPaymentInstructions(array $metadata): string
    {
        $instructions = $metadata['manual_instructions'] ?? '';
        if (
            !is_string($instructions)
            || $instructions === ''
            || $instructions !== trim($instructions)
            || strlen($instructions) > 4096
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions) === 1
        ) {
            return '';
        }

        return $instructions;
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $metadata */
    private function manualPaymentReference(array $payment, array $metadata): string
    {
        $reference = (string) ($metadata['manual_reference'] ?? '');
        if ($this->paymentDisplayReferenceCanonical($reference)) {
            return $reference;
        }

        $remoteId = (string) ($payment['remote_id'] ?? '');
        return $this->paymentDisplayReferenceCanonical($remoteId) ? $remoteId : '';
    }

    private function paymentDisplayReferenceCanonical(string $reference): bool
    {
        return $reference !== ''
            && $reference === trim($reference)
            && strlen($reference) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1
            && !$this->paymentDisplayValueContainsSecret($reference);
    }

    public function paymentWebhookStatus(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        $paymentId = $this->pathId($request->path);
        $receiptId = $this->pathWebhookReceiptId($request->path);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentRepository($pdo);
            $payment = $repo->payment($paymentId);
            $receiptBefore = $repo->webhookReceiptById($receiptId);
            $rawReceiptPaymentId = is_array($receiptBefore) ? ($receiptBefore['payment_id'] ?? null) : null;
            $receiptPaymentId = ($rawReceiptPaymentId === null || $rawReceiptPaymentId === '') ? null : $this->storedPositiveInt($rawReceiptPaymentId);
            if (
                $payment === null
                || $receiptBefore === null
                || (string) ($receiptBefore['provider_id'] ?? '') !== (string) ($payment['provider_id'] ?? '')
                || (($rawReceiptPaymentId !== null && $rawReceiptPaymentId !== '') && $receiptPaymentId === null)
                || ($receiptPaymentId !== null && $receiptPaymentId !== $paymentId)
            ) {
                throw new PaymentException('Payment webhook receipt does not belong to this payment context.');
            }
            if (!in_array((string) ($receiptBefore['status'] ?? ''), ['received', 'failed'], true)) {
                throw new PaymentException('Payment webhook receipt status is not actionable.');
            }
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $receipt = $this->paymentService($pdo, $repo)->updateWebhookReceiptStatus($receiptId, $this->paymentBodyString($request, 'status'));
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.webhook_receipt.status_changed', [
                    'payment_id' => $paymentId,
                    'receipt_id' => $receiptId,
                    'status' => (string) ($receipt['status'] ?? ''),
                ]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('Webhook 状态更新失败', '<h1>Webhook 状态更新失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment webhook status update failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('Webhook 状态更新失败', '<h1>Webhook 状态更新失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/' . $paymentId));
    }

    public function paymentRefund(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        $id = $this->pathPaymentActionId($request->path, 'refund');
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $this->paymentService($pdo)->refundProviderPayment(
                    $id,
                    $this->paymentRefundAmountMinorFromRequest($request, $pdo, $id),
                    $this->paymentBodyString($request, 'reason'),
                    $this->paymentBodyString($request, 'idempotency_key'),
                );
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.refund.created', ['payment_id' => $id]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付退款失败', '<h1>支付退款失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/payments/' . $id . '">返回支付详情</a></p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment refund failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付退款失败', '<h1>支付退款失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments/' . $id . '">返回支付详情</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/' . $id));
    }

    private function beginImmediate(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            return;
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
            return;
        }
        $pdo->beginTransaction();
    }

    public function paymentCapture(Request $request): Response
    {
        return $this->paymentLifecycleAction($request, 'capture');
    }

    public function paymentCancel(Request $request): Response
    {
        return $this->paymentLifecycleAction($request, 'cancel');
    }

    public function paymentSync(Request $request): Response
    {
        return $this->paymentLifecycleAction($request, 'sync');
    }

    public function paymentAuthorizationRevoke(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        $paymentId = $this->pathId($request->path);
        $authorizationId = $this->pathAuthorizationId($request->path);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                $this->paymentService($pdo)->revokePaymentAuthorization($paymentId, $authorizationId);
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.authorization.revoked', ['payment_id' => $paymentId, 'authorization_id' => $authorizationId]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('撤销授权失败', '<h1>撤销授权失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment authorization revoke failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('撤销授权失败', '<h1>撤销授权失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/' . $paymentId));
    }

    public function paymentEntitlementRevoke(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        $paymentId = $this->pathId($request->path);
        $entitlementId = $this->pathEntitlementId($request->path);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new PaymentRepository($pdo);
            $entitlement = $repo->entitlement($entitlementId);
            $entitlementPaymentId = is_array($entitlement) ? $this->storedPositiveInt($entitlement['source_payment_id'] ?? null) : null;
            if ($entitlement === null || $entitlementPaymentId !== $paymentId) {
                throw new PaymentException('Payment entitlement was not found.');
            }

            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                (new PaymentEntitlementService($pdo, $repo))->revoke($entitlementId);
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.entitlement.revoked', ['payment_id' => $paymentId, 'entitlement_id' => $entitlementId]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('撤销权益失败', '<h1>撤销权益失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment entitlement revoke failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('撤销权益失败', '<h1>撤销权益失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments/' . $paymentId . '">返回支付详情</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/' . $paymentId));
    }

    private function paymentLifecycleAction(Request $request, string $action): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return $this->paymentAdminNoStore(Response::html(View::page('支付操作失败', '<h1>支付操作失败</h1><p class="error">支付操作必须通过 POST 请求提交。</p><p><a class="button" href="/admin/payments">返回支付管理</a></p>'), 405)
                ->withHeaders(['Allow' => 'POST']));
        }
        if (!$this->paymentCsrfValid($request)) {
            return $this->paymentAdminNoStore(Response::text('无权执行此操作。', 403));
        }

        $id = $this->pathPaymentActionId($request->path, $action);
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $service = $this->paymentService($pdo);
            $paymentAfterAction = null;
            $alreadyInTransaction = $pdo->inTransaction();
            $this->beginImmediate($pdo);
            try {
                if ($action === 'capture') {
                    $paymentAfterAction = $service->captureProviderPayment($id, $this->paymentBodyString($request, 'idempotency_key'));
                } elseif ($action === 'cancel') {
                    $paymentAfterAction = $service->cancelProviderPayment($id, $this->paymentBodyString($request, 'idempotency_key'));
                } else {
                    $paymentAfterAction = $service->syncProviderPaymentStatus($id);
                }
                (new AuditLogger($pdo))->record('admin', (int) ($guard['id'] ?? 0), 'payment.' . $action . '.requested', ['payment_id' => $id]);
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            if (is_array($paymentAfterAction)) {
                $this->fulfillCardDeliveryPayment($pdo, $paymentAfterAction, (int) ($guard['id'] ?? 0));
            }
        } catch (PaymentException $exception) {
            return $this->paymentAdminNoStore(Response::html(View::page('支付操作失败', '<h1>支付操作失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/payments/' . $id . '">返回支付详情</a></p>'), 400));
        } catch (Throwable $exception) {
            $this->logger->error('Core payment lifecycle action failed', ['source' => 'Core', 'action' => $action, 'error' => $exception->getMessage()]);
            return $this->paymentAdminNoStore(Response::html(View::page('支付操作失败', '<h1>支付操作失败</h1><p class="error">支付服务暂不可用。</p><p><a class="button" href="/admin/payments/' . $id . '">返回支付详情</a></p>'), 500));
        }

        return $this->paymentAdminNoStore(Response::redirect('/admin/payments/' . $id));
    }

    /** @param array<string,mixed> $payment */
    private function fulfillCardDeliveryPayment(PDO $pdo, array $payment, int $adminId): void
    {
        if ((string) ($payment['subject_type'] ?? '') !== 'card_delivery_order'
            || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)
            || preg_match('/^order:([1-9][0-9]{0,17})$/', (string) ($payment['subject_id'] ?? ''), $matches) !== 1
        ) {
            return;
        }
        $orderId = (int) $matches[1];
        $repo = new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', ''));
        $order = $repo->order($orderId);
        if ($order === null) {
            return;
        }
        $trusted = (new PaymentRepository($pdo))->trustedStatus('card_delivery_order', 'order:' . $orderId, (string) ($payment['currency'] ?? ''));
        if ((string) ($trusted['status'] ?? '') !== 'paid') {
            return;
        }
        $repo->markOrderPaid($orderId, (int) ($payment['id'] ?? 0));
        $delivery = (new CardDeliveryService($pdo, $this->settings))->deliverPaidOrder(
            (int) $order['product_id'],
            (string) $orderId,
            (string) ($payment['remote_id'] ?? $payment['id'] ?? ''),
            (int) ($order['quantity'] ?? 1),
        );
        $repo->markOrderFulfilled(
            $orderId,
            (string) ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'out_of_stock',
            isset($delivery['id']) ? (int) $delivery['id'] : null,
            $this->cardDeliveryDeliveryIds($delivery),
        );
        (new AuditLogger($pdo))->record('admin', $adminId, 'card_delivery.payment_fulfilled', ['order_id' => $orderId, 'payment_id' => (int) ($payment['id'] ?? 0), 'delivery_status' => (string) ($delivery['status'] ?? '')]);
    }

    /** @param array<string,mixed> $delivery @return list<int> */
    private function cardDeliveryDeliveryIds(array $delivery): array
    {
        $ids = [];
        foreach (($delivery['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['id']) && (int) $item['id'] > 0) {
                $ids[] = (int) $item['id'];
            }
        }
        if ($ids === [] && isset($delivery['id']) && (int) $delivery['id'] > 0) {
            $ids[] = (int) $delivery['id'];
        }

        return $ids;
    }

    private function paymentProviderSettings(): PaymentProviderSettingsRepository
    {
        return new PaymentProviderSettingsRepository(
            ConnectionFactory::make($this->settings),
            (string) $this->settings->get('security.encryption_key', ''),
        );
    }

    private function paymentCsrfValid(Request $request): bool
    {
        return CsrfToken::verify($this->paymentBodyInput($request, '_csrf'));
    }

    private function paymentBodyInput(Request $request, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $request->body) ? $request->body[$key] : $default;
    }

    private function paymentQueryString(Request $request, string $key, string $default = ''): string
    {
        $value = array_key_exists($key, $request->query) ? $request->query[$key] : $default;
        if (!is_string($value)) {
            throw new PaymentException('Payment filter field is invalid.');
        }

        return $value;
    }

    private function paymentBodyString(Request $request, string $key, string $default = ''): string
    {
        $value = $this->paymentBodyInput($request, $key, $default);
        if (!is_string($value)) {
            throw new PaymentException('Payment form field is invalid.');
        }

        return $value;
    }

    private function paymentProviderBodyChecked(Request $request, string $key): bool
    {
        $value = $this->paymentBodyInput($request, $key, '');

        return is_string($value) && in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    private function paymentProviderIdInput(string $providerId): string
    {
        try {
            $normalized = PaymentProviderRegistry::normalize($providerId);
        } catch (Throwable) {
            throw new PaymentException('Payment provider id is invalid.');
        }
        if ($providerId !== $normalized) {
            throw new PaymentException('Payment provider id is invalid.');
        }

        return $normalized;
    }

    private function paymentProviderStatusInput(string $status): string
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new PaymentException('Payment provider setting status is invalid.');
        }

        return $status;
    }

    private function paymentProviderStatusFromRequest(Request $request): string
    {
        if (array_key_exists('status', $request->body)) {
            return $this->paymentProviderStatusInput($this->paymentBodyString($request, 'status'));
        }
        if (array_key_exists('is_enabled', $request->body)) {
            return $this->paymentProviderTruthyBodyField($request, 'is_enabled') ? 'enabled' : 'disabled';
        }

        return $this->paymentProviderTruthyBodyField($request, 'enabled') ? 'enabled' : 'disabled';
    }

    private function paymentProviderDefaultFromRequest(Request $request): bool
    {
        foreach (['default_provider', 'is_default', 'default'] as $key) {
            if (array_key_exists($key, $request->body)) {
                return $this->paymentProviderTruthyBodyField($request, $key);
            }
        }

        return false;
    }

    private function paymentProviderTruthyBodyField(Request $request, string $key): bool
    {
        $value = $this->paymentBodyString($request, $key, '');
        return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'enabled', 'yes', 'y', '启用', '开启', '已启用', '是', '默认'], true);
    }

    /** @param list<string> $keys */
    private function paymentProviderAnyBodyKeyExists(Request $request, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request->body)) {
                return true;
            }
        }

        return false;
    }

    private function paymentProviderDisplayNameFromRequest(Request $request): string
    {
        foreach (['display_name', 'name', 'title'] as $key) {
            if (array_key_exists($key, $request->body)) {
                return $this->paymentBodyString($request, $key);
            }
        }

        throw new PaymentException('Payment provider settings form submission is incomplete.');
    }

    private function paymentService(PDO $pdo, ?PaymentRepository $repo = null): PaymentService
    {
        return new PaymentService(
            $pdo,
            $repo ?? new PaymentRepository($pdo),
            (string) $this->settings->get('security.encryption_key', ''),
        );
    }

    private function paymentRefundAmountMinor(mixed $value): int
    {
        if (!is_string($value)) {
            throw new PaymentException('Refund amount must be a positive integer minor-unit value.');
        }
        $raw = $value;
        if ($raw === '' || preg_match('/^[1-9][0-9]{0,17}$/', $raw) !== 1) {
            throw new PaymentException('Refund amount must be a positive integer minor-unit value.');
        }

        return (int) $raw;
    }

    private function paymentRefundAmountMinorFromRequest(Request $request, PDO $pdo, int $paymentId): int
    {
        $payment = (new PaymentRepository($pdo))->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment was not found.');
        }
        $currency = $this->normalizeAdminCurrency((string) ($payment['currency'] ?? $this->siteDefaultCurrency()));
        $amount = $request->input('amount', null);
        if ($amount !== null) {
            try {
                $minor = Money::toMinor((string) $amount, $currency);
            } catch (\InvalidArgumentException $exception) {
                throw new PaymentException('退款金额格式无效：' . $exception->getMessage());
            }
            if ($minor <= 0) {
                throw new PaymentException('退款金额必须大于 0。');
            }

            return $minor;
        }

        return $this->paymentRefundAmountMinor($this->paymentBodyInput($request, 'amount_minor', ''));
    }

    /** @return array<string,string> */
    private function paymentProviderSecrets(string $text): array
    {
        $secrets = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($line !== trim($line) || !str_contains($line, '=')) {
                throw new PaymentException('Payment provider secret text is invalid.');
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            if ($key === '' || $value === '' || $key !== trim($key) || $value !== trim($value)) {
                throw new PaymentException('Payment provider secret text is invalid.');
            }
            $secrets[$key] = $value;
        }

        return $secrets;
    }

    /** @return array<string,mixed> */
    private function paymentProviderPublicConfigFromRequest(Request $request, string $providerId): array
    {
        $public = $this->parsePaymentProviderPublicConfigJson($this->paymentProviderPublicJsonFromRequest($request));
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            foreach (['manual_instructions', 'payment_instructions', 'instructions'] as $instructionsKey) {
                if (!array_key_exists($instructionsKey, $request->body)) {
                    continue;
                }
                $instructions = trim($this->paymentBodyString($request, $instructionsKey, ''));
                if ($instructions !== '') {
                    $public['instructions'] = $instructions;
                } else {
                    unset($public['instructions']);
                }
                break;
            }
        } elseif ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            if (array_key_exists('hosted_checkout_url', $request->body)) {
                $checkoutUrl = trim($this->paymentBodyString($request, 'hosted_checkout_url', ''));
                unset($public['checkout_base_url']);
                if ($checkoutUrl !== '') {
                    $public['checkout_url'] = $checkoutUrl;
                } else {
                    unset($public['checkout_url']);
                }
            }
            if (array_key_exists('hosted_return_url_base', $request->body)) {
                $returnUrlBase = trim($this->paymentBodyString($request, 'hosted_return_url_base', ''));
                if ($returnUrlBase !== '') {
                    $public['return_url_base'] = $returnUrlBase;
                } else {
                    unset($public['return_url_base']);
                }
            }
        } elseif ($providerId === 'official.payment.stripe') {
            foreach ([
                'stripe_publishable_key' => 'publishable_key',
                'stripe_mode' => 'mode',
                'stripe_currency' => 'currency',
                'stripe_country' => 'country',
                'stripe_return_url_base' => 'return_url_base',
                'stripe_transport' => 'transport',
            ] as $bodyKey => $publicKey) {
                if (!array_key_exists($bodyKey, $request->body)) {
                    continue;
                }
                $value = trim($this->paymentBodyString($request, $bodyKey, ''));
                if ($publicKey === 'publishable_key') {
                    $value = $this->paymentProviderCompactKeyInput($value);
                }
                if ($publicKey === 'currency' || $publicKey === 'country') {
                    $value = strtoupper($value);
                }
                if ($value !== '') {
                    $public[$publicKey] = $value;
                } else {
                    unset($public[$publicKey]);
                }
            }
        } elseif ($providerId === 'official.payment.paypal') {
            foreach ([
                'paypal_environment' => 'environment',
                'paypal_currency' => 'currency',
                'paypal_return_url_base' => 'return_url_base',
                'paypal_webhook_id' => 'webhook_id',
                'paypal_transport' => 'transport',
            ] as $bodyKey => $publicKey) {
                if (!array_key_exists($bodyKey, $request->body)) {
                    continue;
                }
                $value = trim($this->paymentBodyString($request, $bodyKey, ''));
                if ($publicKey === 'currency') {
                    $value = strtoupper($value);
                }
                if ($value !== '') {
                    $public[$publicKey] = $value;
                } else {
                    unset($public[$publicKey]);
                }
            }
        } elseif ($providerId === 'official.payment.wechatpay') {
            foreach ([
                'wechatpay_app_id' => 'app_id',
                'wechatpay_mch_id' => 'mch_id',
                'wechatpay_merchant_serial' => 'merchant_serial',
                'wechatpay_platform_serial' => 'platform_serial',
                'wechatpay_platform_public_key' => 'platform_public_key',
                'wechatpay_payment_mode' => 'payment_mode',
                'wechatpay_currency' => 'currency',
                'wechatpay_notify_url' => 'notify_url',
                'wechatpay_transport' => 'transport',
            ] as $bodyKey => $publicKey) {
                if (!array_key_exists($bodyKey, $request->body)) {
                    continue;
                }
                $value = trim($this->paymentBodyString($request, $bodyKey, ''));
                if ($publicKey === 'currency') {
                    $value = strtoupper($value);
                }
                if ($value !== '') {
                    $public[$publicKey] = $value;
                } else {
                    unset($public[$publicKey]);
                }
            }
        } elseif ($providerId === 'official.payment.alipay') {
            foreach ([
                'alipay_app_id' => 'app_id',
                'alipay_seller_id' => 'seller_id',
                'alipay_public_key' => 'alipay_public_key',
                'alipay_gateway_mode' => 'gateway_mode',
                'alipay_return_url' => 'return_url',
                'alipay_notify_url' => 'notify_url',
                'alipay_sign_type' => 'sign_type',
                'alipay_charset' => 'charset',
                'alipay_currency' => 'currency',
                'alipay_transport' => 'transport',
            ] as $bodyKey => $publicKey) {
                if (!array_key_exists($bodyKey, $request->body)) {
                    continue;
                }
                $value = trim($this->paymentBodyString($request, $bodyKey, ''));
                if ($publicKey === 'currency' || $publicKey === 'sign_type') {
                    $value = strtoupper($value);
                }
                if ($value !== '') {
                    $public[$publicKey] = $value;
                } else {
                    unset($public[$publicKey]);
                }
            }
        }
        if ($this->paymentProviderDefaultFromRequest($request)) {
            $public['default_provider'] = true;
        } else {
            unset($public['default_provider']);
        }
        if (isset($public['currency']) && is_string($public['currency'])) {
            try {
                $public['currency'] = $this->normalizeAdminCurrency($public['currency']);
            } catch (\InvalidArgumentException $exception) {
                throw new PaymentException($exception->getMessage());
            }
            $provider = PaymentProviderRegistry::get($providerId);
            if ($provider instanceof \Cms\Core\Payment\PaymentProviderCurrencySupportInterface) {
                $supported = array_map(static fn (string $code): string => strtoupper($code), $provider->supportedCurrencies());
                if (!in_array($public['currency'], $supported, true)) {
                    throw new PaymentException('该支付方式不支持所选币种：' . $public['currency']);
                }
            }
        }

        return $public;
    }

    private function paymentProviderPublicJsonFromRequest(Request $request): string
    {
        foreach (['public_config_json', 'config_json', 'public_config', 'settings_json', 'public_settings_json'] as $key) {
            if (array_key_exists($key, $request->body)) {
                return $this->paymentBodyString($request, $key, '{}');
            }
        }

        throw new PaymentException('Payment provider settings form submission is incomplete.');
    }

    /** @return array<string,string> */
    private function paymentProviderSecretsFromRequest(Request $request, string $providerId): array
    {
        $secrets = $this->paymentProviderSecrets($this->paymentBodyString($request, 'secrets_text', ''));
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            $checkoutSecret = trim($this->paymentBodyString($request, 'hosted_checkout_secret', ''));
            $webhookSecret = trim($this->paymentBodyString($request, 'hosted_webhook_secret', ''));
            if ($checkoutSecret !== '') {
                $secrets['checkout_secret'] = $checkoutSecret;
            }
            if ($webhookSecret !== '') {
                $secrets['webhook_secret'] = $webhookSecret;
            }
        } elseif ($providerId === 'official.payment.stripe') {
            $mode = $this->paymentProviderStripeModeFromRequest($request);
            $secretKey = $this->paymentProviderCompactKeyInput($this->paymentBodyString($request, 'stripe_secret_key', ''));
            $webhookSecret = $this->paymentProviderCompactKeyInput($this->paymentBodyString($request, 'stripe_webhook_secret', ''));
            $clearSecretKey = $this->paymentProviderBodyChecked($request, 'stripe_clear_secret_key');
            $clearWebhookSecret = $this->paymentProviderBodyChecked($request, 'stripe_clear_webhook_secret');
            if ($clearSecretKey && $secretKey !== '') {
                throw new PaymentException('Stripe Secret Key 不能同时填写新值并勾选清除。');
            }
            if ($clearWebhookSecret && $webhookSecret !== '') {
                throw new PaymentException('Stripe Webhook Signing Secret 不能同时填写新值并勾选清除。');
            }
            if ($clearSecretKey) {
                $secrets['secret_key'] = PaymentProviderSettingsRepository::CLEAR_SECRET_VALUE;
            }
            if ($clearWebhookSecret) {
                $secrets['webhook_secret'] = PaymentProviderSettingsRepository::CLEAR_SECRET_VALUE;
            }
            if ($secretKey !== '') {
                $this->assertPaymentProviderStripeSecretKey($secretKey, $mode);
                $secrets['secret_key'] = $secretKey;
            }
            if ($webhookSecret !== '') {
                $this->assertPaymentProviderStripeWebhookSecret($webhookSecret);
                $secrets['webhook_secret'] = $webhookSecret;
            }
        } elseif ($providerId === 'official.payment.paypal') {
            $clientId = trim($this->paymentBodyString($request, 'paypal_client_id', ''));
            $clientSecret = trim($this->paymentBodyString($request, 'paypal_client_secret', ''));
            if ($clientId !== '') {
                $secrets['client_id'] = $clientId;
            }
            if ($clientSecret !== '') {
                $secrets['client_secret'] = $clientSecret;
            }
        } elseif ($providerId === 'official.payment.wechatpay') {
            $apiV3Key = trim($this->paymentBodyString($request, 'wechatpay_api_v3_key', ''));
            $merchantPrivateKey = trim($this->paymentBodyString($request, 'wechatpay_merchant_private_key', ''));
            if ($apiV3Key !== '') {
                $secrets['api_v3_key'] = $apiV3Key;
            }
            if ($merchantPrivateKey !== '') {
                $secrets['merchant_private_key'] = $merchantPrivateKey;
            }
        } elseif ($providerId === 'official.payment.alipay') {
            $privateKey = trim($this->paymentBodyString($request, 'alipay_app_private_key', ''));
            if ($privateKey !== '') {
                $secrets['app_private_key'] = $privateKey;
            }
        }

        return $secrets;
    }

    /** @return array<string,string> */
    private function paymentProviderSecretsPreview(Request $request, string $providerId): array
    {
        $repo = new PaymentProviderSettingsRepository(
            ConnectionFactory::make($this->settings),
            (string) $this->settings->get('security.encryption_key', ''),
        );
        try {
            $masked = $repo->maskedSecrets($providerId);
        } catch (PaymentException) {
            $masked = [];
        }
        foreach ($this->paymentProviderSecretsFromRequest($request, $providerId) as $key => $value) {
            if ($value === PaymentProviderSettingsRepository::CLEAR_SECRET_VALUE) {
                unset($masked[$key]);
                continue;
            }
            if ($value !== '') {
                $masked[$key] = '[configured]';
            }
        }

        return $masked;
    }

    private function paymentProviderCompactKeyInput(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }

    private function paymentProviderStripeModeFromRequest(Request $request): string
    {
        $mode = $this->paymentProviderCompactKeyInput($this->paymentBodyString($request, 'stripe_mode', 'test'));
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new PaymentException('Stripe Test / Live Mode 不正确。');
        }

        return $mode;
    }

    private function assertPaymentProviderStripeSecretKey(string $secretKey, string $mode): void
    {
        if (!str_starts_with($secretKey, 'sk_') || strlen($secretKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $secretKey) === 1) {
            throw new PaymentException('Stripe Secret Key 格式不正确。');
        }
        if ($mode === 'test' && !str_starts_with($secretKey, 'sk_test_')) {
            throw new PaymentException('Stripe Test Mode 只能使用 sk_test_ 开头的 Secret Key。');
        }
        if ($mode === 'live' && !str_starts_with($secretKey, 'sk_live_')) {
            throw new PaymentException('Stripe Live Mode 只能使用 sk_live_ 开头的 Secret Key。');
        }
    }

    private function assertPaymentProviderStripeWebhookSecret(string $webhookSecret): void
    {
        if (!str_starts_with($webhookSecret, 'whsec_') || strlen($webhookSecret) < 16 || strlen($webhookSecret) > 191 || preg_match('/[\x00-\x1F\x7F]/', $webhookSecret) === 1) {
            throw new PaymentException('Stripe Webhook Signing Secret 必须是 whsec_ 开头的完整密钥。');
        }
    }

    public function updateIndex(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = $this->root();
        $history = glob($root . '/storage/updates/history/*.json') ?: [];
        sort($history);
        $items = '';
        foreach ($history as $file) {
            $items .= '<li>' . View::escape(basename($file)) . '</li>';
        }
        $items = $items !== '' ? '<ul>' . $items . '</ul>' : '<p class="muted">暂无更新记录</p>';

        $serverUrl = (string) $this->settings->get('updates.server_url', '');
        $currentVersion = $this->currentCoreVersion();
        $siteId = (string) $this->settings->get('site.id', 'local-site');
        $preparedPath = trim((string) ($_GET['prepared_package'] ?? ''));
        if ($preparedPath !== '' && (!str_ends_with($preparedPath, '.zip') || str_contains($preparedPath, "\0"))) {
            $preparedPath = '';
        }
        $preparedNotice = $preparedPath !== ''
            ? '<p class="success">更新包已下载并准备完成，下面的本地路径已自动填入。</p>'
            : '';
        $body = '<h1>系统更新</h1><p class="muted">更新包会先验证签名、SHA-256、环境版本，并生成准备计划。执行更新会进入维护模式并创建恢复点。</p>' .
            '<section class="admin-card"><h2>官方更新中心</h2><p class="muted">用于连接 updates.daiyingcms.com 检查 Daiying CMS Core 是否有新版本。发现更新后可一键下载并准备到服务器本地，不会自动执行安装。</p>' .
            '<form method="post" action="/admin/update/check">' . CsrfToken::field() .
            '<label>更新服务器地址<input name="server_url" value="' . View::escape($serverUrl) . '" placeholder="https://updates.daiyingcms.com"></label>' .
            '<label>产品 ID<input name="product_id" value="daiying.cms" readonly></label>' .
            '<label>当前版本<input name="current_version" value="' . View::escape($currentVersion) . '" readonly></label>' .
            '<label>发布通道<select name="channel"><option value="stable">正式版 stable</option><option value="rc">候选版 rc</option><option value="beta">测试版 beta</option><option value="dev">开发版 dev</option></select></label>' .
            '<input type="hidden" name="site_id" value="' . View::escape($siteId) . '">' .
            '<button type="submit">检查官方更新</button></form></section>' .
            '<form method="post" action="/admin/update/verify">' . CsrfToken::field() .
            $preparedNotice .
            '<label>服务器上的更新包路径<input name="package_path" value="' . View::escape($preparedPath) . '" placeholder="/path/to/update.zip"></label>' .
            '<button type="submit">验证/演练更新包</button></form>' .
            '<form method="post" action="/admin/update/execute">' . CsrfToken::field() .
            '<label>服务器上的更新包路径<input name="package_path" value="' . View::escape($preparedPath) . '" placeholder="/path/to/update.zip"></label>' .
            '<label>二次确认<input name="confirmation" placeholder="UPDATE CORE"></label>' .
            '<button type="submit">执行系统更新</button></form><h2>更新记录</h2>' . $items;

        return Response::html(View::page('系统更新', $body));
    }

    public function updateCheck(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $serverUrl = trim((string) $request->input('server_url', $this->settings->get('updates.server_url', '')));
        $productId = trim((string) $request->input('product_id', 'daiying.cms'));
        $currentVersion = $this->currentCoreVersion();
        $channel = trim((string) $request->input('channel', 'stable'));
        if (!in_array($channel, ['stable', 'rc', 'beta', 'dev'], true)) {
            $channel = 'stable';
        }

        try {
            $payload = (new UpdateServerClient($serverUrl))->latestProduct(
                $productId,
                $currentVersion,
                $channel,
                $currentVersion,
                (string) $request->input('site_id', $this->settings->get('site.id', 'local-site')),
                ['signed_update', 'restore_point', 'rollback', 'health_check', 'migration_chain', 'direct_cross_version_update']
            );
            $compatible = (array) ($payload['compatibility'] ?? []);
            $messages = (array) ($compatible['messages'] ?? []);
            $status = ($payload['update_available'] ?? false) ? '发现可用更新' : '当前没有可用更新';
            $details = '<dl class="admin-definition-list">' .
                '<dt>检查结果</dt><dd>' . View::escape($status) . '</dd>' .
                '<dt>产品 ID</dt><dd><code>' . View::escape((string) ($payload['product_id'] ?? $productId)) . '</code></dd>' .
                '<dt>当前版本</dt><dd>' . View::escape($currentVersion) . '</dd>' .
                '<dt>目标版本</dt><dd>' . View::escape((string) ($payload['latest_version'] ?? $payload['version'] ?? '-')) . '</dd>' .
                '<dt>发布通道</dt><dd>' . View::escape((string) ($payload['channel'] ?? $channel)) . '</dd>' .
                '<dt>直接升级</dt><dd>' . View::escape(($payload['direct_upgrade_supported'] ?? true) ? '可直接升级到最新版' : '当前版本过旧，需要中间基线') . '</dd>' .
                '<dt>预计迁移</dt><dd>' . (int) ($payload['migration_count'] ?? count((array) ($payload['required_migrations'] ?? []))) . ' 个</dd>' .
                '<dt>包 SHA-256</dt><dd><code>' . View::escape((string) ($payload['package_sha256'] ?? '-')) . '</code></dd>' .
                '<dt>包地址</dt><dd><code>' . View::escape((string) ($payload['package_url'] ?? '-')) . '</code></dd>' .
                '<dt>兼容状态</dt><dd>' . View::escape(($compatible['compatible'] ?? true) ? '兼容' : '不兼容') . '</dd>' .
                '</dl>';
            if ($messages !== []) {
                $details .= '<h2>兼容提示</h2><ul>';
                foreach ($messages as $message) {
                    $details .= '<li>' . View::escape((string) $message) . '</li>';
                }
                $details .= '</ul>';
            }
            if (($payload['update_available'] ?? false) && ($compatible['compatible'] ?? true)) {
                $details .= '<form method="post" action="/admin/update/prepare">' . CsrfToken::field() .
                    '<input type="hidden" name="server_url" value="' . View::escape($serverUrl) . '">' .
                    '<input type="hidden" name="product_id" value="' . View::escape($productId) . '">' .
                    '<input type="hidden" name="channel" value="' . View::escape($channel) . '">' .
                    '<input type="hidden" name="site_id" value="' . View::escape((string) $request->input('site_id', $this->settings->get('site.id', 'local-site'))) . '">' .
                    '<button type="submit">下载并准备更新包</button></form>';
            }
            $details .= '<p><a class="button" href="/admin/update">返回系统更新</a></p>';
            $this->auditCoreUpdateAction($guard, 'core.update_checked', $serverUrl, [
                'product_id' => $productId,
                'channel' => $channel,
                'update_available' => (bool) ($payload['update_available'] ?? false),
                'target_version' => (string) ($payload['version'] ?? ''),
            ]);

            return Response::html(View::page('系统更新', '<h1>官方更新检查</h1>' . $details));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_check_failed', $serverUrl, [
                'product_id' => $productId,
                'channel' => $channel,
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Official update check failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('系统更新', '<h1>官方更新检查失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/update">返回系统更新</a></p>'), 400);
        }
    }

    public function updatePrepare(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $serverUrl = trim((string) $request->input('server_url', $this->settings->get('updates.server_url', '')));
        $productId = trim((string) $request->input('product_id', 'daiying.cms'));
        $channel = trim((string) $request->input('channel', 'stable'));
        if (!in_array($channel, ['stable', 'rc', 'beta', 'dev'], true)) {
            $channel = 'stable';
        }
        $currentVersion = $this->currentCoreVersion();

        try {
            $client = new UpdateServerClient($serverUrl);
            $payload = $client->latestProduct(
                $productId,
                $currentVersion,
                $channel,
                $currentVersion,
                (string) $request->input('site_id', $this->settings->get('site.id', 'local-site')),
                ['signed_update', 'restore_point', 'rollback', 'health_check', 'migration_chain', 'direct_cross_version_update']
            );
            if (!($payload['update_available'] ?? false)) {
                throw new \RuntimeException('当前没有可用更新。');
            }
            $compatible = (array) ($payload['compatibility'] ?? []);
            if (($compatible['compatible'] ?? true) !== true) {
                throw new \RuntimeException('当前站点与该更新包不兼容，不能下载准备。');
            }
            $download = $client->downloadPackage($payload, $this->root() . '/storage/updates/incoming');
            $packagePath = (string) $download['path'];

            $this->auditCoreUpdateAction($guard, 'core.update_prepared', (string) ($payload['package_url'] ?? ''), [
                'product_id' => $productId,
                'channel' => $channel,
                'target_version' => (string) ($payload['version'] ?? ''),
                'sha256' => (string) $download['sha256'],
                'size_bytes' => (int) $download['size_bytes'],
                'prepared_package_name' => basename($packagePath),
            ]);

            $body = '<h1>更新包已准备</h1>' .
                '<p class="success">更新包已经下载到当前 CMS 服务器，并完成 SHA-256 校验。</p>' .
                '<dl class="admin-definition-list">' .
                '<dt>目标版本</dt><dd>' . View::escape((string) ($payload['version'] ?? '-')) . '</dd>' .
                '<dt>发布通道</dt><dd>' . View::escape((string) ($payload['channel'] ?? $channel)) . '</dd>' .
                '<dt>本地路径</dt><dd><code>' . View::escape($packagePath) . '</code></dd>' .
                '<dt>SHA-256</dt><dd><code>' . View::escape((string) $download['sha256']) . '</code></dd>' .
                '<dt>大小</dt><dd>' . View::escape((string) $download['size_bytes']) . ' bytes</dd>' .
                '</dl>' .
                '<form method="post" action="/admin/update/verify">' . CsrfToken::field() .
                '<input type="hidden" name="package_path" value="' . View::escape($packagePath) . '">' .
                '<button type="submit">立即验证/演练更新包</button></form>' .
                '<form method="post" action="/admin/update/execute">' . CsrfToken::field() .
                '<input type="hidden" name="package_path" value="' . View::escape($packagePath) . '">' .
                '<label>二次确认<input name="confirmation" placeholder="UPDATE CORE"></label>' .
                '<button type="submit">执行系统更新</button></form>' .
                '<p><a class="button admin-button-secondary" href="/admin/update?prepared_package=' . rawurlencode($packagePath) . '">返回系统更新</a></p>';

            return Response::html(View::page('系统更新', $body));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_prepare_failed', $serverUrl, [
                'product_id' => $productId,
                'channel' => $channel,
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Official update prepare failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('系统更新', '<h1>更新包准备失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/update">返回系统更新</a></p>'), 400);
        }
    }

    public function updateVerify(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $root = $this->root();
            $service = new UpdateService(
                $root,
                $this->currentCoreVersion(),
                new SignatureVerifier((string) $this->settings->get('updates.public_key', '')),
            );
            $plan = $service->dryRun((string) $request->input('package_path', ''));
            $this->auditCoreUpdateAction($guard, 'core.update_verified', (string) $request->input('package_path', ''), [
                'status' => 'dry_run_passed',
                'release_id' => (string) ($plan['release_id'] ?? ''),
                'target_version' => (string) ($plan['target_version'] ?? $plan['to_version'] ?? ''),
                'file_count' => (int) ($plan['file_count'] ?? 0),
                'migration_count' => (int) ($plan['migration_count'] ?? 0),
            ]);
            $body = '<h1>更新包验证通过</h1>' . $this->updatePlanSummaryHtml($plan) .
                '<details open><summary>完整验证计划 JSON</summary><pre>' . View::escape(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
            return Response::html(View::page('系统更新', $body));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_verify_failed', (string) $request->input('package_path', ''), [
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Update verification failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('系统更新', '<h1>更新包验证失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
    }

    public function updateExecute(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $root = $this->root();
            $service = new UpdateService(
                $root,
                $this->currentCoreVersion(),
                new SignatureVerifier((string) $this->settings->get('updates.public_key', '')),
            );
            $result = $service->execute((string) $request->input('package_path', ''), (int) ($guard['id'] ?? 0), (string) $request->input('confirmation', ''));
            $this->auditCoreUpdateAction($guard, 'core.update_execute_completed', (string) $request->input('package_path', ''), [
                'status' => (string) ($result['status'] ?? ''),
                'operation_id' => (string) ($result['operation_id'] ?? ''),
                'release_id' => (string) ($result['release_id'] ?? ''),
            ]);
            $body = '<h1>系统更新完成</h1><pre>' . View::escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            return Response::html(View::page('系统更新', $body));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_execute_failed', (string) $request->input('package_path', ''), [
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Update execution failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('系统更新', '<h1>系统更新失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
    }

    /** @param array<string,mixed> $guard @param array<string,mixed> $context */
    private function auditCoreUpdateAction(array $guard, string $action, string $packagePath, array $context = []): void
    {
        try {
            $safeContext = ['package_name' => basename($packagePath)] + $context;
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), $action, $safeContext);
        } catch (Throwable $exception) {
            $this->logger->error('Core update audit write failed', ['source' => 'Core', 'action' => $action, 'error' => $exception->getMessage()]);
        }
    }

    private function safeAdminErrorSummary(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return 'error';
        }
        $message = basename($message) === $message ? $message : preg_replace('#(/[^\s:]+)+#', '[path]', $message);
        $message = is_string($message) ? $message : 'error';

        return strlen($message) > 240 ? substr($message, 0, 240) : $message;
    }

    public function marketPlugins(): Response
    {
        return $this->marketIndex(['plugin', 'payment_provider'], '插件市场');
    }

    public function marketThemes(): Response
    {
        return $this->marketIndex(['theme'], '主题市场');
    }

    public function marketDeveloperSubmit(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html(View::page('开发者提交', $this->developerSubmissionForm('', [], (string) $request->input('previous_submission_id', ''))));
    }

    public function marketDeveloperSubmitPost(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        $data = [
            'package_type' => (string) $request->input('package_type', 'plugin'),
            'name' => trim((string) $request->input('name', '')),
            'product_id' => trim((string) $request->input('product_id', '')),
            'version' => trim((string) $request->input('version', '')),
            'developer_name' => trim((string) $request->input('developer_name', '')),
            'developer_email' => trim((string) $request->input('developer_email', '')),
            'developer_url' => trim((string) $request->input('developer_url', '')),
            'purchase_url' => trim((string) $request->input('purchase_url', '')),
            'support_url' => trim((string) $request->input('support_url', '')),
            'description' => trim((string) $request->input('description', '')),
            'previous_submission_id' => trim((string) $request->input('previous_submission_id', '')),
        ];

        try {
            $file = $_FILES['package'] ?? null;
            if (!is_array($file)) {
                throw new \RuntimeException('请选择要提交审核的 ZIP 安装包。');
            }
            $data = $this->applyDeveloperSubmissionManifestHints($data, $file);
            $this->validateDeveloperSubmission($data, $file);
            $response = $this->reviewSubmissionClient()->submit([
                'product_id' => $data['product_id'],
                'package_type' => $data['package_type'],
                'version' => $data['version'],
                'developer_name' => $data['developer_name'],
                'developer_email' => $data['developer_email'],
                'developer_url' => $data['developer_url'],
                'purchase_url' => $data['purchase_url'],
                'support_url' => $data['support_url'],
                'description' => $data['description'],
                'previous_submission_id' => $data['previous_submission_id'],
            ], $file);
            $submissionId = (string) ($response['submission_id'] ?? '');
            if ($submissionId === '') {
                throw new \RuntimeException('官方审核服务没有返回提交编号。');
            }
            $data['submission_id'] = $submissionId;
            $data['status'] = (string) ($response['status'] ?? 'MANUAL_REVIEW');
            $this->saveDeveloperSubmission($data, $response);

            return Response::redirect('/admin/market/submissions/' . rawurlencode($submissionId));
        } catch (Throwable $exception) {
            return Response::html(View::page('开发者提交', $this->developerSubmissionForm($exception->getMessage(), $data, $data['previous_submission_id'])), 422);
        }
    }

    public function marketDeveloperSubmissions(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $rows = '';
        try {
            $stmt = ConnectionFactory::make($this->settings)->query('SELECT * FROM cms_review_submissions ORDER BY id DESC LIMIT 100');
            foreach ($stmt->fetchAll() as $row) {
                $rows .= '<tr><td><code>' . View::escape((string) $row['submission_id']) . '</code></td><td>' . View::escape((string) $row['product_id']) . '</td><td>' . View::escape($this->marketTypeLabel((string) $row['package_type'])) . '</td><td>' . View::escape((string) $row['version']) . '</td><td>' . View::escape($this->reviewStatusLabel((string) $row['status'])) . '</td><td>' . View::escape((string) $row['created_at']) . '</td><td>' . View::escape((string) $row['updated_at']) . '</td><td><a class="button admin-button-secondary" href="/admin/market/submissions/' . rawurlencode((string) $row['submission_id']) . '">查看结果</a></td></tr>';
            }
        } catch (Throwable $exception) {
            $rows = '<tr><td colspan="8" class="error">提交记录不可用：' . View::escape($exception->getMessage()) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="8" class="muted">暂无提交记录。</td></tr>';
        $body = '<h1>我的提交</h1><p class="muted">这里显示本 CMS 发起的插件/主题官方审核提交。</p><p><a class="button" href="/admin/market/developer-submit">提交官方审核</a></p><table><thead><tr><th>提交编号</th><th>产品</th><th>类型</th><th>版本</th><th>状态</th><th>提交时间</th><th>最后更新</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('我的提交', $body));
    }

    public function marketDeveloperSubmissionDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $submissionId = (string) ($request->input('id', '') ?: basename($request->path));
        try {
            $report = $this->reviewSubmissionClient()->report($submissionId);
            $this->updateDeveloperSubmissionReport($submissionId, $report);
            $row = $this->developerSubmissionRow($submissionId);
            $status = (string) ($report['status'] ?? ($row['status'] ?? ''));
            $static = is_array($report['static_report'] ?? null) ? $report['static_report'] : [];
            $ai = is_array($report['ai_report'] ?? null) ? $report['ai_report'] : [];
            $issues = $this->reviewIssuesHtml($static, $ai);
            $previous = (string) ($row['previous_submission_id'] ?? '');
            $body = '<h1>审核结果</h1><p><a class="button admin-button-secondary" href="/admin/market/submissions">返回我的提交</a></p><table><tbody>' .
                '<tr><th>提交编号</th><td><code>' . View::escape($submissionId) . '</code></td></tr>' .
                '<tr><th>产品</th><td>' . View::escape((string) ($row['product_id'] ?? $report['product_id'] ?? '')) . '</td></tr>' .
                '<tr><th>类型</th><td>' . View::escape($this->marketTypeLabel((string) ($row['package_type'] ?? $report['package_type'] ?? ''))) . '</td></tr>' .
                '<tr><th>版本</th><td>' . View::escape((string) ($row['version'] ?? $report['version'] ?? '')) . '</td></tr>' .
                '<tr><th>最终状态</th><td>' . View::escape($this->reviewStatusLabel($status)) . '</td></tr>' .
                '<tr><th>静态扫描</th><td>' . View::escape((string) ($static['status'] ?? 'unknown')) . '</td></tr>' .
                '<tr><th>AI 审核</th><td>' . View::escape((string) ($ai['status'] ?? 'pending')) . '</td></tr>' .
                '<tr><th>风险等级</th><td>' . View::escape((string) ($ai['risk_level'] ?? $static['risk_level'] ?? 'unknown')) . '</td></tr>' .
                '<tr><th>上次提交</th><td>' . View::escape($previous === '' ? '-' : $previous) . '</td></tr>' .
                '</tbody></table><h2>问题与建议</h2>' . $issues .
                '<h2>重新提交</h2><p><a class="button" href="/admin/market/developer-submit?previous_submission_id=' . rawurlencode($submissionId) . '">修改后重新提交</a></p>' .
                '<h2>原始审核数据</h2><pre>' . View::escape(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</pre>';

            return Response::html(View::page('审核结果', $body));
        } catch (Throwable $exception) {
            return Response::html(View::page('审核结果', '<h1>审核结果不可用</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/market/submissions">返回我的提交</a></p>'), 502);
        }
    }

    public function marketDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $marketId = (string) $request->input('market_id', '');
        $version = (string) $request->input('version', '');
        try {
            $detail = $this->marketClient()->detail($marketId, $version);
            $versionData = is_array($detail['version'] ?? null) ? $detail['version'] : $detail;
            $isTheme = (string) ($versionData['type'] ?? $versionData['extension_type'] ?? '') === 'theme';
            $detailTitle = $isTheme ? '主题详情' : '插件详情';
            $backUrl = $isTheme ? '/admin/market/themes' : '/admin/market/plugins';
            $backLabel = $isTheme ? '返回主题市场' : '返回插件市场';
            $idLabel = $isTheme ? 'Theme ID' : 'Plugin ID';
            $body = '<h1>' . $detailTitle . '</h1><p><a class="button admin-button-secondary" href="' . $backUrl . '">' . $backLabel . '</a></p>' .
                '<table><tbody>' .
                '<tr><th>Market ID</th><td><code>' . View::escape((string) ($versionData['market_id'] ?? $marketId)) . '</code></td></tr>' .
                '<tr><th>' . $idLabel . '</th><td><code>' . View::escape((string) ($versionData['extension_id'] ?? $versionData['plugin_id'] ?? $versionData['theme_id'] ?? '')) . '</code></td></tr>' .
                '<tr><th>名称</th><td>' . View::escape((string) ($versionData['name'] ?? '')) . '</td></tr>' .
                '<tr><th>类型</th><td>' . View::escape($this->marketTypeLabel((string) ($versionData['type'] ?? $versionData['extension_type'] ?? ''))) . '</td></tr>' .
                '<tr><th>版本</th><td>' . View::escape((string) ($versionData['version'] ?? '')) . '</td></tr>' .
                '<tr><th>渠道</th><td>' . View::escape((string) ($versionData['channel'] ?? $this->marketChannel())) . '</td></tr>' .
                '<tr><th>发布状态</th><td>' . View::escape((string) ($versionData['status'] ?? $versionData['review_status'] ?? '')) . '</td></tr>' .
                '<tr><th>SHA256</th><td><code>' . View::escape((string) ($versionData['package_sha256'] ?? '')) . '</code></td></tr>' .
                '<tr><th>兼容性</th><td>' . View::escape($this->marketCompatibilityText($versionData)) . '</td></tr>' .
                '<tr><th>开发者</th><td>' . View::escape((string) ($versionData['developer_name'] ?? '')) . '</td></tr>' .
                '<tr><th>价格</th><td>' . View::escape((string) ($versionData['pricing_text'] ?? $versionData['price_label'] ?? 'Free')) . '</td></tr>' .
                '<tr><th>授权</th><td>' . ((bool) ($versionData['license_required'] ?? false) ? '需要授权' : '免费') . '</td></tr>' .
                '</tbody></table>' . $this->marketPurchasePanel($versionData) .
                '<h2>原始数据</h2><pre>' . View::escape(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</pre>';

            return Response::html(View::page($detailTitle, $body));
        } catch (Throwable $exception) {
            return Response::html(View::page('插件详情', '<h1>插件详情不可用</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/market/plugins">返回插件市场</a></p>'), 404);
        }
    }

    public function marketLicenseActivate(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $productId = trim((string) $request->input('product_id', ''));
            $licenseKey = trim((string) $request->input('license_key', ''));
            if ($productId === '' || $licenseKey === '') {
                throw new \Cms\Core\Market\MarketException('需要产品 ID 和授权码。');
            }
            $client = $this->marketClient();
            if (!method_exists($client, 'activateLicense')) {
                throw new \Cms\Core\Market\MarketException('当前市场客户端不支持授权激活。');
            }
            $payload = $client->activateLicense(
                $productId,
                $licenseKey,
                (string) $this->settings->get('site.id', 'local-site'),
                (string) $this->settings->get('site.url', '')
            );
            (new CommercialLicenseStore(ConnectionFactory::make($this->settings)))->saveActivation($payload);

            return Response::redirect('/admin/market/plugins?license=activated');
        } catch (Throwable $exception) {
            return Response::html(View::page('授权激活失败', '<h1>授权激活失败</h1><p class="error">' . View::escape($this->licenseErrorText($exception->getMessage())) . '</p><p><a class="button" href="/admin/market/plugins">返回插件市场</a></p>'), 400);
        }
    }

    public function marketDiagnostics(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $diagnostics = $this->marketClient()->diagnostics();
        $body = '<h1>市场诊断</h1><p class="muted">检查官方更新服务器、市场 API、本地缓存和当前渠道。</p>' .
            '<div class="admin-actions">' .
            '<form method="post" action="/admin/market/refresh">' . CsrfToken::field() . '<button type="submit">重新同步市场</button></form>' .
            '<form method="post" action="/admin/market/clear-cache">' . CsrfToken::field() . '<button type="submit">清除市场缓存</button></form>' .
            '<form method="post" action="/admin/market/test-connection">' . CsrfToken::field() . '<button type="submit">测试官方市场连接</button></form>' .
            '</div><table><tbody>' .
            '<tr><th>官方市场服务器地址</th><td>' . View::escape((string) $this->settings->get('market.server_url', '')) . '</td></tr>' .
            '<tr><th>API 连通状态</th><td>' . View::escape((string) ($diagnostics['api_status'] ?? 'unknown')) . '</td></tr>' .
            '<tr><th>HTTP 状态码</th><td>' . (int) ($diagnostics['http_status'] ?? 0) . '</td></tr>' .
            '<tr><th>最近同步时间</th><td>' . View::escape((string) ($diagnostics['last_sync_at'] ?? '')) . '</td></tr>' .
            '<tr><th>最近获取插件数量</th><td>' . (int) ($diagnostics['last_item_count'] ?? 0) . '</td></tr>' .
            '<tr><th>当前市场渠道</th><td>' . View::escape($this->marketChannel()) . '</td></tr>' .
            '<tr><th>缓存状态</th><td>' . View::escape((string) ($diagnostics['cache_status'] ?? 'unknown')) . '</td></tr>' .
            '<tr><th>CMS 版本</th><td>' . View::escape($this->currentCoreVersion()) . '</td></tr>' .
            '<tr><th>PHP 版本</th><td>' . View::escape(PHP_VERSION) . '</td></tr>' .
            '<tr><th>市场 API 版本</th><td>' . View::escape((string) ($diagnostics['api_version'] ?? 'unknown')) . '</td></tr>' .
            '</tbody></table>';

        return Response::html(View::page('市场诊断', $body));
    }

    public function marketRefresh(Request $request): Response
    {
        return $this->marketSyncAction($request, true, false);
    }

    public function marketClearCache(Request $request): Response
    {
        return $this->marketSyncAction($request, false, true);
    }

    public function marketTestConnection(Request $request): Response
    {
        return $this->marketSyncAction($request, true, false, true);
    }

    public function marketAuthorize(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $marketId = (string) $request->input('market_id', '');
            $productId = trim((string) $request->input('product_id', ''));
            $licenseKey = trim((string) $request->input('license_key', ''));
            if ($productId === '') {
                $detail = $this->marketClient()->detail($marketId, '', true);
                $versionData = is_array($detail['version'] ?? null) ? $detail['version'] : $detail;
                $productId = (string) ($versionData['product_id'] ?? $versionData['extension_id'] ?? $versionData['plugin_id'] ?? '');
            }
            if ($licenseKey !== '' && $productId !== '') {
                $payload = $this->marketClient()->activateLicense(
                    $productId,
                    $licenseKey,
                    (string) $this->settings->get('site.id', 'local-site'),
                    (string) $this->settings->get('site.url', '')
                );
                (new CommercialLicenseStore(ConnectionFactory::make($this->settings)))->saveActivation($payload);
            }
            $localLicense = $this->localLicenseForProduct($productId);
            $credential = $licenseKey !== '' ? $licenseKey : (string) ($localLicense['license_key_credential'] ?? '');
            $authorization = $this->marketClient()->authorizeInstall($marketId, (string) $this->settings->get('site.id', 'local-site'), $credential);
            $authorizedMarketId = $authorization->marketId !== '' ? $authorization->marketId : $marketId;
            $root = $this->root();
            $packagePath = (new RemotePackageDownloader($root . '/storage/market/tmp'))->download(
                $authorization->packageUrl,
                $authorization->packageSha256,
            );
            $result = (new MarketPackageInstaller($root))->install(
                $packagePath,
                new InstallAuthorization($authorization->token, $authorization->packageUrl, $authorization->expiresAt, $authorization->packageSha256, $authorizedMarketId),
                ConnectionFactory::make($this->settings)
            );
            $installedType = (string) ($result['extension_type'] ?? $result['type'] ?? '');
            $isTheme = $installedType === 'theme' || str_contains((string) ($authorization->marketId ?? $marketId), ':theme:');
            $message = $isTheme ? '主题已从官方市场安装到当前 CMS。' : '插件已从官方市场安装到当前 CMS。';
            $manageUrl = $isTheme ? '/admin/themes' : '/admin/plugins';
            $manageLabel = $isTheme ? '查看主题管理' : '查看插件管理';
            $backUrl = $isTheme ? '/admin/market/themes' : '/admin/market/plugins';
            $backLabel = $isTheme ? '返回主题市场' : '返回插件市场';
            $body = '<h1>安装完成</h1><p class="success">' . $message . '</p><p><a class="button" href="' . $manageUrl . '">' . $manageLabel . '</a> <a class="button admin-button-secondary" href="' . $backUrl . '">' . $backLabel . '</a></p><pre>' . View::escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</pre>';
            return Response::html(View::page('市场安装', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Market install authorization failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('市场安装失败', '<h1>安装失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/market/plugins">返回插件市场</a></p>'), 400);
        }
    }

    public function marketInstall(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }

        try {
            $authorization = new InstallAuthorization(
                (string) $request->input('token', ''),
                (string) $request->input('package_url', ''),
                (string) $request->input('expires_at', ''),
                (string) $request->input('package_sha256', ''),
                (string) $request->input('market_id', ''),
            );
            $root = $this->root();
            $packagePath = trim((string) $request->input('package_path', ''));
            if ($packagePath === '') {
                $packagePath = (new RemotePackageDownloader($root . '/storage/market/tmp'))->download(
                    $authorization->packageUrl,
                    $authorization->packageSha256,
                );
            }
            $result = (new MarketPackageInstaller($root))->install($packagePath, $authorization, ConnectionFactory::make($this->settings));
            $body = '<h1>扩展安装完成</h1><pre>' . View::escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
            return Response::html(View::page('市场安装', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Market install failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('市场安装', '<h1>安装失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
    }

    /** @return array{id:int,email:string,display_name:string,ip:string,issued_at:int}|null */
    private function mfaPendingUser(): ?array
    {
        $pending = $_SESSION['admin_mfa_pending'] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        $issuedAt = (int) ($pending['issued_at'] ?? 0);
        if ($issuedAt <= 0 || $issuedAt < time() - 600) {
            unset($_SESSION['admin_mfa_pending']);
            return null;
        }
        $adminId = (int) ($pending['id'] ?? 0);
        $email = trim((string) ($pending['email'] ?? ''));
        if ($adminId <= 0 || $email === '') {
            unset($_SESSION['admin_mfa_pending']);
            return null;
        }

        return [
            'id' => $adminId,
            'email' => $email,
            'display_name' => (string) ($pending['display_name'] ?? ''),
            'ip' => (string) ($pending['ip'] ?? '0.0.0.0'),
            'issued_at' => $issuedAt,
        ];
    }

    private function mfaChallengeHtml(string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';

        return '<h1>管理员二次验证</h1>' . $errorHtml .
            '<p class="muted">可以使用系统人脸/指纹/通行密钥验证，也可以输入认证器动态验证码或恢复码。</p>' .
            '<button type="button" data-passkey-login>使用人脸/指纹/通行密钥验证</button><p class="muted" data-passkey-login-status></p>' .
            '<form method="post" action="/admin/mfa">' . CsrfToken::field() .
            '<label>验证码或恢复码<input name="mfa_code" inputmode="numeric" autocomplete="one-time-code" required></label>' .
            '<button type="submit">完成验证</button></form>' .
            '<form method="post" action="/admin/logout">' . CsrfToken::field() .
            '<button type="submit" class="button-secondary">返回登录</button></form>' .
            $this->passkeyLoginScript(CsrfToken::get());
    }

    private function adminSecurityHtml(AdminMfaService $mfa, int $adminId, string $message = ''): string
    {
        $enabled = $mfa->isEnabled($adminId);
        $sessions = $this->adminSessionSecurityHtml($adminId);
        $reauth = $this->adminReauthenticationHtml($adminId);
        $status = $enabled
            ? '<p class="admin-badge admin-badge-success">MFA 已启用</p>'
            : '<p class="admin-badge">MFA 未启用</p>';
        $body = '<h1>后台安全</h1>' . $message . $status .
            '<p class="muted">Core 后台支持 TOTP、恢复码和 Passkey。Passkey 会调用设备的人脸、指纹或系统 PIN，不会把生物识别数据保存到 CMS。</p>' .
            $reauth . $sessions . $this->adminPasskeyHtml($adminId);

        if ($enabled) {
            return $body .
                '<h2>停用 MFA</h2>' .
                '<form method="post" action="/admin/security/mfa-disable" onsubmit="return confirm(\'确定要停用后台 MFA 吗？\')">' . CsrfToken::field() .
                '<label>当前管理员密码<input name="password" type="password" autocomplete="current-password" required></label>' .
                '<button type="submit" class="button-danger">停用 MFA</button></form>';
        }

        $setup = $_SESSION['admin_mfa_setup'] ?? null;
        if (!is_array($setup) || !is_string($setup['secret'] ?? null) || (string) $setup['secret'] === '') {
            $_SESSION['admin_mfa_setup'] = [
                'secret' => AdminMfaService::generateSecret(),
                'recovery_codes' => AdminMfaService::generateRecoveryCodes(),
            ];
            $setup = $_SESSION['admin_mfa_setup'];
        }
        $secret = (string) ($setup['secret'] ?? '');

        return $body .
            '<h2>启用 MFA</h2>' .
            '<p class="muted">在认证器 App 中添加下面的密钥，然后输入 6 位动态验证码完成启用。恢复码会在启用成功后显示一次。</p>' .
            '<dl><dt>账户</dt><dd>' . View::escape((string) (($this->settings->get('site.name', 'Daiying CMS')) ?: 'Daiying CMS')) . '</dd>' .
            '<dt>TOTP 密钥</dt><dd><code>' . View::escape($secret) . '</code></dd></dl>' .
            '<form method="post" action="/admin/security/mfa-enable">' . CsrfToken::field() .
            '<label>认证器验证码<input name="mfa_code" inputmode="numeric" autocomplete="one-time-code" required></label>' .
            '<button type="submit">启用 MFA</button></form>';
    }

    private function adminPasskeyHtml(int $adminId): string
    {
        try {
            $service = new AdminPasskeyService(ConnectionFactory::make($this->settings), $this->settings);
            $rows = '';
            foreach ($service->listForAdmin($adminId) as $passkey) {
                $rows .= '<tr><td>' . View::escape((string) $passkey['label']) . '</td><td>' . View::escape((string) $passkey['created_at']) . '</td><td>' . View::escape((string) ($passkey['last_used_at'] ?? '')) . '</td><td><form method="post" action="/admin/security/passkey-delete" onsubmit="return confirm(\'确定删除这个 Passkey 吗？\')">' . CsrfToken::field() . '<input type="hidden" name="id" value="' . (int) $passkey['id'] . '"><button class="admin-danger" type="submit">删除</button></form></td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="4" class="muted">暂无 Passkey。</td></tr>';
        } catch (Throwable) {
            $rows = '<tr><td colspan="4" class="muted">Passkey 数据暂不可用。</td></tr>';
        }

        return '<section class="admin-card"><h2>Passkey / 人脸指纹登录</h2><p class="muted">添加后，管理员密码验证通过后可以用 Face ID、Touch ID、Windows Hello 或安全密钥完成二次验证。</p>' .
            '<label>设备名称<input data-passkey-label value="我的设备"></label>' .
            '<button type="button" data-passkey-register>添加 Passkey</button><p class="muted" data-passkey-register-status></p>' .
            '<table><thead><tr><th>名称</th><th>创建时间</th><th>最近使用</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table></section>' .
            $this->passkeyRegisterScript(CsrfToken::get());
    }

    private function adminReauthenticationHtml(int $adminId): string
    {
        try {
            $service = new AdminSessionService(ConnectionFactory::make($this->settings));
            $fresh = $service->hasRecentReauthentication();
        } catch (Throwable) {
            $fresh = false;
        }
        $badge = $fresh
            ? '<span class="admin-badge admin-badge-success">已重新验证</span>'
            : '<span class="admin-badge admin-badge-muted">需要时重新验证</span>';
        return '<section class="admin-card"><h2>敏感操作重认证</h2><p>' . $badge . '</p>' .
            '<p class="muted">删除 Passkey、停用 MFA、更新 Core、清理插件等高风险操作前，管理员应先重新输入当前密码完成短时身份确认。</p>' .
            '<form method="post" action="/admin/security/reauth">' . CsrfToken::field() .
            '<label>当前管理员密码<input name="password" type="password" autocomplete="current-password" required></label>' .
            '<button type="submit">重新验证身份</button></form></section>';
    }

    private function adminSessionSecurityHtml(int $adminId): string
    {
        try {
            $service = new AdminSessionService(ConnectionFactory::make($this->settings));
            $rows = '';
            foreach ($service->listForAdmin($adminId) as $session) {
                $status = (string) ($session['revoked_at'] ?? '') !== ''
                    ? '<span class="admin-badge admin-badge-muted">已退出</span>'
                    : ((bool) ($session['current'] ?? false) ? '<span class="admin-badge admin-badge-success">当前会话</span>' : '<span class="admin-badge">有效</span>');
                $ua = (string) ($session['user_agent'] ?? '');
                $rows .= '<tr><td>' . $status . '</td><td>' . View::escape((string) ($session['ip_address'] ?? '')) . '</td><td>' . View::escape($ua === '' ? '-' : mb_substr($ua, 0, 96)) . '</td><td>' . View::escape((string) ($session['mfa_method'] ?? '')) . '</td><td>' . View::escape((string) ($session['last_seen_at'] ?? '')) . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无会话记录。</td></tr>';
        } catch (Throwable) {
            $rows = '<tr><td colspan="5" class="muted">会话记录暂不可用。</td></tr>';
        }

        return '<section class="admin-card"><h2>登录设备与会话</h2><p class="muted">可以保留当前会话，同时退出其他浏览器或设备上的后台登录。</p>' .
            '<form method="post" action="/admin/security/logout-other-sessions" onsubmit="return confirm(\'确定退出其他后台会话吗？\')">' . CsrfToken::field() .
            '<button type="submit" class="admin-button-secondary">退出其他会话</button></form>' .
            '<table><thead><tr><th>状态</th><th>IP</th><th>浏览器/设备</th><th>MFA</th><th>最近活动</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    /** @param list<array{id:string,label:string,status:string,message:string,remediation:string}> $checks */
    private function systemHealthHtml(array $checks): string
    {
        $counts = ['PASS' => 0, 'WARNING' => 0, 'FAIL' => 0, 'BLOCKED' => 0];
        $rows = '';
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'WARNING');
            if (!isset($counts[$status])) {
                $status = 'WARNING';
            }
            $counts[$status]++;
            $class = $status === 'PASS' ? 'admin-badge-success' : ($status === 'FAIL' ? 'admin-badge-danger' : 'admin-badge-muted');
            $rows .= '<tr><td><span class="admin-badge ' . $class . '">' . View::escape($status) . '</span></td><td>' . View::escape((string) ($check['label'] ?? $check['id'] ?? '')) . '</td><td>' . View::escape((string) ($check['message'] ?? '')) . '</td><td>' . View::escape((string) ($check['remediation'] ?? '')) . '</td></tr>';
        }

        return '<div class="admin-page-header"><div><h1>系统健康 / Security & Health</h1><p class="muted">自动检查生产部署、安全配置、更新、市场、会话、MFA、Passkey、备份和 Core 完整性。</p></div><div class="admin-action-row">' .
            '<span class="admin-badge admin-badge-success">PASS ' . $counts['PASS'] . '</span>' .
            '<span class="admin-badge admin-badge-muted">WARNING ' . $counts['WARNING'] . '</span>' .
            '<span class="admin-badge admin-badge-danger">FAIL ' . $counts['FAIL'] . '</span>' .
            '</div></div><table><thead><tr><th>状态</th><th>项目</th><th>结果</th><th>处理建议</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function passkeyRegisterScript(string $csrf): string
    {
        return '<script>
function dyB64uToBuf(v){v=v.replace(/-/g,"+").replace(/_/g,"/");while(v.length%4)v+="=";var s=atob(v);var b=new Uint8Array(s.length);for(var i=0;i<s.length;i++)b[i]=s.charCodeAt(i);return b.buffer}
function dyBufToB64u(b){var s="";var a=new Uint8Array(b);for(var i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"")}
var dyPasskeyRegister=document.querySelector("[data-passkey-register]");
if(dyPasskeyRegister){dyPasskeyRegister.addEventListener("click",async function(){var status=document.querySelector("[data-passkey-register-status]");try{if(!window.PublicKeyCredential)throw new Error("当前浏览器不支持 Passkey");var form=new URLSearchParams();form.set("_csrf","' . View::escape($csrf) . '");var opt=await fetch("/admin/security/passkey-options",{method:"POST",body:form}).then(r=>r.json());if(opt.error)throw new Error(opt.error);opt.challenge=dyB64uToBuf(opt.challenge);opt.user.id=dyB64uToBuf(opt.user.id);var cred=await navigator.credentials.create({publicKey:opt});var out=new URLSearchParams();out.set("_csrf","' . View::escape($csrf) . '");out.set("label",(document.querySelector("[data-passkey-label]")||{}).value||"Passkey");out.set("id",cred.id);out.set("rawId",dyBufToB64u(cred.rawId));out.set("clientDataJSON",dyBufToB64u(cred.response.clientDataJSON));out.set("attestationObject",dyBufToB64u(cred.response.attestationObject));var saved=await fetch("/admin/security/passkey-register",{method:"POST",body:out}).then(r=>r.json());if(saved.error)throw new Error(saved.error);status.textContent="Passkey 已添加。";location.reload()}catch(e){status.textContent="Passkey 添加失败："+e.message}})}
</script>';
    }

    private function passkeyLoginScript(string $csrf): string
    {
        return '<script>
function dyB64uToBuf(v){v=v.replace(/-/g,"+").replace(/_/g,"/");while(v.length%4)v+="=";var s=atob(v);var b=new Uint8Array(s.length);for(var i=0;i<s.length;i++)b[i]=s.charCodeAt(i);return b.buffer}
function dyBufToB64u(b){var s="";var a=new Uint8Array(b);for(var i=0;i<a.length;i++)s+=String.fromCharCode(a[i]);return btoa(s).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"")}
var dyPasskeyLogin=document.querySelector("[data-passkey-login]");
if(dyPasskeyLogin){dyPasskeyLogin.addEventListener("click",async function(){var status=document.querySelector("[data-passkey-login-status]");try{if(!window.PublicKeyCredential)throw new Error("当前浏览器不支持 Passkey");var form=new URLSearchParams();form.set("_csrf","' . View::escape($csrf) . '");var opt=await fetch("/admin/mfa/passkey-options",{method:"POST",body:form}).then(r=>r.json());if(opt.error)throw new Error(opt.error);opt.challenge=dyB64uToBuf(opt.challenge);if(opt.allowCredentials){opt.allowCredentials=opt.allowCredentials.map(function(c){return {type:c.type,id:dyB64uToBuf(c.id)}})}var cred=await navigator.credentials.get({publicKey:opt});var out=new URLSearchParams();out.set("_csrf","' . View::escape($csrf) . '");out.set("id",cred.id);out.set("rawId",dyBufToB64u(cred.rawId));out.set("clientDataJSON",dyBufToB64u(cred.response.clientDataJSON));out.set("authenticatorData",dyBufToB64u(cred.response.authenticatorData));out.set("signature",dyBufToB64u(cred.response.signature));var verified=await fetch("/admin/mfa/passkey-verify",{method:"POST",body:out}).then(r=>r.json());if(verified.error)throw new Error(verified.error);location.href=verified.redirect||"/admin"}catch(e){status.textContent="Passkey 验证失败："+e.message}})}
</script>';
    }

    private function loginHtml(string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';

        return '<h1>管理员登录</h1>' . $errorHtml .
            '<form method="post" action="/admin/login">' . CsrfToken::field() .
            '<label>邮箱<input name="email" type="email" required></label>' .
            '<label>密码<input name="password" type="password" required></label>' .
            '<button type="submit">登录</button></form><p><a href="/admin/forgot-password">忘记管理员密码？</a></p>';
    }

    private function forgotPasswordHtml(string $error = '', string $notice = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $noticeHtml = $notice === '' ? '' : '<p class="admin-badge admin-badge-success">' . View::escape($notice) . '</p>';
        return '<h1>找回管理员密码</h1>' . $errorHtml . $noticeHtml .
            '<p class="muted">输入管理员邮箱后，如果账号存在且邮件已配置，系统会发送一次性重置链接。页面不会显示账号是否存在。</p>' .
            '<form method="post" action="/admin/forgot-password">' . CsrfToken::field() .
            '<label>管理员邮箱<input name="email" type="email" autocomplete="email" required></label>' .
            '<button type="submit">发送重置邮件</button></form><p><a href="/admin/login">返回登录</a></p>';
    }

    private function resetPasswordHtml(string $token = '', string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        return '<h1>重置管理员密码</h1>' . $errorHtml .
            '<form method="post" action="/admin/reset-password">' . CsrfToken::field() .
            '<input type="hidden" name="token" value="' . View::escape($token) . '">' .
            '<label>新密码<input name="password" type="password" autocomplete="new-password" minlength="10" required></label>' .
            '<label>确认新密码<input name="password_confirm" type="password" autocomplete="new-password" minlength="10" required></label>' .
            '<button type="submit">重置密码</button></form><p><a href="/admin/login">返回登录</a></p>';
    }

    private function sendPasswordResetMail(string $email, string $token): bool
    {
        $from = trim((string) ($this->settings->get('mail.from', '') ?: $this->settings->get('site.email', '')));
        if ($from === '' || !function_exists('mail')) {
            return false;
        }
        $siteUrl = rtrim((string) $this->settings->get('site.url', ''), '/');
        if ($siteUrl === '' || !str_starts_with($siteUrl, 'https://')) {
            return false;
        }
        $link = $siteUrl . '/admin/reset-password?token=' . rawurlencode($token);
        $subject = 'Daiying CMS 管理员密码重置';
        $body = "请在 30 分钟内打开以下链接重置管理员密码：\n\n" . $link . "\n\n如果不是你本人操作，请忽略此邮件。";
        return @mail($email, $subject, $body, 'From: ' . $from);
    }

    /** @param list<string> $types */
    private function marketIndex(array $types, string $title): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $items = [];
        $client = $this->marketClient();
        $query = trim((string) ($_GET['q'] ?? ''));
        $forceRefresh = (string) ($_GET['refresh'] ?? '') === '1';
        $errors = [];
        foreach ($types as $type) {
            try {
                $items = array_merge($items, $client->search($type, $query, $forceRefresh));
            } catch (Throwable $exception) {
                $this->logger->error('Market API unavailable', ['source' => 'Core', 'type' => $type, 'error' => $exception->getMessage()]);
                $errors[] = $type . ': ' . $exception->getMessage();
            }
        }

        $installed = $this->marketInstalledMap();
        $rows = '';
        foreach ($items as $item) {
            if (!in_array($item->type, $types, true)) {
                continue;
            }
            $installedKey = $item->extensionId . '|' . $item->type;
            $installedItem = $installed[$installedKey] ?? null;
            $state = $this->marketItemState($item, $installedItem);
            $action = '<a class="button admin-button-secondary" href="/admin/market/detail?market_id=' . rawurlencode($item->marketId) . '&version=' . rawurlencode($item->version) . '">详情</a>';
            if ($state === '不兼容') {
                $action .= ' <span class="muted">当前 CMS 版本不兼容</span>';
            } elseif ($item->licenseRequired && $this->localLicenseForProduct($item->productId) === []) {
                $action .= $this->marketInlinePurchaseActions($item, $installedItem !== null);
            } else {
                $action .= '<form method="post" action="/admin/market/authorize" style="display:inline-block;margin-left:6px">' .
                    CsrfToken::field() . '<input type="hidden" name="market_id" value="' . View::escape($item->marketId) . '">' .
                    '<input type="hidden" name="product_id" value="' . View::escape($item->productId) . '">' .
                    '<button type="submit">' . ($installedItem === null ? '安装' : '更新到 ' . View::escape($item->version)) . '</button></form>';
            }
            $rows .= '<tr><td>' . View::escape($item->name) . '</td><td>' . View::escape($this->marketTypeLabel($item->type)) .
                '</td><td><code>' . View::escape($item->extensionId) . '</code><br><span class="muted">' . View::escape($item->slug) . '</span></td>' .
                '<td>' . View::escape($item->version) . '<br><span class="muted">' . View::escape($item->channel) . '</span></td>' .
                '<td>' . View::escape($state . ($item->licenseRequired ? ' / 需授权' : '')) . '</td><td>' . View::escape($item->priceLabel) . '<br><span class="muted">' . View::escape($item->developerName) . '</span></td><td>' . View::escape($item->reviewStatus) .
                '</td><td>' . View::escape(implode(', ', $item->capabilities)) . '</td><td>' . $action . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="9" class="muted">' . ($errors === [] ? '暂无项目' : '市场 API 异常：' . View::escape(implode('; ', $errors))) . '</td></tr>';
        $errorHtml = $errors === [] ? '' : '<p class="error">市场 API 异常：' . View::escape(implode('; ', $errors)) . '</p>';

        $body = '<h1>' . View::escape($title) . '</h1><p class="muted">市场不可用不会影响网站、后台和已安装扩展。</p>' . $errorHtml .
            '<form class="admin-filter-bar" method="get" action="' . (in_array('theme', $types, true) && count($types) === 1 ? '/admin/market/themes' : '/admin/market/plugins') . '">' .
            '<label>搜索<input name="q" value="' . View::escape($query) . '" placeholder="' . (in_array('theme', $types, true) && count($types) === 1 ? '主题名称 / theme slug' : '支付 / Payment / Stripe / plugin slug') . '"></label><button type="submit">搜索</button></form>' .
            '<div class="admin-actions"><form method="post" action="/admin/market/refresh">' . CsrfToken::field() . '<button type="submit">' . (in_array('theme', $types, true) && count($types) === 1 ? '刷新主题市场' : '刷新插件市场') . '</button></form>' .
            '<a class="button admin-button-secondary" href="/admin/market/diagnostics">市场诊断</a></div>' .
            '<table><thead><tr><th>名称</th><th>类型</th><th>标识</th><th>版本/渠道</th><th>状态</th><th>价格</th><th>审核</th><th>能力</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page($title, $body));
    }

    private function marketTypeLabel(string $type): string
    {
        return match ($type) {
            'plugin' => '插件',
            'payment_provider' => '支付插件',
            'theme' => '主题',
            default => $type,
        };
    }

    /** @param array<string,string> $data @param array<string,mixed> $file */
    private function validateDeveloperSubmission(array $data, array $file): void
    {
        if (!in_array($data['package_type'] ?? '', ['plugin', 'payment_provider', 'theme'], true)) {
            throw new \RuntimeException('产品类型必须是插件、支付插件或主题。');
        }
        if (($data['product_id'] ?? '') === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{2,120}$/', (string) $data['product_id'])) {
            throw new \RuntimeException('产品 ID 只能使用小写字母、数字、点、下划线和短横线。');
        }
        if (($data['version'] ?? '') === '' || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9._-]+)?$/', (string) $data['version'])) {
            throw new \RuntimeException('版本号格式无效，例如 1.0.0。');
        }
        if (($data['name'] ?? '') === '' || ($data['developer_name'] ?? '') === '' || !filter_var($data['developer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('请填写产品名称、开发者名称和有效邮箱。');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('ZIP 上传失败，请重新选择文件。');
        }
        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.zip')) {
            throw new \RuntimeException('只能提交 ZIP 安装包。');
        }
        $size = (int) ($file['size'] ?? 0);
        $max = max(1048576, (int) $this->settings->get('review.max_zip_bytes', 20971520));
        if ($size <= 0 || $size > $max) {
            throw new \RuntimeException('ZIP 大小超过限制，当前限制 ' . (int) floor($max / 1048576) . ' MB。');
        }
        $zip = new \ZipArchive();
        if ($zip->open((string) $file['tmp_name']) !== true) {
            throw new \RuntimeException('ZIP 无法读取。');
        }
        $hasManifest = false;
        $limit = min($zip->numFiles, 2000);
        for ($i = 0; $i < $limit; $i++) {
            $path = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $path);
            if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../') || str_contains($normalized, "\0")) {
                $zip->close();
                throw new \RuntimeException('ZIP 包含不安全路径：' . $path);
            }
            if (basename($normalized) === 'manifest.json' || basename($normalized) === 'plugin.json' || basename($normalized) === 'theme.json') {
                $hasManifest = true;
            }
        }
        $zip->close();
        if (!$hasManifest) {
            throw new \RuntimeException('ZIP 必须包含 manifest.json、plugin.json 或 theme.json。');
        }
    }

    /** @param array<string,string> $data @param array<string,mixed> $file @return array<string,string> */
    private function applyDeveloperSubmissionManifestHints(array $data, array $file): array
    {
        $hints = $this->developerSubmissionManifestHints((string) ($file['tmp_name'] ?? ''));
        if ($hints === []) {
            return $data;
        }
        foreach (['name', 'product_id', 'version', 'developer_name'] as $field) {
            if (($data[$field] ?? '') === '' && (string) ($hints[$field] ?? '') !== '') {
                $data[$field] = (string) $hints[$field];
            }
        }
        if (in_array((string) ($hints['package_type'] ?? ''), ['plugin', 'payment_provider', 'theme'], true)) {
            $currentType = (string) ($data['package_type'] ?? '');
            if ($currentType === '' || (($data['product_id'] ?? '') === (string) ($hints['product_id'] ?? '') && $currentType !== (string) ($hints['package_type'] ?? ''))) {
                $data['package_type'] = (string) $hints['package_type'];
            }
        }

        return $data;
    }

    /** @return array<string,string> */
    private function developerSubmissionManifestHints(string $zipPath): array
    {
        if ($zipPath === '' || !is_file($zipPath)) {
            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        try {
            $best = [];
            $bestPriority = PHP_INT_MAX;
            $limit = min($zip->numFiles, 2000);
            for ($i = 0; $i < $limit; $i++) {
                $path = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($path === '' || str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, "\0") || str_ends_with($path, '/')) {
                    continue;
                }
                $base = basename($path);
                if (!in_array($base, ['plugin.json', 'theme.json', 'manifest.json'], true) || !$this->isSupportedSubmissionManifestPath($path, $base)) {
                    continue;
                }
                $decoded = json_decode((string) $zip->getFromName($path), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $priority = $this->submissionManifestPriority($path);
                if ($priority >= $bestPriority) {
                    continue;
                }
                $packageType = $base === 'theme.json' ? 'theme' : (string) ($decoded['package_type'] ?? ($decoded['type'] ?? 'plugin'));
                if ($packageType === 'payment-provider') {
                    $packageType = 'payment_provider';
                }
                if (!in_array($packageType, ['plugin', 'payment_provider', 'theme'], true)) {
                    $packageType = 'plugin';
                }
                $best = [
                    'package_type' => $packageType,
                    'product_id' => (string) ($decoded['product_id'] ?? $decoded['plugin_id'] ?? $decoded['theme_id'] ?? ''),
                    'name' => (string) ($decoded['name'] ?? $decoded['title'] ?? $decoded['plugin_name'] ?? $decoded['theme_name'] ?? ''),
                    'version' => (string) ($decoded['version'] ?? ''),
                    'developer_name' => (string) ($decoded['author'] ?? $decoded['developer_name'] ?? ''),
                    'source_manifest' => $path,
                ];
                $bestPriority = $priority;
            }

            return $best;
        } finally {
            $zip->close();
        }
    }

    private function isSupportedSubmissionManifestPath(string $path, string $base): bool
    {
        if ($base === 'manifest.json') {
            return $path === 'manifest.json';
        }
        if ($base === 'plugin.json') {
            return $path === 'plugin.json'
                || preg_match('#^[A-Za-z0-9._-]+/plugin\.json$#', $path) === 1
                || preg_match('#^content/plugins/[A-Za-z0-9._-]+/plugin\.json$#', $path) === 1;
        }
        if ($base === 'theme.json') {
            return $path === 'theme.json'
                || preg_match('#^[A-Za-z0-9._-]+/theme\.json$#', $path) === 1
                || preg_match('#^content/themes/[A-Za-z0-9._-]+/theme\.json$#', $path) === 1;
        }

        return false;
    }

    private function submissionManifestPriority(string $path): int
    {
        return match (true) {
            $path === 'manifest.json' => 0,
            $path === 'plugin.json' || $path === 'theme.json' => 1,
            str_starts_with($path, 'content/plugins/') || str_starts_with($path, 'content/themes/') => 3,
            default => 2,
        };
    }

    /** @param array<string,string> $data */
    private function developerSubmissionForm(string $error = '', array $data = [], string $previousSubmissionId = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $type = (string) ($data['package_type'] ?? 'plugin');
        $typeOptions = '<option value="plugin"' . ($type === 'plugin' ? ' selected' : '') . '>插件</option><option value="payment_provider"' . ($type === 'payment_provider' ? ' selected' : '') . '>支付插件</option><option value="theme"' . ($type === 'theme' ? ' selected' : '') . '>主题</option>';

        return '<h1>开发者提交</h1><p class="muted">提交插件、支付插件或主题 ZIP 到 Daiying 官方更新服务器。CMS 只做基础预检，正式静态扫描、AI 审核和人工发布在官方 Update Server 完成。</p>' . $errorHtml .
            '<form method="post" action="/admin/market/developer-submit" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>产品类型<select name="package_type">' . $typeOptions . '</select></label>' .
            '<label>产品名称<input name="name" value="' . View::escape((string) ($data['name'] ?? '')) . '" required></label>' .
            '<label>产品 ID<input name="product_id" value="' . View::escape((string) ($data['product_id'] ?? '')) . '" placeholder="com.example.plugin" required></label>' .
            '<label>版本号<input name="version" value="' . View::escape((string) ($data['version'] ?? '')) . '" placeholder="1.0.0" required></label>' .
            '<label>开发者 / 作者名称<input name="developer_name" value="' . View::escape((string) ($data['developer_name'] ?? '')) . '" required></label>' .
            '<label>开发者邮箱<input name="developer_email" type="email" value="' . View::escape((string) ($data['developer_email'] ?? '')) . '" required></label>' .
            '<label>开发者网站<input name="developer_url" value="' . View::escape((string) ($data['developer_url'] ?? '')) . '"></label>' .
            '<label>购买授权地址（可选）<input name="purchase_url" value="' . View::escape((string) ($data['purchase_url'] ?? '')) . '"></label>' .
            '<label>技术支持地址（可选）<input name="support_url" value="' . View::escape((string) ($data['support_url'] ?? '')) . '"></label>' .
            '<label>产品说明<textarea name="description" rows="5">' . View::escape((string) ($data['description'] ?? '')) . '</textarea></label>' .
            '<input type="hidden" name="previous_submission_id" value="' . View::escape($previousSubmissionId) . '">' .
            '<label>上传 ZIP<input name="package" type="file" accept=".zip,application/zip" required></label>' .
            '<button type="submit">提交官方审核</button></form>';
    }

    /** @param array<string,string> $data @param array<string,mixed> $remote */
    private function saveDeveloperSubmission(array $data, array $remote): void
    {
        $pdo = ConnectionFactory::make($this->settings);
        $now = gmdate('c');
        $stmt = $pdo->prepare('INSERT INTO cms_review_submissions (submission_id, product_id, package_type, version, developer_name, developer_email, developer_url, purchase_url, support_url, description, previous_submission_id, status, remote_report_json, created_at, updated_at) VALUES (:submission_id, :product_id, :package_type, :version, :developer_name, :developer_email, :developer_url, :purchase_url, :support_url, :description, :previous_submission_id, :status, :remote_report_json, :created_at, :updated_at)');
        $stmt->execute([
            ':submission_id' => $data['submission_id'],
            ':product_id' => $data['product_id'],
            ':package_type' => $data['package_type'],
            ':version' => $data['version'],
            ':developer_name' => $data['developer_name'],
            ':developer_email' => $data['developer_email'],
            ':developer_url' => $data['developer_url'],
            ':purchase_url' => $data['purchase_url'],
            ':support_url' => $data['support_url'],
            ':description' => $data['description'],
            ':previous_submission_id' => $data['previous_submission_id'],
            ':status' => $data['status'],
            ':remote_report_json' => json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed> */
    private function developerSubmissionRow(string $submissionId): array
    {
        $stmt = ConnectionFactory::make($this->settings)->prepare('SELECT * FROM cms_review_submissions WHERE submission_id = :submission_id LIMIT 1');
        $stmt->execute([':submission_id' => $submissionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /** @param array<string,mixed> $report */
    private function updateDeveloperSubmissionReport(string $submissionId, array $report): void
    {
        $status = (string) ($report['status'] ?? '');
        if ($status === '') {
            return;
        }
        $stmt = ConnectionFactory::make($this->settings)->prepare('UPDATE cms_review_submissions SET status = :status, remote_report_json = :remote_report_json, updated_at = :updated_at WHERE submission_id = :submission_id');
        $stmt->execute([
            ':status' => $status,
            ':remote_report_json' => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ':updated_at' => gmdate('c'),
            ':submission_id' => $submissionId,
        ]);
    }

    /** @param array<string,mixed> $static @param array<string,mixed> $ai */
    private function reviewIssuesHtml(array $static, array $ai): string
    {
        $rows = '';
        foreach ([
            '静态扫描' => $static,
            'AI 审核' => $ai,
        ] as $source => $report) {
            $issues = [];
            foreach (['findings', 'issues'] as $key) {
                if (isset($report[$key]) && is_array($report[$key])) {
                    $issues = array_merge($issues, $report[$key]);
                }
            }
            if ($issues === [] && in_array((string) ($report['status'] ?? ''), ['FAIL', 'AI_PROVIDER_ERROR'], true)) {
                $issues[] = [
                    'severity' => (string) ($report['status'] ?? 'FAIL'),
                    'file' => '-',
                    'line' => 0,
                    'rule' => (string) ($report['error_class'] ?? 'missing_findings'),
                    'description' => (string) ($report['summary'] ?? '扫描状态为失败，但服务端没有返回具体问题明细。请在官方更新服务器查看原始报告和日志。'),
                    'recommendation' => '请检查审核服务配置、AI Provider、ZIP manifest 和静态扫描日志后重新提交。',
                ];
            }
            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $message = (string) ($issue['message'] ?? $issue['description'] ?? $issue['title'] ?? '');
                if ($message === '' && isset($issue['rule'])) {
                    $message = '规则：' . (string) $issue['rule'];
                }
                $rows .= '<tr><td>' . View::escape($source) . '</td><td>' . View::escape((string) ($issue['severity'] ?? 'INFO')) . '</td><td>' . View::escape((string) ($issue['file'] ?? '-')) . '</td><td>' . View::escape((string) ($issue['line'] ?? '-')) . '</td><td>' . View::escape($message) . '</td><td>' . View::escape((string) ($issue['recommendation'] ?? $issue['suggestion'] ?? '')) . '</td></tr>';
            }
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">静态扫描和 AI 审核暂未返回需要处理的问题。</td></tr>';

        return '<table><thead><tr><th>来源</th><th>严重程度</th><th>文件</th><th>行号</th><th>说明</th><th>修改建议</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function reviewStatusLabel(string $status): string
    {
        return match ($status) {
            'UPLOADED' => '已上传',
            'SCANNING' => '正在扫描',
            'AI_REVIEW' => 'AI 审核中',
            'MANUAL_REVIEW', 'MANUAL_REVIEW_REQUIRED' => '等待人工审核',
            'CHANGES_REQUIRED' => '需要修改',
            'APPROVED' => '审核通过',
            'REJECTED' => '审核拒绝',
            'PUBLISHED' => '已发布',
            default => $status !== '' ? $status : '未知',
        };
    }

    private function reviewSubmissionClient(): ReviewSubmissionClient
    {
        $url = trim((string) $this->settings->get('review.server_url', ''));
        if ($url === '') {
            $url = trim((string) $this->settings->get('updates.server_url', ''));
        }
        if ($url === '') {
            $url = 'https://updates.daiyingcms.com';
        }

        return new ReviewSubmissionClient($url, trim((string) $this->settings->get('review.api_token', '')));
    }

    /** @param array<string,mixed> $versionData */
    private function marketPurchasePanel(array $versionData): string
    {
        if (!(bool) ($versionData['license_required'] ?? false)) {
            $marketId = (string) ($versionData['market_id'] ?? '');
            $productId = (string) ($versionData['product_id'] ?? $versionData['extension_id'] ?? $versionData['plugin_id'] ?? '');
            return '<section class="admin-panel"><h2>安装</h2><p class="admin-badge admin-badge-success">免费产品，无需授权。</p><form method="post" action="/admin/market/authorize">' . CsrfToken::field() .
                '<input type="hidden" name="market_id" value="' . View::escape($marketId) . '">' .
                '<input type="hidden" name="product_id" value="' . View::escape($productId) . '">' .
                '<button type="submit">安装</button></form></section>';
        }
        $productId = (string) ($versionData['product_id'] ?? $versionData['market_id'] ?? '');
        $local = $this->localLicenseForProduct($productId);
        $licenseText = $local === [] ? 'Not Activated' : ((string) ($local['status'] ?? 'ACTIVE') . ' / ' . (string) ($local['license_key_mask'] ?? ''));
        $purchaseUrl = (string) ($versionData['purchase_url'] ?? '');
        $renewUrl = (string) (($versionData['renew_url'] ?? '') ?: $purchaseUrl);
        $supportUrl = (string) ($versionData['support_url'] ?? '');
        $developerUrl = (string) ($versionData['developer_url'] ?? '');
        $links = '';
        foreach ([['购买授权', $purchaseUrl], ['续费更新', $renewUrl], ['支持', $supportUrl], ['查看开发者', $developerUrl]] as [$label, $url]) {
            if ($url !== '') {
                $links .= '<a class="button admin-button-secondary" target="_blank" rel="noopener noreferrer" href="' . View::escape($url) . '">' . View::escape($label) . '</a> ';
            }
        }

        $marketId = (string) ($versionData['market_id'] ?? '');
        return '<section class="admin-panel"><h2>商业授权</h2><p>当前授权：' . View::escape($licenseText) . '</p><p>' . $links . '</p>' .
            '<form method="post" action="/admin/market/authorize">' . CsrfToken::field() .
            '<input type="hidden" name="market_id" value="' . View::escape($marketId) . '">' .
            '<input type="hidden" name="product_id" value="' . View::escape($productId) . '">' .
            '<label>输入授权码<input name="license_key" required></label><button type="submit">输入授权码并安装</button></form></section>';
    }

    private function marketInlinePurchaseActions(\Cms\Core\Market\MarketItem $item, bool $installed): string
    {
        $purchase = $item->purchaseUrl !== '' ? ' <a class="button" target="_blank" rel="noopener noreferrer" href="' . View::escape($item->purchaseUrl) . '">购买授权</a>' : '';
        return $purchase . '<form method="post" action="/admin/market/authorize" style="display:inline-block;margin-left:6px">' .
            CsrfToken::field() . '<input type="hidden" name="product_id" value="' . View::escape($item->productId) . '">' .
            '<input type="hidden" name="market_id" value="' . View::escape($item->marketId) . '">' .
            '<input name="license_key" placeholder="输入授权码" required><button type="submit">' . ($installed ? '输入授权码并更新' : '输入授权码并安装') . '</button></form>';
    }

    /** @return array<string,mixed> */
    private function localLicenseForProduct(string $productId): array
    {
        if ($productId === '') {
            return [];
        }
        try {
            return (new CommercialLicenseStore(ConnectionFactory::make($this->settings)))->licenseForProduct($productId);
        } catch (Throwable) {
            return [];
        }
    }

    private function licenseErrorText(string $code): string
    {
        return match ($code) {
            'LICENSE_REQUIRED' => '需要输入有效授权码。',
            'LICENSE_INVALID' => '授权码无效。',
            'LICENSE_NOT_ACTIVATED' => '授权尚未激活。',
            'LICENSE_PRODUCT_MISMATCH' => '授权码不属于这个产品。',
            'LICENSE_SITE_LIMIT' => '授权绑定站点数量已达到上限。',
            'LICENSE_UPDATE_EXPIRED' => '更新权益已过期。已安装版本会继续运行，新版本需要续费。',
            'LICENSE_DISABLED' => '授权已被临时禁用。',
            'LICENSE_REVOKED' => '授权已被永久撤销。',
            default => $code,
        };
    }

    private function marketClient(): MarketApiClientInterface
    {
        $url = trim((string) $this->settings->get('market.server_url', ''));
        if ($url === '') {
            $url = trim((string) $this->settings->get('updates.server_url', ''));
        }
        if ($url === '') {
            $url = 'https://updates.daiyingcms.com';
        }

        return new HttpMarketClient(
            $url,
            (string) $this->settings->get('market.site_token', ''),
            $this->root() . '/storage/cache/market-api',
            $this->marketChannel(),
            $this->currentCoreVersion()
        );
    }

    private function marketChannel(): string
    {
        $channel = (string) $this->settings->get('market.channel', $this->settings->get('updates.channel', 'stable'));
        return in_array($channel, ['stable', 'rc', 'beta', 'dev'], true) ? $channel : 'stable';
    }

    /** @return array<string,array<string,mixed>> */
    private function marketInstalledMap(): array
    {
        try {
            $map = [];
            foreach ((new MarketInstallRepository(ConnectionFactory::make($this->settings)))->latestInstalledByExtension() as $row) {
                $status = (string) (($this->jsonArray((string) ($row['metadata_json'] ?? '{}'))['status'] ?? 'Installed'));
                if ($status === 'Uninstalled' || (string) ($row['source'] ?? '') === 'uninstalled') {
                    continue;
                }
                $map[(string) $row['extension_id'] . '|' . (string) $row['extension_type']] = $row;
            }
            foreach (glob($this->root() . '/content/plugins/*/plugin.json') ?: [] as $manifestPath) {
                $decoded = $this->jsonArray((string) file_get_contents($manifestPath));
                $pluginId = (string) ($decoded['plugin_id'] ?? basename(dirname($manifestPath)));
                $type = (string) ($decoded['type'] ?? ($decoded['package_type'] ?? 'plugin'));
                if (!in_array($type, ['plugin', 'payment_provider'], true)) {
                    $type = 'plugin';
                }
                $key = $pluginId . '|' . $type;
                $map[$key] ??= [
                    'extension_id' => $pluginId,
                    'extension_type' => $type,
                    'source' => 'local_scan',
                    'market_id' => '',
                    'version' => (string) ($decoded['version'] ?? '0.0.0'),
                    'metadata_json' => '{}',
                ];
            }
            foreach (glob($this->root() . '/content/themes/*/theme.json') ?: [] as $manifestPath) {
                $decoded = $this->jsonArray((string) file_get_contents($manifestPath));
                $themeId = (string) ($decoded['theme_id'] ?? basename(dirname($manifestPath)));
                $key = $themeId . '|theme';
                $map[$key] ??= [
                    'extension_id' => $themeId,
                    'extension_type' => 'theme',
                    'source' => 'local_scan',
                    'market_id' => '',
                    'version' => (string) ($decoded['version'] ?? '0.0.0'),
                    'metadata_json' => '{}',
                ];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed>|null $installed */
    private function marketItemState(\Cms\Core\Market\MarketItem $item, ?array $installed): string
    {
        if (!$item->compatible) {
            return '不兼容';
        }
        if ($installed === null) {
            return '可安装';
        }
        $current = (string) ($installed['version'] ?? '0.0.0');
        if (version_compare($item->version, $current, '>')) {
            return '有更新 ' . $item->version;
        }
        return '已安装';
    }

    /** @param array<string,mixed> $versionData */
    private function marketCompatibilityText(array $versionData): string
    {
        $compatibility = is_array($versionData['compatibility'] ?? null) ? $versionData['compatibility'] : [];
        $compatible = (bool) ($compatibility['compatible'] ?? $versionData['compatible'] ?? true);
        $messages = is_array($compatibility['messages'] ?? null) ? implode('; ', array_map('strval', $compatibility['messages'])) : '';
        return $compatible ? '兼容' : '当前 CMS 版本不兼容' . ($messages !== '' ? '：' . $messages : '');
    }

    private function marketSyncAction(Request $request, bool $forceRefresh, bool $clearCache, bool $testOnly = false): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        $client = $this->marketClient();
        if ($clearCache) {
            $client->clearCache();
        }
        if ($forceRefresh || $testOnly) {
            try {
                $client->search('plugin', '', true);
                $client->search('payment_provider', '', true);
                $client->search('theme', '', true);
            } catch (Throwable $exception) {
                return Response::html(View::page('市场诊断', '<h1>市场同步失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/market/diagnostics">返回市场诊断</a></p>'), 502);
            }
        }
        $target = $testOnly || $clearCache ? '/admin/market/diagnostics' : '/admin/market/plugins?refresh=1';
        return Response::redirect($target);
    }

    /** @return array<string,mixed> */
    private function jsonArray(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function root(): string
    {
        return $this->rootPath ?? dirname(__DIR__, 3);
    }

    private function currentCoreVersion(): string
    {
        $configuredVersion = (string) $this->settings->get('app.version', '0.0.0');
        $pointer = $this->root() . '/storage/updates/current-release.json';
        if (!is_file($pointer) || !is_readable($pointer)) {
            return $configuredVersion;
        }

        $decoded = json_decode((string) file_get_contents($pointer), true);
        if (!is_array($decoded)) {
            return $configuredVersion;
        }

        $version = trim((string) ($decoded['version'] ?? ''));
        if ($version === '' || preg_match('/^[0-9][A-Za-z0-9._-]*$/', $version) !== 1) {
            return $configuredVersion;
        }

        return $version;
    }

    private function pathSegmentInt(string $path, int $index): int
    {
        $parts = explode('/', trim($path, '/'));

        return max(0, (int) ($parts[$index] ?? 0));
    }

    /** @param array<string,mixed> $data @param list<array<string,mixed>> $inventory */
    private function cardDeliveryForm(string $error = '', array $data = [], array $inventory = []): string
    {
        $id = (int) ($data['id'] ?? 0);
        $action = $id > 0 ? '/admin/card-delivery/edit/' . $id : '/admin/card-delivery';
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $status = (string) ($data['status'] ?? 'active');
        $currency = $this->cardDeliveryFormCurrency($data);
        $price = isset($data['price'])
            ? (string) $data['price']
            : $this->moneyInputValue($data['price_minor'] ?? 0, $currency);
        $statusOptions = '';
        foreach (['draft' => '草稿', 'active' => '启用', 'disabled' => '停用'] as $value => $label) {
            $statusOptions .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $inventoryRows = '';
        foreach ($inventory as $item) {
            $inventoryRows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape((string) ($item['secret_masked'] ?? '')) . '</td><td>' . View::escape((string) $item['status']) . '</td><td>' . View::escape((string) ($item['order_id'] ?? '')) . '</td><td>' . View::escape((string) ($item['delivered_at'] ?? '')) . '</td><td><form method="post" action="/admin/card-delivery/inventory/disable/' . (int) $item['id'] . '">' . CsrfToken::field() . '<input type="hidden" name="product_id" value="' . $id . '"><button class="admin-danger" type="submit">禁用</button></form></td></tr>';
        }
        $inventoryRows = $inventoryRows !== '' ? $inventoryRows : '<tr><td colspan="6" class="muted">暂无库存卡密</td></tr>';
        $importPanel = $id > 0
            ? '<section class="editor-card card-delivery-import-card"><h2>批量导入卡密</h2><p class="muted">每行一张卡密，或 CSV 第一列为卡密。</p><form method="post" action="/admin/card-delivery/inventory/' . $id . '/import">' . CsrfToken::field() . '<label>卡密内容<textarea name="secrets_text" rows="10" placeholder="CARD-0001&#10;CARD-0002"></textarea></label><button type="submit">导入库存</button></form></section>'
            : '<section class="editor-card card-delivery-import-card"><h2>批量导入卡密</h2><p class="muted">保存商品后可批量导入卡密。</p></section>';
        $inventoryPanel = '<section class="editor-card card-delivery-inventory-card"><h2>库存</h2><table><thead><tr><th>ID</th><th>卡密</th><th>状态</th><th>订单</th><th>发放时间</th><th>操作</th></tr></thead><tbody>' . $inventoryRows . '</tbody></table></section>';

        return '<div class="editor-header"><div><h1>' . ($id > 0 ? '编辑发卡商品' : '新建发卡商品') . '</h1><p class="muted">卡密库存独立存储，文章区块只引用发卡商品 ID。</p></div><div class="editor-actions"><a class="button editor-secondary" href="/admin/card-delivery">返回发卡管理</a></div></div>' . $errorHtml .
            '<div class="card-delivery-editor-shell"><section class="editor-card card-delivery-product-card"><h2>商品信息</h2><form class="card-delivery-product-form" method="post" action="' . View::escape($action) . '">' . CsrfToken::field() .
            '<label class="card-delivery-field-wide">商品名称<input name="name" value="' . View::escape((string) ($data['name'] ?? '')) . '" required></label>' .
            '<label>售价<input name="price" inputmode="decimal" value="' . View::escape($price) . '" placeholder="1.00"></label>' .
            $this->currencySelect('currency', $currency) .
            '<label>状态<select name="status">' . $statusOptions . '</select></label>' .
            '<label>每单最大购买数量<input name="max_quantity_per_order" type="number" min="1" max="999" value="' . View::escape((string) ($data['max_quantity_per_order'] ?? 1)) . '"></label>' .
            '<label>关联 Commerce 商品 ID（可选）<input name="commerce_product_id" type="number" min="0" value="' . View::escape((string) ($data['commerce_product_id'] ?? 0)) . '"></label>' .
            '<label class="card-delivery-field-wide">说明<textarea name="description" rows="6">' . View::escape((string) ($data['description'] ?? '')) . '</textarea></label>' .
            '<div class="card-delivery-form-actions"><button class="editor-primary" type="submit">保存发卡商品</button></div></form></section>' .
            '<aside class="card-delivery-side-panel"><section class="editor-card card-delivery-stats-card"><h2>商品统计</h2><div class="card-delivery-stats"><div><span>当前库存</span><strong>' . (int) ($data['available_count'] ?? 0) . '</strong></div><div><span>已售数量</span><strong>' . (int) ($data['delivered_count'] ?? 0) . '</strong></div></div></section>' . $importPanel . '</aside>' .
            $inventoryPanel . '</div>';
    }

    private function saveCardDeliveryProduct(Request $request, ?int $id): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if ($request->method !== 'POST') {
            return Response::html(View::page('发卡商品', $this->cardDeliveryForm('发卡商品保存必须通过 POST 请求提交。', ['id' => $id ?? 0])), 405)
                ->withHeaders(['Allow' => 'POST']);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('发卡商品', $this->cardDeliveryForm('CSRF 校验失败，请刷新页面重试。')), 400);
        }
        $data = [
            'id' => $id ?? 0,
            'name' => trim((string) $request->input('name', '')),
            'currency' => (string) $request->input('currency', $this->siteDefaultCurrency()),
            'status' => (string) $request->input('status', 'active'),
            'max_quantity_per_order' => (int) $request->input('max_quantity_per_order', 1),
            'commerce_product_id' => (int) $request->input('commerce_product_id', 0),
            'description' => trim((string) $request->input('description', '')),
            'price' => (string) $request->input('price', ''),
        ];

        try {
            $data['currency'] = $this->normalizeAdminCurrency((string) $data['currency']);
            $data['price_minor'] = $this->moneyInputToMinor($request, 'price', (string) $data['currency'], 'price_minor');
            $pdo = ConnectionFactory::make($this->settings);
            $savedId = (new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', '')))->saveProduct(
                $id,
                $data['name'],
                $data['price_minor'],
                $data['currency'],
                $data['status'],
                $data['max_quantity_per_order'],
                $data['commerce_product_id'] > 0 ? $data['commerce_product_id'] : null,
                $data['description']
            );
            $user = (new AdminAuthenticator($pdo))->user();
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, $id === null ? 'card_delivery.product_created' : 'card_delivery.product_updated', ['product_id' => $savedId]);
            return Response::redirect('/admin/card-delivery/edit/' . $savedId);
        } catch (Throwable $exception) {
            $this->logger->error('Card product save failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('发卡商品', $this->cardDeliveryForm('保存失败：' . $exception->getMessage(), $data)), 422);
        }
    }

    /** @param array<string, mixed> $data */
    private function contentForm(string $error = '', array $data = [], ?int $id = null): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $action = $id === null ? '/admin/content' : '/admin/content/edit/' . $id;
        $blocks = $data['blocks'] ?? [['type' => 'paragraph', 'data' => ['text' => '']]];
        $blockHtml = '';
        $drawerHtml = '';
        foreach (array_values(is_array($blocks) ? $blocks : []) as $i => $block) {
            $type = (string) ($block['type'] ?? 'paragraph');
            $typeLabel = AdminUiText::blockType($type);
            $icon = $this->blockTypeIcon($type);
            $drawerHtml .= '<button type="button" class="editor-block-list-item" data-editor-block-jump="' . $i . '"><span class="block-card-type-icon" aria-hidden="true">' . View::escape($icon) . '</span><span>区块 ' . ($i + 1) . '</span><strong>' . View::escape($typeLabel) . '</strong></button>';
            $blockHtml .= '<fieldset class="block-card editor-block-card" data-editor-block="' . $i . '"><legend>区块 ' . ($i + 1) . '</legend><div class="block-card-header editor-block-card-header"><div class="editor-block-heading"><span class="block-card-type-icon" aria-hidden="true">' . View::escape($icon) . '</span><div><span class="block-card-title">' . View::escape($typeLabel) . '</span><p class="muted">区块 ' . ($i + 1) . '，内容会按当前格式安全保存。</p></div></div><div class="block-card-actions editor-block-actions">' .
                '<button name="block_action" value="up:' . $i . '" type="submit">上移</button>' .
                '<button name="block_action" value="down:' . $i . '" type="submit">下移</button>' .
                '<button name="block_action" value="copy:' . $i . '" type="submit">复制</button>' .
                '<button class="editor-danger" name="block_action" value="delete:' . $i . '" type="submit">删除</button></div></div>' .
                '<label class="editor-block-type">区块类型<select name="blocks[' . $i . '][type]" data-block-type-select>' . $this->blockOptions($type) . '</select></label>' .
                '<div class="block-editor-fields" data-block-editor-fields data-block-index="' . $i . '">' .
                $this->blockFields($i, $type, is_array($block['data'] ?? null) ? $block['data'] : []) .
                '</div></fieldset>';
        }
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $heading = $id === null ? '新建内容' : '编辑内容';
        $actions = '<a class="button editor-secondary" href="/admin/content">返回内容管理</a>';
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($id !== null && $slug !== '') {
            $path = (($data['type'] ?? 'article') === 'page' ? '/' : '/articles/') . rawurlencode($slug);
            $actions = '<a class="button editor-secondary" href="/admin/content">返回内容管理</a><a class="button editor-secondary" href="' . View::escape($path) . '">查看前台</a>';
        }

        $currentStatus = (string) ($data['status'] ?? 'draft');
        $publishLabel = $id === null ? '发布' : '更新发布';
        $previewToken = trim((string) ($meta['preview_token'] ?? ''));
        $previewAction = $id !== null && $previewToken !== ''
            ? '<a class="button editor-secondary" href="/preview/' . $id . '?token=' . rawurlencode($previewToken) . '" target="_blank" rel="noopener">预览</a>'
            : '<button class="editor-secondary" type="submit" name="content_action" value="save">保存后预览</button>';
        $paidContentCurrency = $this->contentMetaCurrency($meta, 'paid_content_currency');
        $paidContentPrice = isset($data['paid_content_price'])
            ? (string) $data['paid_content_price']
            : $this->moneyInputValue($meta['paid_content_price_minor'] ?? 0, $paidContentCurrency);
        $scheduledAt = trim((string) ($meta['scheduled_at'] ?? ''));
        $scheduledDate = '';
        $scheduledTime = '';
        if ($scheduledAt !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $scheduledAt, $matches) === 1) {
            $scheduledDate = $matches[1];
            $scheduledTime = $matches[2];
        }
        $isScheduled = $scheduledAt !== '';
        $paidEnabled = (bool) ($meta['paid_content_enabled'] ?? false);
        $contentType = (string) ($data['type'] ?? 'article');
        $blockCount = count(array_values(is_array($blocks) ? $blocks : []));
        $categorySelector = $this->contentCategorySelector($data['categories'] ?? []);

        return '<div class="editor-header content-editor-page-header"><div><h1>' . $heading . '</h1><p class="muted">专注写正文，发布、分类、付费和 SEO 设置放在右侧。</p></div><div class="editor-actions">' . $actions . '</div></div>' . $errorHtml .
            '<form class="content-editor-form" method="post" action="' . View::escape($action) . '">' . CsrfToken::field() .
            '<div class="content-editor-toolbar"><div><span class="editor-kicker">' . View::escape($id === null ? '新稿件' : ('内容 #' . $id)) . '</span><strong>' . View::escape(AdminUiText::contentStatus($currentStatus)) . '</strong></div><div class="editor-actions"><button class="editor-secondary" type="submit" name="content_action" value="draft">保存草稿</button>' . $previewAction . '<button class="editor-primary" type="submit" name="content_action" value="publish">' . $publishLabel . '</button></div></div>' .
            '<div class="content-editor-workbench"><main class="content-editor-main"><section class="editor-title-panel">' .
            '<label class="editor-title-label" for="content-title">标题</label><input id="content-title" class="editor-title-input" name="title" value="' . View::escape((string) ($data['title'] ?? '')) . '" placeholder="请输入标题" required>' .
            '<label class="editor-slug-row"><span>固定链接</span><input name="slug" value="' . View::escape($slug) . '" placeholder="留空则自动生成"></label>' .
            '</section><section class="editor-card editor-blocks-panel"><div class="editor-section-heading"><div><h2>内容区块</h2><p class="muted">共 ' . $blockCount . ' 个区块，可添加、复制、移动或删除。</p></div><div class="editor-actions"><button type="button" class="editor-secondary" data-editor-block-list-toggle>区块列表</button><button class="editor-primary" name="block_action" value="add" type="submit">添加区块</button></div></div>' . $blockHtml . '</section></main>' .
            '<aside class="content-editor-sidebar">' .
            '<details class="editor-settings-section" open><summary>发布</summary><div class="editor-settings-body"><p class="muted">当前状态：' . View::escape(AdminUiText::contentStatus($currentStatus)) . '</p>' .
            '<label>状态<select name="status">' . $this->statusOptions($currentStatus) . '</select></label>' .
            '<div class="editor-radio-group"><label><input type="radio" name="schedule_mode" value="immediate"' . (!$isScheduled ? ' checked' : '') . ' data-editor-schedule-toggle> 立即发布</label><label><input type="radio" name="schedule_mode" value="scheduled"' . ($isScheduled ? ' checked' : '') . ' data-editor-schedule-toggle> 定时发布</label></div>' .
            '<div class="editor-schedule-fields" data-editor-schedule-fields' . (!$isScheduled ? ' hidden' : '') . '><input type="hidden" name="scheduled_at" value="' . View::escape($scheduledAt) . '"><label>日期<input name="scheduled_date" type="date" value="' . View::escape($scheduledDate) . '"></label><label>时间<input name="scheduled_time" type="time" value="' . View::escape($scheduledTime) . '"></label></div>' .
            '<div class="editor-sidebar-actions"><button class="editor-secondary" type="submit" name="content_action" value="draft">保存草稿</button><button class="editor-primary" type="submit" name="content_action" value="publish">' . $publishLabel . '</button></div></div></details>' .
            '<details class="editor-settings-section" open><summary>内容</summary><div class="editor-settings-body"><label>内容类型<select name="content_type"><option value="article"' . ($contentType === 'article' ? ' selected' : '') . '>文章</option><option value="page"' . ($contentType === 'page' ? ' selected' : '') . '>页面</option></select></label></div></details>' .
            '<details class="editor-settings-section"><summary>分类标签</summary><div class="editor-settings-body">' . $categorySelector .
            '<label>快速新增分类<input name="categories" value="' . View::escape(implode(', ', $this->manualCategoryNames($data['categories'] ?? []))) . '" placeholder="多个分类用逗号分隔"></label>' .
            '<label>标签（逗号分隔）<input name="tags" value="' . View::escape(implode(', ', $data['tags'] ?? [])) . '"></label></div></details>' .
            '<details class="editor-settings-section"><summary>付费内容</summary><div class="editor-settings-body">' .
            '<label><input type="checkbox" name="paid_content_enabled" value="1"' . ($paidEnabled ? ' checked' : '') . ' data-editor-paid-toggle> 启用 Core 付费解锁</label><div data-editor-paid-fields' . (!$paidEnabled ? ' hidden' : '') . '>' .
            '<label>价格<input name="paid_content_price" inputmode="decimal" value="' . View::escape($paidContentPrice) . '" placeholder="1.00"></label>' .
            $this->currencySelect('paid_content_currency', $paidContentCurrency) .
            '<label>按钮文字<input name="paid_content_label" value="' . View::escape((string) ($meta['paid_content_label'] ?? '解锁全文')) . '"></label>' .
            '<label>试看区块数<input name="paid_content_preview_blocks" type="number" min="0" max="100" value="' . View::escape((string) ($meta['paid_content_preview_blocks'] ?? 1)) . '"></label></div></div></details>' .
            '<details class="editor-settings-section"><summary>SEO</summary><div class="editor-settings-body">' .
            '<label>SEO 标题<input name="seo_title" value="' . View::escape((string) ($meta['seo_title'] ?? '')) . '"></label>' .
            '<label>SEO 描述<textarea name="seo_description" rows="3">' . View::escape((string) ($meta['seo_description'] ?? '')) . '</textarea></label>' .
            '<label>规范链接<input name="canonical_url" value="' . View::escape((string) ($meta['canonical_url'] ?? '')) . '"></label>' .
            '<label><input type="checkbox" name="robots_index" value="1"' . (($meta['robots_index'] ?? true) ? ' checked' : '') . '> 允许搜索引擎索引</label>' .
            '<label><input type="checkbox" name="robots_follow" value="1"' . (($meta['robots_follow'] ?? true) ? ' checked' : '') . '> 允许搜索引擎跟踪链接</label></div></details>' .
            '<details class="editor-settings-section"><summary>高级设置</summary><div class="editor-settings-body"><p class="muted">保留给后续扩展字段，当前内容不会受影响。</p></div></details></aside></div>' .
            '<div class="editor-block-drawer" data-editor-block-drawer hidden><button type="button" class="editor-drawer-backdrop" data-editor-block-drawer-backdrop aria-label="关闭区块列表"></button><div class="editor-block-drawer-panel"><div class="editor-drawer-header"><strong>区块列表</strong><button type="button" data-editor-block-list-close>关闭</button></div><div class="editor-block-list">' . $drawerHtml . '</div></div></div></form>' .
            $this->mediaPickerComponent() . $this->blockEditorRendererScript();
    }

    /** @return array<string, mixed> */
    private function contentInput(Request $request): array
    {
        $blocks = $this->parseBlocks($request->input('blocks', []));
        $action = (string) $request->input('block_action', '');
        if ($action !== '') {
            $blocks = $this->applyBlockAction($blocks, $action);
        }

        $contentAction = (string) $request->input('content_action', 'save');
        $status = (string) $request->input('status', 'draft');
        if ($contentAction === 'draft') {
            $status = 'draft';
        } elseif ($contentAction === 'publish') {
            $status = 'published';
        }

        $paidContentCurrency = $this->normalizeAdminCurrency((string) $request->input('paid_content_currency', $this->siteDefaultCurrency()));
        $paidContentPriceMinor = $this->moneyInputToMinor($request, 'paid_content_price', $paidContentCurrency, 'paid_content_price_minor');
        $scheduledAt = trim((string) $request->input('scheduled_at', ''));
        $scheduleMode = (string) $request->input('schedule_mode', $scheduledAt !== '' ? 'scheduled' : 'immediate');
        if ($scheduleMode === 'scheduled') {
            $scheduledDate = trim((string) $request->input('scheduled_date', ''));
            $scheduledTime = trim((string) $request->input('scheduled_time', ''));
            if ($scheduledDate !== '') {
                $scheduledAt = $scheduledDate . 'T' . ($scheduledTime !== '' ? $scheduledTime : '00:00') . ':00+00:00';
            }
        } else {
            $scheduledAt = '';
        }

        return [
            'action' => $action === '' && in_array($contentAction, ['save', 'draft', 'publish'], true) ? 'save' : 'edit',
            'type' => (string) $request->input('content_type', 'article'),
            'title' => trim((string) $request->input('title', '')),
            'slug' => trim((string) $request->input('slug', '')),
            'status' => $status,
            'blocks' => $blocks,
            'categories' => array_values(array_unique(array_merge(
                $this->categoryNamesFromIds($request->input('category_ids', [])),
                $this->csvList((string) $request->input('categories', ''))
            ))),
            'tags' => $this->csvList((string) $request->input('tags', '')),
            'meta' => [
                'seo_title' => trim((string) $request->input('seo_title', '')),
                'seo_description' => trim((string) $request->input('seo_description', '')),
                'canonical_url' => trim((string) $request->input('canonical_url', '')),
                'robots_index' => (string) $request->input('robots_index', '') === '1',
                'robots_follow' => (string) $request->input('robots_follow', '') === '1',
                'scheduled_at' => $scheduledAt,
                'paid_content_enabled' => (string) $request->input('paid_content_enabled', '') === '1',
                'paid_content_price_minor' => (string) $paidContentPriceMinor,
                'paid_content_currency' => $paidContentCurrency,
                'paid_content_label' => trim((string) $request->input('paid_content_label', '解锁全文')),
                'paid_content_preview_blocks' => trim((string) $request->input('paid_content_preview_blocks', '1')),
            ],
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function contentFormData(array $item): array
    {
        $terms = [];
        try {
            $repo = new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
            $terms = $repo->termsForContent((int) $item['id']);
        } catch (Throwable) {
            $terms = [];
        }

        return [
            'type' => (string) $item['content_type'],
            'title' => (string) $item['title'],
            'slug' => (string) $item['slug'],
            'status' => (string) $item['status'],
            'blocks' => $item['blocks'] ?? [],
            'meta' => $item['meta'] ?? [],
            'categories' => array_values(array_map(static fn (array $term): string => (string) $term['name'], array_filter($terms, static fn (array $term): bool => $term['taxonomy'] === 'category'))),
            'tags' => array_values(array_map(static fn (array $term): string => (string) $term['name'], array_filter($terms, static fn (array $term): bool => $term['taxonomy'] === 'tag'))),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function parseBlocks(mixed $input): array
    {
        if (!is_array($input)) {
            return [['type' => 'paragraph', 'data' => ['text' => '']]];
        }
        $blocks = [];
        foreach ($input as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if (isset($data['items_text'])) {
                $data['items'] = preg_split('/\R/', (string) $data['items_text']) ?: [];
            }
            if (isset($data['rows_text'])) {
                $rows = [];
                foreach (preg_split('/\R/', (string) $data['rows_text']) ?: [] as $line) {
                    $rows[] = array_map('trim', explode('|', $line));
                }
                $data['rows'] = $rows;
            }
            if (isset($data['media_ids_text'])) {
                $data['media_ids'] = array_values(array_filter(array_map(static fn (string $id): int => (int) trim($id), preg_split('/[,\s]+/', (string) $data['media_ids_text']) ?: []), static fn (int $id): bool => $id > 0));
            }
            if ($type === 'gallery' && isset($data['gallery_captions_text'])) {
                $items = [];
                foreach (preg_split('/\R/', (string) $data['gallery_captions_text']) ?: [] as $line) {
                    [$mediaId, $caption] = array_pad(preg_split('/\s*\|\s*/', trim($line), 2) ?: [], 2, '');
                    $mediaId = (int) $mediaId;
                    if ($mediaId > 0) {
                        $items[] = ['media_id' => $mediaId, 'caption' => (string) $caption, 'alt' => ''];
                    }
                }
                $data['items'] = $items;
            }
            if ($type === 'card_delivery') {
                foreach (['show_name', 'show_price', 'show_stock', 'show_button'] as $flag) {
                    $data[$flag] = array_key_exists($flag, $data) && (string) $data[$flag] === '1';
                }
            }
            if ($type === 'tip') {
                if (isset($data['amounts_text'])) {
                    $data['amounts'] = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string) $data['amounts_text']) ?: []), static fn (string $amount): bool => $amount !== ''));
                    unset($data['amounts_text']);
                }
                $data['custom_amount'] = array_key_exists('custom_amount', $data) && (string) $data['custom_amount'] === '1';
            }
            if ($type === 'attachment') {
                $currency = $this->contentMetaCurrency($data, 'currency');
                $data['currency'] = $currency;
                $amount = array_key_exists('price', $data) ? (string) $data['price'] : null;
                if ($amount !== null) {
                    try {
                        $data['price_minor'] = (string) Money::toMinor($amount, $currency);
                    } catch (\InvalidArgumentException $exception) {
                        throw new \InvalidArgumentException('付费下载金额格式无效：' . $exception->getMessage());
                    }
                    unset($data['price']);
                } elseif (!isset($data['price_minor']) || !is_string($data['price_minor']) || preg_match('/^(0|[1-9][0-9]{0,17})$/', $data['price_minor']) !== 1) {
                    $data['price_minor'] = '0';
                }
            }
            $blocks[] = ['type' => $type, 'data' => $data];
        }

        return $blocks !== [] ? $blocks : [['type' => 'paragraph', 'data' => ['text' => '']]];
    }

    /** @param list<array<string, mixed>> $blocks @return list<array<string, mixed>> */
    private function applyBlockAction(array $blocks, string $action): array
    {
        if ($action === 'add') {
            $blocks[] = ['type' => 'paragraph', 'data' => ['text' => '']];
            return $blocks;
        }
        [$verb, $index] = array_pad(explode(':', $action, 2), 2, '0');
        $i = (int) $index;
        if (!isset($blocks[$i])) {
            return $blocks;
        }
        if ($verb === 'delete' && count($blocks) > 1) {
            array_splice($blocks, $i, 1);
        } elseif ($verb === 'copy') {
            array_splice($blocks, $i + 1, 0, [$blocks[$i]]);
        } elseif ($verb === 'up' && $i > 0) {
            [$blocks[$i - 1], $blocks[$i]] = [$blocks[$i], $blocks[$i - 1]];
        } elseif ($verb === 'down' && $i < count($blocks) - 1) {
            [$blocks[$i + 1], $blocks[$i]] = [$blocks[$i], $blocks[$i + 1]];
        }

        return array_values($blocks);
    }

    private function blockOptions(string $selected): string
    {
        $types = ['paragraph', 'heading', 'unordered_list', 'ordered_list', 'quote', 'code', 'divider', 'button', 'table', 'html', 'raw_text', 'image', 'gallery', 'video', 'audio', 'attachment', 'card_delivery', 'tip'];
        return implode('', array_map(fn (string $type): string => '<option value="' . View::escape($type) . '"' . ($selected === $type ? ' selected' : '') . '>' . View::escape(AdminUiText::blockType($type)) . '</option>', $types));
    }

    private function blockTypeIcon(string $type): string
    {
        return match ($type) {
            'heading' => 'H',
            'unordered_list', 'ordered_list' => '≡',
            'quote' => '"',
            'code' => '{}',
            'divider' => '-',
            'button' => '↗',
            'table' => '▦',
            'html' => '<>',
            'raw_text' => 'T',
            'image', 'gallery' => '▧',
            'video' => '▶',
            'audio' => '♪',
            'attachment' => '⌁',
            'card_delivery' => '▤',
            'tip' => '¥',
            default => '¶',
        };
    }

    /** @param array<string, mixed> $data */
    private function blockFields(int $i, string $type, array $data): string
    {
        return match ($type) {
            'paragraph' => '<label>正文<textarea name="blocks[' . $i . '][data][text]" rows="5">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label><label>正文样式<select name="blocks[' . $i . '][data][style]"><option value="body">正文</option><option value="lead"' . (($data['style'] ?? '') === 'lead' ? ' selected' : '') . '>导语</option><option value="small"' . (($data['style'] ?? '') === 'small' ? ' selected' : '') . '>小号正文</option></select></label><input type="hidden" name="blocks[' . $i . '][data][bold]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][bold]" value="1"' . (($data['bold'] ?? false) ? ' checked' : '') . '> 粗体</label><input type="hidden" name="blocks[' . $i . '][data][italic]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][italic]" value="1"' . (($data['italic'] ?? false) ? ' checked' : '') . '> 斜体</label><label>对齐<select name="blocks[' . $i . '][data][alignment]"><option value="left">左对齐</option><option value="center"' . (($data['alignment'] ?? '') === 'center' ? ' selected' : '') . '>居中</option><option value="right"' . (($data['alignment'] ?? '') === 'right' ? ' selected' : '') . '>右对齐</option></select></label><label>链接<input name="blocks[' . $i . '][data][link]" value="' . View::escape((string) ($data['link'] ?? '')) . '" placeholder="https://example.com"></label>',
            'heading' => '<label>级别<select name="blocks[' . $i . '][data][level]">' . implode('', array_map(fn (int $level): string => '<option value="' . $level . '"' . ((int) ($data['level'] ?? 2) === $level ? ' selected' : '') . '>H' . $level . '</option>', range(1, 6))) . '</select></label><label>标题内容<input name="blocks[' . $i . '][data][text]" value="' . View::escape((string) ($data['text'] ?? '')) . '"></label><label>对齐方式<select name="blocks[' . $i . '][data][alignment]"><option value="left">左对齐</option><option value="center"' . (($data['alignment'] ?? '') === 'center' ? ' selected' : '') . '>居中</option><option value="right"' . (($data['alignment'] ?? '') === 'right' ? ' selected' : '') . '>右对齐</option></select></label>',
            'unordered_list', 'ordered_list' => $this->listBlockEditor($i, is_array($data['items'] ?? null) ? $data['items'] : []),
            'quote' => '<label>引用<textarea name="blocks[' . $i . '][data][text]" rows="3">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label><label>来源<input name="blocks[' . $i . '][data][cite]" value="' . View::escape((string) ($data['cite'] ?? '')) . '"></label>',
            'code' => '<label>语言<input name="blocks[' . $i . '][data][language]" value="' . View::escape((string) ($data['language'] ?? '')) . '"></label><label>代码<textarea name="blocks[' . $i . '][data][code]" rows="5">' . View::escape((string) ($data['code'] ?? '')) . '</textarea></label>',
            'button' => '<label>按钮文字<input name="blocks[' . $i . '][data][text]" value="' . View::escape((string) ($data['text'] ?? '')) . '"></label><label>URL<input name="blocks[' . $i . '][data][url]" value="' . View::escape((string) ($data['url'] ?? '')) . '"></label><label>打开方式<select name="blocks[' . $i . '][data][target]"><option value="_self">当前窗口</option><option value="_blank"' . (($data['target'] ?? '') === '_blank' ? ' selected' : '') . '>新窗口</option></select></label><label>样式<select name="blocks[' . $i . '][data][style]"><option value="primary">主按钮</option><option value="secondary"' . (($data['style'] ?? '') === 'secondary' ? ' selected' : '') . '>次按钮</option><option value="outline"' . (($data['style'] ?? '') === 'outline' ? ' selected' : '') . '>描边</option></select></label><label>对齐方式<select name="blocks[' . $i . '][data][alignment]"><option value="left">左对齐</option><option value="center"' . (($data['alignment'] ?? '') === 'center' ? ' selected' : '') . '>居中</option><option value="right"' . (($data['alignment'] ?? '') === 'right' ? ' selected' : '') . '>右对齐</option></select></label>',
            'table' => $this->tableBlockEditor($i, is_array($data['rows'] ?? null) ? $data['rows'] : []),
            'html' => '<label>HTML 正文<textarea name="blocks[' . $i . '][data][html]" rows="14" spellcheck="false">' . View::escape((string) ($data['html'] ?? '')) . '</textarea></label><p class="muted">适合编辑从旧站迁移来的正文；保存时会自动清理 script、事件属性和危险链接。</p>',
            'divider' => '<p class="muted">分隔线无正文内容。</p><label>分隔线样式<select name="blocks[' . $i . '][data][style]"><option value="solid">实线</option><option value="dashed"' . (($data['style'] ?? '') === 'dashed' ? ' selected' : '') . '>虚线</option><option value="wide"' . (($data['style'] ?? '') === 'wide' ? ' selected' : '') . '>宽间距</option></select></label><label>上下间距<select name="blocks[' . $i . '][data][spacing]"><option value="normal">标准</option><option value="compact"' . (($data['spacing'] ?? '') === 'compact' ? ' selected' : '') . '>紧凑</option><option value="large"' . (($data['spacing'] ?? '') === 'large' ? ' selected' : '') . '>宽松</option></select></label>',
            'raw_text' => '<label>纯文本<textarea name="blocks[' . $i . '][data][text]" rows="4">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label>',
            'image' => $this->mediaBlockPicker($i, 'media_id', 'image', false, (array) $data) . '<label>替代文字<input name="blocks[' . $i . '][data][alt]" value="' . View::escape((string) ($data['alt'] ?? '')) . '"></label><label>说明文字<input name="blocks[' . $i . '][data][caption]" value="' . View::escape((string) ($data['caption'] ?? '')) . '"></label><label>显示宽度（px）<input name="blocks[' . $i . '][data][width]" type="number" min="0" max="4000" value="' . View::escape((string) ($data['width'] ?? 0)) . '"></label><label>对齐<select name="blocks[' . $i . '][data][alignment]"><option value="none">无</option><option value="left"' . (($data['alignment'] ?? '') === 'left' ? ' selected' : '') . '>左</option><option value="center"' . (($data['alignment'] ?? '') === 'center' ? ' selected' : '') . '>中</option><option value="right"' . (($data['alignment'] ?? '') === 'right' ? ' selected' : '') . '>右</option></select></label><label>链接<input name="blocks[' . $i . '][data][link]" value="' . View::escape((string) ($data['link'] ?? '')) . '"></label>',
            'gallery' => $this->galleryBlockEditor($i, $data),
            'audio' => $this->mediaBlockPicker($i, 'media_id', 'audio', false, (array) $data) . '<label>标题<input name="blocks[' . $i . '][data][title]" value="' . View::escape((string) ($data['title'] ?? '')) . '"></label><input type="hidden" name="blocks[' . $i . '][data][controls]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][controls]" value="1"' . (($data['controls'] ?? true) ? ' checked' : '') . '> 显示播放器控制条</label><label>预加载<select name="blocks[' . $i . '][data][preload]"><option value="metadata">仅元数据</option><option value="none"' . (($data['preload'] ?? '') === 'none' ? ' selected' : '') . '>不预加载</option><option value="auto"' . (($data['preload'] ?? '') === 'auto' ? ' selected' : '') . '>自动</option></select></label>',
            'video' => $this->mediaBlockPicker($i, 'media_id', 'video', false, (array) $data) . $this->mediaBlockPicker($i, 'poster_media_id', 'image', false, (array) $data, '选择封面图片') . '<label>外链视频地址<input name="blocks[' . $i . '][data][source_url]" value="' . View::escape((string) ($data['source_url'] ?? '')) . '" placeholder="https://example.com/video.mp4"></label><input type="hidden" name="blocks[' . $i . '][data][controls]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][controls]" value="1"' . (($data['controls'] ?? true) ? ' checked' : '') . '> 显示播放器控制条</label><input type="hidden" name="blocks[' . $i . '][data][autoplay]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][autoplay]" value="1"' . (($data['autoplay'] ?? false) ? ' checked' : '') . '> 自动播放（将自动静音）</label><input type="hidden" name="blocks[' . $i . '][data][muted]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][muted]" value="1"' . (($data['muted'] ?? false) ? ' checked' : '') . '> 静音</label><input type="hidden" name="blocks[' . $i . '][data][loop]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][loop]" value="1"' . (($data['loop'] ?? false) ? ' checked' : '') . '> 循环播放</label><input type="hidden" name="blocks[' . $i . '][data][playsinline]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][playsinline]" value="1"' . (($data['playsinline'] ?? true) ? ' checked' : '') . '> 移动端内联播放</label><label>预加载<select name="blocks[' . $i . '][data][preload]"><option value="metadata">仅元数据</option><option value="none"' . (($data['preload'] ?? '') === 'none' ? ' selected' : '') . '>不预加载</option><option value="auto"' . (($data['preload'] ?? '') === 'auto' ? ' selected' : '') . '>自动</option></select></label>',
            'attachment' => $this->mediaBlockPicker($i, 'media_id', 'attachment', false, (array) $data) . '<label>显示名称<input name="blocks[' . $i . '][data][display_name]" value="' . View::escape((string) ($data['display_name'] ?? '')) . '"></label><label><input type="checkbox" name="blocks[' . $i . '][data][paid_enabled]" value="1"' . (($data['paid_enabled'] ?? false) ? ' checked' : '') . '> 设为付费下载</label><label>价格<input name="blocks[' . $i . '][data][price]" inputmode="decimal" value="' . View::escape($this->moneyInputValue($data['price_minor'] ?? 0, $this->contentMetaCurrency($data, 'currency'))) . '" placeholder="1.00"></label>' . $this->currencySelect('blocks[' . $i . '][data][currency]', $this->contentMetaCurrency($data, 'currency')) . '<label>按钮文字<input name="blocks[' . $i . '][data][payment_label]" value="' . View::escape((string) ($data['payment_label'] ?? '解锁下载')) . '"></label>',
            'card_delivery' => $this->cardDeliveryBlockFields($i, $data),
            'tip' => $this->tipBlockFields($i, $data),
            default => '<label>内容<textarea name="blocks[' . $i . '][data][text]" rows="4">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label>',
        };
    }

    /** @param array<string,mixed> $data */
    private function tipBlockFields(int $i, array $data): string
    {
        $amounts = is_array($data['amounts'] ?? null) ? implode(', ', array_map('strval', $data['amounts'])) : '1, 5, 10';

        return '<label>标题<input name="blocks[' . $i . '][data][title]" value="' . View::escape((string) ($data['title'] ?? '喜欢这篇内容？')) . '"></label>' .
            '<label>说明<textarea name="blocks[' . $i . '][data][description]" rows="3">' . View::escape((string) ($data['description'] ?? '可以打赏支持作者继续创作。')) . '</textarea></label>' .
            '<label>预设金额<input name="blocks[' . $i . '][data][amounts_text]" value="' . View::escape($amounts) . '" placeholder="1, 5, 10"></label>' .
            $this->currencySelect('blocks[' . $i . '][data][currency]', $this->contentMetaCurrency($data, 'currency')) .
            '<input type="hidden" name="blocks[' . $i . '][data][custom_amount]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][custom_amount]" value="1"' . (($data['custom_amount'] ?? true) ? ' checked' : '') . '> 允许用户填写自定义金额</label>' .
            '<label>按钮文字<input name="blocks[' . $i . '][data][button_text]" value="' . View::escape((string) ($data['button_text'] ?? '打赏支持')) . '"></label><p class="muted">前台会使用已启用的支付方式创建打赏支付；金额请填写正常价格，不要填写最小货币单位。</p>';
    }

    /** @param list<mixed> $items */
    private function listBlockEditor(int $i, array $items): string
    {
        $items = $items !== [] ? $items : [''];
        $rows = '';
        foreach (array_values($items) as $item) {
            $rows .= '<div class="block-list-item"><input data-list-item value="' . View::escape((string) $item) . '"><button type="button" data-list-move="up">上移</button><button type="button" data-list-move="down">下移</button><button type="button" data-list-remove>删除</button></div>';
        }

        return '<div class="block-list-editor" data-list-editor><input type="hidden" name="blocks[' . $i . '][data][items_text]" data-list-output value="' . View::escape(implode("\n", array_map('strval', $items))) . '"><div data-list-items>' . $rows . '</div><button type="button" data-list-add>添加列表项</button></div>';
    }

    /** @param list<mixed> $rows */
    private function tableBlockEditor(int $i, array $rows): string
    {
        $rows = $rows !== [] ? $rows : [['', '']];
        $htmlRows = '';
        foreach (array_values($rows) as $row) {
            $cells = '';
            foreach (array_values(is_array($row) ? $row : []) as $cell) {
                $cells .= '<td><input data-table-cell value="' . View::escape((string) $cell) . '"></td>';
            }
            if ($cells === '') {
                $cells = '<td><input data-table-cell></td><td><input data-table-cell></td>';
            }
            $htmlRows .= '<tr>' . $cells . '<td><button type="button" data-table-row-remove>删行</button></td></tr>';
        }
        $text = implode("\n", array_map(static fn (mixed $row): string => implode(' | ', array_map('strval', is_array($row) ? $row : [])), $rows));

        return '<div class="block-table-editor" data-table-editor><input type="hidden" name="blocks[' . $i . '][data][rows_text]" data-table-output value="' . View::escape($text) . '"><table><tbody data-table-body>' . $htmlRows . '</tbody></table><button type="button" data-table-add-row>增加行</button> <button type="button" data-table-add-col>增加列</button> <button type="button" data-table-remove-col>删除最后一列</button></div>';
    }

    /** @param array<string,mixed> $data */
    private function galleryBlockEditor(int $i, array $data): string
    {
        $ids = is_array($data['media_ids'] ?? null) ? array_values(array_filter(array_map('intval', $data['media_ids']))) : [];
        $captions = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaId = (int) ($item['media_id'] ?? 0);
            if ($mediaId > 0) {
                $captions[$mediaId] = (string) ($item['caption'] ?? '');
            }
        }
        $captionRows = '';
        $captionLines = [];
        foreach ($ids as $mediaId) {
            $caption = $captions[$mediaId] ?? '';
            $captionRows .= '<div class="gallery-caption-item" data-gallery-caption-row="' . $mediaId . '"><span>媒体 #' . $mediaId . '</span><input data-gallery-caption="' . $mediaId . '" value="' . View::escape($caption) . '" placeholder="单张图片 Caption"></div>';
            $captionLines[] = $mediaId . ' | ' . $caption;
        }

        return '<div class="block-gallery-editor" data-gallery-editor>' .
            $this->mediaBlockPicker($i, 'media_ids_text', 'image', true, $data, '选择多张图片') .
            '<label>列数<input name="blocks[' . $i . '][data][columns]" type="number" min="1" max="6" value="' . View::escape((string) ($data['columns'] ?? 3)) . '"></label>' .
            '<label>图库说明<input name="blocks[' . $i . '][data][caption]" value="' . View::escape((string) ($data['caption'] ?? '')) . '"></label>' .
            '<input type="hidden" name="blocks[' . $i . '][data][gallery_captions_text]" data-gallery-caption-output value="' . View::escape(implode("\n", $captionLines)) . '">' .
            '<div class="gallery-caption-editor"><strong>单图 Caption</strong><div data-gallery-caption-items>' . ($captionRows !== '' ? $captionRows : '<p class="muted">选择图片后可为每张图填写 Caption。</p>') . '</div></div>' .
            '</div>';
    }

    /** @param array<string,mixed> $data */
    private function cardDeliveryBlockFields(int $i, array $data): string
    {
        $products = $this->cardDeliveryProducts();
        $selected = (int) ($data['card_product_id'] ?? 0);
        $options = '<option value="0">请选择发卡商品</option>';
        $selectedProduct = null;
        foreach ($products as $product) {
            if ($selected === (int) $product['id']) {
                $selectedProduct = $product;
            }
            $label = '#' . (int) $product['id'] . ' ' . (string) $product['name'] . ' · ' . $this->moneyLabel($product['price_minor'] ?? 0, (string) ($product['currency'] ?? 'USD')) . ' · 库存 ' . (int) ($product['available_count'] ?? 0) . ' · 状态 ' . (string) ($product['status'] ?? '') . ' · 每单最多 ' . (int) ($product['max_quantity_per_order'] ?? 1);
            $options .= '<option value="' . (int) $product['id'] . '"' . ($selected === (int) $product['id'] ? ' selected' : '') . '>' . View::escape($label) . '</option>';
        }

        return '<label>发卡商品<select name="blocks[' . $i . '][data][card_product_id]" data-card-product-select>' . $options . '</select></label>' .
            '<div class="card-product-summary" data-card-product-summary>' . $this->cardDeliveryProductSummary($selectedProduct) . '</div>' .
            '<p><a class="button editor-secondary" href="/admin/card-delivery/new">新建发卡商品</a></p>' .
            '<fieldset><legend>前台展示设置</legend>' .
            '<input type="hidden" name="blocks[' . $i . '][data][show_name]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][show_name]" value="1"' . (($data['show_name'] ?? true) ? ' checked' : '') . '> 显示商品名称</label>' .
            '<input type="hidden" name="blocks[' . $i . '][data][show_price]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][show_price]" value="1"' . (($data['show_price'] ?? true) ? ' checked' : '') . '> 显示价格</label>' .
            '<input type="hidden" name="blocks[' . $i . '][data][show_stock]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][show_stock]" value="1"' . (($data['show_stock'] ?? true) ? ' checked' : '') . '> 显示库存</label>' .
            '<input type="hidden" name="blocks[' . $i . '][data][show_button]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][show_button]" value="1"' . (($data['show_button'] ?? true) ? ' checked' : '') . '> 显示购买按钮</label>' .
            '<label>按钮文字<input name="blocks[' . $i . '][data][button_text]" value="' . View::escape((string) ($data['button_text'] ?? '立即购买')) . '"></label></fieldset>';
    }

    /** @param array<string,mixed>|null $product */
    private function cardDeliveryProductSummary(?array $product): string
    {
        if ($product === null) {
            return '<p class="muted">选择发卡商品后显示商品名称、售价、库存、状态和每单上限。</p>';
        }

        return '<dl class="card-product-facts">' .
            '<dt>商品名称</dt><dd>' . View::escape((string) ($product['name'] ?? '')) . '</dd>' .
            '<dt>售价</dt><dd>' . View::escape($this->moneyLabel($product['price_minor'] ?? 0, (string) ($product['currency'] ?? 'USD'))) . '</dd>' .
            '<dt>当前库存</dt><dd>' . (int) ($product['available_count'] ?? 0) . '</dd>' .
            '<dt>商品状态</dt><dd>' . View::escape((string) ($product['status'] ?? '')) . '</dd>' .
            '<dt>每单最大购买数量</dt><dd>' . (int) ($product['max_quantity_per_order'] ?? 1) . '</dd>' .
            '</dl>';
    }

    /** @return list<array<string,mixed>> */
    private function cardDeliveryProducts(): array
    {
        try {
            return (new CardDeliveryRepository(ConnectionFactory::make($this->settings), (string) $this->settings->get('security.encryption_key', '')))->products();
        } catch (Throwable) {
            return [];
        }
    }

    private function blockEditorRendererScript(): string
    {
        $products = [];
        foreach ($this->cardDeliveryProducts() as $product) {
            $products[] = [
                'id' => (int) $product['id'],
                'name' => (string) $product['name'],
                'price' => $this->moneyLabel($product['price_minor'] ?? 0, (string) ($product['currency'] ?? 'USD')),
                'stock' => (int) ($product['available_count'] ?? 0),
                'status' => (string) ($product['status'] ?? ''),
                'max_quantity' => (int) ($product['max_quantity_per_order'] ?? 1),
            ];
        }
        $currencies = array_values(array_map(static fn (array $currency): array => [
            'code' => (string) $currency['code'],
            'name' => (string) $currency['name'],
        ], CurrencyRegistry::enabled()));
        $json = json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if (!is_string($json)) {
            $json = '[]';
        }
        $currencyJson = json_encode($currencies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if (!is_string($currencyJson)) {
            $currencyJson = '[]';
        }

        return '<script>window.CMS_CARD_PRODUCTS=' . $json . ';window.CMS_CURRENCIES=' . $currencyJson . ';' . <<<'JS'
(function(){
function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function name(i,k){return 'blocks['+i+'][data]['+k+']';}
function currencySelect(i, selected){selected=selected||'USD';var rows=window.CMS_CURRENCIES||[];return '<label>币种<select name="'+name(i,'currency')+'">'+rows.map(function(c){return '<option value="'+esc(c.code)+'"'+(c.code===selected?' selected':'')+'>'+esc(c.code+' — '+c.name)+'</option>';}).join('')+'</select></label>';}
function media(i,field,type,multiple,label){var n=multiple?'media_ids_text':field;return '<div class="media-picker-field" data-picker-field="'+esc(field)+'"><input type="hidden" name="'+name(i,n)+'" value="" data-media-picker-input="'+esc(field)+'"><button type="button" class="media-picker-open" data-block-index="'+i+'" data-target-field="'+esc(field)+'" data-media-type="'+esc(type)+'" data-media-multiple="'+(multiple?'1':'0')+'">'+esc(label||'从媒体库选择')+'</button> <button type="button" class="media-picker-clear" data-target-field="'+esc(field)+'">删除已选媒体</button> <a class="button editor-secondary" href="/admin/media" target="_blank" rel="noopener">上传/管理媒体</a><div class="media-picker-selection" data-media-picker-summary="'+esc(field)+'"><p class="muted">尚未选择媒体。</p></div></div>';}
function listEditor(i){return '<div class="block-list-editor" data-list-editor><input type="hidden" name="'+name(i,'items_text')+'" data-list-output value=""><div data-list-items><div class="block-list-item"><input data-list-item value=""><button type="button" data-list-move="up">上移</button><button type="button" data-list-move="down">下移</button><button type="button" data-list-remove>删除</button></div></div><button type="button" data-list-add>添加列表项</button></div>';}
function tableEditor(i){return '<div class="block-table-editor" data-table-editor><input type="hidden" name="'+name(i,'rows_text')+'" data-table-output value=""><table><tbody data-table-body><tr><td><input data-table-cell></td><td><input data-table-cell></td><td><button type="button" data-table-row-remove>删行</button></td></tr></tbody></table><button type="button" data-table-add-row>增加行</button> <button type="button" data-table-add-col>增加列</button> <button type="button" data-table-remove-col>删除最后一列</button></div>';}
function galleryEditor(i){return '<div class="block-gallery-editor" data-gallery-editor>'+media(i,'media_ids_text','image',true,'选择多张图片')+'<label>列数<input name="'+name(i,'columns')+'" type="number" min="1" max="6" value="3"></label><label>图库说明<input name="'+name(i,'caption')+'"></label><input type="hidden" name="'+name(i,'gallery_captions_text')+'" data-gallery-caption-output value=""><div class="gallery-caption-editor"><strong>单图 Caption</strong><div data-gallery-caption-items><p class="muted">选择图片后可为每张图填写 Caption。</p></div></div></div>';}
function productOptions(){var rows=window.CMS_CARD_PRODUCTS||[];return '<option value="0">请选择发卡商品</option>'+rows.map(function(p){return '<option value="'+p.id+'">#'+p.id+' '+esc(p.name)+' · '+esc(p.price)+' · 库存 '+p.stock+' · 状态 '+esc(p.status)+' · 每单最多 '+esc(p.max_quantity)+'</option>';}).join('');}
function productSummary(id){var p=(window.CMS_CARD_PRODUCTS||[]).find(function(row){return row.id===id;});if(!p){return '<p class="muted">选择发卡商品后显示商品名称、售价、库存、状态和每单上限。</p>';}return '<dl class="card-product-facts"><dt>商品名称</dt><dd>'+esc(p.name)+'</dd><dt>售价</dt><dd>'+esc(p.price)+'</dd><dt>当前库存</dt><dd>'+esc(p.stock)+'</dd><dt>商品状态</dt><dd>'+esc(p.status)+'</dd><dt>每单最大购买数量</dt><dd>'+esc(p.max_quantity)+'</dd></dl>';}
function render(i,type){var h='';
 if(type==='paragraph'){h='<label>正文<textarea name="'+name(i,'text')+'" rows="5"></textarea></label><label>正文样式<select name="'+name(i,'style')+'"><option value="body">正文</option><option value="lead">导语</option><option value="small">小号正文</option></select></label><input type="hidden" name="'+name(i,'bold')+'" value="0"><label><input type="checkbox" name="'+name(i,'bold')+'" value="1"> 粗体</label><input type="hidden" name="'+name(i,'italic')+'" value="0"><label><input type="checkbox" name="'+name(i,'italic')+'" value="1"> 斜体</label><label>对齐<select name="'+name(i,'alignment')+'"><option value="left">左对齐</option><option value="center">居中</option><option value="right">右对齐</option></select></label><label>链接<input name="'+name(i,'link')+'" placeholder="https://example.com"></label>';}
 else if(type==='heading'){h='<label>级别<select name="'+name(i,'level')+'"><option value="1">H1</option><option value="2" selected>H2</option><option value="3">H3</option><option value="4">H4</option><option value="5">H5</option><option value="6">H6</option></select></label><label>标题内容<input name="'+name(i,'text')+'"></label><label>对齐方式<select name="'+name(i,'alignment')+'"><option value="left">左对齐</option><option value="center">居中</option><option value="right">右对齐</option></select></label>';}
 else if(type==='unordered_list'||type==='ordered_list'){h=listEditor(i);}
 else if(type==='quote'){h='<label>引用正文<textarea name="'+name(i,'text')+'" rows="3"></textarea></label><label>来源/作者<input name="'+name(i,'cite')+'"></label>';}
 else if(type==='code'){h='<label>语言<select name="'+name(i,'language')+'"><option value="">纯文本</option><option value="php">PHP</option><option value="js">JavaScript</option><option value="html">HTML</option><option value="css">CSS</option><option value="sql">SQL</option></select></label><label>代码<textarea name="'+name(i,'code')+'" rows="7" spellcheck="false"></textarea></label>';}
 else if(type==='divider'){h='<p class="muted">分隔线无正文内容。</p><label>分隔线样式<select name="'+name(i,'style')+'"><option value="solid">实线</option><option value="dashed">虚线</option><option value="wide">宽间距</option></select></label><label>上下间距<select name="'+name(i,'spacing')+'"><option value="normal">标准</option><option value="compact">紧凑</option><option value="large">宽松</option></select></label>';}
 else if(type==='button'){h='<label>按钮文字<input name="'+name(i,'text')+'"></label><label>URL<input name="'+name(i,'url')+'"></label><label>打开方式<select name="'+name(i,'target')+'"><option value="_self">当前窗口</option><option value="_blank">新窗口</option></select></label><label>样式<select name="'+name(i,'style')+'"><option value="primary">主按钮</option><option value="secondary">次按钮</option><option value="outline">描边</option></select></label><label>对齐方式<select name="'+name(i,'alignment')+'"><option value="left">左对齐</option><option value="center">居中</option><option value="right">右对齐</option></select></label>';}
 else if(type==='table'){h=tableEditor(i);}
 else if(type==='html'){h='<label>HTML 正文<textarea name="'+name(i,'html')+'" rows="14" spellcheck="false"></textarea></label><p class="muted">适合编辑从旧站迁移来的正文；保存时会自动清理 script、事件属性和危险链接。</p>';}
 else if(type==='raw_text'){h='<label>纯文本<textarea name="'+name(i,'text')+'" rows="4"></textarea></label>';}
 else if(type==='image'){h=media(i,'media_id','image',false,'从媒体库选择')+'<label>替代文字<input name="'+name(i,'alt')+'"></label><label>说明文字<input name="'+name(i,'caption')+'"></label><label>显示宽度（px）<input name="'+name(i,'width')+'" type="number" min="0" max="4000" value="0"></label><label>对齐<select name="'+name(i,'alignment')+'"><option value="none">无</option><option value="left">左</option><option value="center">中</option><option value="right">右</option></select></label><label>链接<input name="'+name(i,'link')+'"></label>';}
 else if(type==='gallery'){h=galleryEditor(i);}
 else if(type==='audio'){h=media(i,'media_id','audio',false,'选择音频')+'<label>标题<input name="'+name(i,'title')+'"></label><input type="hidden" name="'+name(i,'controls')+'" value="0"><label><input type="checkbox" name="'+name(i,'controls')+'" value="1" checked> 显示播放器控制条</label><label>预加载<select name="'+name(i,'preload')+'"><option value="metadata">仅元数据</option><option value="none">不预加载</option><option value="auto">自动</option></select></label>';}
 else if(type==='video'){h=media(i,'media_id','video',false,'选择视频')+media(i,'poster_media_id','image',false,'选择封面图片')+'<label>外链视频地址<input name="'+name(i,'source_url')+'" placeholder="https://example.com/video.mp4"></label><input type="hidden" name="'+name(i,'controls')+'" value="0"><label><input type="checkbox" name="'+name(i,'controls')+'" value="1" checked> 显示播放器控制条</label><input type="hidden" name="'+name(i,'autoplay')+'" value="0"><label><input type="checkbox" name="'+name(i,'autoplay')+'" value="1"> 自动播放（将自动静音）</label><input type="hidden" name="'+name(i,'muted')+'" value="0"><label><input type="checkbox" name="'+name(i,'muted')+'" value="1"> 静音</label><input type="hidden" name="'+name(i,'loop')+'" value="0"><label><input type="checkbox" name="'+name(i,'loop')+'" value="1"> 循环播放</label><input type="hidden" name="'+name(i,'playsinline')+'" value="0"><label><input type="checkbox" name="'+name(i,'playsinline')+'" value="1" checked> 移动端内联播放</label><label>预加载<select name="'+name(i,'preload')+'"><option value="metadata">仅元数据</option><option value="none">不预加载</option><option value="auto">自动</option></select></label>';}
 else if(type==='attachment'){h=media(i,'media_id','attachment',false,'选择附件')+'<label>显示名称<input name="'+name(i,'display_name')+'"></label><label><input type="checkbox" name="'+name(i,'paid_enabled')+'" value="1"> 设为付费下载</label><label>价格<input name="'+name(i,'price')+'" inputmode="decimal" value="0.00" placeholder="1.00"></label>'+currencySelect(i,'USD')+'<label>按钮文字<input name="'+name(i,'payment_label')+'" value="解锁下载"></label>';}
 else if(type==='card_delivery'){h='<label>发卡商品<select name="'+name(i,'card_product_id')+'" data-card-product-select>'+productOptions()+'</select></label><div class="card-product-summary" data-card-product-summary>'+productSummary(0)+'</div><p><a class="button editor-secondary" href="/admin/card-delivery/new">新建发卡商品</a></p><fieldset><legend>前台展示设置</legend><input type="hidden" name="'+name(i,'show_name')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_name')+'" value="1" checked> 显示商品名称</label><input type="hidden" name="'+name(i,'show_price')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_price')+'" value="1" checked> 显示价格</label><input type="hidden" name="'+name(i,'show_stock')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_stock')+'" value="1" checked> 显示库存</label><input type="hidden" name="'+name(i,'show_button')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_button')+'" value="1" checked> 显示购买按钮</label><label>按钮文字<input name="'+name(i,'button_text')+'" value="立即购买"></label></fieldset>';}
 else if(type==='tip'){h='<label>标题<input name="'+name(i,'title')+'" value="喜欢这篇内容？"></label><label>说明<textarea name="'+name(i,'description')+'" rows="3">可以打赏支持作者继续创作。</textarea></label><label>预设金额<input name="'+name(i,'amounts_text')+'" value="1, 5, 10" placeholder="1, 5, 10"></label>'+currencySelect(i,'USD')+'<input type="hidden" name="'+name(i,'custom_amount')+'" value="0"><label><input type="checkbox" name="'+name(i,'custom_amount')+'" value="1" checked> 允许用户填写自定义金额</label><label>按钮文字<input name="'+name(i,'button_text')+'" value="打赏支持"></label><p class="muted">前台会使用已启用的支付方式创建打赏支付；金额请填写正常价格，不要填写最小货币单位。</p>';}
 else {h='<label>内容<textarea name="'+name(i,'text')+'" rows="4"></textarea></label>';}
 return h;
}
function syncList(box){var out=box.querySelector('[data-list-output]');if(out){out.value=[].slice.call(box.querySelectorAll('[data-list-item]')).map(function(input){return input.value;}).join('\n');}}
function syncTable(box){var out=box.querySelector('[data-table-output]');if(out){out.value=[].slice.call(box.querySelectorAll('tbody tr')).map(function(tr){return [].slice.call(tr.querySelectorAll('[data-table-cell]')).map(function(input){return input.value;}).join(' | ');}).join('\n');}}
function syncGallery(box){if(!box){return;}var input=box.querySelector('[data-media-picker-input]'),out=box.querySelector('[data-gallery-caption-output]'),items=box.querySelector('[data-gallery-caption-items]');if(!input||!out||!items){return;}var old={};[].slice.call(box.querySelectorAll('[data-gallery-caption]')).forEach(function(el){old[el.dataset.galleryCaption]=el.value;});var ids=(input.value||'').split(/[,\s]+/).map(function(v){return parseInt(v,10)||0;}).filter(function(v){return v>0;});if(!ids.length){items.innerHTML='<p class="muted">选择图片后可为每张图填写 Caption。</p>';out.value='';return;}var current=[].slice.call(box.querySelectorAll('[data-gallery-caption-row]')).map(function(row){return parseInt(row.dataset.galleryCaptionRow,10)||0;}).filter(function(v){return v>0;});if(current.join(',')!==ids.join(',')){items.innerHTML=ids.map(function(id){return '<div class="gallery-caption-item" data-gallery-caption-row="'+id+'"><span>媒体 #'+id+'</span><input data-gallery-caption="'+id+'" value="'+esc(old[id]||'')+'" placeholder="单张图片 Caption"></div>';}).join('');}out.value=ids.map(function(id){var el=box.querySelector('[data-gallery-caption="'+id+'"]');return id+' | '+(el?el.value:(old[id]||''));}).join('\n');}
function toggleBlockDrawer(open){var drawer=document.querySelector('[data-editor-block-drawer]');if(!drawer){return;}drawer.hidden=!open;document.body.classList.toggle('editor-drawer-open',!!open);}
function updatePaidFields(){var wrap=document.querySelector('[data-editor-paid-fields]'),toggle=document.querySelector('[data-editor-paid-toggle]');if(wrap&&toggle){wrap.hidden=!toggle.checked;}}
function updateScheduleFields(){var scheduled=document.querySelector('[data-editor-schedule-toggle][value="scheduled"]'),wrap=document.querySelector('[data-editor-schedule-fields]');if(wrap&&scheduled){wrap.hidden=!scheduled.checked;}}
document.addEventListener('change',function(e){var select=e.target.closest('[data-block-type-select]');if(select){var card=select.closest('.block-card'), fields=card.querySelector('[data-block-editor-fields]'), i=fields.dataset.blockIndex;fields.innerHTML=render(i,select.value);return;}var product=e.target.closest('[data-card-product-select]');if(product){var summary=product.closest('[data-block-editor-fields]').querySelector('[data-card-product-summary]');if(summary){summary.innerHTML=productSummary(parseInt(product.value,10)||0);}return;}if(e.target.closest('[data-editor-paid-toggle]')){updatePaidFields();return;}if(e.target.closest('[data-editor-schedule-toggle]')){updateScheduleFields();return;}});
document.addEventListener('input',function(e){var list=e.target.closest('[data-list-editor]');if(list){syncList(list);}var table=e.target.closest('[data-table-editor]');if(table){syncTable(table);}var gallery=e.target.closest('[data-gallery-editor]');if(gallery){syncGallery(gallery);}});
	document.addEventListener('click',function(e){if(e.target.closest('[data-editor-block-list-toggle]')){toggleBlockDrawer(true);return;}if(e.target.closest('[data-editor-block-list-close]')||e.target.closest('[data-editor-block-drawer-backdrop]')){toggleBlockDrawer(false);return;}var jump=e.target.closest('[data-editor-block-jump]');if(jump){var target=document.querySelector('[data-editor-block="'+jump.dataset.editorBlockJump+'"]');toggleBlockDrawer(false);if(target){target.scrollIntoView({block:'start',behavior:'smooth'});target.classList.add('editor-block-focus');setTimeout(function(){target.classList.remove('editor-block-focus');},1200);}return;}if(e.target.closest('[data-editor-scroll-blocks]')){var firstBlock=document.querySelector('.block-card');if(firstBlock){firstBlock.scrollIntoView({block:'start',behavior:'smooth'});}return;}var list=e.target.closest('[data-list-editor]');if(list){if(e.target.closest('[data-list-add]')){list.querySelector('[data-list-items]').insertAdjacentHTML('beforeend','<div class="block-list-item"><input data-list-item value=""><button type="button" data-list-move="up">上移</button><button type="button" data-list-move="down">下移</button><button type="button" data-list-remove>删除</button></div>');syncList(list);return;}var item=e.target.closest('.block-list-item');if(item&&e.target.closest('[data-list-remove]')){item.remove();syncList(list);return;}if(item&&e.target.closest('[data-list-move="up"]')&&item.previousElementSibling){item.parentElement.insertBefore(item,item.previousElementSibling);syncList(list);return;}if(item&&e.target.closest('[data-list-move="down"]')&&item.nextElementSibling){item.parentElement.insertBefore(item.nextElementSibling,item);syncList(list);return;}}
 var table=e.target.closest('[data-table-editor]');if(table){var body=table.querySelector('[data-table-body]'), first=body.querySelector('tr'), cols=first?first.querySelectorAll('[data-table-cell]').length:2;if(e.target.closest('[data-table-add-row]')){var cells='';for(var c=0;c<cols;c++){cells+='<td><input data-table-cell></td>';}body.insertAdjacentHTML('beforeend','<tr>'+cells+'<td><button type="button" data-table-row-remove>删行</button></td></tr>');syncTable(table);return;}if(e.target.closest('[data-table-add-col]')){body.querySelectorAll('tr').forEach(function(tr){tr.lastElementChild.insertAdjacentHTML('beforebegin','<td><input data-table-cell></td>');});syncTable(table);return;}if(e.target.closest('[data-table-remove-col]')&&cols>1){body.querySelectorAll('tr').forEach(function(tr){var cells=tr.querySelectorAll('td');cells[cells.length-2].remove();});syncTable(table);return;}var row=e.target.closest('tr');if(row&&e.target.closest('[data-table-row-remove]')){row.remove();if(!body.querySelector('tr')){body.insertAdjacentHTML('beforeend','<tr><td><input data-table-cell></td><td><input data-table-cell></td><td><button type="button" data-table-row-remove>删行</button></td></tr>');}syncTable(table);return;}}});
document.querySelectorAll('[data-list-editor]').forEach(syncList);document.querySelectorAll('[data-table-editor]').forEach(syncTable);updatePaidFields();updateScheduleFields();
})();
JS . '</script>';
    }

    /** @param array<string, mixed> $data */
    private function mediaBlockPicker(int $i, string $field, string $mediaType, bool $multiple, array $data, string $buttonLabel = '从媒体库选择'): string
    {
        $value = $multiple
            ? implode(', ', is_array($data['media_ids'] ?? null) ? array_map('strval', $data['media_ids']) : [])
            : (string) ($data[$field] ?? '');
        $name = $multiple ? 'blocks[' . $i . '][data][media_ids_text]' : 'blocks[' . $i . '][data][' . $field . ']';
        $mode = $multiple ? '1' : '0';

        return '<div class="media-picker-field" data-picker-field="' . View::escape($field) . '">' .
            '<input type="hidden" name="' . View::escape($name) . '" value="' . View::escape($value) . '" data-media-picker-input="' . View::escape($field) . '">' .
            '<button type="button" class="media-picker-open" data-block-index="' . $i . '" data-target-field="' . View::escape($field) . '" data-media-type="' . View::escape($mediaType) . '" data-media-multiple="' . $mode . '">' . View::escape($buttonLabel) . '</button> ' .
            '<button type="button" class="media-picker-clear" data-target-field="' . View::escape($field) . '">删除已选媒体</button>' .
            ' <a class="button editor-secondary" href="/admin/media" target="_blank" rel="noopener">上传/管理媒体</a>' .
            '<div class="media-picker-selection" data-media-picker-summary="' . View::escape($field) . '">' . $this->mediaSelectionSummary($value) . '</div>' .
            '</div>';
    }

    private function mediaSelectionSummary(string $value): string
    {
        $ids = array_values(array_filter(array_map(static fn (string $id): int => (int) trim($id), preg_split('/[,\s]+/', $value) ?: []), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return '<p class="muted">尚未选择媒体。</p>';
        }

        $items = '';
        foreach ($ids as $id) {
            $items .= '<li data-media-selected-id="' . $id . '">媒体 #' . $id . ' <button type="button" data-media-move="up">上移</button> <button type="button" data-media-move="down">下移</button> <button type="button" data-media-remove="' . $id . '">移除</button></li>';
        }

        return '<ol>' . $items . '</ol>';
    }

    private function mediaPickerComponent(): string
    {
        $items = $this->mediaPickerItems();
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if (!is_string($json)) {
            $json = '[]';
        }

        return '<div id="media-picker-modal" hidden style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;padding:4vh 4vw;overflow:auto">' .
            '<div style="background:#fff;max-width:980px;margin:0 auto;padding:20px;border-radius:8px;box-shadow:0 20px 60px rgba(15,23,42,.25)">' .
            '<h2>媒体库选择</h2><div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px"><button type="button" data-media-source="local" class="button">本地媒体</button><button type="button" data-media-source="cloudreve" class="button editor-secondary">Cloudreve</button></div><div style="display:flex;gap:12px;flex-wrap:wrap"><label>搜索<input id="media-picker-search" placeholder="文件名"></label><label id="media-picker-path-wrap" hidden>Cloudreve 目录<input id="media-picker-path" value="cloudreve://my/"></label><label>类型<select id="media-picker-type"><option value="">全部</option><option value="image">图片</option><option value="audio">音频</option><option value="video">视频</option><option value="attachment">附件</option></select></label><label id="media-picker-mode-wrap" hidden>选择方式<select id="media-picker-mode"><option value="reference">引用 Cloudreve</option><option value="import">导入到 CMS</option></select></label><button type="button" id="media-picker-refresh" hidden>读取目录</button><button type="button" id="media-picker-close">关闭</button></div><p class="muted" id="media-picker-message"></p>' .
            '<div id="media-picker-results" style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px"></div></div></div>' .
            '<script>window.CMS_MEDIA_PICKER_ITEMS=' . $json . ';window.CMS_MEDIA_PICKER_CSRF=' . json_encode(CsrfToken::get(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ';' . $this->mediaPickerJavascript() . '</script>';
    }

    /** @return list<array<string, mixed>> */
    private function mediaPickerItems(): array
    {
        try {
            $items = $this->mediaLibrary()->list(['status' => 'Active']);
        } catch (Throwable $exception) {
            $this->logger->error('Media picker load failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return [];
        }

        return array_map(fn (array $item): array => $this->mediaPickerItem($item), $items);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function mediaPickerItem(array $item): array
    {
        $id = (int) ($item['id'] ?? 0);
        $name = (string) ($item['original_name'] ?? ('media-' . $id));
        $title = trim((string) ($item['title'] ?? ''));
        $relativePath = trim((string) ($item['relative_path'] ?? ''));
        $storageKey = trim((string) ($item['storage_key'] ?? ''));
        $pathName = basename($relativePath !== '' ? $relativePath : $storageKey);
        $displayName = $title !== '' && $title !== pathinfo($name, PATHINFO_FILENAME) ? $title . '（' . $name . '）' : $name;
        $url = '/media/' . $id;

        return [
            'id' => $id,
            'filename' => $name,
            'display_name' => $displayName,
            'title' => $title,
            'path_name' => $pathName,
            'relative_path' => $relativePath,
            'search_text' => trim($id . ' ' . $displayName . ' ' . $name . ' ' . $title . ' ' . $pathName . ' ' . $relativePath),
            'media_type' => (string) ($item['media_type'] ?? ''),
            'mime_type' => (string) ($item['mime_type'] ?? ''),
            'byte_size' => (int) ($item['byte_size'] ?? 0),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'url' => $url,
            'download_url' => $url . '?download=1',
            'thumbnail_url' => (string) ($item['media_type'] ?? '') === 'image' ? $url : '',
            'provider' => (string) ($item['storage_provider'] ?? 'local'),
        ];
    }

    private function importRemoteMedia(\Cms\Core\Media\RemoteMediaProviderInterface $provider, \Cms\Core\Media\MediaProviderItem $item, int $adminId): int
    {
        $tmpDir = rtrim($this->root() . '/storage/tmp', DIRECTORY_SEPARATOR);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmp = $tmpDir . '/remote-media-' . bin2hex(random_bytes(12)) . '.tmp';
        try {
            $provider->downloadTo($item->id, $item->path, $tmp, $this->mediaLimit('max_file_bytes', 52428800));
            return $this->mediaLibrary()->uploadLocalFile($tmp, $item->name, $adminId);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function mediaPickerJavascript(): string
    {
        return <<<'JS'
(function(){
var modal=document.getElementById('media-picker-modal'),results=document.getElementById('media-picker-results'),search=document.getElementById('media-picker-search'),typeSelect=document.getElementById('media-picker-type'),pathInput=document.getElementById('media-picker-path'),pathWrap=document.getElementById('media-picker-path-wrap'),modeWrap=document.getElementById('media-picker-mode-wrap'),modeSelect=document.getElementById('media-picker-mode'),refreshBtn=document.getElementById('media-picker-refresh'),message=document.getElementById('media-picker-message');
var activeInput=null,activeSummary=null,activeType='',activeMultiple=false,source='local',remoteItems=[];
function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function ids(){return (activeInput&&activeInput.value?activeInput.value:'').split(/[,\s]+/).map(function(v){return parseInt(v,10)||0;}).filter(function(v){return v>0;});}
function writeIds(list){if(activeInput){activeInput.value=list.join(', ');} renderSummary();}
function humanSize(bytes){bytes=parseInt(bytes,10)||0;if(bytes>=1048576){return (bytes/1048576).toFixed(1)+' MB';} return (bytes/1024).toFixed(1)+' KB';}
function shortPath(path){path=String(path||'');if(!path){return '';}try{path=decodeURIComponent(path);}catch(e){}var parts=path.split('/').filter(Boolean);return parts.length?parts[parts.length-1]:path;}
function readJson(response){var type=response.headers.get('content-type')||'';if(type.indexOf('application/json')<0){return response.text().then(function(text){var msg=response.status===401||response.status===403?'登录状态或请求校验已失效，请刷新页面后重试。':'服务器返回了非 JSON 响应，请刷新页面或检查后台路由。';throw new Error(msg);});}return response.json();}
function itemType(item){var mime=String((item&&item.mime_type)||'');if(item&&item.type==='folder'){return 'folder';}if(mime.indexOf('image/')===0){return 'image';}if(mime.indexOf('audio/')===0){return 'audio';}if(mime.indexOf('video/')===0){return 'video';}return item&&item.media_type?item.media_type:'attachment';}
function mediaPreview(item){
 if(!item){return '';}
 if(item.media_type==='image'&&item.thumbnail_url){return '<img src="'+esc(item.thumbnail_url)+'" alt="" style="width:72px;height:54px;object-fit:cover;border:1px solid #d0d7de;border-radius:4px;margin-right:8px;vertical-align:middle">';}
 if(item.media_type==='audio'&&item.url){return '<audio controls preload="metadata" src="'+esc(item.url)+'" style="width:220px;max-width:100%;vertical-align:middle"></audio> ';}
 if(item.media_type==='video'&&item.url){return '<video controls preload="metadata" src="'+esc(item.url)+'" style="width:220px;max-width:100%;height:124px;vertical-align:middle"></video> ';}
 if(item.download_url){return '<a href="'+esc(item.download_url)+'" target="_blank" rel="noopener">下载链接预览</a> ';}
 return '';
}
function renderSummary(){
 if(!activeInput||!activeSummary){return;}
 var list=ids();
 if(!list.length){activeSummary.innerHTML='<p class="muted">尚未选择媒体。</p>';syncGalleryCaptions();return;}
 activeSummary.innerHTML='<ol>'+list.map(function(id){var item=(window.CMS_MEDIA_PICKER_ITEMS||[]).find(function(m){return m.id===id;});var label=item?(item.display_name||item.filename):('媒体 #'+id),meta=item?(' · ID '+item.id+' · '+item.media_type+' · '+humanSize(item.byte_size)):'';return '<li data-media-selected-id="'+id+'" style="margin:8px 0">'+mediaPreview(item)+'<span>'+esc(label)+esc(meta)+'</span> <button type="button" data-media-move="up">上移</button> <button type="button" data-media-move="down">下移</button> <button type="button" data-media-remove="'+id+'">移除</button></li>';}).join('')+'</ol>';
 syncGalleryCaptions();
}
function syncGalleryCaptions(){
 if(!activeInput){return;}
 var box=activeInput.closest('[data-gallery-editor]'); if(!box){return;}
 var out=box.querySelector('[data-gallery-caption-output]'),items=box.querySelector('[data-gallery-caption-items]'); if(!out||!items){return;}
 var old={};[].slice.call(box.querySelectorAll('[data-gallery-caption]')).forEach(function(el){old[el.dataset.galleryCaption]=el.value;});
 var list=ids();
 if(!list.length){items.innerHTML='<p class="muted">选择图片后可为每张图填写 Caption。</p>';out.value='';return;}
 items.innerHTML=list.map(function(id){return '<div class="gallery-caption-item" data-gallery-caption-row="'+id+'"><span>媒体 #'+id+'</span><input data-gallery-caption="'+id+'" value="'+esc(old[id]||'')+'" placeholder="单张图片 Caption"></div>';}).join('');
 out.value=list.map(function(id){return id+' | '+(old[id]||'');}).join('\n');
}
function choose(id){var list=ids(); if(activeMultiple){if(list.indexOf(id)<0){list.push(id);}}else{list=[id];} writeIds(list); if(!activeMultiple){modal.hidden=true;}}
function setSource(next){source=next==='cloudreve'?'cloudreve':'local'; pathWrap.hidden=source!=='cloudreve'; modeWrap.hidden=source!=='cloudreve'; refreshBtn.hidden=source!=='cloudreve'; [].slice.call(document.querySelectorAll('[data-media-source]')).forEach(function(btn){btn.classList.toggle('editor-secondary',btn.dataset.mediaSource!==source);}); if(source==='cloudreve'){loadRemote();}else{message.textContent='';render();}}
function localRows(){var q=(search.value||'').toLowerCase(), filter=typeSelect.value||activeType;return (window.CMS_MEDIA_PICKER_ITEMS||[]).filter(function(item){return (!filter||item.media_type===filter)&&(!q||String(item.search_text||item.filename).toLowerCase().indexOf(q)>=0);});}
function remoteRows(){var q=(search.value||'').toLowerCase(), filter=typeSelect.value||activeType;return remoteItems.filter(function(item){var mt=itemType(item);return mt==='folder'||((!filter||mt===filter)&&(!q||String((item.name||'')+' '+(item.path||'')).toLowerCase().indexOf(q)>=0));});}
function render(){
 if(source==='cloudreve'){
  var remote=remoteRows();
  results.innerHTML=remote.length?remote.map(function(item){var mt=itemType(item),folder=mt==='folder';var thumb='<div style="height:92px;display:flex;align-items:center;justify-content:center;border:1px solid #d0d7de;border-radius:4px;background:#f6f8fa">'+esc(folder?'目录':mt)+'</div>';return '<article style="border:1px solid #d8dee4;border-radius:6px;padding:10px;min-width:0;overflow:hidden">'+thumb+'<strong style="display:block;margin-top:8px;word-break:break-word;overflow-wrap:anywhere;line-height:1.25">'+esc(item.name)+'</strong><span class="muted">'+esc(mt)+' · '+esc(humanSize(item.byte_size))+'</span><br><span class="muted" title="'+esc(item.path)+'" style="display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">位置：'+esc(shortPath(item.path))+'</span><button type="button" '+(folder?'data-media-folder':'data-media-remote')+'="'+esc(item.id)+'" data-path="'+esc(item.path)+'">'+(folder?'打开':'选择')+'</button></article>';}).join(''):'<p class="muted">Cloudreve 中没有可选媒体。</p>';
  return;
 }
 var rows=localRows();
 results.innerHTML=rows.length?rows.map(function(item){var thumb=item.thumbnail_url?'<img src="'+esc(item.thumbnail_url)+'" alt="" style="width:100%;height:92px;object-fit:cover;border:1px solid #d0d7de;border-radius:4px">':'<div style="height:92px;display:flex;align-items:center;justify-content:center;border:1px solid #d0d7de;border-radius:4px;background:#f6f8fa">'+esc(item.media_type)+'</div>';var path=item.relative_path?'<br><span class="muted" title="'+esc(item.relative_path)+'">存储：'+esc(item.provider&&item.provider!=='local'?item.provider+' · ':'')+esc(item.path_name||item.relative_path)+'</span>':'';return '<article style="border:1px solid #d8dee4;border-radius:6px;padding:10px;min-width:0">'+thumb+'<strong style="display:block;margin-top:8px;word-break:break-word;overflow-wrap:anywhere">'+esc(item.display_name||item.filename)+'</strong><span class="muted">ID '+esc(item.id)+' · '+esc(item.media_type)+' · '+esc(humanSize(item.byte_size))+'</span>'+path+'<br><span class="muted">'+esc(item.created_at)+'</span><br><button type="button" data-media-choose="'+item.id+'">选择</button></article>';}).join(''):'<p class="muted">没有可选媒体。</p>';
}
function loadRemote(){
 message.textContent='读取 Cloudreve 中...';
 var params=new URLSearchParams({provider:'cloudreve',path:pathInput.value||'cloudreve://my/',q:search.value||'',page_size:'50'});
 fetch('/admin/media/provider/list?'+params.toString(),{headers:{'Accept':'application/json'}}).then(readJson).then(function(data){if(!data.ok){throw new Error(data.message||'Cloudreve 暂不可用');}remoteItems=data.items||[];message.textContent='';render();}).catch(function(err){remoteItems=[];message.textContent=err.message;render();});
}
function chooseRemote(id,path){
 message.textContent='正在加入媒体库...';
 fetch('/admin/media/provider/select',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({provider:'cloudreve',id:id,path:path,mode:modeSelect.value||'reference',_csrf:window.CMS_MEDIA_PICKER_CSRF||''})}).then(readJson).then(function(data){if(!data.ok){throw new Error(data.message||'选择失败');}var exists=(window.CMS_MEDIA_PICKER_ITEMS||[]).some(function(item){return item.id===data.media.id;});if(!exists){window.CMS_MEDIA_PICKER_ITEMS.unshift(data.media);}choose(data.media.id);message.textContent=data.mode==='import'?'已导入到 CMS 媒体库。':'已引用 Cloudreve 媒体。';}).catch(function(err){message.textContent=err.message;});
}
document.addEventListener('click',function(e){
 var open=e.target.closest('.media-picker-open'); if(open){var box=open.closest('.media-picker-field'); activeInput=box.querySelector('[data-media-picker-input="'+open.dataset.targetField+'"]'); activeSummary=box.querySelector('[data-media-picker-summary="'+open.dataset.targetField+'"]'); activeType=open.dataset.mediaType||''; activeMultiple=open.dataset.mediaMultiple==='1'; typeSelect.value=activeType; search.value=''; modal.hidden=false; setSource(source); return;}
 var sourceBtn=e.target.closest('[data-media-source]'); if(sourceBtn){setSource(sourceBtn.dataset.mediaSource||'local'); return;}
 var refresh=e.target.closest('#media-picker-refresh'); if(refresh){loadRemote(); return;}
 var folder=e.target.closest('[data-media-folder]'); if(folder){pathInput.value=folder.dataset.path||'cloudreve://my/'; loadRemote(); return;}
 var remote=e.target.closest('[data-media-remote]'); if(remote){chooseRemote(remote.getAttribute('data-media-remote')||'',remote.dataset.path||''); return;}
 var clear=e.target.closest('.media-picker-clear'); if(clear){var cbox=clear.closest('.media-picker-field'); activeInput=cbox.querySelector('[data-media-picker-input="'+clear.dataset.targetField+'"]'); activeSummary=cbox.querySelector('[data-media-picker-summary="'+clear.dataset.targetField+'"]'); writeIds([]); return;}
 var pick=e.target.closest('[data-media-choose]'); if(pick){choose(parseInt(pick.dataset.mediaChoose,10)||0); return;}
 var rem=e.target.closest('[data-media-remove]'); if(rem){activeSummary=e.target.closest('.media-picker-selection'); activeInput=activeSummary.parentElement.querySelector('[data-media-picker-input]'); writeIds(ids().filter(function(id){return id!==(parseInt(rem.dataset.mediaRemove,10)||0);})); return;}
 var move=e.target.closest('[data-media-move]'); if(move){activeSummary=e.target.closest('.media-picker-selection'); activeInput=activeSummary.parentElement.querySelector('[data-media-picker-input]'); var li=move.closest('li'), id=parseInt(li.dataset.mediaSelectedId,10)||0, list=ids(), pos=list.indexOf(id), dir=move.dataset.mediaMove; if(pos>=0&&dir==='up'&&pos>0){var t=list[pos-1];list[pos-1]=list[pos];list[pos]=t;} if(pos>=0&&dir==='down'&&pos<list.length-1){var n=list[pos+1];list[pos+1]=list[pos];list[pos]=n;} writeIds(list); return;}
 if(e.target&&e.target.id==='media-picker-close'){modal.hidden=true;}
});
search.addEventListener('input',function(){if(source==='cloudreve'){loadRemote();}else{render();}});
typeSelect.addEventListener('change',render);
pathInput.addEventListener('change',loadRemote);
document.querySelectorAll('.media-picker-field').forEach(function(box){activeInput=box.querySelector('[data-media-picker-input]');activeSummary=box.querySelector('[data-media-picker-summary]');renderSummary();});
activeInput=null;activeSummary=null;
})();
JS;
    }

    private function statusOptions(string $selected): string
    {
        return implode('', array_map(fn (string $status): string => '<option value="' . $status . '"' . ($selected === $status ? ' selected' : '') . '>' . View::escape(AdminUiText::contentStatus($status)) . '</option>', ['draft', 'published', 'scheduled', 'archived']));
    }

    private function blockTypeLabel(string $type): string
    {
        return AdminUiText::blockType($type);
    }

    private function contentStatusLabel(string $status): string
    {
        return AdminUiText::contentStatus($status);
    }

    private function adminBadge(string $label, string $tone = 'muted'): string
    {
        $allowed = ['success', 'warning', 'danger', 'muted'];
        $class = in_array($tone, $allowed, true) ? $tone : 'muted';

        return '<span class="admin-badge admin-badge-' . $class . '">' . View::escape($label) . '</span>';
    }

    private function statusTone(string $status): string
    {
        return match (strtoupper($status)) {
            'PASS', 'OK' => 'success',
            'FAIL', 'BLOCKED' => 'danger',
            'WARNING', 'EXTERNAL ACCEPTANCE REQUIRED' => 'warning',
            default => 'muted',
        };
    }

    /** @param list<string> $labels */
    private function adminTags(array $labels): string
    {
        $html = '';
        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $html .= '<span class="admin-tag">' . View::escape($label) . '</span>';
        }

        return $html !== '' ? $html : $this->adminBadge('无', 'muted');
    }

    /** @param array<string,mixed> $plan */
    private function updatePlanSummaryHtml(array $plan): string
    {
        $features = $this->updatePlanBadges($plan['features'] ?? [], [
            'core_payment_provider_settings' => 'Provider 设置',
            'core_manual_payment_card_delivery_fulfillment' => '人工支付自动发卡',
            'safe_content_delete' => '安全删除内容',
            'admin_compact_density' => '后台紧凑界面',
            'admin_transfer_official_content_data_restore' => '后台恢复官方内容数据',
        ]);
        $gates = $this->updatePlanBadges($plan['acceptance_gates'] ?? [], [
            'provider_settings_persist_after_reload' => '保存后回读',
            'payment_service_enabled_providers_discovers_manual_provider' => '支付服务可发现 Provider',
            'card_delivery_manual_capture_trusted_paid_fulfills_once' => 'capture 后只发卡一次',
            'content_delete_requires_post_csrf_and_permission' => '删除需权限和 CSRF',
            'production_fixture_provider_hidden' => '生产隐藏 fixture',
            'admin_density_browser_smoke_script_available' => '后台密度烟测脚本',
            'admin_transfer_official_content_data_restore_requires_csrf_and_audit' => '后台内容恢复需 CSRF 和审计',
        ]);
        $compatibility = is_array($plan['compatibility'] ?? null) ? $plan['compatibility'] : [];
        $signature = is_array($plan['signature'] ?? null) ? $plan['signature'] : [];

        return '<table><tbody>' .
            '<tr><th>更新包</th><td><code>' . View::escape((string) ($plan['release_id'] ?? $plan['package_id'] ?? '')) . '</code></td></tr>' .
            '<tr><th>版本</th><td>' . View::escape((string) ($plan['from_version'] ?? '')) . ' -> ' . View::escape((string) ($plan['to_version'] ?? $plan['target_version'] ?? '')) . '</td></tr>' .
            '<tr><th>直接升级</th><td>' . View::escape(($plan['direct_upgrade_supported'] ?? true) ? '可直接升级到最新版' : '当前版本过旧，需要中间基线') . '</td></tr>' .
            '<tr><th>文件 / 迁移</th><td>' . (int) ($plan['file_count'] ?? 0) . ' 个文件，' . (int) ($plan['migration_count'] ?? 0) . ' 个迁移</td></tr>' .
            '<tr><th>迁移边界</th><td>hard min: ' . View::escape((string) ($plan['hard_min_version'] ?? '')) . '；migration floor: ' . View::escape((string) ($plan['migration_floor'] ?? '')) . '</td></tr>' .
            '<tr><th>兼容性</th><td>' . View::escape((string) ($compatibility['current_integrity'] ?? '')) . ' / ' . View::escape((string) ($compatibility['database'] ?? '')) . ' / PHP ' . View::escape((string) ($compatibility['php'] ?? '')) . '</td></tr>' .
            '<tr><th>签名</th><td>' . View::escape((string) ($signature['algorithm'] ?? '')) . ' <code>' . View::escape((string) ($signature['key_id'] ?? '')) . '</code></td></tr>' .
            '<tr><th>包含能力</th><td>' . $features . '</td></tr>' .
            '<tr><th>验收门</th><td>' . $gates . '</td></tr>' .
            '<tr><th>说明</th><td>' . View::escape((string) ($plan['notes'] ?? '')) . '</td></tr>' .
            '</tbody></table>';
    }

    /** @param mixed $items @param array<string,string> $labels */
    private function updatePlanBadges(mixed $items, array $labels): string
    {
        if (!is_array($items) || $items === []) {
            return '<span class="muted">未声明</span>';
        }

        $html = '';
        foreach ($items as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            $html .= $this->adminBadge($labels[$item] ?? $item, 'success') . ' ';
        }

        return $html !== '' ? $html : '<span class="muted">未声明</span>';
    }

    private function contentPublicPath(string $type, string $slug): string
    {
        if ($slug === ''
            || $slug !== trim($slug)
            || strlen($slug) > 191
            || str_contains($slug, '/')
            || str_contains($slug, '\\')
            || str_contains($slug, '..')
            || str_contains($slug, '?')
            || str_contains($slug, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1
            || preg_match('/^[\p{L}\p{N}-]+$/u', $slug) !== 1
        ) {
            return '';
        }
        if ($type === 'article') {
            return '/articles/' . rawurlencode($slug);
        }
        if ($type === 'page') {
            return '/' . rawurlencode($slug);
        }

        return '';
    }

    private function pluginStatusLabel(string $status): string
    {
        return AdminUiText::pluginStatus($status);
    }

    private function pluginStatusActionLabel(string $status): string
    {
        return AdminUiText::pluginAction($status);
    }

    private function trustLevelLabel(string $trustLevel): string
    {
        return AdminUiText::trustLevel($trustLevel);
    }

    /** @return list<string> */
    private function csvList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    /** @param list<array<string,mixed>> $categories */
    private function categoryIndexHtml(array $categories, string $notice = ''): string
    {
        $rows = '';
        foreach ($categories as $category) {
            $count = (int) ($category['content_count'] ?? 0);
            $delete = $count === 0
                ? '<form method="post" action="/admin/categories/delete/' . (int) $category['id'] . '" style="display:inline" onsubmit="return confirm(\'确定要删除这个分类吗？\');">' . CsrfToken::field() . '<button class="admin-danger" type="submit">删除</button></form>'
                : '<span class="muted">有内容时不可删除</span>';
            $rows .= '<tr><td>' . (int) $category['id'] . '</td><td><strong>' . View::escape((string) $category['name']) . '</strong><br><code>' . View::escape((string) $category['slug']) . '</code></td><td>' . $count . '</td><td><a class="button" href="/category/' . rawurlencode((string) $category['slug']) . '" target="_blank" rel="noopener">前台</a> <a class="button admin-button-secondary" href="/admin/categories/edit/' . (int) $category['id'] . '">编辑</a> ' . $delete . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="4" class="muted">暂无分类，先在下方创建一个。</td></tr>';

        return '<div class="admin-page-header"><div><h1>分类管理</h1><p class="muted">创建和维护文章分类，发布文章时可以直接选择。</p></div><div class="admin-action-row"><a class="button admin-button-secondary" href="/admin/content">返回内容管理</a><a class="button" href="/admin/content/new">新建内容</a></div></div>' .
            $notice .
            '<section class="editor-card"><h2>新建分类</h2><form method="post" action="/admin/categories">' . CsrfToken::field() .
            '<div class="admin-grid two"><label>分类名称<input name="name" required placeholder="例如：生活日常"></label><label>URL Slug<input name="slug" placeholder="留空自动生成"></label></div>' .
            '<p><button type="submit">创建分类</button></p></form></section>' .
            '<section class="editor-card"><h2>已有分类</h2><table><thead><tr><th>ID</th><th>分类</th><th>内容数</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    /** @param array<string,mixed> $category */
    private function categoryEditHtml(array $category, string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';

        return '<div class="admin-page-header"><div><h1>编辑分类</h1><p class="muted">修改分类名称和前台 URL Slug。</p></div><div class="admin-action-row"><a class="button admin-button-secondary" href="/admin/categories">返回分类管理</a></div></div>' .
            $errorHtml .
            '<section class="editor-card"><form method="post" action="/admin/categories/edit/' . (int) $category['id'] . '">' . CsrfToken::field() .
            '<label>分类名称<input name="name" value="' . View::escape((string) ($category['name'] ?? '')) . '" required></label>' .
            '<label>URL Slug<input name="slug" value="' . View::escape((string) ($category['slug'] ?? '')) . '" required></label>' .
            '<p class="muted">当前关联内容：' . (int) ($category['content_count'] ?? 0) . ' 篇。</p>' .
            '<button type="submit">保存分类</button></form></section>';
    }

    /** @return list<array<string,mixed>> */
    private function categoryRows(): array
    {
        try {
            return (new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults()))->terms('category');
        } catch (Throwable) {
            return [];
        }
    }

    /** @param mixed $selected */
    private function contentCategorySelector(mixed $selected): string
    {
        $selectedNames = array_values(array_map('strval', is_array($selected) ? $selected : []));
        $selectedLookup = array_fill_keys($selectedNames, true);
        $categories = $this->categoryRows();
        $options = '';
        foreach ($categories as $category) {
            $checked = isset($selectedLookup[(string) $category['name']]) ? ' checked' : '';
            $options .= '<label><input type="checkbox" name="category_ids[]" value="' . (int) $category['id'] . '"' . $checked . '> ' . View::escape((string) $category['name']) . '</label>';
        }
        $options = $options !== '' ? $options : '<p class="muted">暂无已建分类，可以先快速新增，或进入分类管理创建。</p>';

        return '<div class="editor-checkbox-list">' . $options . '</div><p><a class="button editor-secondary" href="/admin/categories" target="_blank" rel="noopener">管理分类</a></p>';
    }

    /** @param mixed $selected @return list<string> */
    private function manualCategoryNames(mixed $selected): array
    {
        $selectedNames = array_values(array_map('strval', is_array($selected) ? $selected : []));
        $known = [];
        foreach ($this->categoryRows() as $category) {
            $known[(string) $category['name']] = true;
        }

        return array_values(array_filter($selectedNames, static fn (string $name): bool => !isset($known[$name])));
    }

    /** @param mixed $input @return list<string> */
    private function categoryNamesFromIds(mixed $input): array
    {
        $ids = is_array($input) ? array_values(array_filter(array_map('intval', $input), static fn (int $id): bool => $id > 0)) : [];
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->categoryRows() as $category) {
            if (in_array((int) $category['id'], $ids, true)) {
                $names[] = (string) $category['name'];
            }
        }

        return $names;
    }

    private function navigationForm(string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $items = NavigationBuilder::adminItems($this->settings, ConnectionFactory::make($this->settings), $this->root());
        $rows = '';
        foreach ($items as $i => $item) {
            $disabledNote = ($item['available'] ?? true) ? '' : '<p class="muted">依赖插件未启用，前台将自动隐藏。</p>';
            $rows .= '<tr><td><input name="navigation[' . $i . '][label]" value="' . View::escape((string) $item['label']) . '" required></td>' .
                '<td><input name="navigation[' . $i . '][url]" value="' . View::escape((string) $item['url']) . '" required>' . $disabledNote . '</td>' .
                '<td><label><input type="checkbox" name="navigation[' . $i . '][enabled]" value="1"' . (($item['enabled'] ?? false) ? ' checked' : '') . '> 显示</label></td>' .
                '<td><button type="submit" name="nav_delete" value="' . $i . '">删除</button>' .
                '<input type="hidden" name="navigation[' . $i . '][type]" value="' . View::escape((string) $item['type']) . '">' .
                '<input type="hidden" name="navigation[' . $i . '][requires_plugin]" value="' . View::escape((string) $item['requires_plugin']) . '"></td></tr>';
        }
        $pageButtons = '';
        foreach ($this->navigationPages() as $page) {
            $pageButtons .= '<button type="submit" name="quick_add" value="page:' . (int) $page['id'] . '">' . View::escape((string) $page['title']) . '</button> ';
        }
        $pageButtons = $pageButtons !== '' ? '<p>添加页面：' . $pageButtons . '</p>' : '<p class="muted">暂无可添加的已发布页面。</p>';

        return '<h1>导航菜单</h1>' . $errorHtml .
            '<p class="muted">这里设置网站前台主导航。内部插件 ID 和路由保持不变，前台只显示中文菜单名称。</p>' .
            '<form method="post" action="/admin/navigation">' . CsrfToken::field() .
            '<table><thead><tr><th>菜单名称</th><th>链接地址</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><button type="submit">保存导航</button></p>' .
            '<h2>快速添加</h2>' .
            '<p><button type="submit" name="quick_add" value="home">首页</button> <button type="submit" name="quick_add" value="articles">文章列表</button></p>' .
            $this->navigationPluginQuickButtons() .
            $pageButtons .
            '<h2>添加自定义链接</h2>' .
            '<label>名称<input name="new_label" placeholder="例如：关于我们"></label>' .
            '<label>链接<input name="new_url" placeholder="/about 或 https://example.com"></label>' .
            '<button type="submit" name="quick_add" value="custom">添加自定义链接</button></form>';
    }

    /** @return array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}|null */
    private function navigationQuickItem(Request $request): ?array
    {
        $quick = (string) $request->input('quick_add', '');
        if ($quick === 'home') {
            return ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => true, 'requires_plugin' => ''];
        }
        if ($quick === 'articles') {
            return ['label' => '文章', 'url' => '/articles', 'type' => 'articles', 'enabled' => true, 'requires_plugin' => ''];
        }
        if (str_starts_with($quick, 'plugin_nav:')) {
            $pluginItems = NavigationBuilder::pluginItems($this->settings, ConnectionFactory::make($this->settings), $this->root(), true);
            return $pluginItems[(int) substr($quick, 11)] ?? null;
        }
        if (str_starts_with($quick, 'page:')) {
            return $this->navigationPageItem((int) substr($quick, 5));
        }
        if ($quick === 'custom') {
            $label = trim((string) $request->input('new_label', ''));
            $url = trim((string) $request->input('new_url', ''));
            $custom = NavigationBuilder::sanitizeForSave([['label' => $label, 'url' => $url, 'type' => 'custom', 'enabled' => true, 'requires_plugin' => '']]);
            return $custom[0] ?? null;
        }

        return null;
    }

    private function navigationPluginQuickButtons(): string
    {
        $items = NavigationBuilder::pluginItems($this->settings, ConnectionFactory::make($this->settings), $this->root(), true);
        if ($items === []) {
            return '';
        }
        $buttons = '';
        foreach ($items as $i => $item) {
            $buttons .= '<button type="submit" name="quick_add" value="plugin_nav:' . $i . '">' . View::escape((string) $item['label']) . '</button> ';
        }

        return '<p>添加插件入口：' . $buttons . '</p>';
    }

    private function adminActorId(): ?int
    {
        try {
            $user = (new AdminAuthenticator(ConnectionFactory::make($this->settings)))->user();
            return is_array($user) ? (int) $user['id'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{id:int,title:string,slug:string}> */
    private function navigationPages(): array
    {
        try {
            $stmt = ConnectionFactory::make($this->settings)->query("SELECT id, title, slug FROM cms_contents WHERE content_type = 'page' AND status = 'published' ORDER BY title ASC LIMIT 20");
            return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']], $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}|null */
    private function navigationPageItem(int $id): ?array
    {
        try {
            $stmt = ConnectionFactory::make($this->settings)->prepare("SELECT title, slug FROM cms_contents WHERE id = :id AND content_type = 'page' AND status = 'published' LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }
            return ['label' => (string) $row['title'], 'url' => '/' . trim((string) $row['slug'], '/'), 'type' => 'page', 'enabled' => true, 'requires_plugin' => ''];
        } catch (Throwable) {
            return null;
        }
    }

    private function adForm(AdRepository $repo): string
    {
        $slots = $repo->slots();
        if ($slots === []) {
            $slots = $this->defaultAdSlots();
        }
        $stats = $repo->stats();
        $rows = '';
        foreach ($slots as $i => $slot) {
            $key = (string) ($slot['slot_key'] ?? '');
            $slotStats = $stats[$key] ?? ['impressions' => 0, 'clicks' => 0];
            $rows .= '<tr><td><input name="slots[' . $i . '][slot_key]" value="' . View::escape($key) . '" placeholder="home_top"></td>' .
                '<td><input name="slots[' . $i . '][label]" value="' . View::escape((string) ($slot['label'] ?? '广告位')) . '"></td>' .
                '<td><input name="slots[' . $i . '][placement]" value="' . View::escape((string) ($slot['placement'] ?? '')) . '"></td>' .
                '<td><label class="inline"><input type="checkbox" name="slots[' . $i . '][enabled]" value="1"' . ((int) ($slot['enabled'] ?? 0) === 1 ? ' checked' : '') . '> 启用</label></td>' .
                '<td>' . (int) $slotStats['impressions'] . '</td><td>' . (int) $slotStats['clicks'] . '</td></tr>' .
                '<tr><td colspan="6"><textarea name="slots[' . $i . '][html]" rows="4" placeholder="广告 HTML，脚本和危险属性会在前台输出时过滤">' . View::escape((string) ($slot['html'] ?? '')) . '</textarea>' .
                '<p class="muted">预览：' . AdRenderer::sanitizeHtml((string) ($slot['html'] ?? '')) . '</p></td></tr>';
        }
        $next = count($slots);
        $rows .= '<tr><td><input name="slots[' . $next . '][slot_key]" placeholder="custom_slot"></td><td><input name="slots[' . $next . '][label]" placeholder="自定义广告位"></td><td><input name="slots[' . $next . '][placement]" placeholder="主题插槽"></td><td><label class="inline"><input type="checkbox" name="slots[' . $next . '][enabled]" value="1"> 启用</label></td><td>0</td><td>0</td></tr>' .
            '<tr><td colspan="6"><textarea name="slots[' . $next . '][html]" rows="4" placeholder="新增广告 HTML"></textarea></td></tr>';

        return '<h1>广告统计</h1><p class="muted">Core 统一管理广告位、验证文件和基础曝光/点击统计；主题只消费同一份广告数据。</p>' .
            '<form method="post" action="/admin/ads">' . CsrfToken::field() .
            '<h2>广告验证文件</h2><label>ads.txt<textarea name="ads_txt" rows="5" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0">' . View::escape($repo->adsTxt()) . '</textarea></label>' .
            '<p><a class="button" href="/ads.txt" target="_blank" rel="noopener">打开 /ads.txt</a></p>' .
            '<h2>广告位</h2><table><thead><tr><th>插槽键</th><th>名称</th><th>位置说明</th><th>状态</th><th>曝光</th><th>点击</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<button type="submit">保存广告设置</button></form>';
    }

    /** @return list<array<string,mixed>> */
    private function adSlotInput(Request $request): array
    {
        $input = $request->input('slots', []);
        if (!is_array($input)) {
            return [];
        }
        $slots = [];
        foreach ($input as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $slots[] = [
                'slot_key' => (string) ($slot['slot_key'] ?? ''),
                'label' => (string) ($slot['label'] ?? ''),
                'placement' => (string) ($slot['placement'] ?? ''),
                'html' => (string) ($slot['html'] ?? ''),
                'enabled' => (string) ($slot['enabled'] ?? '') === '1',
            ];
        }

        return $slots;
    }

    /** @return list<array<string,mixed>> */
    private function defaultAdSlots(): array
    {
        return [
            ['slot_key' => 'home_top', 'label' => '首页顶部', 'placement' => 'home_top', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'home_banner', 'label' => '首页横幅', 'placement' => 'home_banner', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'article_top', 'label' => '文章顶部', 'placement' => 'article_top', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'article_bottom', 'label' => '文章底部', 'placement' => 'article_bottom', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'archive_top', 'label' => '列表顶部', 'placement' => 'archive_top', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'sidebar', 'label' => '侧栏', 'placement' => 'sidebar', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'footer_top', 'label' => '页脚上方', 'placement' => 'footer_top', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'head', 'label' => 'Head 验证代码', 'placement' => 'head', 'html' => '', 'enabled' => 0],
            ['slot_key' => 'footer', 'label' => 'Footer 代码', 'placement' => 'footer', 'html' => '', 'enabled' => 0],
        ];
    }

    private function mediaLibrary(): MediaLibrary
    {
        return new MediaLibrary(ConnectionFactory::make($this->settings), $this->root() . '/content/uploads', (array) $this->settings->get('media', []));
    }

    private function siteBrandUrl(string $url, bool $favicon): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 地址格式无效。');
        }
        $parts = parse_url($url);
        if (str_starts_with($url, '/')) {
            $path = (string) (is_array($parts) ? ($parts['path'] ?? '') : $url);
        } else {
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 地址只允许站内绝对路径或 http/https 图片地址。');
            }
            $path = (string) ($parts['path'] ?? '');
        }

        $pattern = $favicon ? '/\.(avif|gif|ico|jpe?g|png|svg|webp)$/i' : '/\.(avif|gif|jpe?g|png|svg|webp)$/i';
        if ($path !== '' && preg_match($pattern, $path) !== 1) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 地址必须指向图片文件。');
        }

        return $url;
    }

    /** @return array{site_logo_url:string,site_favicon_url:string} */
    private function siteBrandUploads(int $adminId): array
    {
        return [
            'site_logo_url' => $this->siteBrandUploadUrl($_FILES['site_logo_file'] ?? null, false, $adminId),
            'site_favicon_url' => $this->siteBrandUploadUrl($_FILES['site_favicon_file'] ?? null, true, $adminId),
        ];
    }

    private function siteBrandUploadUrl(mixed $file, bool $favicon, int $adminId): string
    {
        if (!is_array($file) || !isset($file['name'], $file['tmp_name'])) {
            return '';
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 上传失败，错误码：' . $error);
        }
        $tmpName = (string) $file['tmp_name'];
        $originalName = (string) $file['name'];
        $size = (int) ($file['size'] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 上传文件无效。');
        }
        if ($size > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 图片不能超过 5MB。');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = $favicon ? ['avif', 'gif', 'ico', 'jpg', 'jpeg', 'png', 'webp'] : ['avif', 'gif', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException(($favicon ? 'Favicon' : 'Logo') . ' 只支持常见图片格式。');
        }

        $id = $this->mediaLibrary()->uploadLocalFile($tmpName, $originalName, $adminId);

        return '/media/' . $id . '/' . rawurlencode($this->siteBrandFileName($originalName, $favicon ? 'favicon.' . $extension : 'logo.' . $extension));
    }

    private function siteBrandFileName(string $name, string $fallback): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        return $name !== '' ? $name : $fallback;
    }

    private function mediaLimit(string $key, int $default): int
    {
        $limits = (array) $this->settings->get('media', []);
        return (int) ($limits[$key] ?? $default);
    }

    /** @return list<array{name:string,tmp_name:string,size:int,error:int}> */
    private function uploadedFiles(array $files): array
    {
        $normalized = [];
        $names = $files['name'] ?? [];
        if (!is_array($names)) {
            return isset($files['name'], $files['tmp_name']) ? [[
                'name' => (string) $files['name'],
                'tmp_name' => (string) $files['tmp_name'],
                'size' => (int) ($files['size'] ?? 0),
                'error' => (int) ($files['error'] ?? UPLOAD_ERR_OK),
            ]] : [];
        }
        foreach ($names as $i => $name) {
            $normalized[] = [
                'name' => (string) $name,
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'size' => (int) ($files['size'][$i] ?? 0),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_OK),
            ];
        }

        return $normalized;
    }

    /** @return list<string> */
    private function enabledPluginIds(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("SELECT plugin_id FROM cms_plugins WHERE status = 'Enabled'");
        } catch (Throwable) {
            return [];
        }
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (string) $row['plugin_id'];
        }

        return $ids;
    }

    /** @param list<string> $enabledPlugins @return list<array{id:string,name:string,version:string,author:string,current:bool,compatible:bool,usable:bool,valid:bool,required_plugins:list<string>,settings_schema:array<string,mixed>,reason:string,description:string}> */
    private function themeRows(string $root, ThemeManager $manager, array $enabledPlugins): array
    {
        $rows = [];
        foreach (glob($root . '/content/themes/*', GLOB_ONLYDIR) ?: [] as $themeDir) {
            $id = basename($themeDir);
            try {
                $runtime = $manager->load($id);
                $status = $manager->describe($id, $runtime->manifest, $enabledPlugins);
                $rows[] = [
                    'id' => $id,
                    'name' => $runtime->manifest->name,
                    'version' => $runtime->manifest->version,
                    'author' => $runtime->manifest->author,
                    'current' => $status['current'],
                    'compatible' => $status['compatible'],
                    'usable' => $status['usable'],
                    'valid' => true,
                    'required_plugins' => $runtime->manifest->requiredPlugins,
                    'settings_schema' => $runtime->manifest->settingsSchema,
                    'reason' => $status['reason'],
                    'description' => $this->themeManifestDescription($runtime->manifest->settingsSchema),
                ];
            } catch (Throwable $exception) {
                $rows[] = [
                    'id' => $id,
                    'name' => 'Invalid theme',
                    'version' => '',
                    'author' => '',
                    'current' => $id === $manager->activeThemeId(),
                    'compatible' => false,
                    'usable' => false,
                    'valid' => false,
                    'required_plugins' => [],
                    'settings_schema' => [],
                    'reason' => $exception->getMessage(),
                    'description' => '',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    private function themeIdFromSettingsPath(string $path): string
    {
        if (preg_match('#^/admin/themes/([^/]+)/settings$#', $path, $matches) !== 1) {
            throw new \RuntimeException('主题设置路径无效。');
        }

        $themeId = rawurldecode((string) $matches[1]);
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $themeId)) {
            throw new \RuntimeException('主题 ID 无效。');
        }

        return $themeId;
    }

    private function requestQueryFlag(string $name): bool
    {
        $value = $_GET[$name] ?? null;

        return $value !== null && $value !== '' && $value !== '0';
    }

    /** @param array<string, mixed> $schema @return array<string, array<string, mixed>> */
    private function themeSettingsSchema(array $schema, bool $includeHidden = false): array
    {
        $fields = [];
        $order = 0;
        foreach ($schema as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $name = (string) $key;
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $name)) {
                continue;
            }
            $hidden = $this->themeSettingIsDeveloperOnly($name, $definition);
            if ($hidden && !$includeHidden) {
                continue;
            }
            $type = $this->normalizeThemeSettingType((string) ($definition['type'] ?? 'text'));
            $fields[$name] = [
                'name' => $name,
                'type' => $type,
                'raw_type' => (string) ($definition['type'] ?? 'text'),
                'label' => $this->themeSettingLabel($name, $definition),
                'description' => trim((string) ($definition['description'] ?? '')),
                'group' => $this->themeSettingGroup($definition),
                'order' => (int) ($definition['order'] ?? $order),
                'default' => $definition['default'] ?? '',
                'options' => is_array($definition['options'] ?? null) ? $definition['options'] : [],
                'placeholder' => trim((string) ($definition['placeholder'] ?? '')),
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'step' => $definition['step'] ?? null,
                'hidden' => $hidden,
            ];
            $order++;
        }

        uasort($fields, static fn (array $a, array $b): int => ((int) $a['order'] <=> (int) $b['order']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $fields;
    }

    /** @param array<string, array<string, mixed>> $schema */
    private function themeSettingsNav(array $schema): string
    {
        $groups = [];
        foreach ($schema as $field) {
            $group = (string) $field['group'];
            $groups[$group] = true;
        }
        $html = '';
        foreach (array_keys($groups) as $group) {
            $html .= '<a href="#theme-group-' . View::escape($this->anchorId($group)) . '">' . View::escape($group) . '</a>';
        }

        return $html !== '' ? $html : '<span class="muted">无可视化设置</span>';
    }

    /** @param array<string, array<string, mixed>> $schema */
    private function themeSettingsSections(string $themeId, array $schema): string
    {
        if ($schema === []) {
            return '';
        }
        $current = $this->settings->get('theme.settings.' . $themeId, []);
        $current = is_array($current) ? $current : [];
        $groups = [];
        foreach ($schema as $field) {
            if (($field['hidden'] ?? false) === true) {
                continue;
            }
            $groups[(string) $field['group']][] = $field;
        }

        $html = '';
        foreach ($groups as $group => $fields) {
            $html .= '<section class="admin-card admin-theme-settings-section" id="theme-group-' . View::escape($this->anchorId((string) $group)) . '"><h2>' . View::escape((string) $group) . '</h2><div class="admin-theme-field-grid">';
            foreach ($fields as $field) {
                $value = $current[(string) $field['name']] ?? $field['default'];
                $html .= $this->renderThemeSettingField($field, $value);
            }
            $html .= '</div></section>';
        }

        return $html;
    }

    /** @param array<string, mixed> $field */
    private function renderThemeSettingField(array $field, mixed $value): string
    {
        $name = (string) $field['name'];
        $id = 'theme-setting-' . $name;
        $label = View::escape((string) $field['label']);
        $desc = trim((string) $field['description']);
        $description = $desc !== '' ? '<p class="admin-field-description">' . View::escape($desc) . '</p>' : '';
        $inputName = 'settings[' . View::escape($name) . ']';
        $placeholder = (string) $field['placeholder'];
        $placeholderAttr = $placeholder !== '' ? ' placeholder="' . View::escape($placeholder) . '"' : '';
        $valueString = (string) (is_bool($value) ? ($value ? '1' : '0') : $value);
        $type = (string) $field['type'];

        $control = match ($type) {
            'textarea', 'richtext' => '<textarea id="' . View::escape($id) . '" name="' . $inputName . '" rows="' . ($type === 'richtext' ? '8' : '4') . '"' . $placeholderAttr . '>' . View::escape($valueString) . '</textarea>',
            'select' => $this->renderThemeSelect($field, $valueString, $id, $inputName),
            'radio' => $this->renderThemeChoices($field, $valueString, $inputName, false),
            'checkbox' => $this->renderThemeChoices($field, is_array($value) ? $value : explode(',', $valueString), $inputName, true),
            'toggle' => '<label class="admin-toggle"><input type="checkbox" name="' . $inputName . '" value="1"' . ($this->truthy($value) ? ' checked' : '') . '><span></span><strong>' . $label . '</strong></label>',
            'color' => '<input id="' . View::escape($id) . '" type="color" name="' . $inputName . '" value="' . View::escape($valueString !== '' ? $valueString : '#1663f1') . '">',
            'image', 'icon' => $this->renderThemeMediaField($field, $valueString, $id, $inputName, $placeholderAttr),
            'url' => '<input id="' . View::escape($id) . '" type="text" inputmode="url" name="' . $inputName . '" value="' . View::escape($valueString) . '"' . $placeholderAttr . '>',
            'email' => '<input id="' . View::escape($id) . '" type="email" name="' . $inputName . '" value="' . View::escape($valueString) . '"' . $placeholderAttr . '>',
            'number', 'range' => '<input id="' . View::escape($id) . '" type="' . View::escape($type) . '" name="' . $inputName . '" value="' . View::escape($valueString) . '"' . $this->numberAttributes($field) . $placeholderAttr . '>',
            'text' => '<input id="' . View::escape($id) . '" type="text" name="' . $inputName . '" value="' . View::escape($valueString) . '"' . $placeholderAttr . '>',
            default => '<div class="admin-compat-note">此字段类型暂不支持可视化编辑：' . View::escape((string) $field['raw_type']) . '</div>',
        };

        if ($type === 'toggle') {
            return '<div class="admin-theme-field admin-theme-field-toggle">' . $control . $description . '</div>';
        }

        return '<label class="admin-theme-field" for="' . View::escape($id) . '"><span>' . $label . '</span>' . $control . $description . '</label>';
    }

    /** @param array<string, mixed> $field */
    private function renderThemeMediaField(array $field, string $value, string $id, string $inputName, string $placeholderAttr): string
    {
        $name = (string) $field['name'];
        $preview = $value !== '' && $this->safeThemeMediaPreviewUrl($value)
            ? '<img src="' . View::escape($value) . '" alt="" loading="lazy">'
            : '<span>未设置图片</span>';

        return '<div class="admin-theme-media-field">' .
            '<div class="admin-theme-media-preview">' . $preview . '</div>' .
            '<div class="admin-theme-media-controls">' .
            '<input id="' . View::escape($id) . '" type="text" inputmode="url" name="' . $inputName . '" value="' . View::escape($value) . '"' . $placeholderAttr . '>' .
            '<div class="admin-theme-media-upload"><input type="file" name="theme_assets[' . View::escape($name) . ']" accept="image/*"><button type="submit" name="theme_asset_upload" value="' . View::escape($name) . '" class="admin-button-secondary">上传并保存</button></div>' .
            '<p class="admin-field-description">可以填写已有图片地址，也可以直接上传图片到媒体库并自动回填。</p>' .
            '</div></div>';
    }

    private function safeThemeMediaPreviewUrl(string $url): bool
    {
        return str_starts_with($url, '/') || preg_match('/^https?:\/\//i', $url) === 1;
    }

    /** @param array<string, mixed> $field */
    private function renderThemeSelect(array $field, string $value, string $id, string $inputName): string
    {
        $html = '<select id="' . View::escape($id) . '" name="' . $inputName . '">';
        foreach ($this->themeOptions($field['options'] ?? []) as $optionValue => $optionLabel) {
            $html .= '<option value="' . View::escape((string) $optionValue) . '"' . ((string) $optionValue === $value ? ' selected' : '') . '>' . View::escape((string) $optionLabel) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param array<string, mixed> $field */
    private function renderThemeChoices(array $field, mixed $value, string $inputName, bool $multiple): string
    {
        $selected = is_array($value) ? array_map('strval', $value) : [(string) $value];
        $html = '<div class="admin-choice-list">';
        foreach ($this->themeOptions($field['options'] ?? []) as $optionValue => $optionLabel) {
            $checked = in_array((string) $optionValue, $selected, true) ? ' checked' : '';
            $name = $multiple ? $inputName . '[]' : $inputName;
            $html .= '<label><input type="' . ($multiple ? 'checkbox' : 'radio') . '" name="' . $name . '" value="' . View::escape((string) $optionValue) . '"' . $checked . '> ' . View::escape((string) $optionLabel) . '</label>';
        }

        return $html . '</div>';
    }

    /** @param array<string, mixed> $field */
    private function numberAttributes(array $field): string
    {
        $attrs = '';
        foreach (['min', 'max', 'step'] as $name) {
            if ($field[$name] !== null && $field[$name] !== '') {
                $attrs .= ' ' . $name . '="' . View::escape((string) $field[$name]) . '"';
            }
        }

        return $attrs;
    }

    /** @param array<string, mixed> $options @return array<string, string> */
    private function themeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $clean = [];
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $optionValue = (string) ($value['value'] ?? $key);
                $optionLabel = (string) ($value['label'] ?? $optionValue);
            } else {
                $optionValue = is_int($key) ? (string) $value : (string) $key;
                $optionLabel = (string) $value;
            }
            $clean[$optionValue] = $optionLabel;
        }

        return $clean;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $schema */
    private function themeDetailsPanel(array $row, array $schema): string
    {
        $required = $row['required_plugins'] === [] ? '无' : implode('、', array_map('strval', $row['required_plugins']));
        $status = ((bool) $row['compatible']) ? '兼容' : '不兼容';
        $reason = (string) $row['reason'];
        $developerCount = 0;
        foreach ($schema as $name => $definition) {
            if (is_array($definition) && $this->themeSettingIsDeveloperOnly((string) $name, $definition)) {
                $developerCount++;
            }
        }

        return '<section class="admin-card admin-theme-details" id="theme-details"><h2>主题详情</h2><dl class="admin-theme-meta">' .
            '<div><dt>主题 ID</dt><dd><code>' . View::escape((string) $row['id']) . '</code></dd></div>' .
            '<div><dt>版本</dt><dd>v' . View::escape((string) $row['version']) . '</dd></div>' .
            '<div><dt>作者</dt><dd>' . View::escape((string) $row['author']) . '</dd></div>' .
            '<div><dt>状态</dt><dd>' . View::escape($status) . ($reason !== '' ? ' · ' . View::escape($reason) : '') . '</dd></div>' .
            '<div><dt>必需插件</dt><dd>' . View::escape($required) . '</dd></div>' .
            '<div><dt>开发字段</dt><dd>' . View::escape((string) $developerCount) . ' 个已从普通设置隐藏</dd></div>' .
            '</dl></section>';
    }

    /** @param array<string, mixed> $schema */
    private function themeManifestDescription(array $schema): string
    {
        foreach (['description', 'site_description', 'hero_subtitle'] as $key) {
            $definition = $schema[$key] ?? null;
            if (is_array($definition) && trim((string) ($definition['default'] ?? '')) !== '') {
                return trim((string) $definition['default']);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $definition */
    private function themeSettingIsDeveloperOnly(string $name, array $definition): bool
    {
        $ui = (string) ($definition['ui'] ?? '');
        $scope = (string) ($definition['scope'] ?? '');
        if (in_array($ui, ['hidden', 'developer'], true) || $scope === 'developer') {
            return true;
        }

        return in_array($name, ['release_version', 'official_build_note'], true);
    }

    private function normalizeThemeSettingType(string $type): string
    {
        return match (strtolower($type)) {
            'string' => 'text',
            'bool', 'boolean' => 'toggle',
            'int', 'integer', 'float' => 'number',
            'wysiwyg', 'html' => 'richtext',
            default => strtolower($type),
        };
    }

    /** @param array<string, mixed> $definition */
    private function themeSettingLabel(string $name, array $definition): string
    {
        $label = trim((string) ($definition['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return str_replace(' ', ' ', ucwords(str_replace('_', ' ', $name)));
    }

    /** @param array<string, mixed> $definition */
    private function themeSettingGroup(array $definition): string
    {
        $group = trim((string) ($definition['group'] ?? ($definition['section'] ?? '基础设置')));

        return $group !== '' ? $group : '基础设置';
    }

    private function anchorId(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?: '', '-'));

        return $slug !== '' ? $slug : substr(sha1($value), 0, 8);
    }

    /** @param array<string, array<string, mixed>> $schema @param array<string, mixed> $current @return array<string, mixed> */
    private function sanitizeThemeSettings(array $schema, mixed $input, array $current = []): array
    {
        $input = is_array($input) ? $input : [];
        $settings = $current;
        foreach ($schema as $key => $definition) {
            if (($definition['hidden'] ?? false) === true) {
                continue;
            }
            $name = (string) $key;
            $type = (string) ($definition['type'] ?? 'text');
            $raw = $input[$name] ?? null;
            $settings[$name] = match ($type) {
                'toggle' => in_array($raw, ['1', 1, true, 'true', 'on'], true),
                'checkbox' => array_values(array_map('strval', is_array($raw) ? $raw : [])),
                'number', 'range' => $this->sanitizeThemeNumber($definition, $raw),
                'url', 'image', 'icon' => $this->sanitizeThemeUrl($name, $definition, $raw),
                'email' => $this->sanitizeThemeEmail($name, $raw),
                'color' => $this->sanitizeThemeColor($name, $definition, $raw),
                'select', 'radio' => $this->sanitizeThemeChoice($name, $definition, $raw),
                default => trim((string) ($raw ?? '')),
            };
        }

        return $settings;
    }

    /** @param array<string, array<string, mixed>> $schema @return array<string,string> */
    private function themeUploadedAssetUrls(array $schema, int $adminId): array
    {
        $files = $_FILES['theme_assets'] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }
        $urls = [];
        foreach ($files['name'] as $field => $originalName) {
            $field = (string) $field;
            $definition = $schema[$field] ?? null;
            if (!is_array($definition) || !in_array((string) ($definition['type'] ?? ''), ['image', 'icon'], true) || ($definition['hidden'] ?? false) === true) {
                continue;
            }
            $error = (int) ($files['error'][$field] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException($this->themeSettingLabel($field, $definition) . ' 上传失败，请重新选择图片。');
            }
            $tmp = (string) ($files['tmp_name'][$field] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new \RuntimeException($this->themeSettingLabel($field, $definition) . ' 上传文件无效。');
            }
            $this->assertThemeAssetLooksLikeImage((string) $originalName, $tmp, $this->themeSettingLabel($field, $definition));
            $mediaId = $this->mediaLibrary()->uploadLocalFile($tmp, (string) $originalName, $adminId);
            $media = $this->mediaLibrary()->find($mediaId);
            if (!is_array($media) || (string) ($media['media_type'] ?? '') !== 'image') {
                throw new \RuntimeException($this->themeSettingLabel($field, $definition) . ' 只能上传图片文件。');
            }
            $urls[$field] = '/media/' . $mediaId;
        }

        return $urls;
    }

    private function assertThemeAssetLooksLikeImage(string $originalName, string $tmp, string $label): void
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            throw new \RuntimeException($label . ' 只能上传 JPG、PNG、GIF、WebP 或 AVIF 图片。');
        }
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmp);
            if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
                throw new \RuntimeException($label . ' 只能上传图片文件。');
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function sanitizeThemeNumber(array $definition, mixed $raw): int|float
    {
        $value = is_numeric($raw) ? (float) $raw : (float) ($definition['default'] ?? 0);
        if (($definition['min'] ?? null) !== null) {
            $value = max($value, (float) $definition['min']);
        }
        if (($definition['max'] ?? null) !== null) {
            $value = min($value, (float) $definition['max']);
        }

        return fmod($value, 1.0) === 0.0 ? (int) $value : $value;
    }

    /** @param array<string, mixed> $definition */
    private function sanitizeThemeUrl(string $name, array $definition, mixed $raw): string
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return $value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !preg_match('/^https?:\/\//i', $value)) {
            throw new \RuntimeException($this->themeSettingLabel($name, $definition) . ' 必须填写有效的 http/https 地址、站内路径或锚点。');
        }

        return $value;
    }

    private function sanitizeThemeEmail(string $name, mixed $raw): string
    {
        $value = trim((string) ($raw ?? ''));
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException($name . ' 必须填写有效邮箱地址。');
        }

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function sanitizeThemeColor(string $name, array $definition, mixed $raw): string
    {
        $value = trim((string) ($raw ?? ($definition['default'] ?? '#1663f1')));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new \RuntimeException($this->themeSettingLabel($name, $definition) . ' 必须填写有效的 HEX 颜色值。');
        }

        return strtolower($value);
    }

    /** @param array<string, mixed> $definition */
    private function sanitizeThemeChoice(string $name, array $definition, mixed $raw): string
    {
        $value = trim((string) ($raw ?? ''));
        $options = $this->themeOptions($definition['options'] ?? []);
        if ($options !== [] && !array_key_exists($value, $options)) {
            throw new \RuntimeException($this->themeSettingLabel($name, $definition) . ' 的选项无效。');
        }

        return $value;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, ['1', 1, true, 'true', 'on'], true);
    }

    private function removeThemeDirectory(string $dir): void
    {
        $root = realpath($this->root() . '/content/themes');
        $target = realpath($dir);
        if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('主题目录不在允许删除范围内。');
        }
        $items = scandir($target);
        if ($items === false) {
            throw new \RuntimeException('无法读取主题目录。');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $target . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeThemeDirectory($path);
            } elseif (!@unlink($path)) {
                throw new \RuntimeException('无法删除主题文件：' . $item);
            }
        }
        if (!@rmdir($target)) {
            throw new \RuntimeException('无法删除主题目录。');
        }
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutator */
    private function writeConfig(string $root, callable $mutator): void
    {
        $target = $root . '/config/app.php';
        $current = is_file($target) ? require $target : [];
        if (!is_array($current)) {
            throw new \RuntimeException('Config file is invalid.');
        }
        $next = $mutator($current);
        $tmp = $target . '.config';
        $backup = $target . '.config.bak';
        if (@file_put_contents($tmp, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($next, true) . ";\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary config.');
        }
        if (is_file($target) && !copy($target, $backup)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to create config backup.');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            if (is_file($backup)) {
                copy($backup, $target);
                @unlink($backup);
            }
            throw new \RuntimeException('Unable to activate config.');
        }
        @unlink($backup);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutator */
    private function writeThemeConfig(string $root, callable $mutator): void
    {
        $target = $root . '/config/app.php';
        $current = is_file($target) ? require $target : [];
        if (!is_array($current)) {
            throw new \RuntimeException('Config file is invalid.');
        }
        $next = $mutator($current);
        $tmp = $target . '.theme';
        $backup = $target . '.theme.bak';
        if (@file_put_contents($tmp, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($next, true) . ";\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary theme config.');
        }
        if (is_file($target) && !copy($target, $backup)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to create theme config backup.');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            if (is_file($backup)) {
                copy($backup, $target);
                @unlink($backup);
            }
            throw new \RuntimeException('Unable to activate theme config.');
        }
        @unlink($backup);
    }

    /** @param list<mixed> $dependencies */
    private function pluginDependencySummary(array $dependencies): string
    {
        if ($dependencies === []) {
            return '无必需依赖';
        }
        $labels = [];
        foreach ($dependencies as $dependency) {
            $pluginId = is_array($dependency) ? (string) ($dependency['plugin_id'] ?? '') : (string) $dependency;
            if ($pluginId === '') {
                continue;
            }
            $range = '';
            if (is_array($dependency)) {
                $min = (string) ($dependency['min_version'] ?? $dependency['min'] ?? '');
                $max = (string) ($dependency['max_version'] ?? $dependency['max'] ?? '');
                $range = trim(($min !== '' ? ' >= ' . $min : '') . ($max !== '' ? ' < ' . $max : ''));
            }
            $labels[] = AdminUiText::pluginName($pluginId, $pluginId) . ($range !== '' ? '（' . $range . '）' : '');
        }

        return $labels === [] ? '依赖声明无效' : implode('；', $labels);
    }

    /** @param list<mixed> $dependencies */
    private function pluginDependencyWarning(\PDO $pdo, string $pluginId, array $dependencies): string
    {
        foreach ($dependencies as $dependency) {
            $depId = is_array($dependency) ? (string) ($dependency['plugin_id'] ?? '') : (string) $dependency;
            if ($depId === '') {
                return '依赖声明无效，请检查插件详情。';
            }
            $stmt = $pdo->prepare('SELECT name, version, status FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
            $stmt->execute([':plugin_id' => $depId]);
            $row = $stmt->fetch();
            if (!is_array($row) || (string) ($row['status'] ?? '') !== PluginLifecycle::ENABLED) {
                return AdminUiText::pluginName($pluginId, $pluginId) . ' 需要先安装并启用 ' . AdminUiText::pluginName($depId, (string) ($row['name'] ?? $depId)) . ' 插件。';
            }
            $version = (string) ($row['version'] ?? '');
            $min = is_array($dependency) ? (string) ($dependency['min_version'] ?? $dependency['min'] ?? '') : '';
            $max = is_array($dependency) ? (string) ($dependency['max_version'] ?? $dependency['max'] ?? '') : '';
            if ($min !== '' && version_compare($version, $min, '<')) {
                return AdminUiText::pluginName($pluginId, $pluginId) . ' 需要更新 ' . AdminUiText::pluginName($depId, (string) ($row['name'] ?? $depId)) . ' 插件。';
            }
            if ($max !== '' && version_compare($version, $max, '>=')) {
                return AdminUiText::pluginName($pluginId, $pluginId) . ' 暂不兼容当前 ' . AdminUiText::pluginName($depId, (string) ($row['name'] ?? $depId)) . ' 插件版本。';
            }
        }

        return '';
    }

    /** @param list<mixed> $capabilities */
    private function isContentModule(array $capabilities, string $pluginId = '', mixed $manifest = null, array $row = []): bool
    {
        $source = (string) ($row['source'] ?? '');
        $trustLevel = (string) ($row['trust_level'] ?? '');
        $manifestType = is_object($manifest) && property_exists($manifest, 'type') ? (string) $manifest->type : '';
        if (str_starts_with($pluginId, 'official.') || $source === 'official_market' || $source === 'bundled_official' || $trustLevel === 'trusted_php' || $manifestType === 'system-plugin') {
            return false;
        }

        return in_array('blocks.register', array_map('strval', $capabilities), true);
    }

    private function safeExtensionReturn(string $value, bool $defaultModule): string
    {
        if ($value === 'modules' || $value === '/admin/modules') {
            return '/admin/modules';
        }
        if ($value === 'plugins' || $value === '/admin/plugins') {
            return '/admin/plugins';
        }

        return $defaultModule ? '/admin/modules' : '/admin/plugins';
    }

    private function pathId(string $path): int
    {
        if (preg_match('#^/admin/payments/([1-9][0-9]{0,17})(?:/|$)#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function pathAuthorizationId(string $path): int
    {
        if (preg_match('#^/admin/payments/[1-9][0-9]{0,17}/authorizations/([1-9][0-9]{0,17})/revoke$#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function pathWebhookReceiptId(string $path): int
    {
        if (preg_match('#^/admin/payments/[1-9][0-9]{0,17}/webhooks/([1-9][0-9]{0,17})/status$#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function pathEntitlementId(string $path): int
    {
        if (preg_match('#^/admin/payments/[1-9][0-9]{0,17}/entitlements/([1-9][0-9]{0,17})/revoke$#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function pathPaymentActionId(string $path, string $action): int
    {
        if (!in_array($action, ['capture', 'cancel', 'sync', 'refund'], true)) {
            return 0;
        }
        if (preg_match('#^/admin/payments/([1-9][0-9]{0,17})/' . preg_quote($action, '#') . '$#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function canonicalPathId(string $value): int
    {
        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : 0;
    }

    private function storedPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function siteDefaultCurrency(): string
    {
        $configured = (string) $this->settings->get('payment.default_currency', 'USD');
        try {
            CurrencyRegistry::require($configured);
            return CurrencyRegistry::normalizeCode($configured);
        } catch (\InvalidArgumentException) {
            return 'USD';
        }
    }

    private function normalizeAdminCurrency(string $currency): string
    {
        try {
            CurrencyRegistry::require($currency);
            return CurrencyRegistry::normalizeCode($currency);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('请选择受支持的币种。');
        }
    }

    /** @param array<string,mixed> $data */
    private function cardDeliveryFormCurrency(array $data): string
    {
        try {
            return $this->normalizeAdminCurrency((string) ($data['currency'] ?? $this->siteDefaultCurrency()));
        } catch (\InvalidArgumentException) {
            return $this->siteDefaultCurrency();
        }
    }

    /** @param array<string,mixed> $meta */
    private function contentMetaCurrency(array $meta, string $key): string
    {
        try {
            return $this->normalizeAdminCurrency((string) ($meta[$key] ?? $this->siteDefaultCurrency()));
        } catch (\InvalidArgumentException) {
            return $this->siteDefaultCurrency();
        }
    }

    private function currencySelect(string $name, string $selected, string $label = '币种'): string
    {
        try {
            $selected = CurrencyRegistry::normalizeCode($selected);
        } catch (\InvalidArgumentException) {
            $selected = $this->siteDefaultCurrency();
        }
        $options = '';
        foreach (CurrencyRegistry::enabled() as $code => $currency) {
            $options .= '<option value="' . View::escape($code) . '"' . ($selected === $code ? ' selected' : '') . '>' .
                View::escape($code . ' — ' . $currency['name']) . '</option>';
        }

        return '<label>' . View::escape($label) . '<select name="' . View::escape($name) . '">' . $options . '</select></label>';
    }

    /** @param list<string> $supported */
    private function currencySelectForProvider(string $name, string $selected, array $supported, string $label = '默认币种'): string
    {
        try {
            $selected = CurrencyRegistry::normalizeCode($selected);
        } catch (\InvalidArgumentException) {
            $selected = $supported[0] ?? $this->siteDefaultCurrency();
        }
        $supported = array_map(static fn (string $code): string => strtoupper($code), $supported);
        if ($supported !== [] && !in_array($selected, $supported, true)) {
            $selected = $supported[0];
        }
        $options = '';
        foreach (CurrencyRegistry::enabled() as $code => $currency) {
            if (!in_array($code, $supported, true)) {
                continue;
            }
            $options .= '<option value="' . View::escape($code) . '"' . ($selected === $code ? ' selected' : '') . '>' .
                View::escape($code . ' — ' . $currency['name']) . '</option>';
        }

        return '<label>' . View::escape($label) . '<select name="' . View::escape($name) . '">' . $options . '</select></label>';
    }

    private function moneyInputValue(mixed $amountMinor, string $currency): string
    {
        try {
            return Money::fromMinor(is_int($amountMinor) ? $amountMinor : (string) $amountMinor, $currency);
        } catch (\InvalidArgumentException) {
            return '0.00';
        }
    }

    private function moneyInputToMinor(Request $request, string $amountName, string $currency, string $legacyMinorName = ''): int
    {
        $amount = $request->input($amountName, null);
        if ($amount === null && $legacyMinorName !== '') {
            $legacy = $request->input($legacyMinorName, '0');
            if (is_int($legacy)) {
                return $legacy;
            }
            if (is_string($legacy) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $legacy) === 1) {
                return (int) $legacy;
            }
        }

        try {
            return Money::toMinor((string) ($amount ?? ''), $currency);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException('金额格式无效：' . $exception->getMessage());
        }
    }

    private function moneyLabel(mixed $amountMinor, string $currency): string
    {
        if (!$this->paymentAmountMinorIsDisplayable($amountMinor) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return '支付金额无效';
        }

        try {
            return Money::format(is_int($amountMinor) ? $amountMinor : (string) $amountMinor, $currency, true);
        } catch (\InvalidArgumentException) {
            return '支付金额无效';
        }
    }

    private function paymentAmountMinorIsDisplayable(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1;
    }

    private function paymentStatusLabel(string $status): string
    {
        return [
            'pending' => '待处理',
            'authorized' => '已授权',
            'paid' => '已支付',
            'partially_refunded' => '部分退款',
            'refunded' => '已退款',
            'failed' => '失败',
            'cancelled' => '已取消',
        ][$status] ?? '支付状态无效';
    }

    private function paymentRefundStatusLabel(string $status): string
    {
        return [
            'pending' => '待处理',
            'completed' => '已完成',
            'failed' => '失败',
            'cancelled' => '已取消',
        ][$status] ?? '退款状态无效';
    }

    private function paymentAuthorizationStatusLabel(string $status): string
    {
        return [
            'active' => '有效',
            'revoked' => '已撤销',
            'expired' => '已过期',
        ][$status] ?? '授权状态无效';
    }

    private function paymentEntitlementStatusLabel(string $status): string
    {
        return [
            'active' => '有效',
            'revoked' => '已撤销',
            'expired' => '已过期',
        ][$status] ?? '权益状态无效';
    }

    private function paymentWebhookReceiptStatusLabel(string $status): string
    {
        return [
            'received' => '已接收',
            'processed' => '已处理',
            'ignored' => '已忽略',
            'failed' => '失败',
        ][$status] ?? 'Webhook 状态无效';
    }

    private function paymentAuthorizationEventTypeLabel(string $eventType): string
    {
        return [
            'created' => '已创建',
            'consumed' => '已使用',
            'revoked' => '已撤销',
            'expired' => '已过期',
        ][$eventType] ?? '授权事件无效';
    }

    private function paymentAuthorizationUsageLabel(mixed $usedCount, mixed $maxUses): string
    {
        $usedCount = $this->storedNonNegativeInt($usedCount);
        $maxUses = $this->storedNonNegativeInt($maxUses);
        if ($usedCount === null || $maxUses === null) {
            return '无效';
        }

        return (string) $usedCount . ' / ' . ($maxUses > 0 ? (string) $maxUses : '不限');
    }

    private function paymentDetailIdLabel(mixed $value): string
    {
        $id = $this->storedPositiveInt($value);

        return $id !== null ? (string) $id : '无效';
    }

    private function paymentTimestampLabel(mixed $value): string
    {
        if (!is_string($value) || !$this->paymentCanonicalUtcTimestamp($value)) {
            return '支付时间无效';
        }

        return $value;
    }

    private function paymentOptionalTimestampLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->paymentTimestampLabel($value);
    }

    private function paymentCanonicalUtcTimestamp(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $value, $matches) !== 1) {
            return false;
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return false;
        }

        return (int) $matches[4] <= 23 && (int) $matches[5] <= 59 && (int) $matches[6] <= 59;
    }

    private function storedNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function paymentStatusOptions(string $selected): string
    {
        $statuses = ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'];
        return implode('', array_map(fn (string $status): string => '<option value="' . View::escape($status) . '"' . ($selected === $status ? ' selected' : '') . '>' . View::escape($this->paymentStatusLabel($status)) . '</option>', $statuses));
    }

    /** @param array<string,int|string> $status */
    private function trustedPaymentStatusHtml(array $status): string
    {
        $currency = (string) ($status['currency'] ?? '');

        return '<h2>Subject 可信状态</h2><table><tbody><tr><th>币种</th><td>' . View::escape($currency) . '</td></tr>' .
            '<tr><th>可信状态</th><td>' . View::escape((string) ($status['status'] ?? 'unpaid')) . '</td></tr>' .
            '<tr><th>已付总额</th><td>' . View::escape($this->moneyLabel($status['paid_minor'] ?? 0, $currency)) . '</td></tr>' .
            '<tr><th>已完成退款</th><td>' . View::escape($this->moneyLabel($status['refunded_minor'] ?? 0, $currency)) . '</td></tr>' .
            '<tr><th>净支付</th><td>' . View::escape($this->moneyLabel($status['net_paid_minor'] ?? 0, $currency)) . '</td></tr></tbody></table>';
    }

    /** @param list<array{currency:string,payment_count:int,amount_minor:int|string,refunded_minor:int|string,net_paid_minor:int|string}> $summary */
    private function paymentSummaryHtml(array $summary): string
    {
        if ($summary === []) {
            return '<table><thead><tr><th>币种</th><th>记录数</th><th>账面金额</th><th>已完成退款</th><th>净额</th></tr></thead><tbody><tr><td colspan="5" class="muted">当前筛选暂无汇总</td></tr></tbody></table>';
        }

        $rows = '';
        foreach ($summary as $row) {
            $currency = (string) ($row['currency'] ?? '');
            $rows .= '<tr><td>' . View::escape($currency) . '</td><td>' . (int) ($row['payment_count'] ?? 0) . '</td><td>' .
                View::escape($this->moneyLabel($row['amount_minor'] ?? 0, $currency)) . '</td><td>' .
                View::escape($this->moneyLabel($row['refunded_minor'] ?? 0, $currency)) . '</td><td>' .
                View::escape($this->moneyLabel($row['net_paid_minor'] ?? 0, $currency)) . '</td></tr>';
        }

        return '<table><thead><tr><th>币种</th><th>记录数</th><th>账面金额</th><th>已完成退款</th><th>净额</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private function paymentDiagnosticsHtml(array $diagnostics): string
    {
        if ($diagnostics === []) {
            return '<h2>支付诊断</h2><p class="muted">暂无支付失败诊断。</p>';
        }

        $rows = '';
        foreach ($diagnostics as $row) {
            $subject = View::escape((string) ($row['subject_type'] ?? '')) . '<br><span class="muted">' . View::escape((string) ($row['subject_id'] ?? '')) . '</span>';
            $http = isset($row['http_status']) && (int) $row['http_status'] > 0 ? (string) (int) $row['http_status'] : '-';
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $checkoutHost = (string) ($metadata['checkout_host'] ?? '-');
            $errorParts = [];
            foreach (['provider_error_type', 'provider_error_code'] as $key) {
                $value = (string) ($row[$key] ?? '');
                if ($value !== '') {
                    $errorParts[] = $value;
                }
            }
            $error = $errorParts !== [] ? implode(' / ', $errorParts) . '<br>' : '';
            $error .= View::escape((string) ($row['safe_error_message'] ?? ''));
            $rows .= '<tr><td>' . View::escape($this->paymentTimestampLabel($row['created_at'] ?? null)) . '</td><td>' .
                View::escape((string) ($row['provider_id'] ?? '')) . '</td><td>' . $subject . '</td><td>' .
                View::escape((string) ($row['stage'] ?? 'provider.create')) . '</td><td>' .
                View::escape((string) ($row['status'] ?? 'failed')) . '</td><td>' . View::escape($http) . '</td><td>' .
                View::escape((string) ($row['provider_request_id'] ?? '-')) . '</td><td>' . View::escape($checkoutHost !== '' ? $checkoutHost : '-') . '</td><td>' . $error . '</td></tr>';
        }

        return '<h2>支付诊断</h2><p class="muted">最近支付失败会记录在这里，前台仍只显示安全提示，不展示密钥或 Authorization 头。</p>' .
            '<table><thead><tr><th>时间</th><th>Provider</th><th>Subject</th><th>阶段</th><th>状态</th><th>HTTP</th><th>Stripe Request ID</th><th>Checkout Host</th><th>安全错误</th></tr></thead><tbody>' .
            $rows . '</tbody></table>';
    }

    /** @param list<array<string,mixed>> $payments */
    private function paymentCsv(array $payments): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['ID', 'Subject Type', 'Subject ID', 'Provider', 'Remote ID', 'Reference', 'Status', 'Amount Minor', 'Refunded Minor', 'Net Paid Minor', 'Currency', 'Idempotency Key', 'Created At', 'Updated At'], ',', '"', '\\');
        foreach ($payments as $payment) {
            fputcsv($handle, $this->paymentCsvRow($payment), ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /** @param array<string,mixed> $payment @return list<string> */
    private function paymentCsvRow(array $payment): array
    {
        $id = $this->paymentExportPositiveInteger($payment['id'] ?? null);
        $amountMinor = $this->paymentExportPositiveInteger($payment['amount_minor'] ?? null);
        $refundedMinor = $this->paymentExportNonNegativeInteger($payment['refunded_minor'] ?? null);
        $netPaidMinor = $this->paymentExportNonNegativeInteger($payment['net_paid_minor'] ?? null);
        if ($refundedMinor > $amountMinor || $netPaidMinor !== $amountMinor - $refundedMinor) {
            throw new PaymentException('Payment export row is invalid.');
        }
        $currency = (string) ($payment['currency'] ?? '');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Payment export row is invalid.');
        }
        $status = (string) ($payment['status'] ?? '');
        if (!in_array($status, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment export row is invalid.');
        }
        $createdAt = $this->paymentExportTimestamp((string) ($payment['created_at'] ?? ''));
        $updatedAt = $this->paymentExportTimestamp((string) ($payment['updated_at'] ?? ''));

        return [
            (string) $id,
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['subject_type'] ?? ''))),
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['subject_id'] ?? ''))),
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['provider_id'] ?? ''))),
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['remote_id'] ?? ''))),
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['reference'] ?? ''))),
            $this->csvCell($status),
            (string) $amountMinor,
            (string) $refundedMinor,
            (string) $netPaidMinor,
            $this->csvCell($currency),
            $this->csvCell($this->paymentExportDisplayText((string) ($payment['idempotency_key'] ?? ''))),
            $this->csvCell($createdAt),
            $this->csvCell($updatedAt),
        ];
    }

    private function paymentExportPositiveInteger(mixed $value): int
    {
        $integer = $this->paymentExportNonNegativeInteger($value);
        if ($integer <= 0) {
            throw new PaymentException('Payment export row is invalid.');
        }

        return $integer;
    }

    private function paymentExportNonNegativeInteger(mixed $value): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new PaymentException('Payment export row is invalid.');
        }
        if ($integer < 0) {
            throw new PaymentException('Payment export row is invalid.');
        }

        return $integer;
    }

    private function paymentExportDisplayText(string $value): string
    {
        if ($value !== '' && ($value !== trim($value) || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || $this->paymentDisplayValueContainsSecret($value))) {
            throw new PaymentException('Payment export row is invalid.');
        }

        return $value;
    }

    private function paymentExportTimestamp(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value) !== 1) {
            throw new PaymentException('Payment export row is invalid.');
        }

        return $value;
    }

    private function csvCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $trimmed = ltrim($value);
        if ($trimmed !== '' && str_contains('=+-@', $trimmed[0])) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param array<string,mixed> $filters @return array<string,string> */
    private function paymentExportAuditFilters(array $filters): array
    {
        $safe = [];
        foreach (['q', 'status', 'provider_id', 'subject_type', 'currency', 'created_from', 'created_to'] as $key) {
            $value = (string) ($filters[$key] ?? '');
            if ($value === '') {
                continue;
            }
            if ($key === 'currency') {
                if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
                    continue;
                }
            }
            $safe[$key] = mb_substr($value, 0, 191);
        }

        return $safe;
    }

    /** @return array{id:int,email:string,display_name:string}|Response */
    private function requireAdmin(): array|Response
    {
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $auth = new AdminAuthenticator($pdo);
            $user = $auth->user();
            if (is_array($user) && (int) ($user['id'] ?? 0) > 0 && !(new AdminSessionService($pdo))->touchCurrent((int) $user['id'])) {
                $auth->logout();
                return Response::redirect('/admin/login');
            }
        } catch (Throwable $exception) {
            $this->logger->error('Admin guard failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::redirect('/admin/login');
        }

        return $user === null || $user['id'] <= 0 ? Response::redirect('/admin/login') : $user;
    }

    private function adminHasCapability(string $capability): bool
    {
        $sessionUser = $_SESSION['admin_user'] ?? null;
        if (!is_array($sessionUser) || !array_key_exists('capabilities', $sessionUser)) {
            return true;
        }
        $capabilities = $sessionUser['capabilities'];
        if (!is_array($capabilities)) {
            return false;
        }
        $capabilities = array_values(array_map('strval', $capabilities));

        return in_array('*', $capabilities, true)
            || in_array('admin.super', $capabilities, true)
            || in_array($capability, $capabilities, true);
    }
}

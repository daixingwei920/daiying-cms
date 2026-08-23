<?php

declare(strict_types=1);

namespace Cms\Core\Admin;

use Cms\Core\CardDelivery\CardDeliveryException;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\CardDelivery\CardDeliveryService;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Auth\AdminMfaService;
use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Export\OfficialExportContentImporter;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Import\ImportException;
use Cms\Core\Import\ImportService;
use Cms\Core\Import\WordPressImporter;
use Cms\Core\Import\ZBlogImporter;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Market\HttpMarketClient;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\OfflineMarketClient;
use Cms\Core\Market\MarketApiClientInterface;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Navigation\NavigationBuilder;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Cms\Core\Theme\LocalThemePackageInstaller;
use Cms\Core\Theme\ThemeManager;
use Cms\Core\UrlMapping\UrlMappingRepository;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Support\AdminUiText;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentEntitlementService;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
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

            if ((new AdminMfaService($pdo))->isEnabled((int) $user['id'])) {
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
            (new AuditLogger($pdo))->record('admin', $user['id'] ?? null, 'admin.login', ['email' => $email, 'ip' => $ip, 'mfa' => false]);
        } catch (Throwable $exception) {
            $this->logger->error('Admin login failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('管理员登录', $this->loginHtml('登录服务暂不可用。')), 500);
        }

        return Response::redirect('/admin');
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
            (new AuditLogger($pdo))->record('admin', $adminId, 'admin.login', ['email' => (string) $pending['email'], 'ip' => (string) $pending['ip'], 'mfa' => true]);

            return Response::redirect('/admin');
        } catch (Throwable $exception) {
            $this->logger->error('Admin MFA challenge failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('管理员二次验证', $this->mfaChallengeHtml('二次验证服务暂不可用。')), 500);
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

        $links = [
            '<a class="button" href="/admin/content">内容列表</a>',
            '<a class="button" href="/admin/content/new">新建内容</a>',
            '<a class="button" href="/admin/settings">站点设置</a>',
            '<a class="button" href="/admin/security">后台安全</a>',
            '<a class="button" href="/admin/media">媒体库</a>',
            '<a class="button" href="/admin/navigation">导航菜单</a>',
            '<a class="button" href="/admin/themes">主题</a>',
            '<a class="button" href="/admin/plugins">插件</a>',
            '<a class="button" href="/admin/card-delivery">发卡管理</a>',
            '<a class="button" href="/admin/payments">支付</a>',
            '<a class="button" href="/admin/recovery">恢复</a>',
            '<a class="button" href="/admin/transfer">导入导出</a>',
            '<a class="button" href="/admin/update">更新</a>',
        ];
        if ((bool) $this->settings->get('market.enabled', false)) {
            $marketLinks = [
                '<a class="button" href="/admin/market/plugins">插件市场</a>',
                '<a class="button" href="/admin/market/themes">主题市场</a>',
                '<a class="button" href="/admin/market-server/operations">市场运营</a>',
                '<a class="button" href="/admin/market-server/review">审核队列</a>',
                '<a class="button" href="/admin/market-server/download-audits">下载审计</a>',
                '<a class="button" href="/admin/market-server/payments">付款结算</a>',
                '<a class="button" href="/admin/market-server/ai-settings">AI 设置</a>',
            ];
            if ((bool) $this->settings->get('market.developer_mode', false)) {
                array_splice($marketLinks, 3, 0, ['<a class="button" href="/admin/market-server/developer">开发者中心</a>']);
            }
            array_splice($links, 5, 0, $marketLinks);
        }

        $body = '<h1>管理后台</h1><p class="muted">当前登录：' . View::escape($user['display_name']) . '</p>' .
            '<p>CMS 内容、媒体、主题、插件、更新和恢复主链路已启用。</p>' .
            '<p>' . implode(' ', $links) . '</p>' .
            '<form method="post" action="/admin/logout">' . CsrfToken::field() . '<button type="submit">退出登录</button></form>';

        return Response::html(View::page('管理后台', $body));
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

            return Response::html(View::page('后台安全', $this->adminSecurityHtml($mfa, $adminId, $message)));
        } catch (Throwable $exception) {
            $this->logger->error('Admin security page failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('后台安全', '<h1>后台安全</h1><p class="error">后台安全设置暂不可用。</p>'), 500);
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
            $this->writeConfig($this->root(), static function (array $config) use ($input): array {
                $config['site'] = is_array($config['site'] ?? null) ? $config['site'] : [];
                $config['site']['name'] = $input['site_name'];
                $config['site']['url'] = $input['site_url'];
                $config['seo'] = is_array($config['seo'] ?? null) ? $config['seo'] : [];
                $config['seo']['robots_index'] = $input['robots_index'];
                $config['market'] = is_array($config['market'] ?? null) ? $config['market'] : [];
                $config['market']['enabled'] = $input['market_enabled'];
                $config['market']['developer_mode'] = $input['developer_mode'] && $input['market_enabled'];

                return $config;
            });
            (new AuditLogger(ConnectionFactory::make($this->settings)))->record('admin', (int) ($guard['id'] ?? 0), 'site.settings_saved', [
                'robots_index' => $input['robots_index'],
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
        $robotsIndex = (bool) $this->settings->get('seo.robots_index', true);
        $marketEnabled = (bool) $this->settings->get('market.enabled', false);
        $developerMode = (bool) $this->settings->get('market.developer_mode', false) && $marketEnabled;

        return '<h1>站点设置</h1>' . $message .
            '<p class="muted">这里保存 CMS Core 站点身份和基础 SEO 抓取策略。关闭索引后，前台页面会输出 noindex,nofollow，robots.txt 会禁止抓取，sitemap.xml 不列出公开内容。</p>' .
            '<form method="post" action="/admin/settings">' . CsrfToken::field() .
            '<label>站点名称<input name="site_name" maxlength="120" value="' . View::escape($siteName) . '" required></label>' .
            '<label>站点 URL<input name="site_url" value="' . View::escape($siteUrl) . '" placeholder="https://example.com"></label>' .
            '<p class="muted">站点 URL 用于 canonical、robots.txt 和 sitemap.xml。留空时前台会回退到本地基准地址。</p>' .
            '<label><input type="checkbox" name="robots_index" value="1"' . ($robotsIndex ? ' checked' : '') . '> 允许搜索引擎索引本站</label>' .
            '<label><input type="checkbox" name="market_enabled" value="1"' . ($marketEnabled ? ' checked' : '') . '> 启用后台市场与平台服务入口</label>' .
            '<label><input type="checkbox" name="developer_mode" value="1"' . ($developerMode ? ' checked' : '') . '> 启用开发者模式</label>' .
            '<p class="muted">开发者模式用于本机扩展开发、提交审核和查看开发者项目；普通站点可保持关闭。</p>' .
            '<button type="submit">保存站点设置</button></form>' .
            '<p><a class="button" href="/admin">返回后台首页</a></p>';
    }

    /** @return array{site_name:string,site_url:string,robots_index:bool,market_enabled:bool,developer_mode:bool} */
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

        return [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'robots_index' => (string) $request->input('robots_index', '') === '1',
            'market_enabled' => (string) $request->input('market_enabled', '') === '1',
            'developer_mode' => (string) $request->input('developer_mode', '') === '1',
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
            $items = $repo->latest();
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
            $rows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape(AdminUiText::contentType((string) $item['content_type'])) .
                '</td><td>' . View::escape((string) $item['title']) . '</td><td>' .
                View::escape($this->contentStatusLabel((string) $item['status'])) . '</td><td><a class="button" href="/admin/content/edit/' . (int) $item['id'] . '">编辑</a>' . $view . ' <a class="button" href="' . View::escape($preview) . '">预览</a> ' . $delete . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无内容</td></tr>';

        $body = '<h1>内容管理</h1><p><a class="button" href="/admin/content/new">新建内容</a> <a class="button" href="/admin/navigation">设置前台导航</a></p>' .
            $notice . '<table><thead><tr><th>ID</th><th>类型</th><th>标题</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('内容管理', $body));
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

        try {
            $library = $this->mediaLibrary();
            $items = $library->list([
                'type' => (string) ($_GET['type'] ?? ''),
                'filename' => (string) ($_GET['filename'] ?? ''),
                'status' => (string) ($_GET['status'] ?? ''),
            ]);
            $rows = '';
            foreach ($items as $item) {
                $mediaUrl = '/media/' . (int) $item['id'];
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
        $body = '<h1>媒体库</h1>' .
            '<form method="get" action="/admin/media"><label>类型<select name="type"><option value="">全部</option><option value="image">图片</option><option value="audio">音频</option><option value="video">视频</option><option value="attachment">附件</option></select></label><label>文件名<input name="filename" value="' . View::escape((string) ($_GET['filename'] ?? '')) . '"></label><label>状态<select name="status"><option value="">全部</option><option value="Active">可用</option><option value="Deleted">已删除</option></select></label><button type="submit">筛选</button></form>' .
            '<form method="post" action="/admin/media/upload" enctype="multipart/form-data" id="media-upload">' . CsrfToken::field() .
            '<label>上传文件<input type="file" name="media_files[]" multiple></label><progress id="media-progress" max="100" value="0"></progress><p class="muted" id="media-error">支持图片、音频、视频、PDF、TXT、ZIP 和 Office 附件。</p><button type="submit">上传</button></form>' .
            '<script>document.getElementById("media-upload").addEventListener("submit",function(){document.getElementById("media-progress").value=15;});</script>' .
            '<table><thead><tr><th>ID</th><th>类型</th><th>文件名</th><th>大小</th><th>状态</th><th>上传时间</th><th>URL</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('媒体库', $body));
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
        $rows = '';
        foreach ($this->themeRows($root, $manager, $enabledPlugins) as $row) {
            $settingsForm = '';
            if ($row['valid']) {
                $settingsForm = '<form method="post" action="/admin/themes/settings">' . CsrfToken::field() .
                    '<input type="hidden" name="theme_id" value="' . View::escape($row['id']) . '">' .
                    $this->themeSettingFields($row['id'], $row['settings_schema']) .
                    '<button type="submit">保存设置</button></form>';
            }
            $activate = $row['valid'] && $row['usable'] && !$row['current']
                ? '<form method="post" action="/admin/themes/activate">' . CsrfToken::field() .
                    '<input type="hidden" name="theme_id" value="' . View::escape($row['id']) . '"><button type="submit">启用</button></form>'
                : '';
            $rows .= '<tr><td>' . View::escape($row['id']) . ($row['current'] ? ' <strong>当前</strong>' : '') . '</td><td>' .
                View::escape($row['name']) . '</td><td>' . View::escape($row['version']) . '</td><td>' .
                View::escape($row['author']) . '</td><td>' . View::escape($row['compatible'] ? '兼容' : '不兼容') .
                '</td><td>' . View::escape($row['required_plugins'] !== [] ? implode(', ', $row['required_plugins']) : '无') .
                '</td><td>' . View::escape($row['reason'] !== '' ? $row['reason'] : '可用') .
                '</td><td>' . $activate . $settingsForm . '</td></tr>';
        }

        $warning = '';
        try {
            $manager->assertUsable($active, $enabledPlugins);
        } catch (Throwable $exception) {
            $warning = '<p class="error">当前主题不可用，前台会自动使用安全主题渲染。原因：' . View::escape($exception->getMessage()) . '</p>';
        }

        $body = '<h1>主题</h1><p class="muted">主题只负责 UI。切换主题会验证 Manifest、Core 兼容性、必需插件和模板入口，并保留每个主题自己的设置。</p>' .
            $warning .
            '<form method="post" action="/admin/themes/local-install" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>上传主题 ZIP 安装<input type="file" name="theme_zip" accept=".zip" required></label><button type="submit">上传并安装主题</button></form>' .
            '<p class="muted">主题包必须包含单独主题目录和 theme.json，只会写入 content/themes/{theme_id}。</p>' .
            '<table><thead><tr><th>ID</th><th>名称</th><th>版本</th><th>作者</th><th>兼容</th><th>必需插件</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('主题', $body));
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
            $settings = $this->sanitizeThemeSettings($runtime->manifest->settingsSchema, $request->input('settings', []));
            $this->writeThemeConfig($root, static function (array $items) use ($themeId, $settings): array {
                $items['theme']['settings'][$themeId] = $settings;
                return $items;
            });
            (new AuditLogger($pdo))->record('admin', $guard['id'] ?? null, 'theme.settings_saved', ['theme_id' => $themeId]);
        } catch (Throwable $exception) {
            $this->logger->error('Theme settings save failed', ['source' => 'Core', 'theme_id' => $themeId, 'error' => $exception->getMessage()]);
            return Response::html(View::page('主题设置保存失败', '<h1>主题设置保存失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a class="button" href="/admin/themes">返回主题</a></p>'), 400);
        }

        return Response::redirect('/admin/themes');
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
            foreach ($stmt->fetchAll() as $row) {
                $pluginId = (string) $row['plugin_id'];
                $manifest = $manifests[$pluginId] ?? null;
                $status = (string) $row['status'];
                $next = $status === PluginLifecycle::ENABLED ? PluginLifecycle::DISABLED : PluginLifecycle::ENABLED;
                $capabilities = json_decode((string) ($row['capabilities_json'] ?? '[]'), true) ?: [];
                if ($this->isContentModule($capabilities) !== $isModule) {
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
            }
        } catch (Throwable $exception) {
            $this->logger->error('Plugin index failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page($title, '<h1>' . View::escape($title) . '</h1><p class="error">扩展服务暂不可用，请先运行迁移。</p>'), 500);
        }

        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">' . View::escape($emptyLabel) . '</td></tr>';
        $body = '<h1>' . View::escape($title) . '</h1><p class="muted">停用只会停止' . View::escape($itemLabel) . '运行，不会删除文件、配置或业务数据。技术信息、卸载和危险操作请进入详情页。</p>' .
            '<form method="post" action="/admin/plugins/local-preview" enctype="multipart/form-data">' . CsrfToken::field() .
            '<input type="hidden" name="return_to" value="' . View::escape($returnTo) . '">' .
            '<label>上传 ZIP 安装<input type="file" name="plugin_zip" accept=".zip" required></label><button type="submit">上传并扫描</button></form>' .
            '<p class="muted">本地插件由管理员自行承担信任责任，安装前会先进行安全扫描和预检。</p>' .
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
        $returnTo = $this->safeExtensionReturn((string) $request->input('from', ''), $this->isContentModule($capabilities));
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

    public function paymentsIndex(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $repo = new PaymentRepository(ConnectionFactory::make($this->settings));
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
                $capabilities = $provider !== null ? $this->adminTags($provider->capabilities()) : $this->adminBadge('未注册', 'warning');
                $defaultCell = $defaultLabel === '无效'
                    ? View::escape($defaultLabel)
                    : $this->adminBadge($defaultLabel, $defaultLabel === '是' ? 'success' : 'muted');
                $rows .= '<tr><td><code class="admin-nowrap">' . View::escape($providerId) . '</code></td><td>' . View::escape($this->paymentProviderDisplayNameLabel($provider, $setting, $providerId)) .
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

        $body = '<h1>支付 Provider 设置</h1><p><a class="button" href="/admin/payments">返回支付管理</a></p>' .
            $notice .
            '<p class="muted">诊断会标记：可用于 Core 支付、默认 Provider 缺少收款能力、托管跳转收银台 URL 未配置、Webhook 密钥未配置、旧支付字段是否已与 Core 标准字段同步。</p>' .
            $repairForm .
            '<table><thead><tr><th>Provider ID</th><th>名称</th><th>必要配置</th><th>启用状态</th><th>默认</th><th>旧字段同步</th><th>诊断</th><th>能力</th><th>公共配置</th><th>密钥掩码</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            $chainCheck .
            $form;

        return $this->paymentAdminNoStore(Response::html(View::page('支付 Provider 设置', $body)));
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

        return '<h2 id="provider-form">配置 Provider：' . View::escape($providerId) . '</h2>' .
            '<form method="post" action="/admin/payments/providers/save">' . CsrfToken::field() .
            '<input type="hidden" name="provider_settings_form" value="1">' .
            '<input type="hidden" name="provider_id" value="' . View::escape($providerId) . '">' .
            '<label>Provider ID<input value="' . View::escape($providerId) . '" disabled></label>' .
            '<label>显示名称<input name="display_name" value="' . View::escape($displayName) . '"></label>' .
            '<label>状态<select name="status">' . $statusOptions . '</select></label>' .
            '<label><input type="checkbox" name="default_provider" value="1"' . $checked . '> 设为默认 Provider</label>' .
            $fields .
            '<details><summary>高级公共 JSON（可选）</summary><p class="muted">普通配置无需填写；这里只放非密钥字段，会与上方字段合并。</p><textarea name="public_config_json" rows="3">' . View::escape($advanced) . '</textarea></details>' .
            '<button type="submit">保存 Provider 配置</button></form>';
    }

    /** @param array<string,mixed> $public @param list<string> $secretKeys */
    private function paymentProviderSchemaFields(string $providerId, array $public, array $secretKeys): string
    {
        if ($providerId === ManualPaymentProvider::PROVIDER_ID) {
            $instructions = (string) ($public['instructions'] ?? '请按站点说明完成线下付款，管理员确认后自动发卡。');
            return '<fieldset><legend>人工确认支付</legend>' .
                '<label>付款说明<textarea name="manual_instructions" rows="3">' . View::escape($instructions) . '</textarea></label>' .
                '<p class="muted">启用后，买家下单进入待确认；管理员在支付详情中点击 capture 后，Core Payment 会进入 trusted paid 并触发自动发卡。</p></fieldset>';
        }
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            $checkout = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
            $return = (string) ($public['return_url_base'] ?? '');
            $checkoutSecretHint = in_array('checkout_secret', $secretKeys, true) ? '已配置，留空则保留' : '可选，至少 16 个字符';
            $webhookSecretHint = in_array('webhook_secret', $secretKeys, true) ? '已配置，留空则保留' : '建议配置，用于验签';
            return '<fieldset><legend>托管跳转支付</legend>' .
                '<label>收银台 URL<input name="hosted_checkout_url" value="' . View::escape($checkout) . '" placeholder="https://pay.example.com/checkout"></label>' .
                '<label>回跳 URL Base<input name="hosted_return_url_base" value="' . View::escape($return) . '" placeholder="https://www.example.com"></label>' .
                '<label>Checkout 签名密钥<input name="hosted_checkout_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($checkoutSecretHint) . '"></label>' .
                '<label>Webhook 密钥<input name="hosted_webhook_secret" type="password" autocomplete="new-password" placeholder="' . View::escape($webhookSecretHint) . '"></label>' .
                '<p class="muted">Webhook URL：/payment/webhooks/' . View::escape($providerId) . '</p></fieldset>';
        }

        return '<fieldset><legend>通用 Provider 配置</legend><label>密钥配置（一行一个 KEY=VALUE；留空则保留已有密钥）<textarea name="secrets_text" rows="4"></textarea></label></fieldset>';
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
        $refundForm = in_array((string) $payment['status'], ['paid', 'partially_refunded'], true)
            ? '<h2>发起退款</h2><form method="post" action="/admin/payments/' . $id . '/refund">' . CsrfToken::field() .
                '<label>退款金额（最小货币单位）<input name="amount_minor" type="number" min="1" required></label>' .
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
                    $this->paymentRefundAmountMinor($this->paymentBodyInput($request, 'amount_minor', '')),
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
        }
        if ($this->paymentProviderDefaultFromRequest($request)) {
            $public['default_provider'] = true;
        } else {
            unset($public['default_provider']);
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
        }

        return $secrets;
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

        $body = '<h1>Core 更新</h1><p class="muted">手动更新包会先验证签名、SHA-256、环境版本，并生成准备计划。执行更新会进入维护模式并创建恢复点。</p>' .
            '<form method="post" action="/admin/update/verify">' . CsrfToken::field() .
            '<label>服务器上的更新包路径<input name="package_path" placeholder="/path/to/update.zip"></label>' .
            '<button type="submit">验证/演练更新包</button></form>' .
            '<form method="post" action="/admin/update/execute">' . CsrfToken::field() .
            '<label>服务器上的更新包路径<input name="package_path" placeholder="/path/to/update.zip"></label>' .
            '<label>二次确认<input name="confirmation" placeholder="UPDATE CORE"></label>' .
            '<button type="submit">执行 Core 更新</button></form><h2>更新记录</h2>' . $items;

        return Response::html(View::page('Core 更新', $body));
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
                (string) $this->settings->get('app.version', '0.0.0'),
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
            return Response::html(View::page('Core 更新', $body));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_verify_failed', (string) $request->input('package_path', ''), [
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Update verification failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('Core 更新', '<h1>更新包验证失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
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
                (string) $this->settings->get('app.version', '0.0.0'),
                new SignatureVerifier((string) $this->settings->get('updates.public_key', '')),
            );
            $result = $service->execute((string) $request->input('package_path', ''), (int) ($guard['id'] ?? 0), (string) $request->input('confirmation', ''));
            $this->auditCoreUpdateAction($guard, 'core.update_execute_completed', (string) $request->input('package_path', ''), [
                'status' => (string) ($result['status'] ?? ''),
                'operation_id' => (string) ($result['operation_id'] ?? ''),
                'release_id' => (string) ($result['release_id'] ?? ''),
            ]);
            $body = '<h1>Core 更新完成</h1><pre>' . View::escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            return Response::html(View::page('Core 更新', $body));
        } catch (Throwable $exception) {
            $this->auditCoreUpdateAction($guard, 'core.update_execute_failed', (string) $request->input('package_path', ''), [
                'error' => $this->safeAdminErrorSummary($exception),
            ]);
            $this->logger->error('Update execution failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('Core 更新', '<h1>Core 更新失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 400);
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
        return $this->marketIndex('plugin', '插件市场');
    }

    public function marketThemes(): Response
    {
        return $this->marketIndex('theme', '主题市场');
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
            $authorization = $this->marketClient()->authorizeInstall($marketId, (string) $this->settings->get('site.id', 'local-site'));
            $body = '<h1>安装授权已获取</h1><p class="muted">授权有效期：' . View::escape($authorization->expiresAt) . '</p>' .
                '<form method="post" action="/admin/market/install">' . CsrfToken::field() .
                '<input type="hidden" name="market_id" value="' . View::escape($authorization->marketId) . '">' .
                '<input type="hidden" name="token" value="' . View::escape($authorization->token) . '">' .
                '<input type="hidden" name="expires_at" value="' . View::escape($authorization->expiresAt) . '">' .
                '<input type="hidden" name="package_sha256" value="' . View::escape($authorization->packageSha256) . '">' .
                '<label>市场包 ZIP 路径<input name="package_path" required></label>' .
                '<button type="submit">验证并安装</button></form>';
            return Response::html(View::page('市场安装授权', $body));
        } catch (Throwable $exception) {
            $this->logger->error('Market install authorization failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('市场安装授权', '<h1>授权失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 500);
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
                (string) $request->input('market_id', ''),
                (string) $request->input('expires_at', ''),
                (string) $request->input('package_sha256', ''),
            );
            $root = $this->root();
            $result = (new MarketPackageInstaller($root))->install((string) $request->input('package_path', ''), $authorization, ConnectionFactory::make($this->settings));
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
            '<p class="muted">请输入认证器中的 6 位动态验证码，或输入一枚未使用的恢复码。</p>' .
            '<form method="post" action="/admin/mfa">' . CsrfToken::field() .
            '<label>验证码或恢复码<input name="mfa_code" inputmode="numeric" autocomplete="one-time-code" required></label>' .
            '<button type="submit">完成验证</button></form>' .
            '<form method="post" action="/admin/logout">' . CsrfToken::field() .
            '<button type="submit" class="button-secondary">返回登录</button></form>';
    }

    private function adminSecurityHtml(AdminMfaService $mfa, int $adminId, string $message = ''): string
    {
        $enabled = $mfa->isEnabled($adminId);
        $status = $enabled
            ? '<p class="admin-badge admin-badge-success">MFA 已启用</p>'
            : '<p class="admin-badge">MFA 未启用</p>';
        $body = '<h1>后台安全</h1>' . $message . $status .
            '<p class="muted">Core 后台支持 TOTP 动态验证码和一次性恢复码。启用后，管理员登录必须先通过密码，再完成二次验证。</p>';

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

    private function loginHtml(string $error = ''): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';

        return '<h1>管理员登录</h1>' . $errorHtml .
            '<form method="post" action="/admin/login">' . CsrfToken::field() .
            '<label>邮箱<input name="email" type="email" required></label>' .
            '<label>密码<input name="password" type="password" required></label>' .
            '<button type="submit">登录</button></form>';
    }

    private function marketIndex(string $type, string $title): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $items = $this->marketClient()->search($type);
        } catch (Throwable $exception) {
            $this->logger->error('Market API unavailable; using offline items', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $items = (new OfflineMarketClient())->search($type);
        }

        $rows = '';
        foreach ($items as $item) {
            if ($item->type !== $type) {
                continue;
            }
            $rows .= '<tr><td>' . View::escape($item->name) . '</td><td>' . View::escape($item->version) .
                '</td><td>' . View::escape($item->priceLabel) . '</td><td>' . View::escape($item->reviewStatus) .
                '</td><td>' . View::escape(implode(', ', $item->capabilities)) . '</td><td><form method="post" action="/admin/market/authorize">' .
                CsrfToken::field() . '<input type="hidden" name="market_id" value="' . View::escape($item->marketId) . '">' .
                '<button type="submit">获取安装授权</button></form></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">暂无项目</td></tr>';

        $body = '<h1>' . View::escape($title) . '</h1><p class="muted">市场不可用不会影响网站、后台和已安装扩展。</p>' .
            '<table><thead><tr><th>名称</th><th>版本</th><th>价格</th><th>审核</th><th>能力</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page($title, $body));
    }

    private function marketClient(): MarketApiClientInterface
    {
        $url = (string) $this->settings->get('market.server_url', '');
        if ($url === '') {
            return new OfflineMarketClient();
        }

        return new HttpMarketClient($url, (string) $this->settings->get('market.site_token', ''));
    }

    private function root(): string
    {
        return $this->rootPath ?? dirname(__DIR__, 3);
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
        $statusOptions = '';
        foreach (['draft' => '草稿', 'active' => '启用', 'disabled' => '停用'] as $value => $label) {
            $statusOptions .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $inventoryRows = '';
        foreach ($inventory as $item) {
            $inventoryRows .= '<tr><td>' . (int) $item['id'] . '</td><td>' . View::escape((string) ($item['secret_masked'] ?? '')) . '</td><td>' . View::escape((string) $item['status']) . '</td><td>' . View::escape((string) ($item['order_id'] ?? '')) . '</td><td>' . View::escape((string) ($item['delivered_at'] ?? '')) . '</td><td><form method="post" action="/admin/card-delivery/inventory/disable/' . (int) $item['id'] . '">' . CsrfToken::field() . '<input type="hidden" name="product_id" value="' . $id . '"><button class="admin-danger" type="submit">禁用</button></form></td></tr>';
        }
        $inventoryRows = $inventoryRows !== '' ? $inventoryRows : '<tr><td colspan="6" class="muted">暂无库存卡密</td></tr>';
        $inventoryPanel = $id > 0
            ? '<section class="editor-card"><h2>批量导入卡密</h2><form method="post" action="/admin/card-delivery/inventory/' . $id . '/import">' . CsrfToken::field() . '<label>一行一个卡密，或 CSV 第一列为卡密<textarea name="secrets_text" rows="8"></textarea></label><button type="submit">导入库存</button></form></section>' .
                '<section class="editor-card"><h2>库存</h2><table><thead><tr><th>ID</th><th>卡密</th><th>状态</th><th>订单</th><th>发放时间</th><th>操作</th></tr></thead><tbody>' . $inventoryRows . '</tbody></table></section>'
            : '<section class="editor-card"><h2>库存</h2><p class="muted">保存商品后可批量导入卡密。</p></section>';

        return '<div class="editor-header"><div><h1>' . ($id > 0 ? '编辑发卡商品' : '新建发卡商品') . '</h1><p class="muted">卡密库存独立存储，文章区块只引用发卡商品 ID。</p></div><div class="editor-actions"><a class="button editor-secondary" href="/admin/card-delivery">返回发卡管理</a></div></div>' . $errorHtml .
            '<div class="editor-shell"><div class="editor-main-panel"><section class="editor-card"><h2>商品信息</h2><form method="post" action="' . View::escape($action) . '">' . CsrfToken::field() .
            '<label>商品名称<input name="name" value="' . View::escape((string) ($data['name'] ?? '')) . '" required></label>' .
            '<label>售价（最小货币单位）<input name="price_minor" type="number" min="0" value="' . View::escape((string) ($data['price_minor'] ?? 0)) . '"></label>' .
            '<label>币种<input name="currency" value="' . View::escape((string) ($data['currency'] ?? 'USD')) . '"></label>' .
            '<label>状态<select name="status">' . $statusOptions . '</select></label>' .
            '<label>每单最大购买数量<input name="max_quantity_per_order" type="number" min="1" max="999" value="' . View::escape((string) ($data['max_quantity_per_order'] ?? 1)) . '"></label>' .
            '<label>关联 Commerce 商品 ID（可选）<input name="commerce_product_id" type="number" min="0" value="' . View::escape((string) ($data['commerce_product_id'] ?? 0)) . '"></label>' .
            '<label>说明<textarea name="description" rows="4">' . View::escape((string) ($data['description'] ?? '')) . '</textarea></label>' .
            '<button class="editor-primary" type="submit">保存发卡商品</button></form></section>' . $inventoryPanel . '</div>' .
            '<aside class="editor-side-panel"><section class="editor-card"><h2>商品统计</h2><p>当前库存：' . (int) ($data['available_count'] ?? 0) . '</p><p>已售数量：' . (int) ($data['delivered_count'] ?? 0) . '</p></section></aside></div>';
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
            'price_minor' => (int) $request->input('price_minor', 0),
            'currency' => trim((string) $request->input('currency', 'USD')),
            'status' => (string) $request->input('status', 'active'),
            'max_quantity_per_order' => (int) $request->input('max_quantity_per_order', 1),
            'commerce_product_id' => (int) $request->input('commerce_product_id', 0),
            'description' => trim((string) $request->input('description', '')),
        ];

        try {
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
        foreach (array_values(is_array($blocks) ? $blocks : []) as $i => $block) {
            $type = (string) ($block['type'] ?? 'paragraph');
            $blockHtml .= '<fieldset class="block-card"><legend>区块 ' . ($i + 1) . '</legend><div class="block-card-header"><div><span class="block-card-title">' . View::escape(AdminUiText::blockType($type)) . '</span><p class="muted">区块内容会按当前格式安全保存，旧内容可继续编辑。</p></div><div class="block-card-actions">' .
                '<button name="block_action" value="up:' . $i . '" type="submit">上移</button>' .
                '<button name="block_action" value="down:' . $i . '" type="submit">下移</button>' .
                '<button name="block_action" value="copy:' . $i . '" type="submit">复制</button>' .
                '<button class="editor-danger" name="block_action" value="delete:' . $i . '" type="submit">删除</button></div></div>' .
                '<label>区块类型<select name="blocks[' . $i . '][type]" data-block-type-select>' . $this->blockOptions($type) . '</select></label>' .
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

        return '<div class="editor-header"><div><h1>' . $heading . '</h1><p class="muted">使用内容区块编写文章或页面，旧内容可继续打开、编辑和保存。</p></div><div class="editor-actions">' . $actions . '</div></div>' . $errorHtml .
            '<form method="post" action="' . View::escape($action) . '">' . CsrfToken::field() .
            '<div class="editor-actions"><button class="editor-secondary" type="submit" name="content_action" value="draft">保存草稿</button><button class="editor-primary" type="submit" name="content_action" value="save">保存当前状态</button><button class="editor-primary" type="submit" name="content_action" value="publish">' . $publishLabel . '</button></div>' .
            '<div class="editor-shell"><div class="editor-main-panel"><section class="editor-card"><h2>标题与固定链接</h2>' .
            '<label>内容类型<select name="content_type"><option value="article"' . (($data['type'] ?? 'article') === 'article' ? ' selected' : '') . '>文章</option><option value="page"' . (($data['type'] ?? '') === 'page' ? ' selected' : '') . '>页面</option></select></label>' .
            '<label>标题<input name="title" value="' . View::escape((string) ($data['title'] ?? '')) . '" required></label>' .
            '<label>固定链接<input name="slug" value="' . View::escape((string) ($data['slug'] ?? '')) . '"></label>' .
            '</section><section class="editor-card"><h2>内容区块</h2><p class="muted">用区块卡片组织正文，可添加、复制、上下移动或删除区块。</p>' . $blockHtml .
            '<button name="block_action" value="add" type="submit">新增区块</button></section></div>' .
            '<aside class="editor-side-panel"><section class="editor-card"><h2>发布</h2><p class="muted">当前状态：' . View::escape(AdminUiText::contentStatus($currentStatus)) . '</p>' .
            '<label>状态<select name="status">' . $this->statusOptions($currentStatus) . '</select></label>' .
            '<label>计划发布时间<input name="scheduled_at" value="' . View::escape((string) ($meta['scheduled_at'] ?? '')) . '" placeholder="2026-08-14T12:00:00+00:00"></label>' .
            '<button class="editor-secondary" type="submit" name="content_action" value="draft">保存草稿</button><button class="editor-primary" type="submit" name="content_action" value="publish">' . $publishLabel . '</button></section>' .
            '<section class="editor-card"><h2>付费内容</h2>' .
            '<label><input type="checkbox" name="paid_content_enabled" value="1"' . (($meta['paid_content_enabled'] ?? false) ? ' checked' : '') . '> 启用 Core 付费解锁</label>' .
            '<label>价格（最小货币单位）<input name="paid_content_price_minor" type="number" min="0" value="' . View::escape((string) ($meta['paid_content_price_minor'] ?? 0)) . '"></label>' .
            '<label>币种<input name="paid_content_currency" value="' . View::escape((string) ($meta['paid_content_currency'] ?? 'USD')) . '"></label>' .
            '<label>按钮文字<input name="paid_content_label" value="' . View::escape((string) ($meta['paid_content_label'] ?? '解锁全文')) . '"></label>' .
            '<label>试看区块数<input name="paid_content_preview_blocks" type="number" min="0" max="100" value="' . View::escape((string) ($meta['paid_content_preview_blocks'] ?? 1)) . '"></label></section>' .
            '<section class="editor-card"><h2>分类与标签</h2><label>分类（逗号分隔）<input name="categories" value="' . View::escape(implode(', ', $data['categories'] ?? [])) . '"></label>' .
            '<label>标签（逗号分隔）<input name="tags" value="' . View::escape(implode(', ', $data['tags'] ?? [])) . '"></label></section>' .
            '<section class="editor-card"><h2>SEO</h2>' .
            '<label>SEO 标题<input name="seo_title" value="' . View::escape((string) ($meta['seo_title'] ?? '')) . '"></label>' .
            '<label>SEO 描述<textarea name="seo_description" rows="3">' . View::escape((string) ($meta['seo_description'] ?? '')) . '</textarea></label>' .
            '<label>规范链接<input name="canonical_url" value="' . View::escape((string) ($meta['canonical_url'] ?? '')) . '"></label>' .
            '<label><input type="checkbox" name="robots_index" value="1"' . (($meta['robots_index'] ?? true) ? ' checked' : '') . '> 允许搜索引擎索引</label>' .
            '<label><input type="checkbox" name="robots_follow" value="1"' . (($meta['robots_follow'] ?? true) ? ' checked' : '') . '> 允许搜索引擎跟踪链接</label></section></aside></div></form>' .
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

        return [
            'action' => $action === '' && in_array($contentAction, ['save', 'draft', 'publish'], true) ? 'save' : 'edit',
            'type' => (string) $request->input('content_type', 'article'),
            'title' => trim((string) $request->input('title', '')),
            'slug' => trim((string) $request->input('slug', '')),
            'status' => $status,
            'blocks' => $blocks,
            'categories' => $this->csvList((string) $request->input('categories', '')),
            'tags' => $this->csvList((string) $request->input('tags', '')),
            'meta' => [
                'seo_title' => trim((string) $request->input('seo_title', '')),
                'seo_description' => trim((string) $request->input('seo_description', '')),
                'canonical_url' => trim((string) $request->input('canonical_url', '')),
                'robots_index' => (string) $request->input('robots_index', '') === '1',
                'robots_follow' => (string) $request->input('robots_follow', '') === '1',
                'scheduled_at' => trim((string) $request->input('scheduled_at', '')),
                'paid_content_enabled' => (string) $request->input('paid_content_enabled', '') === '1',
                'paid_content_price_minor' => (string) $request->input('paid_content_price_minor', '0'),
                'paid_content_currency' => (string) $request->input('paid_content_currency', 'USD'),
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
        $types = ['paragraph', 'heading', 'unordered_list', 'ordered_list', 'quote', 'code', 'divider', 'button', 'table', 'raw_text', 'image', 'gallery', 'video', 'audio', 'attachment', 'card_delivery'];
        return implode('', array_map(fn (string $type): string => '<option value="' . View::escape($type) . '"' . ($selected === $type ? ' selected' : '') . '>' . View::escape(AdminUiText::blockType($type)) . '</option>', $types));
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
            'divider' => '<p class="muted">分隔线无正文内容。</p><label>分隔线样式<select name="blocks[' . $i . '][data][style]"><option value="solid">实线</option><option value="dashed"' . (($data['style'] ?? '') === 'dashed' ? ' selected' : '') . '>虚线</option><option value="wide"' . (($data['style'] ?? '') === 'wide' ? ' selected' : '') . '>宽间距</option></select></label><label>上下间距<select name="blocks[' . $i . '][data][spacing]"><option value="normal">标准</option><option value="compact"' . (($data['spacing'] ?? '') === 'compact' ? ' selected' : '') . '>紧凑</option><option value="large"' . (($data['spacing'] ?? '') === 'large' ? ' selected' : '') . '>宽松</option></select></label>',
            'raw_text' => '<label>纯文本<textarea name="blocks[' . $i . '][data][text]" rows="4">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label>',
            'image' => $this->mediaBlockPicker($i, 'media_id', 'image', false, (array) $data) . '<label>替代文字<input name="blocks[' . $i . '][data][alt]" value="' . View::escape((string) ($data['alt'] ?? '')) . '"></label><label>说明文字<input name="blocks[' . $i . '][data][caption]" value="' . View::escape((string) ($data['caption'] ?? '')) . '"></label><label>显示宽度（px）<input name="blocks[' . $i . '][data][width]" type="number" min="0" max="4000" value="' . View::escape((string) ($data['width'] ?? 0)) . '"></label><label>对齐<select name="blocks[' . $i . '][data][alignment]"><option value="none">无</option><option value="left"' . (($data['alignment'] ?? '') === 'left' ? ' selected' : '') . '>左</option><option value="center"' . (($data['alignment'] ?? '') === 'center' ? ' selected' : '') . '>中</option><option value="right"' . (($data['alignment'] ?? '') === 'right' ? ' selected' : '') . '>右</option></select></label><label>链接<input name="blocks[' . $i . '][data][link]" value="' . View::escape((string) ($data['link'] ?? '')) . '"></label>',
            'gallery' => $this->galleryBlockEditor($i, $data),
            'audio' => $this->mediaBlockPicker($i, 'media_id', 'audio', false, (array) $data) . '<label>标题<input name="blocks[' . $i . '][data][title]" value="' . View::escape((string) ($data['title'] ?? '')) . '"></label><input type="hidden" name="blocks[' . $i . '][data][controls]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][controls]" value="1"' . (($data['controls'] ?? true) ? ' checked' : '') . '> 显示播放器控制条</label><label>预加载<select name="blocks[' . $i . '][data][preload]"><option value="metadata">仅元数据</option><option value="none"' . (($data['preload'] ?? '') === 'none' ? ' selected' : '') . '>不预加载</option><option value="auto"' . (($data['preload'] ?? '') === 'auto' ? ' selected' : '') . '>自动</option></select></label>',
            'video' => $this->mediaBlockPicker($i, 'media_id', 'video', false, (array) $data) . $this->mediaBlockPicker($i, 'poster_media_id', 'image', false, (array) $data, '选择封面图片') . '<label>外链视频地址<input name="blocks[' . $i . '][data][source_url]" value="' . View::escape((string) ($data['source_url'] ?? '')) . '" placeholder="https://example.com/video.mp4"></label><input type="hidden" name="blocks[' . $i . '][data][controls]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][controls]" value="1"' . (($data['controls'] ?? true) ? ' checked' : '') . '> 显示播放器控制条</label><input type="hidden" name="blocks[' . $i . '][data][autoplay]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][autoplay]" value="1"' . (($data['autoplay'] ?? false) ? ' checked' : '') . '> 自动播放（将自动静音）</label><input type="hidden" name="blocks[' . $i . '][data][muted]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][muted]" value="1"' . (($data['muted'] ?? false) ? ' checked' : '') . '> 静音</label><input type="hidden" name="blocks[' . $i . '][data][loop]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][loop]" value="1"' . (($data['loop'] ?? false) ? ' checked' : '') . '> 循环播放</label><input type="hidden" name="blocks[' . $i . '][data][playsinline]" value="0"><label><input type="checkbox" name="blocks[' . $i . '][data][playsinline]" value="1"' . (($data['playsinline'] ?? true) ? ' checked' : '') . '> 移动端内联播放</label><label>预加载<select name="blocks[' . $i . '][data][preload]"><option value="metadata">仅元数据</option><option value="none"' . (($data['preload'] ?? '') === 'none' ? ' selected' : '') . '>不预加载</option><option value="auto"' . (($data['preload'] ?? '') === 'auto' ? ' selected' : '') . '>自动</option></select></label>',
            'attachment' => $this->mediaBlockPicker($i, 'media_id', 'attachment', false, (array) $data) . '<label>显示名称<input name="blocks[' . $i . '][data][display_name]" value="' . View::escape((string) ($data['display_name'] ?? '')) . '"></label><label><input type="checkbox" name="blocks[' . $i . '][data][paid_enabled]" value="1"' . (($data['paid_enabled'] ?? false) ? ' checked' : '') . '> 设为付费下载</label><label>价格（最小货币单位）<input name="blocks[' . $i . '][data][price_minor]" type="number" min="0" value="' . View::escape((string) ($data['price_minor'] ?? 0)) . '"></label><label>币种<input name="blocks[' . $i . '][data][currency]" value="' . View::escape((string) ($data['currency'] ?? 'USD')) . '"></label><label>按钮文字<input name="blocks[' . $i . '][data][payment_label]" value="' . View::escape((string) ($data['payment_label'] ?? '解锁下载')) . '"></label>',
            'card_delivery' => $this->cardDeliveryBlockFields($i, $data),
            default => '<label>内容<textarea name="blocks[' . $i . '][data][text]" rows="4">' . View::escape((string) ($data['text'] ?? '')) . '</textarea></label>',
        };
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
        $products = array_map(static fn (array $product): array => [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'price' => (string) ($product['currency'] ?? 'USD') . ' ' . number_format(((int) ($product['price_minor'] ?? 0)) / 100, 2, '.', ''),
            'stock' => (int) ($product['available_count'] ?? 0),
            'status' => (string) ($product['status'] ?? ''),
            'max_quantity' => (int) ($product['max_quantity_per_order'] ?? 1),
        ], $this->cardDeliveryProducts());
        $json = json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if (!is_string($json)) {
            $json = '[]';
        }

        return '<script>window.CMS_CARD_PRODUCTS=' . $json . ';' . <<<'JS'
(function(){
function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function name(i,k){return 'blocks['+i+'][data]['+k+']';}
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
 else if(type==='raw_text'){h='<label>纯文本<textarea name="'+name(i,'text')+'" rows="4"></textarea></label>';}
 else if(type==='image'){h=media(i,'media_id','image',false,'从媒体库选择')+'<label>替代文字<input name="'+name(i,'alt')+'"></label><label>说明文字<input name="'+name(i,'caption')+'"></label><label>显示宽度（px）<input name="'+name(i,'width')+'" type="number" min="0" max="4000" value="0"></label><label>对齐<select name="'+name(i,'alignment')+'"><option value="none">无</option><option value="left">左</option><option value="center">中</option><option value="right">右</option></select></label><label>链接<input name="'+name(i,'link')+'"></label>';}
 else if(type==='gallery'){h=galleryEditor(i);}
 else if(type==='audio'){h=media(i,'media_id','audio',false,'选择音频')+'<label>标题<input name="'+name(i,'title')+'"></label><input type="hidden" name="'+name(i,'controls')+'" value="0"><label><input type="checkbox" name="'+name(i,'controls')+'" value="1" checked> 显示播放器控制条</label><label>预加载<select name="'+name(i,'preload')+'"><option value="metadata">仅元数据</option><option value="none">不预加载</option><option value="auto">自动</option></select></label>';}
 else if(type==='video'){h=media(i,'media_id','video',false,'选择视频')+media(i,'poster_media_id','image',false,'选择封面图片')+'<label>外链视频地址<input name="'+name(i,'source_url')+'" placeholder="https://example.com/video.mp4"></label><input type="hidden" name="'+name(i,'controls')+'" value="0"><label><input type="checkbox" name="'+name(i,'controls')+'" value="1" checked> 显示播放器控制条</label><input type="hidden" name="'+name(i,'autoplay')+'" value="0"><label><input type="checkbox" name="'+name(i,'autoplay')+'" value="1"> 自动播放（将自动静音）</label><input type="hidden" name="'+name(i,'muted')+'" value="0"><label><input type="checkbox" name="'+name(i,'muted')+'" value="1"> 静音</label><input type="hidden" name="'+name(i,'loop')+'" value="0"><label><input type="checkbox" name="'+name(i,'loop')+'" value="1"> 循环播放</label><input type="hidden" name="'+name(i,'playsinline')+'" value="0"><label><input type="checkbox" name="'+name(i,'playsinline')+'" value="1" checked> 移动端内联播放</label><label>预加载<select name="'+name(i,'preload')+'"><option value="metadata">仅元数据</option><option value="none">不预加载</option><option value="auto">自动</option></select></label>';}
 else if(type==='attachment'){h=media(i,'media_id','attachment',false,'选择附件')+'<label>显示名称<input name="'+name(i,'display_name')+'"></label><label><input type="checkbox" name="'+name(i,'paid_enabled')+'" value="1"> 设为付费下载</label><label>价格（最小货币单位）<input name="'+name(i,'price_minor')+'" type="number" min="0" value="0"></label><label>币种<input name="'+name(i,'currency')+'" value="USD"></label><label>按钮文字<input name="'+name(i,'payment_label')+'" value="解锁下载"></label>';}
 else if(type==='card_delivery'){h='<label>发卡商品<select name="'+name(i,'card_product_id')+'" data-card-product-select>'+productOptions()+'</select></label><div class="card-product-summary" data-card-product-summary>'+productSummary(0)+'</div><p><a class="button editor-secondary" href="/admin/card-delivery/new">新建发卡商品</a></p><fieldset><legend>前台展示设置</legend><input type="hidden" name="'+name(i,'show_name')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_name')+'" value="1" checked> 显示商品名称</label><input type="hidden" name="'+name(i,'show_price')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_price')+'" value="1" checked> 显示价格</label><input type="hidden" name="'+name(i,'show_stock')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_stock')+'" value="1" checked> 显示库存</label><input type="hidden" name="'+name(i,'show_button')+'" value="0"><label><input type="checkbox" name="'+name(i,'show_button')+'" value="1" checked> 显示购买按钮</label><label>按钮文字<input name="'+name(i,'button_text')+'" value="立即购买"></label></fieldset>';}
 else {h='<label>内容<textarea name="'+name(i,'text')+'" rows="4"></textarea></label>';}
 return h;
}
function syncList(box){var out=box.querySelector('[data-list-output]');if(out){out.value=[].slice.call(box.querySelectorAll('[data-list-item]')).map(function(input){return input.value;}).join('\n');}}
function syncTable(box){var out=box.querySelector('[data-table-output]');if(out){out.value=[].slice.call(box.querySelectorAll('tbody tr')).map(function(tr){return [].slice.call(tr.querySelectorAll('[data-table-cell]')).map(function(input){return input.value;}).join(' | ');}).join('\n');}}
function syncGallery(box){if(!box){return;}var input=box.querySelector('[data-media-picker-input]'),out=box.querySelector('[data-gallery-caption-output]'),items=box.querySelector('[data-gallery-caption-items]');if(!input||!out||!items){return;}var old={};[].slice.call(box.querySelectorAll('[data-gallery-caption]')).forEach(function(el){old[el.dataset.galleryCaption]=el.value;});var ids=(input.value||'').split(/[,\s]+/).map(function(v){return parseInt(v,10)||0;}).filter(function(v){return v>0;});if(!ids.length){items.innerHTML='<p class="muted">选择图片后可为每张图填写 Caption。</p>';out.value='';return;}var current=[].slice.call(box.querySelectorAll('[data-gallery-caption-row]')).map(function(row){return parseInt(row.dataset.galleryCaptionRow,10)||0;}).filter(function(v){return v>0;});if(current.join(',')!==ids.join(',')){items.innerHTML=ids.map(function(id){return '<div class="gallery-caption-item" data-gallery-caption-row="'+id+'"><span>媒体 #'+id+'</span><input data-gallery-caption="'+id+'" value="'+esc(old[id]||'')+'" placeholder="单张图片 Caption"></div>';}).join('');}out.value=ids.map(function(id){var el=box.querySelector('[data-gallery-caption="'+id+'"]');return id+' | '+(el?el.value:(old[id]||''));}).join('\n');}
document.addEventListener('change',function(e){var select=e.target.closest('[data-block-type-select]');if(select){var card=select.closest('.block-card'), fields=card.querySelector('[data-block-editor-fields]'), i=fields.dataset.blockIndex;fields.innerHTML=render(i,select.value);return;}var product=e.target.closest('[data-card-product-select]');if(product){var summary=product.closest('[data-block-editor-fields]').querySelector('[data-card-product-summary]');if(summary){summary.innerHTML=productSummary(parseInt(product.value,10)||0);}return;}});
document.addEventListener('input',function(e){var list=e.target.closest('[data-list-editor]');if(list){syncList(list);}var table=e.target.closest('[data-table-editor]');if(table){syncTable(table);}var gallery=e.target.closest('[data-gallery-editor]');if(gallery){syncGallery(gallery);}});
document.addEventListener('click',function(e){var list=e.target.closest('[data-list-editor]');if(list){if(e.target.closest('[data-list-add]')){list.querySelector('[data-list-items]').insertAdjacentHTML('beforeend','<div class="block-list-item"><input data-list-item value=""><button type="button" data-list-move="up">上移</button><button type="button" data-list-move="down">下移</button><button type="button" data-list-remove>删除</button></div>');syncList(list);return;}var item=e.target.closest('.block-list-item');if(item&&e.target.closest('[data-list-remove]')){item.remove();syncList(list);return;}if(item&&e.target.closest('[data-list-move="up"]')&&item.previousElementSibling){item.parentElement.insertBefore(item,item.previousElementSibling);syncList(list);return;}if(item&&e.target.closest('[data-list-move="down"]')&&item.nextElementSibling){item.parentElement.insertBefore(item.nextElementSibling,item);syncList(list);return;}}
 var table=e.target.closest('[data-table-editor]');if(table){var body=table.querySelector('[data-table-body]'), first=body.querySelector('tr'), cols=first?first.querySelectorAll('[data-table-cell]').length:2;if(e.target.closest('[data-table-add-row]')){var cells='';for(var c=0;c<cols;c++){cells+='<td><input data-table-cell></td>';}body.insertAdjacentHTML('beforeend','<tr>'+cells+'<td><button type="button" data-table-row-remove>删行</button></td></tr>');syncTable(table);return;}if(e.target.closest('[data-table-add-col]')){body.querySelectorAll('tr').forEach(function(tr){tr.lastElementChild.insertAdjacentHTML('beforebegin','<td><input data-table-cell></td>');});syncTable(table);return;}if(e.target.closest('[data-table-remove-col]')&&cols>1){body.querySelectorAll('tr').forEach(function(tr){var cells=tr.querySelectorAll('td');cells[cells.length-2].remove();});syncTable(table);return;}var row=e.target.closest('tr');if(row&&e.target.closest('[data-table-row-remove]')){row.remove();if(!body.querySelector('tr')){body.insertAdjacentHTML('beforeend','<tr><td><input data-table-cell></td><td><input data-table-cell></td><td><button type="button" data-table-row-remove>删行</button></td></tr>');}syncTable(table);return;}}});
document.querySelectorAll('[data-list-editor]').forEach(syncList);document.querySelectorAll('[data-table-editor]').forEach(syncTable);
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
            '<h2>媒体库选择</h2><div style="display:flex;gap:12px;flex-wrap:wrap"><label>搜索<input id="media-picker-search" placeholder="文件名"></label><label>类型<select id="media-picker-type"><option value="">全部</option><option value="image">图片</option><option value="audio">音频</option><option value="video">视频</option><option value="attachment">附件</option></select></label><button type="button" id="media-picker-close">关闭</button></div>' .
            '<div id="media-picker-results" style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px"></div></div></div>' .
            '<script>window.CMS_MEDIA_PICKER_ITEMS=' . $json . ';' . $this->mediaPickerJavascript() . '</script>';
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

        return array_map(static function (array $item): array {
            $id = (int) ($item['id'] ?? 0);
            $name = (string) ($item['original_name'] ?? ('media-' . $id));
            $url = '/media/' . $id;

            return [
                'id' => $id,
                'filename' => $name,
                'media_type' => (string) ($item['media_type'] ?? ''),
                'mime_type' => (string) ($item['mime_type'] ?? ''),
                'byte_size' => (int) ($item['byte_size'] ?? 0),
                'created_at' => (string) ($item['created_at'] ?? ''),
                'url' => $url,
                'download_url' => $url . '?download=1',
                'thumbnail_url' => (string) ($item['media_type'] ?? '') === 'image' ? $url : '',
            ];
        }, $items);
    }

    private function mediaPickerJavascript(): string
    {
        return <<<'JS'
(function(){
var modal=document.getElementById('media-picker-modal'),results=document.getElementById('media-picker-results'),search=document.getElementById('media-picker-search'),typeSelect=document.getElementById('media-picker-type');
var activeInput=null,activeSummary=null,activeType='',activeMultiple=false;
function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function ids(){return (activeInput&&activeInput.value?activeInput.value:'').split(/[,\s]+/).map(function(v){return parseInt(v,10)||0;}).filter(function(v){return v>0;});}
function writeIds(list){if(activeInput){activeInput.value=list.join(', ');} renderSummary();}
function humanSize(bytes){if(bytes>=1048576){return (bytes/1048576).toFixed(1)+' MB';} return (bytes/1024).toFixed(1)+' KB';}
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
 activeSummary.innerHTML='<ol>'+list.map(function(id){var item=(window.CMS_MEDIA_PICKER_ITEMS||[]).find(function(m){return m.id===id;});var label=item?item.filename:('媒体 #'+id),meta=item?(' · '+item.media_type+' · '+humanSize(item.byte_size)):'';return '<li data-media-selected-id="'+id+'" style="margin:8px 0">'+mediaPreview(item)+'<span>'+esc(label)+esc(meta)+'</span> <button type="button" data-media-move="up">上移</button> <button type="button" data-media-move="down">下移</button> <button type="button" data-media-remove="'+id+'">移除</button></li>';}).join('')+'</ol>';
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
function render(){
 var q=(search.value||'').toLowerCase(), filter=typeSelect.value||activeType;
 var rows=(window.CMS_MEDIA_PICKER_ITEMS||[]).filter(function(item){return (!filter||item.media_type===filter)&&(!q||String(item.filename).toLowerCase().indexOf(q)>=0);});
 results.innerHTML=rows.length?rows.map(function(item){var thumb=item.thumbnail_url?'<img src="'+esc(item.thumbnail_url)+'" alt="" style="width:100%;height:92px;object-fit:cover;border:1px solid #d0d7de;border-radius:4px">':'<div style="height:92px;display:flex;align-items:center;justify-content:center;border:1px solid #d0d7de;border-radius:4px;background:#f6f8fa">'+esc(item.media_type)+'</div>';return '<article style="border:1px solid #d8dee4;border-radius:6px;padding:10px">'+thumb+'<strong style="display:block;margin-top:8px;word-break:break-all">'+esc(item.filename)+'</strong><span class="muted">'+esc(item.media_type)+' · '+esc(humanSize(item.byte_size))+'</span><br><span class="muted">'+esc(item.created_at)+'</span><br><button type="button" data-media-choose="'+item.id+'">选择</button></article>';}).join(''):'<p class="muted">没有可选媒体。</p>';
}
document.addEventListener('click',function(e){
 var open=e.target.closest('.media-picker-open'); if(open){var box=open.closest('.media-picker-field'); activeInput=box.querySelector('[data-media-picker-input="'+open.dataset.targetField+'"]'); activeSummary=box.querySelector('[data-media-picker-summary="'+open.dataset.targetField+'"]'); activeType=open.dataset.mediaType||''; activeMultiple=open.dataset.mediaMultiple==='1'; typeSelect.value=activeType; search.value=''; modal.hidden=false; render(); return;}
 var clear=e.target.closest('.media-picker-clear'); if(clear){var cbox=clear.closest('.media-picker-field'); activeInput=cbox.querySelector('[data-media-picker-input="'+clear.dataset.targetField+'"]'); activeSummary=cbox.querySelector('[data-media-picker-summary="'+clear.dataset.targetField+'"]'); writeIds([]); return;}
 var pick=e.target.closest('[data-media-choose]'); if(pick){choose(parseInt(pick.dataset.mediaChoose,10)||0); return;}
 var rem=e.target.closest('[data-media-remove]'); if(rem){activeSummary=e.target.closest('.media-picker-selection'); activeInput=activeSummary.parentElement.querySelector('[data-media-picker-input]'); writeIds(ids().filter(function(id){return id!==(parseInt(rem.dataset.mediaRemove,10)||0);})); return;}
 var move=e.target.closest('[data-media-move]'); if(move){activeSummary=e.target.closest('.media-picker-selection'); activeInput=activeSummary.parentElement.querySelector('[data-media-picker-input]'); var li=move.closest('li'), id=parseInt(li.dataset.mediaSelectedId,10)||0, list=ids(), pos=list.indexOf(id), dir=move.dataset.mediaMove; if(pos>=0&&dir==='up'&&pos>0){var t=list[pos-1];list[pos-1]=list[pos];list[pos]=t;} if(pos>=0&&dir==='down'&&pos<list.length-1){var n=list[pos+1];list[pos+1]=list[pos];list[pos]=n;} writeIds(list); return;}
 if(e.target&&e.target.id==='media-picker-close'){modal.hidden=true;}
});
search.addEventListener('input',render); typeSelect.addEventListener('change',render);
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
            '<tr><th>文件 / 迁移</th><td>' . (int) ($plan['file_count'] ?? 0) . ' 个文件，' . (int) ($plan['migration_count'] ?? 0) . ' 个迁移</td></tr>' .
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
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug) !== 1) {
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

    private function mediaLibrary(): MediaLibrary
    {
        return new MediaLibrary(ConnectionFactory::make($this->settings), $this->root() . '/content/uploads', (array) $this->settings->get('media', []));
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

    /** @param list<string> $enabledPlugins @return list<array{id:string,name:string,version:string,author:string,current:bool,compatible:bool,usable:bool,valid:bool,required_plugins:list<string>,settings_schema:array<string,mixed>,reason:string}> */
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
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /** @param array<string, mixed> $schema */
    private function themeSettingFields(string $themeId, array $schema): string
    {
        if ($schema === []) {
            return '<p class="muted">无可配置项</p>';
        }
        $current = $this->settings->get('theme.settings.' . $themeId, []);
        if (!is_array($current)) {
            $current = [];
        }
        $html = '';
        foreach ($schema as $key => $definition) {
            $name = (string) $key;
            if (!is_array($definition)) {
                $definition = [];
            }
            $value = (string) ($current[$name] ?? ($definition['default'] ?? ''));
            $html .= '<label>' . View::escape($name) . '<input name="settings[' . View::escape($name) . ']" value="' . View::escape($value) . '"></label>';
        }

        return $html;
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    private function sanitizeThemeSettings(array $schema, mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $settings = [];
        foreach ($schema as $key => $definition) {
            $name = (string) $key;
            if (!is_array($definition)) {
                $definition = [];
            }
            $type = (string) ($definition['type'] ?? 'string');
            $raw = $input[$name] ?? ($definition['default'] ?? '');
            $settings[$name] = match ($type) {
                'bool', 'boolean' => in_array($raw, ['1', 1, true, 'true', 'on'], true),
                'int', 'integer' => (int) $raw,
                default => trim((string) $raw),
            };
        }

        return $settings;
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
    private function isContentModule(array $capabilities): bool
    {
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

    private function moneyLabel(mixed $amountMinor, string $currency): string
    {
        if (!$this->paymentAmountMinorIsDisplayable($amountMinor) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return '支付金额无效';
        }

        return $currency . ' ' . number_format((int) $amountMinor / 100, 2, '.', '');
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
            $auth = new AdminAuthenticator(ConnectionFactory::make($this->settings));
            $user = $auth->user();
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

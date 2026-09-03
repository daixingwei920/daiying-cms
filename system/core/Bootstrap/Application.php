<?php

declare(strict_types=1);

namespace Cms\Core\Bootstrap;

use Cms\Core\Advertising\AdController;
use Cms\Core\Advertising\AdRepository;
use Cms\Core\Config\Settings;
use Cms\Core\CardDelivery\CardDeliveryController;
use Cms\Core\Error\ErrorHandler;
use Cms\Core\Extension\ExtensionAssetController;
use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Install\InstallController;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Integrity\CoreBoundary;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaController;
use Cms\Core\Navigation\NavigationBuilder;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginAdminRequestContext;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaidContentController;
use Cms\Core\Payment\PaidDownloadController;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentWebhookController;
use Cms\Core\Payment\TipController;
use Cms\Core\Recovery\RecoveryController;
use Cms\Core\Recovery\RunMode;
use Cms\Core\Routing\Router;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Support\View;
use Cms\Core\Theme\ThemeManager;
use Throwable;

final class Application
{
    private function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
        private readonly Router $router,
    ) {
    }

    public static function boot(string $rootPath): self
    {
        $settings = Settings::load($rootPath);
        $logger = new FileLogger($rootPath . '/storage/logs/app.log');

        ErrorHandler::register($logger, (bool) $settings->get('app.debug', false));
        if (!self::isStatelessHealthRequest()) {
            SessionManager::start((bool) $settings->get('app.secure_cookies', false));
        }
        CoreBoundary::assertWritablePaths($rootPath);
        $mode = RunMode::detect($rootPath, (string) $settings->get('app.mode', RunMode::NORMAL));
        $logger->info('Core startup', [
            'source' => 'Core',
            'mode' => $mode,
            'version' => (string) $settings->get('app.version', '0.0.0'),
            'installed' => is_file($rootPath . '/storage/installed.lock'),
            'maintenance' => is_file($rootPath . '/storage/maintenance.mode'),
        ]);

        $events = new EventDispatcher();
        $blocks = new BlockRegistry();
        $pluginRuntime = new PluginRuntimeRegistry();
        self::registerCorePaymentProviders($settings);
        if (RunMode::safeLoadsPlugins($mode)) {
            try {
                $pdo = ConnectionFactory::make($settings);
                $secrets = new PluginSecretStore($pdo, (string) $settings->get('security.encryption_key', ''));
                $plugins = new PluginManager(
                    $rootPath . '/content/plugins',
                    $pdo,
                    $logger,
                    $events,
                    $blocks,
                    $pluginRuntime,
                    new OfficialPluginRegistry($rootPath),
                    $secrets,
                );
                $plugins->syncDiscovered();
                $plugins->bootEnabled();
            } catch (Throwable $exception) {
                $logger->error('Plugin bootstrap skipped', ['source' => 'Core', 'error' => $exception->getMessage()]);
            }
        } else {
            $logger->info('Plugin bootstrap skipped by run mode', ['source' => 'Core', 'mode' => $mode]);
        }

        $router = new Router();
        View::setAdminPluginMenus($pluginRuntime->menus());
        View::setFrontNavigation(NavigationBuilder::build($settings, null, $rootPath));
        self::registerCoreRoutes($router, $settings, $rootPath, $logger, $mode, $pluginRuntime);

        return new self($rootPath, $settings, $logger, $router);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled request failure', [
                'source' => 'Core',
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ((bool) $this->settings->get('app.debug', false)) {
                $response = Response::text($exception::class . ': ' . $exception->getMessage(), 500);
                return $this->withConfiguredSecurityHeaders($response);
            }

            $response = Response::text('服务器暂时无法处理请求，请稍后再试。', 500);
        }

        return $this->withConfiguredSecurityHeaders($response);
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    private function withConfiguredSecurityHeaders(Response $response): Response
    {
        if (!$this->hstsEnabled()) {
            return $response;
        }

        return $response->withHeaders([
            'Strict-Transport-Security' => $this->hstsValue(),
        ]);
    }

    private function hstsEnabled(): bool
    {
        $siteUrl = (string) $this->settings->get('site.url', '');
        $parts = parse_url($siteUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && (bool) $this->settings->get('security.hsts_enabled', false);
    }

    private function hstsValue(): string
    {
        $maxAge = (int) $this->settings->get('security.hsts_max_age', 31536000);
        if ($maxAge < 0) {
            $maxAge = 0;
        }

        $value = 'max-age=' . $maxAge;
        if ((bool) $this->settings->get('security.hsts_include_subdomains', false)) {
            $value .= '; includeSubDomains';
        }
        if ((bool) $this->settings->get('security.hsts_preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    private static function isStatelessHealthRequest(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');

        return $path === '/health';
    }

    private static function registerCoreRoutes(Router $router, Settings $settings, string $rootPath, FileLogger $logger, string $mode, PluginRuntimeRegistry $pluginRuntime): void
    {
        $router->get('/', static function () use ($rootPath, $settings, $logger): Response {
            if (!is_file($rootPath . '/storage/installed.lock')) {
                return Response::redirect('/install');
            }

            $contents = [];
            $enabledPlugins = [];
            try {
                $pdo = ConnectionFactory::make($settings);
                $contents = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->latest(10);
                try {
                    $stmt = $pdo->query("SELECT plugin_id FROM cms_plugins WHERE status = 'Enabled'");
                    foreach ($stmt->fetchAll() as $row) {
                        $enabledPlugins[] = (string) $row['plugin_id'];
                    }
                } catch (Throwable) {
                    $enabledPlugins = [];
                }
            } catch (Throwable $exception) {
                $logger->error('Front page content load failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            }

            $themes = new ThemeManager($rootPath . '/content/themes', $settings, $logger);
            $theme = RunMode::detect($rootPath, (string) $settings->get('app.mode', RunMode::NORMAL)) === RunMode::SAFE
                ? $themes->load($themes->safeThemeId())
                : $themes->activeWithPlugins($enabledPlugins);

            try {
                return Response::html($theme->render('home', [
                    'site_name' => (string) $settings->get('site.name', 'PHP CMS'),
                    'contents' => $contents,
                    'navigation' => NavigationBuilder::build($settings, null, $rootPath),
                    'ad_slots' => self::adSlots($settings),
                ]));
            } catch (Throwable $exception) {
                $logger->error('Theme render failed; retrying safe theme', ['source' => 'Core', 'error' => $exception->getMessage()]);
                $safe = $themes->load($themes->safeThemeId());
                return Response::html($safe->render('home', [
                    'site_name' => (string) $settings->get('site.name', 'PHP CMS'),
                    'contents' => [],
                    'navigation' => NavigationBuilder::build($settings, null, $rootPath),
                    'ad_slots' => self::adSlots($settings),
                ]));
            }
        });

        $router->get('/health', static function () use ($rootPath, $settings, $mode): Response {
            $version = (string) $settings->get('app.version', '0.0.0');
            $release = [];
            $pointer = $rootPath . '/storage/updates/current-release.json';
            if (is_file($pointer)) {
                $decoded = json_decode((string) file_get_contents($pointer), true);
                if (is_array($decoded)) {
                    $release = $decoded;
                    $version = (string) ($decoded['version'] ?? $version);
                }
            }
            return Response::json([
                'status' => 'ok',
                'mode' => $mode,
                'version' => $version,
                'release_id' => (string) ($release['release_id'] ?? ''),
                'maintenance' => is_file($rootPath . '/storage/maintenance.mode'),
                'installed' => is_file($rootPath . '/storage/installed.lock'),
            ]);
        });

        $assets = new ExtensionAssetController($rootPath);
        $router->get('/extension-assets/{type}/{id}', [$assets, 'show']);

        $front = new ContentFrontController($rootPath, $settings, $logger);
        $router->get('/articles', [$front, 'articles']);
        $router->get('/articles/{slug}', [$front, 'article']);
        $router->get('/post/{slug}', [$front, 'legacyRedirect']);
        $router->get('/search', [$front, 'search']);
        $router->get('/login', [$front, 'loginForm']);
        $router->post('/login', [$front, 'login']);
        $router->get('/register', [$front, 'registerForm']);
        $router->post('/register', [$front, 'register']);
        $router->post('/logout', [$front, 'logout']);
        $router->post('/comments', [$front, 'commentStore']);
        $router->get('/category/{slug}', [$front, 'term']);
        $router->get('/tag/{slug}', [$front, 'term']);
        $router->get('/preview/{id}', [$front, 'preview']);
        $router->get('/sitemap.xml', [$front, 'sitemap']);
        $router->get('/robots.txt', [$front, 'robots']);
        $ads = new AdController($settings, $logger);
        $router->get('/ads.txt', [$ads, 'adsTxt']);
        $router->get('/ads/track/{slot}/{event}', [$ads, 'track']);
        $media = new MediaController($rootPath, $settings);
        $router->get('/media/{id}', [$media, 'show']);
        $router->get('/media/{id}/{name}', [$media, 'show']);
        $paidDownloads = new PaidDownloadController($settings);
        $router->post('/paid-download/{content_id}/{media_id}/checkout', [$paidDownloads, 'checkout']);
        $router->get('/paid-download/{content_id}/{media_id}/complete', [$paidDownloads, 'complete']);
        $paidContent = new PaidContentController($settings);
        $router->post('/paid-content/{content_id}/checkout', [$paidContent, 'checkout']);
        $router->get('/paid-content/{content_id}/complete', [$paidContent, 'complete']);
        $tips = new TipController($settings);
        $router->post('/tips/checkout', [$tips, 'checkout']);
        $paymentWebhooks = new PaymentWebhookController($settings);
        $router->post('/payment/webhooks/{provider_id}', [$paymentWebhooks, 'receive']);
        $cards = new CardDeliveryController($settings);
        $router->post('/card-delivery/{id}/checkout', [$cards, 'checkout']);
        $router->get('/card-delivery/orders/{id}/complete', [$cards, 'complete']);

        $recovery = new RecoveryController($rootPath, $settings, $logger);
        $router->get('/recovery', [$recovery, 'index']);
        $router->get('/diagnostics', [$recovery, 'diagnostics']);
        $router->post('/recovery/action', [$recovery, 'action']);

        $install = new InstallController($rootPath, $settings, $logger);
        $router->get('/install', [$install, 'show']);
        $router->post('/install', [$install, 'store']);

        $admin = new AdminController($settings, $logger, $rootPath);
        $router->get('/admin/login', [$admin, 'loginForm']);
        $router->post('/admin/login', [$admin, 'login']);
        $router->get('/admin/forgot-password', [$admin, 'forgotPasswordForm']);
        $router->post('/admin/forgot-password', [$admin, 'forgotPassword']);
        $router->get('/admin/reset-password', [$admin, 'resetPasswordForm']);
        $router->post('/admin/reset-password', [$admin, 'resetPassword']);
        $router->get('/admin/mfa', [$admin, 'mfaChallengeForm']);
        $router->post('/admin/mfa', [$admin, 'mfaChallenge']);
        $router->post('/admin/mfa/passkey-options', [$admin, 'mfaPasskeyOptions']);
        $router->post('/admin/mfa/passkey-verify', [$admin, 'mfaPasskeyVerify']);
        $router->post('/admin/logout', [$admin, 'logout']);
        $router->get('/admin', [$admin, 'dashboard']);
        $router->get('/admin/security', [$admin, 'adminSecurity']);
        $router->post('/admin/security/mfa-enable', [$admin, 'adminSecurityEnableMfa']);
        $router->post('/admin/security/mfa-disable', [$admin, 'adminSecurityDisableMfa']);
        $router->post('/admin/security/reauth', [$admin, 'adminSecurityReauthenticate']);
        $router->post('/admin/security/logout-other-sessions', [$admin, 'adminSecurityLogoutOtherSessions']);
        $router->post('/admin/security/passkey-options', [$admin, 'adminPasskeyOptions']);
        $router->post('/admin/security/passkey-register', [$admin, 'adminPasskeyRegister']);
        $router->post('/admin/security/passkey-delete', [$admin, 'adminPasskeyDelete']);
        $router->get('/admin/system-health', [$admin, 'systemHealth']);
        $router->get('/admin/health-doctor', [$admin, 'healthDoctor']);
        $router->get('/admin/site-vault', [$admin, 'siteVault']);
        $router->post('/admin/site-vault', [$admin, 'siteVault']);
        $router->get('/admin/site-timeline', [$admin, 'siteTimeline']);
        $router->get('/admin/settings', [$admin, 'siteSettings']);
        $router->post('/admin/settings', [$admin, 'siteSettingsSave']);
        $router->get('/admin/content', [$admin, 'contentIndex']);
        $router->get('/admin/content/new', [$admin, 'contentCreate']);
        $router->post('/admin/content', [$admin, 'contentStore']);
        $router->get('/admin/content/edit/{id}', [$admin, 'contentEdit']);
        $router->post('/admin/content/edit/{id}', [$admin, 'contentUpdate']);
        $router->post('/admin/content/delete/{id}', [$admin, 'contentDelete']);
        $router->get('/admin/categories', [$admin, 'categoryIndex']);
        $router->post('/admin/categories', [$admin, 'categoryStore']);
        $router->get('/admin/categories/edit/{id}', [$admin, 'categoryEdit']);
        $router->post('/admin/categories/edit/{id}', [$admin, 'categoryUpdate']);
        $router->post('/admin/categories/delete/{id}', [$admin, 'categoryDelete']);
        $router->get('/admin/media', [$admin, 'mediaIndex']);
        $router->post('/admin/media/upload', [$admin, 'mediaUpload']);
        $router->get('/admin/media/provider/list', [$admin, 'mediaProviderList']);
        $router->post('/admin/media/provider/select', [$admin, 'mediaProviderSelect']);
        $router->post('/admin/media/provider/upload', [$admin, 'mediaProviderUpload']);
        $router->get('/admin/media/detail/{id}', [$admin, 'mediaDetail']);
        $router->post('/admin/media/detail/{id}', [$admin, 'mediaUpdate']);
        $router->get('/admin/ads', [$admin, 'adIndex']);
        $router->post('/admin/ads', [$admin, 'adSave']);
        $router->get('/admin/comments', [$admin, 'commentIndex']);
        $router->post('/admin/comments/action', [$admin, 'commentAction']);
        $router->get('/admin/card-delivery', [$admin, 'cardDeliveryIndex']);
        $router->get('/admin/card-delivery/new', [$admin, 'cardDeliveryCreate']);
        $router->post('/admin/card-delivery', [$admin, 'cardDeliveryStore']);
        $router->get('/admin/card-delivery/edit/{id}', [$admin, 'cardDeliveryEdit']);
        $router->post('/admin/card-delivery/edit/{id}', [$admin, 'cardDeliveryUpdate']);
        $router->post('/admin/card-delivery/inventory/{id}/import', [$admin, 'cardDeliveryInventoryImport']);
        $router->post('/admin/card-delivery/inventory/disable/{id}', [$admin, 'cardDeliveryInventoryDisable']);
        $router->post('/admin/card-delivery/orders/{id}/fulfill', [$admin, 'cardDeliveryOrderFulfill']);
        $router->get('/admin/themes', [$admin, 'themeIndex']);
        $router->get('/admin/themes/{theme_id}/settings', [$admin, 'themeSettings']);
        $router->post('/admin/themes/local-install', [$admin, 'themeLocalInstall']);
        $router->post('/admin/themes/activate', [$admin, 'themeActivate']);
        $router->post('/admin/themes/delete', [$admin, 'themeDelete']);
        $router->post('/admin/themes/reset', [$admin, 'themeSettingsReset']);
        $router->post('/admin/themes/settings', [$admin, 'themeSettingsSave']);
        $router->get('/admin/navigation', [$admin, 'navigationIndex']);
        $router->post('/admin/navigation', [$admin, 'navigationSave']);
        $router->get('/admin/modules', [$admin, 'moduleIndex']);
        $router->get('/admin/plugins', [$admin, 'pluginIndex']);
        $router->get('/admin/plugins/detail', [$admin, 'pluginDetail']);
        $router->post('/admin/plugins/status', [$admin, 'pluginStatus']);
        $router->post('/admin/plugins/local-preview', [$admin, 'pluginLocalPreview']);
        $router->post('/admin/plugins/local-install', [$admin, 'pluginLocalInstall']);
        $router->post('/admin/plugins/uninstall', [$admin, 'pluginUninstall']);
        $router->post('/admin/plugins/purge', [$admin, 'pluginPurge']);
        $router->get('/admin/recovery', [$recovery, 'index']);
        $router->get('/admin/diagnostics', [$recovery, 'diagnostics']);
        $router->post('/admin/recovery/action', [$recovery, 'action']);
        $router->get('/admin/transfer', [$admin, 'transferIndex']);
        $router->get('/admin/transfer/download/{name}', [$admin, 'transferDownload']);
        $router->post('/admin/transfer/export', [$admin, 'transferExport']);
        $router->post('/admin/transfer/import', [$admin, 'transferImport']);
        $router->post('/admin/transfer/preflight/{name}', [$admin, 'transferPreflight']);
        $router->post('/admin/transfer/restore-content/{name}', [$admin, 'transferRestoreContent']);
        $router->post('/admin/transfer/delete/{name}', [$admin, 'transferDelete']);
        $router->post('/admin/transfer/url-mappings/{id}/delete', [$admin, 'transferUrlMappingDelete']);
        $router->get('/admin/migrations', [$admin, 'externalMigrationIndex']);
        $router->post('/admin/migrations/scan', [$admin, 'externalMigrationScan']);
        $router->post('/admin/migrations/dry-run/{id}', [$admin, 'externalMigrationDryRun']);
        $router->post('/admin/migrations/run/{id}', [$admin, 'externalMigrationRun']);
        $router->post('/admin/migrations/resume/{id}', [$admin, 'externalMigrationResume']);
        $router->post('/admin/migrations/retry-failed/{id}', [$admin, 'externalMigrationRetryFailed']);
        $router->post('/admin/migrations/rollback/{id}', [$admin, 'externalMigrationRollback']);
        $router->post('/admin/migrations/export-package', [$admin, 'externalMigrationExportPackage']);
        $router->get('/admin/payments', [$admin, 'paymentsIndex']);
        $router->get('/admin/payments/export.csv', [$admin, 'paymentsExport']);
        $router->get('/admin/payments/providers', [$admin, 'paymentProviders']);
        $router->post('/admin/payments/providers', [$admin, 'paymentProviderSave']);
        $router->post('/admin/payments/providers/save', [$admin, 'paymentProviderSave']);
        $router->post('/admin/payments/providers/repair-storage', [$admin, 'paymentProviderRepairStorage']);
        $router->post('/admin/payments/authorizations/expire', [$admin, 'paymentAuthorizationsExpire']);
        $router->post('/admin/payments/entitlements/expire', [$admin, 'paymentEntitlementsExpire']);
        $router->get('/admin/payments/{id}', [$admin, 'paymentDetail']);
        $router->post('/admin/payments/{id}/capture', [$admin, 'paymentCapture']);
        $router->post('/admin/payments/{id}/cancel', [$admin, 'paymentCancel']);
        $router->post('/admin/payments/{id}/sync', [$admin, 'paymentSync']);
        $router->post('/admin/payments/{id}/authorizations/{authorization_id}/revoke', [$admin, 'paymentAuthorizationRevoke']);
        $router->post('/admin/payments/{id}/entitlements/{entitlement_id}/revoke', [$admin, 'paymentEntitlementRevoke']);
        $router->post('/admin/payments/{id}/webhooks/{receipt_id}/status', [$admin, 'paymentWebhookStatus']);
        $router->post('/admin/payments/{id}/refund', [$admin, 'paymentRefund']);
        $router->get('/admin/update', [$admin, 'updateIndex']);
        $router->post('/admin/update/check', [$admin, 'updateCheck']);
        $router->post('/admin/update/prepare', [$admin, 'updatePrepare']);
        $router->post('/admin/update/verify', [$admin, 'updateVerify']);
        $router->post('/admin/update/execute', [$admin, 'updateExecute']);
        if (self::marketFeaturesEnabled($settings)) {
            $router->get('/admin/market/plugins', [$admin, 'marketPlugins']);
            $router->get('/admin/market/themes', [$admin, 'marketThemes']);
            $router->get('/admin/market/developer-submit', [$admin, 'marketDeveloperSubmit']);
            $router->post('/admin/market/developer-submit', [$admin, 'marketDeveloperSubmitPost']);
            $router->get('/admin/market/submissions', [$admin, 'marketDeveloperSubmissions']);
            $router->get('/admin/market/submissions/{id}', [$admin, 'marketDeveloperSubmissionDetail']);
            $router->get('/admin/market/detail', [$admin, 'marketDetail']);
            $router->post('/admin/market/license-activate', [$admin, 'marketLicenseActivate']);
            $router->get('/admin/market/diagnostics', [$admin, 'marketDiagnostics']);
            $router->post('/admin/market/refresh', [$admin, 'marketRefresh']);
            $router->post('/admin/market/clear-cache', [$admin, 'marketClearCache']);
            $router->post('/admin/market/test-connection', [$admin, 'marketTestConnection']);
            $router->post('/admin/market/authorize', [$admin, 'marketAuthorize']);
            $router->post('/admin/market/install', [$admin, 'marketInstall']);
        }
        self::registerPluginRoutes($router, $settings, $logger, $pluginRuntime);
        $router->get('/{slug}', [$front, 'page']);
    }

    /** @return array<string,array{enabled:bool,html:string,label:string}> */
    private static function adSlots(Settings $settings): array
    {
        try {
            return (new AdRepository(ConnectionFactory::make($settings)))->activeSlotsForRender();
        } catch (Throwable) {
            return [];
        }
    }

    private static function registerPluginRoutes(Router $router, Settings $settings, FileLogger $logger, PluginRuntimeRegistry $runtime): void
    {
        foreach ($runtime->routes() as $route) {
            $handler = static function (Request $request) use ($route, $settings, $logger): Response {
                try {
                    $pdo = ConnectionFactory::make($settings);
                    $stmt = $pdo->prepare('SELECT status, capabilities_json FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
                    $stmt->execute([':plugin_id' => $route->pluginId]);
                    $row = $stmt->fetch();
                    $stmt->closeCursor();
                    if (!is_array($row) || (string) $row['status'] !== PluginLifecycle::ENABLED) {
                        return Response::text('页面不存在。', 404);
                    }
                    $capabilities = json_decode((string) ($row['capabilities_json'] ?? '[]'), true) ?: [];
                    if ($route->capability !== null && !in_array($route->capability, $capabilities, true)) {
                        return Response::text('没有权限访问该功能。', 403);
                    }
                    $context = null;
                    if ($route->admin) {
                        $auth = new AdminAuthenticator($pdo);
                        $user = $auth->user();
                        if ($user === null || (int) ($user['id'] ?? 0) <= 0) {
                            return Response::redirect('/admin/login');
                        }
                        $context = new PluginAdminRequestContext(
                            $route->pluginId,
                            (int) $user['id'],
                            array_values(array_map('strval', $capabilities)),
                            self::requestCorrelationId($request),
                            bin2hex(random_bytes(8)),
                            self::safeClientIp($request),
                        );
                    }
                    if ($route->csrf && !CsrfToken::verify($request->input('_csrf'))) {
                        return Response::text('请求校验失败，请刷新页面后重试。', 403);
                    }

                    if ($context !== null) {
                        $request = new Request($request->method, $request->path, $request->query, $request->body, $request->server + ['plugin_admin_context' => $context]);
                    }
                    if ($route->admin && $route->method === 'POST') {
                        SessionManager::close();
                    }
                    $response = ($route->handler)($request);
                    return $response instanceof Response ? $response : Response::text('插件路由返回了无效响应。', 500);
                } catch (Throwable $exception) {
                    $logger->error('Plugin route failed', ['source' => 'Plugin', 'plugin_id' => $route->pluginId, 'error' => $exception->getMessage()]);
                    return Response::text('插件路由暂时不可用。', 503);
                }
            };
            $router->route($route->method, $route->path, $handler);
        }
    }

    private static function requestCorrelationId(Request $request): string
    {
        $header = (string) ($request->server['HTTP_X_CORRELATION_ID'] ?? '');
        if ($header !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,96}$/', $header) === 1) {
            return $header;
        }
        return bin2hex(random_bytes(8));
    }

    private static function safeClientIp(Request $request): string
    {
        $ip = (string) ($request->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    private static function marketFeaturesEnabled(Settings $settings): bool
    {
        return (bool) $settings->get('market.enabled', false);
    }

    private static function registerCorePaymentProviders(Settings $settings): void
    {
        PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
        PaymentProviderRegistry::register(HostedRedirectPaymentProvider::PROVIDER_ID, new HostedRedirectPaymentProvider());

        if ((bool) $settings->get('payment.fixture_provider_enabled', false) !== true) {
            return;
        }

        PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
    }
}

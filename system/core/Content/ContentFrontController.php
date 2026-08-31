<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use Cms\Core\Advertising\AdRepository;
use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\Comment\CommentRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Navigation\NavigationBuilder;
use Cms\Core\Payment\PaidContentService;
use Cms\Core\Payment\PaidDownloadService;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Recovery\RunMode;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\Money;
use Cms\Core\Support\View;
use Cms\Core\Theme\ThemeManager;
use Throwable;

final class ContentFrontController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
    ) {
    }

    public function articles(Request $request): Response
    {
        try {
            $repo = $this->repo();
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $perPage = 10;
            $items = array_map(fn (array $item): array => $this->viewModel($item), $repo->publicList('article', $page, $perPage));
            return Response::html($this->theme()->render('list', [
                'site_name' => (string) $this->settings->get('site.name', 'PHP CMS'),
                'navigation' => NavigationBuilder::build($this->settings, null, $this->rootPath),
                'title' => 'Articles',
                'items' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $repo->publicCount('article')],
                'seo' => $this->seo(['title' => 'Articles']),
                'ad_slots' => $this->adSlots(),
            ]));
        } catch (Throwable $exception) {
            $this->logger->error('Article list failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->notFound();
        }
    }

    public function article(Request $request): Response
    {
        if (($mapped = $this->mapped($request->path)) !== null) {
            return Response::redirect($mapped['target'], $mapped['status']);
        }
        $slug = $this->slugFromPath($request->path);
        $content = $this->repo()->publicBySlug('article', $slug);

        return $content === null ? $this->notFound() : $this->content($content, false, $request);
    }

    public function page(Request $request): Response
    {
        if (($mapped = $this->mapped($request->path)) !== null) {
            return Response::redirect($mapped['target'], $mapped['status']);
        }
        $slug = $this->slugFromPath($request->path);
        $content = $this->repo()->publicBySlug('page', $slug);

        return $content === null ? $this->notFound() : $this->content($content, false, $request);
    }

    public function search(Request $request): Response
    {
        try {
            $query = $this->cleanSearchQuery((string) ($request->query['q'] ?? ''));
            $repo = $this->repo();
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $perPage = 10;
            $items = $query !== ''
                ? array_map(fn (array $item): array => $this->viewModel($item), $repo->publicSearch($query, $page, $perPage))
                : [];
            $total = $query !== '' ? $repo->publicSearchCount($query) : 0;
            $title = $query !== '' ? '搜索：' . $query : '搜索';

            return Response::html($this->theme()->render('search', [
                'site_name' => (string) $this->settings->get('site.name', 'PHP CMS'),
                'navigation' => NavigationBuilder::build($this->settings, null, $this->rootPath),
                'title' => $title,
                'query' => $query,
                'items' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
                'seo' => $this->seo(['title' => $title, 'description' => '搜索站内已发布内容。', 'path' => '/search']),
                'ad_slots' => $this->adSlots(),
            ]));
        } catch (Throwable $exception) {
            $this->logger->error('Search failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return $this->notFound();
        }
    }

    public function preview(Request $request): Response
    {
        $id = (int) basename($request->path);
        $token = (string) ($request->query['token'] ?? '');
        $content = $token !== '' ? $this->repo()->previewByToken($id, $token) : null;

        return $content === null ? Response::text('预览链接无效或已过期。', 403) : $this->content($content, true, $request);
    }

    public function loginForm(Request $request): Response
    {
        $redirect = $this->safeRedirect((string) ($request->query['redirect'] ?? '/'));
        return Response::html(View::page('会员登录', $this->authFormHtml('login', $redirect)));
    }

    public function login(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::html(View::page('会员登录', $this->authFormHtml('login', '/')), 405)->withHeaders(['Allow' => 'POST']);
        }
        $redirect = $this->safeRedirect((string) $request->input('redirect', '/'));
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('会员登录', $this->authFormHtml('login', $redirect, '请求校验失败，请刷新页面重试。')), 403);
        }
        try {
            $auth = new FrontUserAuthenticator(ConnectionFactory::make($this->settings));
            if (!$auth->attempt((string) $request->input('email', ''), (string) $request->input('password', ''), $this->clientIp($request))) {
                return Response::html(View::page('会员登录', $this->authFormHtml('login', $redirect, '邮箱或密码不正确。')), 401);
            }
            return Response::redirect($redirect);
        } catch (Throwable $exception) {
            $this->logger->error('Front login failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('会员登录', $this->authFormHtml('login', $redirect, '登录服务暂不可用。')), 500);
        }
    }

    public function registerForm(Request $request): Response
    {
        $redirect = $this->safeRedirect((string) ($request->query['redirect'] ?? '/'));
        return Response::html(View::page('会员注册', $this->authFormHtml('register', $redirect)));
    }

    public function register(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::html(View::page('会员注册', $this->authFormHtml('register', '/')), 405)->withHeaders(['Allow' => 'POST']);
        }
        $redirect = $this->safeRedirect((string) $request->input('redirect', '/'));
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('会员注册', $this->authFormHtml('register', $redirect, '请求校验失败，请刷新页面重试。')), 403);
        }
        try {
            (new FrontUserAuthenticator(ConnectionFactory::make($this->settings)))->register(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) $request->input('display_name', '')
            );
            return Response::redirect($redirect);
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('会员注册', $this->authFormHtml('register', $redirect, $exception->getMessage())), 422);
        } catch (Throwable $exception) {
            $this->logger->error('Front register failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('会员注册', $this->authFormHtml('register', $redirect, '注册服务暂不可用。')), 500);
        }
    }

    public function logout(Request $request): Response
    {
        if ($request->method !== 'POST' || !CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('无权执行此操作。', 403);
        }
        try {
            (new FrontUserAuthenticator(ConnectionFactory::make($this->settings)))->logout();
        } catch (Throwable $exception) {
            $this->logger->error('Front logout failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }

        return Response::redirect($this->safeRedirect((string) $request->input('redirect', '/')));
    }

    public function commentStore(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::text('评论必须通过 POST 提交。', 405)->withHeaders(['Allow' => 'POST']);
        }
        $redirect = $this->safeRedirect((string) $request->input('redirect', '/'));
        if (!CsrfToken::verify($request->input('_csrf'))) {
            $this->flash('comment_error', '请求校验失败，请刷新页面重试。');
            return Response::redirect($redirect . '#comments');
        }
        if (!(bool) $this->settings->get('comments.enabled', true)) {
            $this->flash('comment_error', '评论功能暂未开启。');
            return Response::redirect($redirect . '#comments');
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $content = $this->repo()->find((int) $request->input('content_id', 0));
            if ($content === null || (string) ($content['status'] ?? '') !== 'published') {
                $this->flash('comment_error', '评论内容不存在。');
                return Response::redirect($redirect . '#comments');
            }
            $user = (new FrontUserAuthenticator($pdo))->user();
            if ($user === null && !(bool) $this->settings->get('comments.allow_guest', true)) {
                $this->flash('comment_error', '请先登录后再评论。');
                return Response::redirect('/login?redirect=' . rawurlencode($redirect));
            }
            $status = (bool) $this->settings->get('comments.require_approval', true) ? 'pending' : 'approved';
            (new CommentRepository($pdo))->create([
                'content_id' => (int) $content['id'],
                'user_id' => $user['id'] ?? null,
                'author_name' => $user['display_name'] ?? (string) $request->input('author_name', ''),
                'author_email' => $user['email'] ?? (string) $request->input('author_email', ''),
                'body' => (string) $request->input('body', ''),
                'status' => $status,
                'ip' => $this->clientIp($request),
                'user_agent' => (string) ($request->server['HTTP_USER_AGENT'] ?? ''),
            ]);
            $this->flash('comment_notice', $status === 'approved' ? '评论已发布。' : '评论已提交，审核通过后会显示。');
        } catch (\InvalidArgumentException $exception) {
            $this->flash('comment_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Comment submit failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $this->flash('comment_error', '评论提交失败，请稍后重试。');
        }

        return Response::redirect($redirect . '#comments');
    }

    public function term(Request $request): Response
    {
        $parts = explode('/', trim($request->path, '/'));
        $taxonomy = $parts[0] === 'tag' ? 'tag' : 'category';
        $slug = $this->decodeSlugSegment((string) ($parts[1] ?? ''));
        $repo = $this->repo();
        $term = $repo->termBySlug($taxonomy, $slug);
        if ($term === null) {
            return $this->notFound();
        }
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $items = array_map(fn (array $item): array => $this->viewModel($item), $repo->publicByTerm($taxonomy, $slug, $page, 10));
        $label = $taxonomy === 'tag' ? '标签' : '分类';
        $title = ucfirst($taxonomy) . ': ' . $term['name'];
        $total = $repo->publicCountByTerm($taxonomy, $slug);

        return Response::html($this->theme()->render('list', [
            'site_name' => (string) $this->settings->get('site.name', 'PHP CMS'),
            'navigation' => NavigationBuilder::build($this->settings, null, $this->rootPath),
            'title' => $title,
            'term' => $term,
                'base_path' => '/' . ($taxonomy === 'tag' ? 'tag' : 'category') . '/' . rawurlencode($term['slug']),
            'empty_message' => '该' . $label . '暂时没有文章。',
            'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => 10, 'total' => $total],
            'seo' => $this->seo(['title' => $title, 'path' => '/' . ($taxonomy === 'tag' ? 'tag' : 'category') . '/' . $term['slug']]),
            'ad_slots' => $this->adSlots(),
        ]));
    }

    public function sitemap(): Response
    {
        $base = $this->siteBaseUrl();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        if ($this->siteAllowsRobotsIndex()) {
            $xml .= '<url><loc>' . $this->x($base . '/') . '</loc></url>';
            $xml .= '<url><loc>' . $this->x($base . '/articles') . '</loc></url>';
            foreach ($this->repo()->sitemapItems() as $item) {
                $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
                if (($meta['robots_index'] ?? true) !== true) {
                    continue;
                }
                $path = $this->publicContentUrl((string) $item['content_type'], (string) $item['slug']);
                $xml .= '<url><loc>' . $this->x($base . $path) . '</loc><lastmod>' . $this->x((string) $item['updated_at']) . '</lastmod></url>';
            }
        }
        $xml .= '</urlset>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function robots(): Response
    {
        $base = $this->siteBaseUrl();
        $body = "User-agent: *\n";
        if (!$this->siteAllowsRobotsIndex()) {
            $body .= "Disallow: /\n";
            $body .= 'Sitemap: ' . $base . "/sitemap.xml\n";

            return Response::text($body);
        }
        $body .= "Disallow: /admin\n";
        $body .= "Disallow: /install\n";
        $body .= "Disallow: /recovery\n";
        $body .= "Disallow: /preview/\n";
        $body .= "Allow: /\n";
        $body .= 'Sitemap: ' . $base . "/sitemap.xml\n";

        return Response::text($body);
    }

    /** @param array<string, mixed> $content */
    private function content(array $content, bool $preview = false, ?Request $request = null): Response
    {
        $view = $this->viewModel($content, $preview, $request);
        $response = Response::html($this->theme()->render('content', $view));

        return $this->paymentTokenRequested($request)
            ? $response->withHeaders(['Cache-Control' => 'private, no-store'])
            : $response;
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    private function viewModel(array $content, bool $preview = false, ?Request $request = null): array
    {
        $terms = $this->repo()->termsForContent((int) $content['id']);
        $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
        $url = $this->publicContentUrl((string) $content['content_type'], (string) $content['slug']);
        $seo = $this->seo([
            'title' => (string) ($meta['seo_title'] ?? $content['title']),
            'description' => (string) ($meta['seo_description'] ?? ''),
            'canonical' => (string) ($meta['canonical_url'] ?? ''),
            'path' => $url,
            'robots_index' => $preview ? false : (bool) ($meta['robots_index'] ?? true),
            'robots_follow' => (bool) ($meta['robots_follow'] ?? true),
            'type' => (string) $content['content_type'],
        ]);

        $paymentToken = $this->paymentTokenFromRequest($request);
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
        $paidContent = $this->paidContentViewModel($content, $blocks, $paymentToken, $preview);
        $renderBlocks = is_array($paidContent['render_blocks'] ?? null) ? $paidContent['render_blocks'] : $blocks;
        $media = $this->mediaViewModels($renderBlocks, (int) $content['id'], $paymentToken);
        $renderedBlocks = (new BlockRenderer(
            $media,
            $this->cardProductViewModels($renderBlocks),
            $this->tipPaymentProvidersByCurrency($renderBlocks),
            (int) $content['id'],
        ))->render($renderBlocks);
        if (($paidContent['enabled'] ?? false) && !($paidContent['authorized'] ?? false)) {
            $renderedBlocks .= $this->paidContentWallHtml($paidContent);
        }

        return [
            'site_name' => (string) $this->settings->get('site.name', 'PHP CMS'),
            'navigation' => NavigationBuilder::build($this->settings, null, $this->rootPath),
            'content' => $content,
            'title' => (string) $content['title'],
            'media' => $media,
            'rendered_blocks' => $renderedBlocks,
            'paid_content' => $paidContent,
            'published_at' => $content['published_at'] ?? null,
            'updated_at' => $content['updated_at'] ?? null,
            'categories' => array_values(array_filter($terms, static fn (array $term): bool => $term['taxonomy'] === 'category')),
            'tags' => array_values(array_filter($terms, static fn (array $term): bool => $term['taxonomy'] === 'tag')),
            'seo' => $seo,
            'canonical' => $seo['canonical'],
            'preview' => $preview,
            'ad_slots' => $this->adSlots(),
            'comments' => $this->commentsViewModel((int) $content['id'], $url, $preview),
        ];
    }

    /** @return array<string,mixed> */
    private function commentsViewModel(int $contentId, string $url, bool $preview): array
    {
        if ($preview || !(bool) $this->settings->get('comments.enabled', true)) {
            return ['enabled' => false];
        }
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $user = (new FrontUserAuthenticator($pdo))->user();
            return [
                'enabled' => true,
                'allow_guest' => (bool) $this->settings->get('comments.allow_guest', true),
                'require_approval' => (bool) $this->settings->get('comments.require_approval', true),
                'items' => (new CommentRepository($pdo))->approvedForContent($contentId),
                'user' => $user,
                'csrf' => CsrfToken::get(),
                'content_id' => $contentId,
                'redirect' => $url,
                'notice' => $this->consumeFlash('comment_notice'),
                'error' => $this->consumeFlash('comment_error'),
            ];
        } catch (Throwable $exception) {
            $this->logger->error('Comments view model failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return ['enabled' => false];
        }
    }

    private function slugFromPath(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        return $this->decodeSlugSegment((string) end($parts));
    }

    private function decodeSlugSegment(string $segment): string
    {
        if ($segment === '' || str_contains($segment, '/') || str_contains($segment, '\\')) {
            return '';
        }
        $decoded = rawurldecode($segment);
        if ($decoded === '' || $decoded !== trim($decoded) || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return '';
        }

        return $decoded;
    }

    private function publicContentUrl(string $type, string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '#';
        }

        return ($type === 'article' ? '/articles/' : '/') . rawurlencode($slug);
    }

    /** @param array<string,mixed> $content @param list<array<string,mixed>> $blocks @return array<string,mixed> */
    private function paidContentViewModel(array $content, array $blocks, string $paymentToken, bool $preview): array
    {
        try {
            $service = new PaidContentService(ConnectionFactory::make($this->settings), $this->settings);
            $config = $service->configFor($content);
            if ($config === null) {
                return ['enabled' => false, 'authorized' => true, 'render_blocks' => $blocks];
            }
            $authorized = $preview || ($paymentToken !== '' && $service->isAuthorized((int) $content['id'], $paymentToken));
            $previewCount = max(0, (int) ($config['preview_blocks'] ?? 1));
            $available = ($config['available'] ?? true) === true;

            return [
                'enabled' => true,
                'authorized' => $authorized,
                'amount_minor' => (int) $config['amount_minor'],
                'currency' => (string) $config['currency'],
                'available' => $available,
                'label' => $available ? (string) $config['label'] : '支付配置不可用',
                'checkout_url' => '/paid-content/' . (int) $content['id'] . '/checkout',
                'payment_providers' => $available ? (new PaymentProviderSelector(ConnectionFactory::make($this->settings), $this->settings))->enabledProviders((string) $config['currency']) : [],
                'render_blocks' => $authorized ? $blocks : array_slice($blocks, 0, $previewCount),
            ];
        } catch (Throwable $exception) {
            $this->logger->error('Paid content view model failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
            if (($meta['paid_content_enabled'] ?? false) === true) {
                $previewCount = max(0, min(100, (int) ($meta['paid_content_preview_blocks'] ?? 0)));
                return [
                    'enabled' => true,
                    'authorized' => false,
                    'amount_minor' => 0,
                    'currency' => 'USD',
                    'available' => false,
                    'label' => '支付配置不可用',
                    'checkout_url' => '#',
                    'payment_providers' => [],
                    'render_blocks' => array_slice($blocks, 0, $previewCount),
                ];
            }

            return ['enabled' => false, 'authorized' => true, 'render_blocks' => $blocks];
        }
    }

    private function paymentTokenFromRequest(?Request $request): string
    {
        if (!$this->paymentTokenRequested($request)) {
            return '';
        }
        $token = $request->query['payment_token'];
        if (!is_string($token)) {
            return '';
        }
        if ($token === '' || strlen($token) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            return '';
        }

        return $token;
    }

    private function paymentTokenRequested(?Request $request): bool
    {
        return $request !== null && array_key_exists('payment_token', $request->query);
    }

    private function cleanSearchQuery(string $query): string
    {
        $query = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $query) ?? '');
        if ($query === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($query, 0, 80, 'UTF-8') : substr($query, 0, 80);
    }

    /** @param array<string,mixed> $paidContent */
    private function paidContentWallHtml(array $paidContent): string
    {
        $price = $this->safePaymentPrice($paidContent['amount_minor'] ?? null, $paidContent['currency'] ?? null);
        $label = $this->safePaymentLabel((string) ($paidContent['label'] ?? '解锁全文'), '解锁全文');
        if ($label === '') {
            $label = '解锁全文';
        }
        $checkoutUrl = $this->safeCheckoutPath((string) ($paidContent['checkout_url'] ?? ''), '/paid-content/');
        if ($price === null || $checkoutUrl === '' || ($paidContent['available'] ?? true) !== true || count(is_array($paidContent['payment_providers'] ?? null) ? $paidContent['payment_providers'] : []) === 0) {
            return '<section class="paid-content-wall"><p><strong>付费内容</strong></p><p>支付配置暂不可用，内容仍由 CMS Core 锁定。</p></section>';
        }

        return '<section class="paid-content-wall"><p><strong>付费内容</strong></p><p>继续阅读需要完成支付授权。</p><form method="post" action="' . $this->x($checkoutUrl) . '">' . CsrfToken::field() . $this->paymentProviderFields(is_array($paidContent['payment_providers'] ?? null) ? $paidContent['payment_providers'] : []) . '<button type="submit">' . $this->x($label) . ' · ' . $this->x($price) . '</button></form></section>';
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function seo(array $input): array
    {
        $title = trim((string) ($input['title'] ?? $this->settings->get('site.name', 'PHP CMS')));
        $description = trim((string) ($input['description'] ?? ''));
        $base = $this->siteBaseUrl();
        $canonical = trim((string) ($input['canonical'] ?? ''));
        if ($canonical === '') {
            $canonical = $base . (string) ($input['path'] ?? '/');
        }
        $scheme = strtolower((string) parse_url($canonical, PHP_URL_SCHEME));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            $canonical = $base . (string) ($input['path'] ?? '/');
        }
        $robotsIndex = $this->siteAllowsRobotsIndex() && (($input['robots_index'] ?? true) ? true : false);
        $robotsFollow = $this->siteAllowsRobotsIndex() && (($input['robots_follow'] ?? true) ? true : false);
        $robots = ($robotsIndex ? 'index' : 'noindex') . ',' . ($robotsFollow ? 'follow' : 'nofollow');

        return [
            'title' => $title !== '' ? $title : (string) $this->settings->get('site.name', 'PHP CMS'),
            'description' => $description !== '' ? $description : $title,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => ($input['type'] ?? '') === 'article' ? 'article' : 'website',
        ];
    }

    /** @return array{target:string,status:int}|null */
    private function mapped(string $path): ?array
    {
        try {
            return $this->repo()->mappedUrl($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function notFound(): Response
    {
        try {
            return Response::html($this->theme()->render('error', [
                'site_name' => (string) $this->settings->get('site.name', 'PHP CMS'),
                'navigation' => NavigationBuilder::build($this->settings, null, $this->rootPath),
                'title' => '页面未找到',
                'message' => '你访问的页面不存在，或内容已经移动。',
                'status_code' => 404,
                'ad_slots' => $this->adSlots(),
                'seo' => $this->seo([
                    'title' => '页面未找到',
                    'description' => '你访问的页面不存在，或内容已经移动。',
                    'path' => '/',
                    'robots_index' => false,
                    'robots_follow' => false,
                ]),
            ]), 404);
        } catch (Throwable $exception) {
            $this->logger->error('Theme 404 render failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html('<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>页面未找到</title><meta name="robots" content="noindex,nofollow"></head><body><h1>页面未找到</h1><p>你访问的页面不存在。</p><p><a href="/">返回首页</a></p></body></html>', 404);
        }
    }

    private function repo(): ContentRepository
    {
        return new ContentRepository(ConnectionFactory::make($this->settings), ContentTypeRegistry::defaults());
    }

    /** @return array<string,array{enabled:bool,html:string,label:string}> */
    private function adSlots(): array
    {
        try {
            return (new AdRepository(ConnectionFactory::make($this->settings)))->activeSlotsForRender();
        } catch (Throwable) {
            return [];
        }
    }

    private function theme(): \Cms\Core\Theme\ThemeRuntime
    {
        $enabledPlugins = [];
        try {
            $stmt = ConnectionFactory::make($this->settings)->query("SELECT plugin_id FROM cms_plugins WHERE status = 'Enabled'");
            foreach ($stmt->fetchAll() as $row) {
                $enabledPlugins[] = (string) $row['plugin_id'];
            }
        } catch (Throwable) {
            $enabledPlugins = [];
        }
        $manager = new ThemeManager($this->rootPath . '/content/themes', $this->settings, $this->logger);

        return RunMode::detect($this->rootPath, (string) $this->settings->get('app.mode', RunMode::NORMAL)) === RunMode::SAFE
            ? $manager->load($manager->safeThemeId())
            : $manager->activeWithPlugins($enabledPlugins);
    }

    /** @param list<array<string,mixed>> $blocks @return array<int,array<string,mixed>> */
    private function mediaViewModels(array $blocks, int $contentId = 0, string $paymentToken = ''): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['media_id', 'poster_media_id'] as $key) {
                if ((int) ($data[$key] ?? 0) > 0) {
                    $ids[] = (int) $data[$key];
                }
            }
            foreach (($data['media_ids'] ?? []) as $id) {
                if ((int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }
        $library = new MediaLibrary(ConnectionFactory::make($this->settings), $this->rootPath . '/content/uploads');
        $viewModels = [];
        foreach (array_unique($ids) as $id) {
            $viewModels[$id] = $library->viewModel($id);
        }
        if ($contentId > 0) {
            $paid = new PaidDownloadService(ConnectionFactory::make($this->settings), $this->settings);
            foreach ($blocks as $block) {
                if ((string) ($block['type'] ?? '') !== 'attachment') {
                    continue;
                }
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                $mediaId = (int) ($data['media_id'] ?? 0);
                if ($mediaId <= 0 || !isset($viewModels[$mediaId]) || !($data['paid_enabled'] ?? false)) {
                    continue;
                }
                $config = $paid->configFor($contentId, $mediaId);
                if ($config === null) {
                    continue;
                }
                $available = ($config['available'] ?? true) === true;
                $authorized = $paymentToken !== '' && $paid->isAuthorized($contentId, $mediaId, $paymentToken);
                $viewModels[$mediaId]['paid_download'] = [
                    'enabled' => true,
                    'authorized' => $authorized,
                    'amount_minor' => (int) $config['amount_minor'],
                    'currency' => (string) $config['currency'],
                    'available' => $available,
                    'label' => $available ? (string) $config['label'] : '支付配置不可用',
                    'checkout_url' => '/paid-download/' . $contentId . '/' . $mediaId . '/checkout',
                    'payment_providers' => $available ? (new PaymentProviderSelector(ConnectionFactory::make($this->settings), $this->settings))->enabledProviders((string) $config['currency']) : [],
                ];
                if ($authorized) {
                    $viewModels[$mediaId]['download_url'] = '/media/' . $mediaId . '?download=1&content_id=' . $contentId . '&payment_token=' . rawurlencode($paymentToken);
                }
            }
        }

        return $viewModels;
    }

    /** @param list<array<string,mixed>> $blocks @return array<int,array<string,mixed>> */
    private function cardProductViewModels(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if ((string) ($block['type'] ?? '') !== 'card_delivery') {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $id = (int) ($data['card_product_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        try {
            $repo = new CardDeliveryRepository(ConnectionFactory::make($this->settings));
            $products = [];
            foreach (array_unique($ids) as $id) {
                $product = $repo->product($id);
                if (is_array($product)) {
                    $products[$id] = $product;
                }
            }

            return $products;
        } catch (Throwable $exception) {
            $this->logger->error('Card delivery product view model failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return [];
        }
    }

    /** @param list<array{id:string,label:string}> $providers */
    private function paymentProviderFields(array $providers): string
    {
        if (count($providers) === 1) {
            $id = (string) ($providers[0]['id'] ?? '');
            return $this->safeProviderId($id) ? '<input type="hidden" name="provider_id" value="' . $this->x($id) . '">' : '';
        }
        if (count($providers) < 2) {
            return '';
        }

        $html = '<fieldset class="payment-provider-options"><legend>支付方式</legend>';
        foreach ($providers as $provider) {
            $id = (string) ($provider['id'] ?? '');
            if (!$this->safeProviderId($id)) {
                continue;
            }
            $label = $this->safePaymentLabel((string) ($provider['label'] ?? $id), $id);
            $html .= '<label><input type="radio" name="provider_id" value="' . $this->x($id) . '"' . ($html === '<fieldset class="payment-provider-options"><legend>支付方式</legend>' ? ' checked' : '') . '> ' . $this->x($label) . '</label>';
        }

        return $html . '</fieldset>';
    }

    private function safeProviderId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $id) === 1;
    }

    private function safePaymentLabel(string $label, string $fallback): string
    {
        if ($label === '' || $label !== trim($label) || strlen($label) > 191 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return $fallback;
        }

        return $label;
    }

    private function safePaymentPrice(mixed $amountMinor, mixed $currency): ?string
    {
        if (!is_int($amountMinor) || $amountMinor <= 0) {
            return null;
        }
        if (!is_string($currency)) {
            return null;
        }

        try {
            return Money::format($amountMinor, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param list<array<string,mixed>> $blocks @return array<string,list<array{id:string,label:string}>> */
    private function tipPaymentProvidersByCurrency(array $blocks): array
    {
        $currencies = [];
        foreach ($blocks as $block) {
            if ((string) ($block['type'] ?? '') !== 'tip') {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $currency = is_string($data['currency'] ?? null) ? strtoupper(trim((string) $data['currency'])) : 'USD';
            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $currencies[$currency] = true;
            }
        }
        if ($currencies === []) {
            return [];
        }

        try {
            $selector = new PaymentProviderSelector(ConnectionFactory::make($this->settings), $this->settings);
            $providers = [];
            foreach (array_keys($currencies) as $currency) {
                $providers[$currency] = $selector->enabledProviders($currency);
            }

            return $providers;
        } catch (Throwable $exception) {
            $this->logger->error('Tip payment providers failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return [];
        }
    }

    private function safeCheckoutPath(string $path, string $prefix): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        $pattern = $prefix === '/paid-content/'
            ? '#^/paid-content/[1-9][0-9]{0,17}/checkout$#'
            : '#^/paid-download/[1-9][0-9]{0,17}/[1-9][0-9]{0,17}/checkout$#';
        if (preg_match($pattern, $path) !== 1) {
            return '';
        }

        return $path;
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function authFormHtml(string $mode, string $redirect, string $error = ''): string
    {
        $isRegister = $mode === 'register';
        $title = $isRegister ? '会员注册' : '会员登录';
        $action = $isRegister ? '/register' : '/login';
        $switchUrl = ($isRegister ? '/login' : '/register') . '?redirect=' . rawurlencode($redirect);
        $switchText = $isRegister ? '已有账号，去登录' : '没有账号，去注册';
        $displayName = $isRegister ? '<label>昵称<input name="display_name" autocomplete="name" required></label>' : '';
        $errorHtml = $error !== '' ? '<p class="error">' . View::escape($error) . '</p>' : '';

        return '<h1>' . $title . '</h1>' . $errorHtml .
            '<form method="post" action="' . $action . '">' . CsrfToken::field() .
            '<input type="hidden" name="redirect" value="' . View::escape($redirect) . '">' .
            $displayName .
            '<label>邮箱<input name="email" type="email" autocomplete="email" required></label>' .
            '<label>密码<input name="password" type="password" autocomplete="' . ($isRegister ? 'new-password' : 'current-password') . '" required></label>' .
            '<button type="submit">' . ($isRegister ? '注册并登录' : '登录') . '</button> <a class="button" href="' . View::escape($switchUrl) . '">' . $switchText . '</a>' .
            '</form>';
    }

    private function safeRedirect(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '/';
        }
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api') || str_starts_with($path, '/install')) {
            return '/';
        }

        return $path;
    }

    private function clientIp(Request $request): string
    {
        $ip = (string) ($request->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    private function flash(string $key, string $message): void
    {
        $_SESSION[$key] = $message;
    }

    private function consumeFlash(string $key): string
    {
        $value = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);

        return is_string($value) ? $value : '';
    }

    private function siteBaseUrl(): string
    {
        $base = rtrim((string) $this->settings->get('site.url', ''), '/');
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $base : 'http://localhost';
    }

    private function siteAllowsRobotsIndex(): bool
    {
        return (bool) $this->settings->get('seo.robots_index', true);
    }
}

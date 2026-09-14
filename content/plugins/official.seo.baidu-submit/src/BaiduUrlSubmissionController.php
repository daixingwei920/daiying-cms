<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;

final class BaiduUrlSubmissionController
{
    public function __construct(
        private readonly BaiduUrlSubmissionRepository $repo,
        private readonly BaiduUrlSubmissionService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $siteUrl = $this->defaultSiteUrl($request);

        return Response::html(View::page('百度主动推送', $this->html(null, $siteUrl)));
    }

    public function save(Request $request): Response
    {
        $siteUrl = rtrim(trim((string) $request->input('site_url', '')), '/');
        $enabled = (string) $request->input('enabled', '') === '1';
        $dedupe = max(60, min(86400, (int) $request->input('dedupe_window_seconds', 1800)));
        $token = trim((string) $request->input('api_token', ''));
        if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            return Response::html(View::page('百度主动推送', $this->html(['type' => 'error', 'message' => '站点地址无效。'], $this->defaultSiteUrl($request))), 422);
        }
        $this->repo->saveSettings($siteUrl, $enabled, $dedupe, $token === '' ? null : $token);

        return Response::html(View::page('百度主动推送', $this->html(['type' => 'success', 'message' => '配置已保存。'], $this->defaultSiteUrl($request))));
    }

    public function submit(Request $request): Response
    {
        $urls = preg_split('/\R+/', trim((string) $request->input('urls', ''))) ?: [];
        $result = $this->service->submitUrls(array_values(array_filter(array_map('trim', $urls), static fn (string $url): bool => $url !== '')), 'manual');

        return Response::html(View::page('百度主动推送', $this->html(['type' => $result['status'] === 'failed' ? 'error' : 'success', 'message' => $result['message'], 'result' => $result], $this->defaultSiteUrl($request))));
    }

    public function processQueue(Request $request): Response
    {
        $result = $this->service->processQueue(10);
        $processed = (int) ($result['processed'] ?? 0);
        return Response::html(View::page('百度主动推送', $this->html(['type' => 'success', 'message' => '队列处理完成：' . $processed . ' 个任务。'], $this->defaultSiteUrl($request))));
    }

    /** @param array{type:string,message:string,result?:array<string,mixed>}|null $notice */
    private function html(?array $notice, string $defaultSiteUrl): string
    {
        $settings = $this->repo->settings();
        if ($settings['site_url'] === '' && $defaultSiteUrl !== '') {
            $settings['site_url'] = $defaultSiteUrl;
        }
        $masked = $this->repo->maskedToken();
        $stats = $this->repo->stats();
        $noticeHtml = '';
        if ($notice !== null) {
            $class = $notice['type'] === 'error' ? 'error' : 'success';
            $noticeHtml = '<p class="' . $class . '">' . View::escape($notice['message']) . '</p>';
            if (isset($notice['result']) && is_array($notice['result'])) {
                $noticeHtml .= $this->resultTable($notice['result']);
            }
        }

        $rows = '';
        foreach ($this->repo->recentLogs(20) as $row) {
            $rows .= '<tr><td>' . View::escape((string) $row['created_at']) . '</td><td><code>' . View::escape((string) $row['status']) . '</code></td><td>' . View::escape((string) $row['http_status']) . '</td><td>' . View::escape((string) $row['baidu_success']) . '</td><td>' . View::escape((string) $row['baidu_remain']) . '</td><td>' . View::escape((string) $row['url']) . '</td><td>' . View::escape((string) $row['error_summary']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无提交记录</td></tr>';

        return '<div class="admin-page-header"><div><h1>百度主动推送</h1><p class="muted">发布新文章/页面后自动提交 URL，也可以手动提交公开 URL。不会修改 sitemap.xml、robots.txt 或 Google/Bing SEO 设置。</p></div></div>' .
            $noticeHtml .
            '<div class="cards-grid"><div class="admin-card"><strong>' . (int) $stats['total'] . '</strong><span>累计提交</span></div><div class="admin-card"><strong>' . (int) $stats['today'] . '</strong><span>今日提交</span></div><div class="admin-card"><strong>' . View::escape((string) ($stats['last_time'] ?? '-')) . '</strong><span>最后提交</span></div></div>' .
            '<form method="post" action="/admin/seo/baidu-submit/save" class="admin-form">' . CsrfToken::field() .
            '<label><input type="checkbox" name="enabled" value="1" ' . ($settings['enabled'] ? 'checked' : '') . '> 启用百度主动推送</label>' .
            '<label>站点地址<input name="site_url" value="' . View::escape($settings['site_url']) . '" placeholder="https://www.daiyingcms.com"></label>' .
            '<label>百度 API Token <input type="password" name="api_token" value="" placeholder="' . View::escape($masked === null ? '保存后只显示掩码' : '已配置（' . $masked . '），留空则保留') . '"></label>' .
            '<label>去重窗口（秒）<input type="number" name="dedupe_window_seconds" min="60" max="86400" value="' . (int) $settings['dedupe_window_seconds'] . '"></label>' .
            '<button class="button" type="submit">保存配置</button></form>' .
            '<form method="post" action="/admin/seo/baidu-submit/submit" class="admin-form">' . CsrfToken::field() .
            '<label>手动提交 URL<textarea name="urls" rows="4" placeholder="' . View::escape(($settings['site_url'] ?: $defaultSiteUrl) . '/articles/example') . '"></textarea></label>' .
            '<button class="button" type="submit">提交到百度</button></form>' .
            '<form method="post" action="/admin/seo/baidu-submit/process-queue" class="admin-form">' . CsrfToken::field() .
            '<button class="button admin-button-secondary" type="submit">处理待提交队列</button></form>' .
            '<h2>最近提交记录</h2><table><thead><tr><th>时间</th><th>状态</th><th>HTTP</th><th>success</th><th>remain</th><th>URL</th><th>错误</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** @param array<string,mixed> $result */
    private function resultTable(array $result): string
    {
        return '<table><tbody>' .
            '<tr><th>HTTP status</th><td>' . View::escape((string) ($result['http_status'] ?? '-')) . '</td></tr>' .
            '<tr><th>success</th><td>' . View::escape((string) ($result['success'] ?? '-')) . '</td></tr>' .
            '<tr><th>remain</th><td>' . View::escape((string) ($result['remain'] ?? '-')) . '</td></tr>' .
            '<tr><th>not_same_site</th><td><code>' . View::escape(json_encode($result['not_same_site'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') . '</code></td></tr>' .
            '<tr><th>not_valid</th><td><code>' . View::escape(json_encode($result['not_valid'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') . '</code></td></tr>' .
            '</tbody></table>';
    }

    private function defaultSiteUrl(Request $request): string
    {
        $host = (string) ($request->server['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($request->server['HTTPS']) && $request->server['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host;
    }
}

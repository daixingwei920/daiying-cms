<?php

declare(strict_types=1);

use Cms\Core\Http\Response;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Security\CsrfToken;
use Official\VideoCollector\CategoryMapper;
use Official\VideoCollector\HlsChecker;
use Official\VideoCollector\ProviderDetector;
use Official\VideoCollector\ResourceProviderParser;
use Official\VideoCollector\SafeHttpClient;
use Official\VideoCollector\SecretVault;
use Official\VideoCollector\VideoRepository;

require_once __DIR__ . '/src/VideoSystem.php';

return static function (PluginContext $context): void {
    $http = new SafeHttpClient();
    $parser = new ResourceProviderParser();
    $categories = new CategoryMapper();
    $detector = new ProviderDetector($parser, $categories, $http);
    $checker = new HlsChecker($http);
    $vault = new SecretVault();
    $repo = null;
    if (method_exists($context, 'pdo')) {
        try {
            $repo = new VideoRepository($context->pdo(), $http, $categories);
        } catch (\Throwable) {
            $repo = null;
        }
    }

    $param = static function ($request, string $key, string $default = ''): string {
        if (is_object($request) && method_exists($request, 'input')) {
            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }
        return (string) ($_GET[$key] ?? $_POST[$key] ?? $default);
    };
    $html = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $decodeJson = static function (mixed $value): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    };
    $pageShell = static function (string $title, string $body): string {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#172033}.wrap{max-width:1180px;margin:0 auto;padding:28px 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:20px;margin:0 0 18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}label{display:block;font-weight:650;margin:12px 0 6px}input,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px}input[type=checkbox]{width:auto}button,.button{display:inline-block;background:#0b7a75;color:#fff;border:0;border-radius:6px;padding:9px 13px;text-decoration:none;margin:8px 8px 0 0;cursor:pointer}.button.secondary,button.secondary{background:#475467}.button.light,button.light{background:#eef2f7;color:#27364a}.muted{color:#667085}.tag{display:inline-block;background:#e8faf8;color:#075c58;border-radius:999px;padding:4px 9px;margin:3px}.warn{color:#b54708;font-weight:700}.ok{color:#027a48;font-weight:700}.fail{color:#b42318;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border-bottom:1px solid #eaecf0;padding:10px;text-align:left;vertical-align:top}code{word-break:break-all}.actions form{display:inline}.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.media-card{display:block;background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:14px;color:#172033;text-decoration:none;min-height:128px}.player{background:#020617;color:#fff;min-height:380px;display:grid;place-items:center;border-radius:8px;overflow:hidden}.player video,.player iframe{width:100%;height:100%;min-height:380px;border:0;background:#000}.episode-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px}.episode-grid a{display:block;border:1px solid #d8dee8;border-radius:6px;padding:9px;color:#172033;text-decoration:none;background:#fff}@media(max-width:640px){.wrap{padding:18px 12px}.grid,.media-grid{grid-template-columns:1fr}.player,.player video,.player iframe{min-height:240px}}</style><main class="wrap">' . $body . '</main>';
    };
    $samplePlayUrls = static function (array $items, int $limit = 5): array {
        $urls = [];
        foreach ($items as $item) {
            foreach (($item['play_groups'] ?? []) as $group) {
                foreach (($group['episodes'] ?? []) as $episode) {
                    if (!is_string($episode['url'] ?? null) || $episode['url'] === '') {
                        continue;
                    }
                    $urls[] = [
                        'title' => (string) ($item['title'] ?? ''),
                        'episode' => (string) ($episode['title'] ?? ''),
                        'play_source_code' => (string) ($group['play_source_code'] ?? ''),
                        'url' => (string) $episode['url'],
                        'url_type' => (string) ($episode['url_type'] ?? 'embed'),
                    ];
                    if (count($urls) >= $limit) {
                        return $urls;
                    }
                }
            }
        }
        return $urls;
    };

    if (method_exists($context, 'adminMenu')) {
        $context->adminMenu('影视采集', '/admin/video-collector', 'video_collector.manage');
    }

    if (method_exists($context, 'frontRoute')) {
        $context->frontRoute('GET', '/videos', static function ($request) use ($repo, $pageShell, $html): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::html($pageShell('影视', '<section class="panel"><h1>影视</h1><p>影视内容暂未启用。</p></section>'));
            }
            $cards = '';
            foreach ($repo->publicVideos(48) as $video) {
                $cards .= '<a class="media-card" href="/videos/detail?id=' . (int) $video['id'] . '"><strong>' . $html((string) $video['title']) . '</strong><p class="muted">' . $html((string) ($video['category_name'] ?? $video['type'] ?? '未分类')) . ' · ' . $html((string) ($video['year'] ?? '')) . ' · ' . (int) ($video['episode_count'] ?? 0) . ' 集</p><p>' . $html(mb_substr((string) ($video['description'] ?? ''), 0, 80)) . '</p></a>';
            }
            if ($cards === '') {
                $cards = '<section class="panel"><p>暂时还没有影视内容。</p></section>';
            }
            return Response::html($pageShell('影视', '<h1>影视</h1><section class="media-grid">' . $cards . '</section>'));
        });
        $context->frontRoute('GET', '/videos/detail', static function ($request) use ($repo, $pageShell, $html, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('影视内容暂未启用。', 503);
            }
            $video = $repo->publicVideo((int) $param($request, 'id', '0'));
            if ($video === null) {
                return Response::text('视频不存在。', 404);
            }
            $episodes = '';
            foreach ($repo->episodesForVideo((int) $video['id']) as $episode) {
                $episodes .= '<a href="/videos/watch?episode_id=' . (int) $episode['id'] . '">' . $html((string) $episode['title']) . '</a>';
            }
            return Response::html($pageShell((string) $video['title'], '<p><a class="button light" href="/videos">返回影视</a></p><section class="panel"><h1>' . $html((string) $video['title']) . '</h1><p class="muted">' . $html((string) ($video['category_name'] ?? $video['type'] ?? '未分类')) . ' · ' . $html((string) ($video['year'] ?? '')) . ' · ' . (int) ($video['episode_count'] ?? 0) . ' 集</p><p>' . $html((string) ($video['description'] ?? '')) . '</p></section><section class="panel"><h2>选集</h2><div class="episode-grid">' . ($episodes !== '' ? $episodes : '<p>暂无可播放剧集。</p>') . '</div></section>'));
        });
        $context->frontRoute('GET', '/videos/watch', static function ($request) use ($repo, $pageShell, $html, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('影视内容暂未启用。', 503);
            }
            $episode = $repo->episodeWithVideo((int) $param($request, 'episode_id', '0'));
            if ($episode === null) {
                return Response::text('剧集不存在。', 404);
            }
            $playUrls = $repo->playUrlsForEpisode((int) $episode['id']);
            $current = $playUrls[0] ?? null;
            $player = '<p>暂无可用播放地址。</p>';
            if (is_array($current)) {
                $url = $html((string) $current['url']);
                $type = (string) ($current['url_type'] ?? '');
                $player = in_array($type, ['hls', 'mp4'], true)
                    ? '<video controls playsinline preload="metadata" src="' . $url . '"></video>'
                    : '<iframe src="' . $url . '" sandbox="allow-same-origin allow-presentation" referrerpolicy="no-referrer" loading="lazy"></iframe>';
            }
            $sources = '';
            foreach ($playUrls as $row) {
                $sources .= '<span class="tag">' . $html((string) ($row['display_name'] ?? $row['code'] ?? '播放线路')) . ' · ' . $html((string) ($row['health_status'] ?? 'unknown')) . '</span>';
            }
            return Response::html($pageShell((string) $episode['video_title'], '<p><a class="button light" href="/videos/detail?id=' . (int) $episode['video_id'] . '">返回详情</a></p><section class="panel"><h1>' . $html((string) $episode['video_title']) . ' · ' . $html((string) $episode['title']) . '</h1><div class="player">' . $player . '</div><p>' . $sources . '</p></section>'));
        });
    }

    if (method_exists($context, 'adminRoute')) {
        $context->adminRoute('GET', '/admin/video-collector', static function ($request) use ($repo, $html, $decodeJson, $pageShell): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::html($pageShell('影视采集', '<section class="panel"><h1>影视采集</h1><p class="fail">数据库权限不可用。</p></section>'), 503);
            }
            $notice = isset($request->query['saved']) ? '<p class="ok">操作已完成。</p>' : '';
            $stats = $repo->stats();
            $providerRows = '';
            foreach ($repo->listProviders() as $provider) {
                $summary = $decodeJson($provider['type_summary_json'] ?? null);
                $tags = '';
                foreach ($summary as $type => $count) {
                    $tags .= '<span class="tag">' . $html((string) $type) . ':' . (int) $count . '</span>';
                }
                $providerRows .= '<tr><td><strong>' . $html((string) $provider['name']) . '</strong><br><code>' . $html((string) $provider['api_url']) . '</code></td><td>' . $html((string) $provider['provider_type']) . '<br>' . $tags . '</td><td>' . ((int) $provider['enabled'] === 1 ? '<span class="ok">启用</span>' : '<span class="muted">停用</span>') . '<br>自动同步：' . ((int) ($provider['auto_sync_enabled'] ?? 0) === 1 ? '开' : '关') . '</td><td>' . $html((string) ($provider['health_status'] ?? 'unknown')) . '<br>资源数：' . (int) ($provider['resource_count'] ?? 0) . '</td><td class="actions"><form method="post" action="/admin/video-collector/job/create">' . CsrfToken::field() . '<input type="hidden" name="provider_id" value="' . (int) $provider['id'] . '"><button type="submit">立即采集全部</button></form><form method="post" action="/admin/video-collector/provider/toggle">' . CsrfToken::field() . '<input type="hidden" name="provider_id" value="' . (int) $provider['id'] . '"><input type="hidden" name="enabled" value="' . ((int) $provider['enabled'] === 1 ? '0' : '1') . '"><button class="secondary" type="submit">' . ((int) $provider['enabled'] === 1 ? '停用' : '启用') . '</button></form><form method="post" action="/admin/video-collector/provider/delete" onsubmit="return confirm(\'确认删除该 Provider 吗？已采集内容会保留。\')">' . CsrfToken::field() . '<input type="hidden" name="provider_id" value="' . (int) $provider['id'] . '"><button class="light" type="submit">删除</button></form></td></tr>';
            }
            if ($providerRows === '') {
                $providerRows = '<tr><td colspan="5">还没有 Provider。粘贴资源站 API 地址即可开始。</td></tr>';
            }
            $jobRows = '';
            foreach ($repo->latestJobs(12) as $job) {
                $percent = (int) ($job['total_items'] ?? 0) > 0 ? round(((int) ($job['processed_items'] ?? 0) / (int) $job['total_items']) * 100) : 0;
                $jobRows .= '<tr><td>#' . (int) $job['id'] . '<br>' . $html((string) ($job['provider_name'] ?? '')) . '</td><td>' . $html((string) $job['status']) . '<br>' . $percent . '%</td><td>' . (int) ($job['processed_items'] ?? 0) . '/' . (int) ($job['total_items'] ?? 0) . '<br>成功 ' . (int) ($job['success_count'] ?? 0) . ' · 跳过 ' . (int) ($job['skipped_count'] ?? 0) . ' · 失败 ' . (int) ($job['failed_count'] ?? 0) . '</td><td class="actions"><form method="post" action="/admin/video-collector/job/run">' . CsrfToken::field() . '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><button type="submit">继续运行一批</button></form><form method="post" action="/admin/video-collector/job/action">' . CsrfToken::field() . '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><input type="hidden" name="status" value="' . ((string) $job['status'] === 'paused' ? 'pending' : 'paused') . '"><button class="secondary" type="submit">' . ((string) $job['status'] === 'paused' ? '恢复' : '暂停') . '</button></form><form method="post" action="/admin/video-collector/job/action">' . CsrfToken::field() . '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><input type="hidden" name="status" value="cancelled"><button class="light" type="submit">取消</button></form></td></tr>';
            }
            if ($jobRows === '') {
                $jobRows = '<tr><td colspan="4">暂无采集任务。</td></tr>';
            }
            $body = '<h1>影视采集</h1>' . $notice .
                '<section class="grid"><div class="panel"><strong>影片</strong><p>' . $stats['videos'] . '</p></div><div class="panel"><strong>剧集</strong><p>' . $stats['episodes'] . '</p></div><div class="panel"><strong>播放地址</strong><p>' . $stats['play_urls'] . '</p></div><div class="panel"><strong>运行任务</strong><p>' . $stats['running_jobs'] . '</p></div></section>' .
                '<section class="panel"><h2>一键添加资源站</h2><form method="post" action="/admin/video-collector/provider/save">' . CsrfToken::field() . '<label>资源站 API 地址</label><input name="api_url" type="url" placeholder="https://example.com/api.php/provide/vod/?ac=detail" required><details><summary>高级设置</summary><label>显示名称</label><input name="name" placeholder="自动使用域名"><label>Provider 类型</label><select name="provider_type"><option value="">自动识别</option><option value="maccms_json">MACCMS JSON</option><option value="maccms_xml">MACCMS XML</option><option value="m3u8_json">M3U8 JSON</option><option value="m3u8_xml">M3U8 XML</option><option value="custom_json">Custom JSON</option><option value="custom_xml">Custom XML</option></select><label>批次大小</label><input name="batch_size" type="number" min="1" max="100" value="20"><label><input type="checkbox" name="auto_sync_enabled" value="1"> 开启自动同步</label></details><button type="submit">检测并保存</button><a class="button secondary" href="/admin/video-collector/provider/preview">只预览</a></form></section>' .
                '<section class="panel"><h2>Provider</h2><table><tr><th>资源站</th><th>类型</th><th>状态</th><th>健康</th><th>操作</th></tr>' . $providerRows . '</table></section>' .
                '<section class="panel"><h2>采集队列</h2><table><tr><th>任务</th><th>状态</th><th>进度</th><th>操作</th></tr>' . $jobRows . '</table></section>';
            return Response::html($pageShell('影视采集', $body));
        }, 'video_collector.manage', false);

        $context->adminRoute('POST', '/admin/video-collector/provider/save', static function ($request) use ($repo, $http, $detector, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $apiUrl = trim($param($request, 'api_url'));
            $http->assertSafeUrl($apiUrl);
            $response = $http->get($apiUrl);
            $detection = $detector->detect($apiUrl, $response['body']);
            $type = $param($request, 'provider_type', '');
            if ($type !== '') {
                $detection['provider_type'] = $type;
            }
            $providerId = $repo->saveProvider([
                'id' => (int) $param($request, 'provider_id', '0'),
                'name' => trim($param($request, 'name')) !== '' ? trim($param($request, 'name')) : (string) $detection['name'],
                'provider_type' => (string) $detection['provider_type'],
                'api_url' => $apiUrl,
                'base_url' => '',
                'enabled' => 1,
                'auto_sync_enabled' => $param($request, 'auto_sync_enabled') === '1' ? 1 : 0,
            ]);
            $repo->recordProviderDetection($providerId, $detection);
            return Response::redirect('/admin/video-collector?saved=provider');
        });

        $context->adminRoute('POST', '/admin/video-collector/provider/toggle', static function ($request) use ($repo, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $provider = $repo->provider((int) $param($request, 'provider_id', '0'));
            if ($provider !== null) {
                $provider['enabled'] = $param($request, 'enabled', '0') === '1' ? 1 : 0;
                $repo->saveProvider($provider);
            }
            return Response::redirect('/admin/video-collector?saved=toggle');
        });

        $context->adminRoute('POST', '/admin/video-collector/provider/delete', static function ($request) use ($repo, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $repo->deleteProvider((int) $param($request, 'provider_id', '0'));
            return Response::redirect('/admin/video-collector?saved=delete');
        });

        $context->adminRoute('POST', '/admin/video-collector/job/create', static function ($request) use ($repo, $http, $detector, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $provider = $repo->provider((int) $param($request, 'provider_id', '0'));
            if ($provider === null) {
                return Response::text('Provider 不存在。', 404);
            }
            $response = $http->get((string) $provider['api_url']);
            $detection = $detector->detect((string) $provider['api_url'], $response['body']);
            $repo->recordProviderDetection((int) $provider['id'], $detection);
            $jobId = $repo->createJob((int) $provider['id'], 'full_collect', is_array($detection['items'] ?? null) ? $detection['items'] : [], (int) $param($request, 'batch_size', '20'));
            $repo->runJob($jobId, 20);
            return Response::redirect('/admin/video-collector?saved=job');
        });

        $context->adminRoute('POST', '/admin/video-collector/job/run', static function ($request) use ($repo, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $repo->runJob((int) $param($request, 'job_id', '0'), (int) $param($request, 'batch_size', '20'));
            return Response::redirect('/admin/video-collector?saved=run');
        });

        $context->adminRoute('POST', '/admin/video-collector/job/action', static function ($request) use ($repo, $param): Response {
            if (!$repo instanceof VideoRepository) {
                return Response::text('数据库权限不可用。', 503);
            }
            $repo->setJobStatus((int) $param($request, 'job_id', '0'), $param($request, 'status', 'paused'));
            return Response::redirect('/admin/video-collector?saved=job_action');
        });

        $context->adminRoute('GET', '/admin/video-collector/provider/preview', static function ($request) use ($http, $detector, $param, $html, $pageShell): Response {
            $url = $param($request, 'api_url');
            if ($url === '') {
                return Response::html($pageShell('资源站预览', '<h1>资源站预览</h1><section class="panel"><form method="get" action="/admin/video-collector/provider/preview"><label>API URL</label><input name="api_url" type="url" placeholder="https://example.com/api.php/provide/vod/?ac=detail" required><button type="submit">自动识别</button><a class="button secondary" href="/admin/video-collector">返回</a></form></section>'));
            }
            $response = $http->get($url);
            $detection = $detector->detect($url, $response['body']);
            $items = array_slice(is_array($detection['items'] ?? null) ? $detection['items'] : [], 0, 20);
            if ($param($request, 'format') === 'json') {
                return Response::json(['provider' => array_diff_key($detection, ['items' => true]), 'items' => $items]);
            }
            $rows = '';
            foreach ($items as $item) {
                $groups = [];
                foreach (($item['play_groups'] ?? []) as $group) {
                    $groups[] = (string) ($group['play_source_code'] ?? 'default') . ':' . count($group['episodes'] ?? []);
                }
                $rows .= '<tr><td>' . $html((string) ($item['external_id'] ?? '')) . '</td><td>' . $html((string) ($item['title'] ?? '')) . '</td><td>' . $html((string) ($item['type'] ?? '')) . '</td><td>' . $html((string) ($item['year'] ?? '')) . '</td><td>' . $html(implode(', ', $groups)) . '</td></tr>';
            }
            $body = '<h1>资源站预览</h1><section class="panel"><p><strong>自动识别：</strong>' . $html((string) $detection['provider_type']) . '</p><p><strong>资源：</strong>' . (int) $detection['resource_count'] . ' 部，播放地址样本 ' . (int) $detection['episode_count'] . ' 个</p><p><span class="tag">' . $html((string) $detection['health_status']) . '</span></p><a class="button" href="/admin/video-collector/source/health?api_url=' . rawurlencode($url) . '">抽样健康检测</a><a class="button secondary" href="/admin/video-collector/provider/preview?format=json&api_url=' . rawurlencode($url) . '">查看 JSON</a><a class="button secondary" href="/admin/video-collector">返回</a></section><section class="panel"><h2>影片样例</h2><table><tr><th>来源 ID</th><th>标题</th><th>类型</th><th>年份</th><th>线路:集数</th></tr>' . $rows . '</table></section>';
            return Response::html($pageShell('影视资源站预览', $body));
        }, 'video_collector.manage', false);

        $context->adminRoute('GET', '/admin/video-collector/source/health', static function ($request) use ($http, $detector, $checker, $param, $html, $pageShell, $samplePlayUrls): Response {
            $url = $param($request, 'api_url');
            if ($url === '') {
                return Response::html($pageShell('播放源健康检测', '<section class="panel"><p>Missing api_url.</p><a class="button secondary" href="/admin/video-collector">返回</a></section>'));
            }
            $response = $http->get($url);
            $detection = $detector->detect($url, $response['body']);
            $samples = $samplePlayUrls(array_slice(is_array($detection['items'] ?? null) ? $detection['items'] : [], 0, 20), 5);
            $rows = '';
            foreach ($samples as $sample) {
                try {
                    $health = $sample['url_type'] === 'hls' ? $checker->inspect($sample['url']) : ['health_status' => ($http->isSafePublicUrl($sample['url']) ? 'unknown' : 'failed'), 'reason' => $sample['url_type'] . '_not_downloaded'];
                } catch (\Throwable $e) {
                    $health = ['health_status' => 'failed', 'reason' => $e->getMessage()];
                }
                $rows .= '<tr><td>' . $html($sample['title']) . '</td><td>' . $html($sample['episode']) . '</td><td>' . $html($sample['play_source_code']) . '</td><td>' . $html($sample['url_type']) . '</td><td>' . $html((string) ($health['health_status'] ?? 'unknown')) . '</td><td><code>' . $html((string) ($health['reason'] ?? $health['playlist_type'] ?? '')) . '</code></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="6">没有可检测的播放地址。常见 MACCMS 详情接口需要追加 ac=detail。</td></tr>';
            }
            return Response::html($pageShell('播放源健康检测', '<h1>播放源健康检测</h1><section class="panel"><a class="button secondary" href="/admin/video-collector/provider/preview?api_url=' . rawurlencode($url) . '">返回预览</a></section><section class="panel"><table><tr><th>影片</th><th>集</th><th>线路</th><th>URL 类型</th><th>健康</th><th>说明</th></tr>' . $rows . '</table></section>'));
        }, 'video_collector.manage', false);
    }

    if (method_exists($context, 'data')) {
        $context->data()->put('api', 'video_core_contract', [
            'entities' => ['Video', 'Season', 'Episode', 'ResourceProvider', 'PlaySource', 'EpisodePlayUrl', 'CollectorJob', 'CollectorJobItem'],
            'content_types' => ['movie', 'tv', 'short_drama', 'anime', 'variety', 'documentary', 'uncategorized'],
            'provider_types' => ['maccms_json', 'maccms_xml', 'm3u8_json', 'm3u8_xml', 'custom_json', 'custom_xml', 'authorized_api'],
            'play_protocols' => ['hls', 'mp4', 'embed', 'other'],
            'resource_provider_is_not_play_source' => true,
            'download_video_files_by_default' => false,
            'uninstall_policy' => 'retain_formal_content',
            'smart_mode' => ['provider_crud' => true, 'queue_resume' => true, 'idempotent_import' => true, 'frontend_player' => true],
        ]);
    }

    unset($vault);
};

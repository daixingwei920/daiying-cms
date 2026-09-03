<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Official\VideoCollector\HlsChecker;
use Official\VideoCollector\ResourceProviderParser;
use Official\VideoCollector\SafeHttpClient;
use Official\VideoCollector\SecretVault;

require_once __DIR__ . '/src/VideoSystem.php';

return static function (PluginContext $context): void {
    $http = new SafeHttpClient();
    $parser = new ResourceProviderParser();
    $checker = new HlsChecker($http);
    $vault = new SecretVault();
    $param = static function ($request, string $key, string $default = ''): string {
        foreach (['input', 'query', 'get'] as $method) {
            if (is_object($request) && method_exists($request, $method)) {
                $value = $request->{$method}($key);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }
        }
        return (string) ($_GET[$key] ?? $default);
    };
    $html = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pageShell = static function (string $title, string $body): string {
        return '<!doctype html><meta charset="utf-8"><title>' . $title . '</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#172033}.wrap{max-width:1180px;margin:0 auto;padding:28px 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:20px;margin:0 0 18px}label{display:block;font-weight:650;margin:12px 0 6px}input,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px}button,.button{display:inline-block;background:#0b7a75;color:#fff;border:0;border-radius:6px;padding:10px 14px;text-decoration:none;margin:12px 8px 0 0}.button.secondary{background:#475467}.muted{color:#667085}.tag{display:inline-block;background:#e8faf8;color:#075c58;border-radius:999px;padding:4px 9px;margin:3px}.warn{color:#b54708;font-weight:700}.ok{color:#027a48;font-weight:700}.fail{color:#b42318;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border-bottom:1px solid #eaecf0;padding:10px;text-align:left;vertical-align:top}code{word-break:break-all}</style><main class="wrap">' . $body . '</main>';
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
        $context->frontRoute('GET', '/videos', static function ($request) use ($pageShell) {
            return \Cms\Core\Http\Response::html($pageShell('影视', <<<'HTML'
<h1>影视</h1>
<section class="panel">
  <p><span class="tag">电影</span><span class="tag">电视剧</span><span class="tag">短剧</span><span class="tag">动漫</span><span class="tag">综艺</span></p>
  <a class="button" href="/movie/">电影</a>
  <a class="button" href="/tv/">电视剧</a>
  <a class="button" href="/short-drama/">短剧</a>
  <a class="button" href="/anime/">动漫</a>
  <a class="button" href="/variety/">综艺</a>
</section>
HTML));
        });
    }

    if (method_exists($context, 'adminRoute')) {
        $context->adminRoute('GET', '/admin/video-collector', static fn ($request) => \Cms\Core\Http\Response::html(<<<'HTML'
<!doctype html><meta charset="utf-8"><title>影视采集</title>
<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#172033}.wrap{max-width:1100px;margin:0 auto;padding:28px 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:20px;margin:0 0 18px}label{display:block;font-weight:650;margin:12px 0 6px}input,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px}button{background:#0b7a75;color:#fff;border:0;border-radius:6px;padding:10px 14px;margin-top:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.muted{color:#667085}.tag{display:inline-block;background:#e8faf8;color:#075c58;border-radius:999px;padding:4px 9px;margin:3px}</style>
<main class="wrap">
  <h1>影视采集</h1>
  <section class="panel">
    <h2>资源站 Provider 预览</h2>
    <form method="get" action="/admin/video-collector/provider/preview">
      <label>Provider 类型</label>
      <select name="provider_type"><option value="maccms_json">MACCMS JSON</option><option value="maccms_xml">MACCMS XML</option><option value="m3u8_json">M3U8 JSON</option><option value="m3u8_xml">M3U8 XML</option><option value="custom_json">Custom JSON</option><option value="custom_xml">Custom XML</option></select>
      <label>API URL</label>
      <input name="api_url" type="url" placeholder="https://example.com/api.php/provide/vod/" required>
      <button type="submit">拉取预览</button>
    </form>
    <p class="muted">Resource Provider 与 Play Source 分离；默认只保存资料、海报和播放地址，不下载视频文件。</p>
  </section>
  <section class="grid">
    <div class="panel"><h2>内容类型</h2><p><span class="tag">电影</span><span class="tag">电视剧</span><span class="tag">短剧</span><span class="tag">动漫</span><span class="tag">综艺</span></p></div>
    <div class="panel"><h2>分类映射</h2><p>来源分类默认不无限制导入，必须映射到 CMS 类型和分类后启用。</p></div>
    <div class="panel"><h2>每集多线路</h2><p>播放地址保存到 Episode Play URL 独立表，支持 HLS/M3U8、MP4、Embed。</p></div>
    <div class="panel"><h2>健康检测</h2><p>支持 healthy、degraded、failed、unknown，限制检测频率和重试次数。</p></div>
  </section>
</main>
HTML), 'video_collector.manage', false);
        $context->adminRoute('GET', '/admin/video-collector/provider/preview', static function ($request) use ($http, $parser, $param, $html, $pageShell, $samplePlayUrls) {
            $url = $param($request, 'api_url');
            $type = $param($request, 'provider_type', 'maccms_json');
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing api_url.</p>');
            }
            $response = $http->get($url);
            $items = array_slice($parser->parsePayload($type, $response['body']), 0, 20);
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['items' => $items]);
            }
            $rows = '';
            $episodeTotal = 0;
            foreach ($items as $item) {
                $groups = [];
                foreach (($item['play_groups'] ?? []) as $group) {
                    $count = count($group['episodes'] ?? []);
                    $episodeTotal += $count;
                    $groups[] = (string) ($group['play_source_code'] ?? 'default') . ':' . $count;
                }
                $rows .= '<tr><td>' . $html((string) ($item['external_id'] ?? '')) . '</td><td>' . $html((string) ($item['title'] ?? '')) . '</td><td>' . $html((string) ($item['type'] ?? '')) . '</td><td>' . $html((string) ($item['year'] ?? '')) . '</td><td>' . $html(implode(', ', $groups)) . '</td></tr>';
            }
            $detailHint = $episodeTotal === 0 ? '<p class="warn">当前返回没有播放地址。MACCMS 资源站通常需要在 API URL 里加入 ac=detail，例如 /api.php/provide/vod/?ac=detail。</p>' : '<p class="ok">已识别到播放地址，Resource Provider 和 Play Source 会分开保存。</p>';
            $body = '<h1>资源站预览</h1><section class="panel"><p><strong>Provider 类型：</strong>' . $html($type) . '</p><p><strong>样本影片：</strong>' . count($items) . '</p><p><strong>样本播放地址数：</strong>' . $episodeTotal . '</p>' . $detailHint . '<a class="button" href="/admin/video-collector/source/health?provider_type=' . rawurlencode($type) . '&api_url=' . rawurlencode($url) . '">抽样健康检测</a><a class="button secondary" href="/admin/video-collector/provider/preview?format=json&provider_type=' . rawurlencode($type) . '&api_url=' . rawurlencode($url) . '">查看 JSON</a><a class="button secondary" href="/admin/video-collector">返回</a></section><section class="panel"><h2>影片样例</h2><table><tr><th>来源 ID</th><th>标题</th><th>类型</th><th>年份</th><th>播放线路:集数</th></tr>' . $rows . '</table></section>';
            unset($samplePlayUrls);
            return \Cms\Core\Http\Response::html($pageShell('影视资源站预览', $body));
        }, 'video_collector.manage', false);
        $context->adminRoute('GET', '/admin/video-collector/source/health', static function ($request) use ($http, $parser, $checker, $param, $html, $pageShell, $samplePlayUrls) {
            $url = $param($request, 'api_url');
            $type = $param($request, 'provider_type', 'maccms_json');
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing api_url.</p>');
            }
            $response = $http->get($url);
            $items = array_slice($parser->parsePayload($type, $response['body']), 0, 20);
            $samples = $samplePlayUrls($items, 5);
            $results = [];
            foreach ($samples as $sample) {
                if ($sample['url_type'] === 'hls') {
                    try {
                        $health = $checker->inspect($sample['url']);
                    } catch (\Throwable $e) {
                        $health = ['health_status' => 'failed', 'reason' => $e->getMessage()];
                    }
                } else {
                    try {
                        $http->assertSafeUrl($sample['url']);
                        $health = ['health_status' => 'unknown', 'reason' => $sample['url_type'] . '_not_downloaded_in_local_test'];
                    } catch (\Throwable $e) {
                        $health = ['health_status' => 'failed', 'reason' => $e->getMessage()];
                    }
                }
                $results[] = $sample + ['health' => $health];
            }
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['samples' => $results]);
            }
            $rows = '';
            foreach ($results as $result) {
                $health = $result['health'];
                $rows .= '<tr><td>' . $html($result['title']) . '</td><td>' . $html($result['episode']) . '</td><td>' . $html($result['play_source_code']) . '</td><td>' . $html($result['url_type']) . '</td><td>' . $html((string) ($health['health_status'] ?? 'unknown')) . '</td><td><code>' . $html((string) ($health['reason'] ?? $health['playlist_type'] ?? '')) . '</code></td></tr>';
            }
            $empty = $rows === '' ? '<p class="warn">没有可检测的播放地址。请把资源站 API URL 改成详情接口，常见写法是追加 ac=detail。</p>' : '';
            $body = '<h1>播放源健康检测</h1><section class="panel">' . $empty . '<a class="button secondary" href="/admin/video-collector/provider/preview?provider_type=' . rawurlencode($type) . '&api_url=' . rawurlencode($url) . '">返回预览</a><a class="button secondary" href="/admin/video-collector/source/health?format=json&provider_type=' . rawurlencode($type) . '&api_url=' . rawurlencode($url) . '">查看 JSON</a></section><section class="panel"><table><tr><th>影片</th><th>集</th><th>线路</th><th>URL 类型</th><th>健康</th><th>说明</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('播放源健康检测', $body));
        }, 'video_collector.manage', false);
    }

    if (method_exists($context, 'data')) {
        $context->data()->put('api', 'video_core_contract', [
            'entities' => ['Video', 'Season', 'Episode', 'ResourceProvider', 'PlaySource', 'EpisodePlayUrl'],
            'content_types' => ['movie', 'tv', 'short_drama', 'anime', 'variety', 'sports', 'other'],
            'provider_types' => ['maccms_json', 'maccms_xml', 'm3u8_json', 'm3u8_xml', 'custom_json', 'custom_xml', 'authorized_api'],
            'play_protocols' => ['hls', 'mp4', 'embed', 'other'],
            'resource_provider_is_not_play_source' => true,
            'download_video_files_by_default' => false,
            'uninstall_policy' => 'retain_formal_content',
        ]);
    }

    unset($vault);
};

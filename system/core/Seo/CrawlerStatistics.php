<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Support\View;
use Cms\Core\Routing\BasePath;
use InvalidArgumentException;

/** A read-only view of the existing bounded aggregate ledger. */
final class CrawlerStatistics
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly Settings $settings) {}

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public function snapshot(array $rows, Request $request): array
    {
        $agents = CrawlerRegistry::agents();
        $query = $request->query;
        $filters = [];
        foreach (['days' => '7', 'platform' => 'all', 'agent' => 'all', 'status' => 'all', 'page' => '1'] as $key => $default) {
            $value = $query[$key] ?? $default;
            if (!is_string($value)) { throw new InvalidArgumentException('筛选参数无效。'); }
            $filters[$key] = $value;
        }
        if (!in_array($filters['days'], ['1', '7', '30'], true)
            || !in_array($filters['platform'], ['all', 'domestic', 'other'], true)
            || ($filters['agent'] !== 'all' && !isset($agents[$filters['agent']]))
            || !in_array($filters['status'], ['all', '2', '3', '4', '5'], true)
            || !preg_match('/^[1-9][0-9]{0,4}$/D', $filters['page'])) {
            throw new InvalidArgumentException('筛选参数无效。');
        }
        $retention = max(1, min(30, (int) $this->settings->get('seo.crawler_retention_days', 7)));
        $days = min($retention, (int) $filters['days']);
        $now = time();
        $today = gmdate('Y-m-d', $now);
        $floor = gmdate('Y-m-d', $now - ($days - 1) * 86400);
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) { $daily[gmdate('Y-m-d', $now - $i * 86400)] = 0; }
        $selected = []; $ranking = []; $sources = []; $total = $domestic = $errors = $todayHits = 0;
        foreach ($rows as $row) {
            $agent = $row['agent'] ?? null; $day = $row['day'] ?? null;
            $status = $row['status'] ?? null; $hits = $row['hits'] ?? null;
            // Old six-field ledgers remain valid; malformed/unknown data cannot inflate the UI.
            if (!is_string($agent) || !isset($agents[$agent]) || !is_string($day)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || !isset($daily[$day])
                || !is_int($status) || $status < 100 || $status > 599
                || !is_int($hits) || $hits < 1 || $hits > 10000000
                || !is_string($row['route'] ?? null) || !in_array($row['route'], ['/', '/articles', '/robots.txt', '/sitemap.xml', '/llms.txt', '/articles/{slug}', '/category/{slug}', '/tag/{slug}'], true)) { continue; }
            $info = $agents[$agent];
            if (($filters['agent'] !== 'all' && $filters['agent'] !== $agent)
                || ($filters['platform'] === 'domestic' && !$info['domestic'])
                || ($filters['platform'] === 'other' && $info['domestic'])
                || ($filters['status'] !== 'all' && intdiv($status, 100) !== (int) $filters['status'])) { continue; }
            $total += $hits; $daily[$day] += $hits;
            $source = CrawlerSourceVerification::status($row);
            $sources[$source] = ($sources[$source] ?? 0) + $hits;
            if ($info['domestic']) { $domestic += $hits; }
            if ($status >= 400) { $errors += $hits; }
            if ($day === $today) { $todayHits += $hits; }
            $ranking[$agent] ??= ['agent' => $agent, 'label' => $info['label'], 'kind' => $info['kind'], 'domestic' => $info['domestic'], 'hits' => 0, 'errors' => 0, 'sources' => []];
            $ranking[$agent]['hits'] += $hits;
            $ranking[$agent]['sources'][$source] = ($ranking[$agent]['sources'][$source] ?? 0) + $hits;
            if ($status >= 400) { $ranking[$agent]['errors'] += $hits; }
            $selected[] = $row;
        }
        uasort($ranking, static fn ($a, $b): int => ($b['hits'] <=> $a['hits']) ?: strcmp($a['agent'], $b['agent']));
        usort($selected, static fn ($a, $b): int => strcmp($b['day'], $a['day']) ?: (($b['hits'] <=> $a['hits']) ?: strcmp($a['agent'] . $a['route'] . $a['status'], $b['agent'] . $b['route'] . $b['status'])));
        $rowCount = count($selected); $pages = max(1, (int) ceil($rowCount / self::PAGE_SIZE));
        $page = min((int) $filters['page'], $pages);
        return compact('filters', 'retention', 'days', 'today', 'floor', 'daily', 'ranking', 'sources', 'total', 'domestic', 'errors', 'todayHits', 'rowCount', 'pages', 'page')
            + ['rows' => array_slice($selected, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)];
    }

    /** @param list<array<string,mixed>> $rows */
    public function render(array $rows, Request $request): string
    {
        $s = $this->snapshot($rows, $request); $f = $s['filters']; $e = static fn ($v): string => View::escape((string) $v);
        $base = BasePath::fromSettings($this->settings);
        $url = static fn (string $path): string => $e(BasePath::prefix($path, $base));
        $html = '<div class="admin-compact compact-crawlers"><div class="admin-page-header"><div><h1>蜘蛛记录与统计</h1><p class="muted">国内平台与其他平台的 UA 声明标记；不是请求所在地，也不是收录或 AI 引用统计。</p></div><a class="button admin-button-secondary" href="' . $url('/admin/settings') . '">SEO / GEO 设置</a></div>';
        $html .= '<p class="admin-badge ' . ($this->settings->get('seo.crawler_log_enabled', false) ? 'admin-badge-success">采集已开启' : 'admin-badge-warning">采集已关闭，以下仅显示保留记录') . '</p>';
        $html .= '<p>蜘蛛名称与用途是 UA 自报；来源按连接地址核验，只有匹配官方 IP 段或通过反向 DNS 及正向回查才标记已验证。历史记录不能补验。聚合日志不保存 IP、原始 UA、查询串或具体文章地址；并发可能丢样，丢样数量未知，不能推算完整访问总量、采集率或独立访客数。</p>';
        $verified = ($s['sources']['source_verified_ip'] ?? 0) + ($s['sources']['source_verified_dns'] ?? 0);
        $html .= '<p>当前筛选已验证来源：<strong>' . number_format($verified) . ' / ' . number_format($s['total']) . '</strong> 次请求；后台待核验 ' . number_format($s['sources']['source_pending'] ?? 0) . ' 次。验证来源不代表已被收录或 AI 引用。</p>';
        $html .= '<details><summary>来源核验说明与后台运行</summary><p>待核验连接 IP 仅在私有任务中加密临存，有效期1小时，完成后删除；过期任务由后台或后续采集清理。未运行后台时不会虚构已验证结果。缓存过期、网络故障和不支持的蜘蛛均单独标记；未通过核验也不会自动封禁。</p><p>沿用站点每分钟计划任务 <code>php cli.php run-scheduled-tasks</code>，也可运行 <code>php cli.php seo:crawlers:verify</code>。每轮最多4个来源，网页请求不进行 DNS 或外部网络查询。代理/CDN 站点应在服务器端正确恢复真实连接地址；不采信访客自行提交的转发 IP。</p></details>';
        $html .= '<form method="get" action="' . $url('/admin/seo/crawlers') . '" class="admin-filter-bar" aria-label="蜘蛛统计筛选">';
        foreach (['days' => ['统计周期', ['1' => '今天', '7' => '最近7天', '30' => '最近30天']], 'platform' => ['平台标记', ['all' => '全部平台', 'domestic' => '国内平台标记', 'other' => '其他平台标记']], 'status' => ['HTTP 状态', ['all' => '全部状态', '2' => '2xx 成功', '3' => '3xx 跳转', '4' => '4xx 请求错误', '5' => '5xx 服务错误']]] as $key => [$label, $options]) {
            $html .= '<label>' . $label . '<select name="' . $key . '">';
            foreach ($options as $value => $text) { $html .= '<option value="' . $e($value) . '"' . ((string) $value === $f[$key] ? ' selected' : '') . '>' . $text . '</option>'; }
            $html .= '</select></label>';
        }
        $html .= '<label>蜘蛛声明<select name="agent"><option value="all">全部蜘蛛</option>';
        foreach (CrawlerRegistry::agents() as $agent => $info) { $html .= '<option value="' . $e($agent) . '"' . ($f['agent'] === $agent ? ' selected' : '') . '>' . $e($info['label'] . ' · ' . $agent) . '</option>'; }
        $html .= '</select></label><button type="submit">筛选</button><a href="' . $url('/admin/seo/crawlers') . '">重置</a></form>';
        $html .= '<p class="muted">当前筛选范围：' . $e($s['floor']) . ' 至 ' . $e($s['today']) . '（UTC）。配置保留 ' . $s['retention'] . ' 天，较长周期不会恢复已删除记录；上限2,000条聚合、512 KiB。</p>';
        $html .= '<div class="admin-dashboard-grid">';
        foreach (['筛选范围内已采集请求数' => $s['total'], '其中今日已采集请求数' => $s['todayHits'], '国内平台标记已采集请求数' => $s['domestic'], '4xx / 5xx 已采集请求数' => $s['errors']] as $label => $number) { $html .= '<section class="card admin-dashboard-card"><span>' . $label . '</span><strong>' . number_format($number) . '</strong></section>'; }
        $html .= '</div>';
        if ($s['total'] === 0) { $html .= '<div class="admin-empty-state">当前筛选下暂无记录。请确认已开启采集，并等待带有已支持蜘蛛标记的请求访问公开页面；空记录不能证明蜘蛛没有访问，也不能证明已经收录。</div>'; }
        $html .= '<div class="compact-crawler-columns"><section class="editor-card"><h2>已采集请求按日趋势（UTC）</h2><div class="crawler-table-wrap compact-scroll" tabindex="0" role="region" aria-label="按日趋势"><table class="crawler-stat-table"><thead><tr><th>UTC 日期</th><th>已采集请求数</th><th>相对采集量</th></tr></thead><tbody>';
        $peak = max(1, ...array_values($s['daily']));
        foreach ($s['daily'] as $day => $hits) { $width = (int) round($hits / $peak * 100); $html .= '<tr><td>' . $e($day) . '</td><td>' . number_format($hits) . '</td><td><span class="crawler-trend-track" aria-hidden="true"><span style="width:' . $width . '%"></span></span></td></tr>'; }
        $html .= '</tbody></table></div></section><section class="editor-card"><h2>蜘蛛声明排名（' . count($s['ranking']) . ' 种）</h2><div class="crawler-table-wrap compact-scroll" tabindex="0" role="region" aria-label="蜘蛛声明排名"><table class="crawler-stat-table"><thead><tr><th>蜘蛛 / UA 标记</th><th>平台分类</th><th>类型标记</th><th>已采集请求数</th><th>4xx / 5xx</th><th>来源</th></tr></thead><tbody>';
        foreach ($s['ranking'] as $r) { $html .= '<tr><td>' . $e($r['label']) . '<br><code>' . $e($r['agent']) . '</code></td><td>' . ($r['domestic'] ? '国内平台标记' : '其他平台标记') . '</td><td>' . $e($r['kind']) . '</td><td>' . number_format($r['hits']) . '</td><td>' . number_format($r['errors']) . '</td><td>' . self::sourceSummary($r['sources']) . '</td></tr>'; }
        if ($s['ranking'] === []) { $html .= '<tr><td colspan="6">暂无匹配的蜘蛛声明。</td></tr>'; }
        $html .= '</tbody></table></div></section></div><section class="editor-card"><h2>聚合明细</h2><p>共 ' . $s['rowCount'] . ' 条聚合，第 ' . $s['page'] . ' / ' . $s['pages'] . ' 页，每页最多50条。</p><div class="crawler-table-wrap compact-scroll" tabindex="0" role="region" aria-label="聚合明细"><table class="crawler-stat-table"><thead><tr><th>UTC 日期</th><th>蜘蛛声明</th><th>公开路由类别</th><th>HTTP 状态</th><th>已采集次数</th><th>来源</th></tr></thead><tbody>';
        foreach ($s['rows'] as $row) { $html .= '<tr>'; foreach (['day', 'agent', 'route', 'status', 'hits'] as $key) { $html .= '<td>' . $e($row[$key]) . '</td>'; } $source = CrawlerSourceVerification::status($row); $html .= '<td>' . self::sourceSummary([$source => $row['hits']]) . '</td></tr>'; }
        if ($s['rows'] === []) { $html .= '<tr><td colspan="6">暂无匹配记录。</td></tr>'; }
        $html .= '</tbody></table></div><nav class="admin-pagination" aria-label="聚合明细分页">';
        foreach ([$s['page'] - 1 => '上一页', $s['page'] + 1 => '下一页'] as $page => $label) { if ($page >= 1 && $page <= $s['pages']) { $html .= '<a href="' . $url('/admin/seo/crawlers?' . http_build_query(array_replace($f, ['page' => (string) $page]))) . '">' . $label . '</a>'; } }
        return $html . '</nav><p class="muted">国内平台标记包括百度、搜狗、360、神马、字节 Bytespider、头条、Petal。分类来自公开 UA 规则，不验证运营方或用途，不表示 AI 搜索/训练策略已覆盖这些蜘蛛。</p></section></div>';
    }

    /** A mixed agent is never given an all-verified badge. Counts always remain visible. */
    private static function sourceSummary(array $counts): string
    {
        $total = array_sum($counts); $verified = ($counts['source_verified_ip'] ?? 0) + ($counts['source_verified_dns'] ?? 0);
        if (count($counts) === 1) {
            $status = (string) array_key_first($counts);
            return '<span class="admin-badge ' . ($verified > 0 ? 'admin-badge-success' : 'admin-badge-warning') . '" style="white-space:normal">' . View::escape(CrawlerSourceVerification::label($status)) . '</span>';
        }
        $html = '<details><summary>已验证 ' . number_format($verified) . ' / ' . number_format($total) . '</summary>';
        foreach ($counts as $status => $hits) { $html .= '<div>' . View::escape(CrawlerSourceVerification::label((string) $status)) . '：' . number_format($hits) . '</div>'; }
        return $html . '</details>';
    }

    /** Site-wide best-effort counters: only the time window applies, never UA/status filters. */
    public function renderObservation(array $observation, Request $request): string
    {
        $range = $this->snapshot([], $request);
        $counts = array_fill_keys(CrawlerObservation::REASONS, 0);
        foreach (($observation['days'] ?? []) as $day => $values) {
            if (!isset($range['daily'][$day]) || !is_array($values)) { continue; }
            foreach ($counts as $reason => $unused) {
                $n = $values[$reason] ?? 0;
                if (is_int($n) && $n >= 0) { $counts[$reason] += $n; }
            }
        }
        $stored = $counts['stored']; unset($counts['stored']);
        $unavailable = max(0, (int) ($observation['unavailable_shards'] ?? 0));
        $html = '<details class="admin-card"><summary>全站采集丢样观测（UTC）</summary><p>仅跟随上方时间窗口，不受平台、蜘蛛或 HTTP 状态筛选影响；不能与上方筛选结果相减或直接比较。只观测采集开启时、已识别 UA 对受支持公开路由的 GET / HEAD 尝试。</p>';
        $html .= '<p>观测本身也可能因争锁、写入失败或计数上限而漏记，以下均为观测下界。不能计算真实访问量、完整丢样数或丢样率；这些全站计数不区分来源核验结果。旧记录不会补算。</p><div class="admin-dashboard-grid">';
        foreach (['观测到的写入成功' => $stored, '观测到的未记入次数' => array_sum($counts), '当前不可读取的观测分片' => $unavailable] as $label => $number) {
            $html .= '<section class="card admin-dashboard-card"><span>' . $label . '</span><strong>' . number_format($number) . '</strong></section>';
        }
        $html .= '</div><p class="muted">固定8个分片，每片最多8 KiB、最多30天、每原因每日最多10,000,000次；与采集日志使用同一保留天数。不可读分片是当前状态，不是丢失请求数。</p><div class="crawler-table-wrap"><table class="crawler-stat-table"><thead><tr><th>未记入原因</th><th>观测次数下界</th></tr></thead><tbody>';
        foreach (['lock_busy' => '日志锁忙（非阻塞跳过）', 'row_limit' => '聚合条数上限', 'byte_limit' => '日志字节上限', 'invalid_ledger' => '日志结构无效', 'io_error' => '日志读写失败', 'unsafe_path' => '不安全的符号链接路径', 'hit_cap' => '聚合计数上限'] as $reason => $label) {
            $html .= '<tr><td>' . $label . '</td><td>' . number_format($counts[$reason]) . '</td></tr>';
        }
        return $html . '</tbody></table></div></details>';
    }
}

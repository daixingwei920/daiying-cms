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
        $selected = []; $ranking = []; $total = $domestic = $errors = $todayHits = 0;
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
            if ($info['domestic']) { $domestic += $hits; }
            if ($status >= 400) { $errors += $hits; }
            if ($day === $today) { $todayHits += $hits; }
            $ranking[$agent] ??= ['agent' => $agent, 'label' => $info['label'], 'kind' => $info['kind'], 'domestic' => $info['domestic'], 'hits' => 0, 'errors' => 0];
            $ranking[$agent]['hits'] += $hits;
            if ($status >= 400) { $ranking[$agent]['errors'] += $hits; }
            $selected[] = $row;
        }
        uasort($ranking, static fn ($a, $b): int => ($b['hits'] <=> $a['hits']) ?: strcmp($a['agent'], $b['agent']));
        usort($selected, static fn ($a, $b): int => strcmp($b['day'], $a['day']) ?: (($b['hits'] <=> $a['hits']) ?: strcmp($a['agent'] . $a['route'] . $a['status'], $b['agent'] . $b['route'] . $b['status'])));
        $rowCount = count($selected); $pages = max(1, (int) ceil($rowCount / self::PAGE_SIZE));
        $page = min((int) $filters['page'], $pages);
        return compact('filters', 'retention', 'days', 'today', 'floor', 'daily', 'ranking', 'total', 'domestic', 'errors', 'todayHits', 'rowCount', 'pages', 'page')
            + ['rows' => array_slice($selected, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)];
    }

    /** @param list<array<string,mixed>> $rows */
    public function render(array $rows, Request $request): string
    {
        $s = $this->snapshot($rows, $request); $f = $s['filters']; $e = static fn ($v): string => View::escape((string) $v);
        $base = BasePath::fromSettings($this->settings);
        $url = static fn (string $path): string => $e(BasePath::prefix($path, $base));
        $html = '<div class="admin-page-header"><div><h1>蜘蛛记录与统计</h1><p class="muted">国内平台与其他平台的 UA 声明标记；不是请求所在地，也不是收录或 AI 引用统计。</p></div><a class="button admin-button-secondary" href="' . $url('/admin/settings') . '">SEO / GEO 设置</a></div>';
        $html .= '<p class="admin-badge ' . ($this->settings->get('seo.crawler_log_enabled', false) ? 'admin-badge-success">采集已开启' : 'admin-badge-warning">采集已关闭，以下仅显示保留记录') . '</p>';
        $html .= '<p>UA 自称，来源未验证。不保存 IP、原始 UA、查询串或具体文章地址。记录按 UTC 日期和公开路由类别聚合；并发可能丢样，不能当作完整访问总量或独立访客数。</p>';
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
        foreach (['筛选范围内记录请求数' => $s['total'], '其中今日记录请求数' => $s['todayHits'], '国内平台标记请求数' => $s['domestic'], '4xx / 5xx 记录请求数' => $s['errors']] as $label => $number) { $html .= '<section class="card admin-dashboard-card"><span>' . $label . '</span><strong>' . number_format($number) . '</strong></section>'; }
        $html .= '</div>';
        if ($s['total'] === 0) { $html .= '<div class="admin-empty-state">当前筛选下暂无记录。请确认已开启采集，并等待带有已支持蜘蛛标记的请求访问公开页面；空记录不能证明蜘蛛没有访问，也不能证明已经收录。</div>'; }
        $html .= '<h2>按日趋势（UTC）</h2><div class="crawler-table-wrap"><table class="crawler-stat-table"><thead><tr><th>UTC 日期</th><th>记录请求数</th><th>相对请求量</th></tr></thead><tbody>';
        $peak = max(1, ...array_values($s['daily']));
        foreach ($s['daily'] as $day => $hits) { $width = (int) round($hits / $peak * 100); $html .= '<tr><td>' . $e($day) . '</td><td>' . number_format($hits) . '</td><td><span class="crawler-trend-track" aria-hidden="true"><span style="width:' . $width . '%"></span></span></td></tr>'; }
        $html .= '</tbody></table></div><h2>蜘蛛声明排名（' . count($s['ranking']) . ' 种）</h2><div class="crawler-table-wrap"><table class="crawler-stat-table"><thead><tr><th>蜘蛛 / UA 标记</th><th>平台分类</th><th>类型标记</th><th>记录请求数</th><th>4xx / 5xx</th><th>来源</th></tr></thead><tbody>';
        foreach ($s['ranking'] as $r) { $html .= '<tr><td>' . $e($r['label']) . '<br><code>' . $e($r['agent']) . '</code></td><td>' . ($r['domestic'] ? '国内平台标记' : '其他平台标记') . '</td><td>' . $e($r['kind']) . '</td><td>' . number_format($r['hits']) . '</td><td>' . number_format($r['errors']) . '</td><td>未验证</td></tr>'; }
        if ($s['ranking'] === []) { $html .= '<tr><td colspan="6">暂无匹配的蜘蛛声明。</td></tr>'; }
        $html .= '</tbody></table></div><h2>聚合明细</h2><p>共 ' . $s['rowCount'] . ' 条聚合，第 ' . $s['page'] . ' / ' . $s['pages'] . ' 页，每页最多50条。</p><div class="crawler-table-wrap"><table class="crawler-stat-table"><thead><tr><th>UTC 日期</th><th>蜘蛛声明</th><th>公开路由类别</th><th>HTTP 状态</th><th>次数</th><th>来源</th></tr></thead><tbody>';
        foreach ($s['rows'] as $row) { $html .= '<tr>'; foreach (['day', 'agent', 'route', 'status', 'hits'] as $key) { $html .= '<td>' . $e($row[$key]) . '</td>'; } $html .= '<td>未验证</td></tr>'; }
        if ($s['rows'] === []) { $html .= '<tr><td colspan="6">暂无匹配记录。</td></tr>'; }
        $html .= '</tbody></table></div><nav class="admin-pagination" aria-label="聚合明细分页">';
        foreach ([$s['page'] - 1 => '上一页', $s['page'] + 1 => '下一页'] as $page => $label) { if ($page >= 1 && $page <= $s['pages']) { $html .= '<a href="' . $url('/admin/seo/crawlers?' . http_build_query(array_replace($f, ['page' => (string) $page]))) . '">' . $label . '</a>'; } }
        return $html . '</nav><p class="muted">国内平台标记包括百度、搜狗、360、神马、字节 Bytespider、头条、Petal。分类来自公开 UA 规则，不验证运营方或用途，不表示 AI 搜索/训练策略已覆盖这些蜘蛛。</p>';
    }
}

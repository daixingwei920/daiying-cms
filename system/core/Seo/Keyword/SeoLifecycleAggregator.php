<?php

declare(strict_types=1);

namespace Cms\Core\Seo\Keyword;

use Cms\Core\Content\ContentRepository;
use Cms\Core\Seo\SearchEngine\OfficialBaiduSubmitBridge;
use PDO;

final class SeoLifecycleAggregator
{
    /** @var list<array<string,mixed>> */
    private array $keywordRows = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $metricsByKeyword = [];
    /** @var array<string,list<array<string,string>>> */
    private array $submissionsByUrl = [];
    /** @var array<string,bool> */
    private array $sitemapUrls = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $conflicts = [];
    private bool $loaded = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentRepository $content,
        private readonly ?OfficialBaiduSubmitBridge $baiduBridge = null,
        private readonly string $siteUrl = '',
    ) {
    }

    /** @param array<string,string> $filters @return array{rows:list<array<string,mixed>>,stats:array<string,mixed>,opportunities:list<array<string,mixed>>} */
    public function dashboard(array $filters = []): array
    {
        $this->load($filters);
        $rows = array_map(fn (array $row): array => $this->augmentRow($row), $this->keywordRows);
        $opportunities = $this->opportunities($rows);

        return [
            'rows' => $rows,
            'stats' => $this->stats($rows),
            'opportunities' => array_slice($opportunities, 0, 25),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(string $keyword): array
    {
        $this->load(['q' => $keyword]);
        $detail = $this->content->seoKeywordDetail($keyword);
        $detail = $this->augmentRow($detail);
        $detail['opportunities'] = $this->opportunities([$detail]);
        return $detail;
    }

    /** @param array<string,string> $filters */
    private function load(array $filters): void
    {
        if ($this->loaded) {
            return;
        }
        $this->keywordRows = $this->content->seoKeywordCenterRows($filters);
        $this->conflicts = $this->content->targetKeywordConflicts();
        $this->metricsByKeyword = $this->metricsByKeyword();
        $this->submissionsByUrl = $this->submissionsByUrl();
        $this->sitemapUrls = $this->sitemapUrls();
        $this->loaded = true;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function augmentRow(array $row): array
    {
        $keyword = (string) ($row['keyword'] ?? '');
        $primaryUrl = trim((string) ($row['primary_url'] ?? ''));
        $bindings = is_array($row['bindings'] ?? null) ? $row['bindings'] : [];
        $matchedBinding = $this->matchingBinding($primaryUrl, $bindings);
        $metrics = $this->metricsByKeyword[$keyword] ?? [];
        $primaryMetrics = $this->filterMetricsForUrl($metrics, $primaryUrl);
        $latestSubmission = $this->latestSubmission($primaryUrl);
        $pageReadiness = $this->pageReadiness($primaryUrl, $matchedBinding, $bindings);
        $sitemapStatus = $this->sitemapStatus($primaryUrl);
        $indexEvidence = $this->indexEvidence($primaryMetrics, $latestSubmission);
        $trends = $this->metricTrends($metrics, $primaryUrl);

        $lifecycle = [
            'target_keyword' => $keyword !== '' ? 'present' : 'missing',
            'landing_page_assigned' => $primaryUrl !== '' ? 'yes' : 'no',
            'page_exists' => !empty($pageReadiness['exists']) ? 'yes' : 'no',
            'page_seo_ready' => !empty($pageReadiness['ready']) ? 'yes' : 'no',
            'sitemap' => $sitemapStatus,
            'submitted' => $latestSubmission !== null ? (string) ($latestSubmission['status'] ?? 'submitted') : 'unknown',
            'index_evidence' => (string) ($indexEvidence['status'] ?? 'unknown'),
            'observed_search_data' => $primaryMetrics !== [] ? 'yes' : 'not_available',
            'ranking' => $this->bestPosition($primaryMetrics) ?? 'Not Available',
        ];

        $row['lifecycle'] = $lifecycle;
        $row['page_readiness'] = $pageReadiness;
        $row['sitemap_status'] = $sitemapStatus;
        $row['latest_baidu_submission'] = $latestSubmission;
        $row['index_evidence'] = $indexEvidence;
        $row['metric_trends'] = $trends;
        $row['observed_metrics'] = array_map(fn (array $metric): array => $this->displayMetric($metric), $metrics);
        $row['conflict'] = isset($this->conflicts[$keyword]) || !empty($row['conflict']);
        $row['opportunity'] = $this->scoreRow($row);

        return $row;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function stats(array $rows): array
    {
        $submitted = 0;
        $withObserved = 0;
        $freshDates = [];
        foreach ($rows as $row) {
            if (($row['latest_baidu_submission'] ?? null) !== null) {
                $submitted++;
            }
            if (($row['lifecycle']['observed_search_data'] ?? '') === 'yes') {
                $withObserved++;
            }
            foreach (($row['metric_trends'] ?? []) as $engine => $windows) {
                if (is_array($windows) && (string) ($windows['latest_period_end'] ?? '') !== '') {
                    $freshDates[] = (string) $windows['latest_period_end'];
                }
            }
        }
        rsort($freshDates);

        return [
            'total' => count($rows),
            'active' => count(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'active')),
            'landing_pages_assigned' => count(array_filter($rows, static fn (array $row): bool => trim((string) ($row['primary_url'] ?? '')) !== '')),
            'seo_ready' => count(array_filter($rows, static fn (array $row): bool => !empty($row['page_readiness']['ready']))),
            'in_sitemap' => count(array_filter($rows, static fn (array $row): bool => ($row['sitemap_status'] ?? '') === 'in_sitemap')),
            'baidu_submitted' => $submitted,
            'with_observed_data' => $withObserved,
            'missing_observed_data' => max(0, count($rows) - $withObserved),
            'conflicts' => count(array_filter($rows, static fn (array $row): bool => !empty($row['conflict']))),
            'data_freshness' => $freshDates[0] ?? 'Not Available',
            'search_metrics_connected' => $withObserved > 0,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function opportunities(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $score = is_array($row['opportunity'] ?? null) ? $row['opportunity'] : ['score' => 0, 'reasons' => [], 'types' => []];
            if ((int) ($score['score'] ?? 0) <= 0 && trim((string) ($row['primary_url'] ?? '')) !== '') {
                continue;
            }
            $items[] = [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'primary_url' => (string) ($row['primary_url'] ?? ''),
                'engine' => $this->primaryEngine($row),
                'type' => implode(', ', (array) ($score['types'] ?? [])),
                'score' => (int) ($score['score'] ?? 0),
                'evidence' => implode('; ', (array) ($score['reasons'] ?? [])),
                'suggested_action' => $this->suggestedAction((array) ($score['types'] ?? [])),
                'last_metric_period' => $this->lastMetricPeriod($row),
                'last_baidu_submission' => (string) ($row['latest_baidu_submission']['created_at'] ?? 'Not Available'),
                'index_evidence' => (string) ($row['index_evidence']['status'] ?? 'unknown'),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));
        return $items;
    }

    /** @param array<string,mixed> $row @return array{score:int,reasons:list<string>,types:list<string>} */
    private function scoreRow(array $row): array
    {
        $score = 0;
        $reasons = [];
        $types = [];
        $primaryUrl = trim((string) ($row['primary_url'] ?? ''));
        $status = (string) ($row['status'] ?? '');

        if ($primaryUrl === '') {
            $score += 20;
            $types[] = 'Missing Landing Page';
            $reasons[] = 'Primary landing page is not assigned';
        }

        if (!empty($row['conflict'])) {
            $score += 10;
            $types[] = 'Potential Cannibalization';
            $reasons[] = 'Keyword has multiple target bindings';
        }

        $readiness = is_array($row['page_readiness'] ?? null) ? $row['page_readiness'] : [];
        if (!empty($readiness['gaps'])) {
            $gapScore = min(15, count((array) $readiness['gaps']) * 5);
            $score += $gapScore;
            $types[] = 'On-page SEO Gap';
            $reasons[] = implode(', ', (array) $readiness['gaps']);
        }

        if (($row['sitemap_status'] ?? '') === 'in_sitemap' && ($row['latest_baidu_submission'] ?? null) === null && $primaryUrl !== '' && (($readiness['robots_index'] ?? '') === 'index')) {
            $score += 10;
            $types[] = 'Submission Gap';
            $reasons[] = 'Indexable sitemap URL has no Baidu submission log';
        }

        $trends = is_array($row['metric_trends'] ?? null) ? $row['metric_trends'] : [];
        if ($trends === []) {
            $score += 10;
            $types[] = 'No Observed Data';
            $reasons[] = 'Observed search metrics are Not Available';
        }

        foreach ($trends as $engine => $windows) {
            if (!is_array($windows)) {
                continue;
            }
            $window = is_array($windows['30d'] ?? null) ? $windows['30d'] : [];
            $impressions = $this->nullableFloat($window['impressions'] ?? null);
            $clicks = $this->nullableFloat($window['clicks'] ?? null);
            $ctr = $this->nullableFloat($window['ctr'] ?? null);
            $position = $this->nullableFloat($window['average_position'] ?? null);
            if ($impressions !== null && $impressions > 0) {
                $score += min(30, (int) floor(log($impressions + 1, 10) * 10));
                $reasons[] = ucfirst((string) $engine) . ' impressions ' . (string) (int) $impressions;
            }
            if ($position !== null && $position >= 8 && $position <= 20 && $impressions !== null && $impressions >= 20) {
                $score += 20;
                $types[] = 'Near Page-One';
                $reasons[] = 'Position ' . round($position, 2);
            }
            if ($impressions !== null && $impressions >= 50 && $ctr !== null && $ctr < 2.0) {
                $score += 15;
                $types[] = 'Low CTR';
                $reasons[] = 'CTR ' . round($ctr, 2) . '%';
            }
            if ($clicks !== null && $clicks === 0.0 && $impressions !== null && $impressions > 0) {
                $reasons[] = 'Clicks are observed as 0';
            }
            if (!empty($windows['stale'])) {
                $score += 10;
                $types[] = 'Stale Data';
                $reasons[] = 'No fresh ' . ucfirst((string) $engine) . ' metrics in 30 days';
            }
        }

        if ($status === 'paused') {
            $score = min($score, 20);
            $reasons[] = 'Keyword is paused';
        }

        $types = array_values(array_unique(array_filter($types)));
        return ['score' => min(100, $score), 'reasons' => array_values(array_unique($reasons)), 'types' => $types];
    }

    /** @param list<array<string,mixed>> $bindings */
    private function matchingBinding(string $primaryUrl, array $bindings): ?array
    {
        $primary = $this->canonicalKey($primaryUrl);
        foreach ($bindings as $binding) {
            if ($primary !== '' && ($primary === $this->canonicalKey((string) ($binding['url_path'] ?? '')) || $primary === $this->canonicalKey((string) ($binding['canonical'] ?? '')))) {
                return $binding;
            }
        }

        return count($bindings) === 1 ? $bindings[0] : null;
    }

    /** @param list<array<string,mixed>> $bindings @return array<string,mixed> */
    private function pageReadiness(string $primaryUrl, ?array $binding, array $bindings): array
    {
        $assigned = trim($primaryUrl) !== '';
        $exists = $assigned && ($binding !== null || isset($this->sitemapUrls[$this->canonicalKey($primaryUrl)]));
        $title = trim((string) ($binding['seo_title'] ?? $binding['title'] ?? ''));
        $description = trim((string) ($binding['seo_description'] ?? ''));
        $h1 = trim((string) ($binding['title'] ?? ''));
        $robots = (string) ($binding['index_status'] ?? ($exists ? 'index' : 'unknown'));
        $canonical = trim((string) ($binding['canonical'] ?? ''));
        $canonicalConsistent = $binding !== null && $assigned && $canonical !== ''
            ? $this->canonicalKey($canonical) === $this->canonicalKey($primaryUrl)
            : ($binding !== null && $assigned && $this->canonicalKey((string) ($binding['url_path'] ?? '')) === $this->canonicalKey($primaryUrl));
        $gaps = [];
        if (!$assigned) {
            $gaps[] = 'Primary URL missing';
        }
        if ($assigned && !$exists) {
            $gaps[] = 'Page not found in CMS bindings or sitemap';
        }
        if ($title === '') {
            $gaps[] = 'Title missing';
        }
        if ($description === '') {
            $gaps[] = 'Description missing';
        }
        if ($h1 === '') {
            $gaps[] = 'H1 missing';
        }
        if ($assigned && !$canonicalConsistent) {
            $gaps[] = 'Canonical mismatch';
        }
        if ($robots !== 'index') {
            $gaps[] = 'Robots noindex or unknown';
        }

        return [
            'assigned' => $assigned,
            'exists' => $exists,
            'http_status' => $exists ? '200 or internal route available' : ($assigned ? 'unknown' : 'not_applicable'),
            'title' => $title !== '' ? $title : 'Not Available',
            'description' => $description !== '' ? $description : 'Not Available',
            'h1' => $h1 !== '' ? $h1 : 'Not Available',
            'canonical' => $canonical !== '' ? $canonical : 'Not Available',
            'canonical_consistent' => $canonicalConsistent,
            'robots_index' => $robots,
            'robots_follow' => 'Not Available',
            'ready' => $assigned && $exists && $title !== '' && $description !== '' && $h1 !== '' && $canonicalConsistent && $robots === 'index',
            'gaps' => $gaps,
            'binding_count' => count($bindings),
        ];
    }

    private function sitemapStatus(string $primaryUrl): string
    {
        if (trim($primaryUrl) === '') {
            return 'not_applicable';
        }

        return isset($this->sitemapUrls[$this->canonicalKey($primaryUrl)]) ? 'in_sitemap' : 'not_in_sitemap';
    }

    /** @param list<array<string,mixed>> $metrics @param array<string,string>|null $submission @return array<string,string> */
    private function indexEvidence(array $metrics, ?array $submission): array
    {
        foreach ($metrics as $metric) {
            $impressions = $this->nullableFloat($metric['impressions'] ?? null);
            $clicks = $this->nullableFloat($metric['clicks'] ?? null);
            if (($impressions !== null && $impressions > 0) || ($clicks !== null && $clicks > 0)) {
                return ['status' => 'evidence_indexed', 'evidence' => 'Observed search metrics exist', 'last_checked' => (string) ($metric['period_end'] ?? '')];
            }
        }
        if ($submission !== null) {
            $status = (string) ($submission['status'] ?? '');
            if (in_array($status, ['failed', 'invalid_url', 'missing_token'], true)) {
                return ['status' => 'submission_failed', 'evidence' => 'Baidu plugin submission failed', 'last_checked' => (string) ($submission['created_at'] ?? '')];
            }
            return ['status' => 'submitted', 'evidence' => 'Accepted by submission channel; Submitted is not Indexed', 'last_checked' => (string) ($submission['created_at'] ?? '')];
        }

        return ['status' => $this->baiduBridge === null ? 'not_available' : 'unknown', 'evidence' => 'No reliable URL-level index evidence', 'last_checked' => 'Not Available'];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function metricsByKeyword(): array
    {
        if (!$this->tableExists('cms_seo_keyword_metrics')) {
            return [];
        }
        $grouped = [];
        $sql = 'SELECT keyword, url_path, search_engine, impressions, clicks, ctr, average_position, period_start, period_end, source, created_at FROM cms_seo_keyword_metrics ORDER BY keyword ASC, search_engine ASC, period_end DESC';
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(string) ($row['keyword'] ?? '')][] = $row;
        }
        return $grouped;
    }

    /** @return array<string,list<array<string,string>>> */
    private function submissionsByUrl(): array
    {
        $logs = $this->baiduBridge?->recentLogs(500) ?? [];
        $grouped = [];
        foreach ($logs as $log) {
            $key = $this->canonicalKey((string) ($log['url'] ?? ''));
            if ($key === '') {
                continue;
            }
            $grouped[$key][] = $log;
        }
        return $grouped;
    }

    /** @return array<string,bool> */
    private function sitemapUrls(): array
    {
        $urls = [
            $this->canonicalKey('/'),
            $this->canonicalKey('/articles'),
        ];
        foreach ($this->content->sitemapItems() as $item) {
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            if (($meta['robots_index'] ?? true) !== true) {
                continue;
            }
            $type = (string) ($item['content_type'] ?? 'article');
            $slug = ltrim((string) ($item['slug'] ?? ''), '/');
            $urls[] = $this->canonicalKey($type === 'page' ? '/' . $slug : '/articles/' . $slug);
        }
        foreach ($this->content->sitemapTerms() as $term) {
            $urls[] = $this->canonicalKey('/' . ((string) ($term['taxonomy'] ?? '') === 'tag' ? 'tag' : 'category') . '/' . ltrim((string) ($term['slug'] ?? ''), '/'));
        }

        return array_fill_keys(array_values(array_filter(array_unique($urls))), true);
    }

    /** @param list<array<string,mixed>> $metrics @return list<array<string,mixed>> */
    private function filterMetricsForUrl(array $metrics, string $url): array
    {
        $key = $this->canonicalKey($url);
        if ($key === '') {
            return [];
        }
        return array_values(array_filter($metrics, fn (array $metric): bool => $this->canonicalKey((string) ($metric['url_path'] ?? '')) === $key));
    }

    /** @return array<string,string>|null */
    private function latestSubmission(string $url): ?array
    {
        $items = $this->submissionsByUrl[$this->canonicalKey($url)] ?? [];
        return $items[0] ?? null;
    }

    /** @param list<array<string,mixed>> $metrics @return array<string,array<string,mixed>> */
    private function metricTrends(array $metrics, string $url): array
    {
        $key = $this->canonicalKey($url);
        $byEngine = [];
        foreach ($metrics as $metric) {
            if ($key !== '' && $this->canonicalKey((string) ($metric['url_path'] ?? '')) !== $key) {
                continue;
            }
            $engine = (string) ($metric['search_engine'] ?? 'manual');
            $byEngine[$engine][] = $metric;
        }

        $out = [];
        foreach ($byEngine as $engine => $items) {
            $latest = '';
            foreach ($items as $item) {
                $latest = max($latest, (string) ($item['period_end'] ?? ''));
            }
            $out[$engine] = [
                '7d' => $this->windowAggregate($items, 7, $latest),
                '30d' => $this->windowAggregate($items, 30, $latest),
                '90d' => $this->windowAggregate($items, 90, $latest),
                'latest_period_end' => $latest,
                'freshness' => $latest !== '' ? $latest : 'Not Available',
                'stale' => $latest === '' || strtotime($latest . ' 00:00:00 UTC') < strtotime('-30 days'),
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function windowAggregate(array $items, int $days, string $latest): array
    {
        if ($latest === '') {
            return ['impressions' => 'Not Available', 'clicks' => 'Not Available', 'ctr' => 'Not Available', 'average_position' => 'Not Available'];
        }
        $cutoff = strtotime($latest . ' 00:00:00 UTC') - (($days - 1) * 86400);
        $impressions = 0;
        $clicks = 0;
        $hasImpressions = false;
        $hasClicks = false;
        $positionWeighted = 0.0;
        $positionWeight = 0.0;
        $positionSum = 0.0;
        $positionCount = 0;
        foreach ($items as $item) {
            $end = strtotime((string) ($item['period_end'] ?? '') . ' 00:00:00 UTC');
            if ($end === false || $end < $cutoff) {
                continue;
            }
            $imp = $this->nullableFloat($item['impressions'] ?? null);
            $clk = $this->nullableFloat($item['clicks'] ?? null);
            $pos = $this->nullableFloat($item['average_position'] ?? null);
            if ($imp !== null) {
                $impressions += (int) $imp;
                $hasImpressions = true;
            }
            if ($clk !== null) {
                $clicks += (int) $clk;
                $hasClicks = true;
            }
            if ($pos !== null) {
                if ($imp !== null && $imp > 0) {
                    $positionWeighted += $pos * $imp;
                    $positionWeight += $imp;
                } else {
                    $positionSum += $pos;
                    $positionCount++;
                }
            }
        }
        $ctr = $hasImpressions && $impressions > 0 && $hasClicks ? round(($clicks / $impressions) * 100, 2) : null;
        $position = $positionWeight > 0 ? round($positionWeighted / $positionWeight, 2) : ($positionCount > 0 ? round($positionSum / $positionCount, 2) : null);

        return [
            'impressions' => $hasImpressions ? $impressions : 'Not Available',
            'clicks' => $hasClicks ? $clicks : 'Not Available',
            'ctr' => $ctr ?? 'Not Available',
            'average_position' => $position ?? 'Not Available',
        ];
    }

    /** @param list<array<string,mixed>> $metrics */
    private function bestPosition(array $metrics): string
    {
        $best = null;
        foreach ($metrics as $metric) {
            $pos = $this->nullableFloat($metric['average_position'] ?? null);
            if ($pos !== null && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }
        return $best === null ? 'Not Available' : (string) round($best, 2);
    }

    /** @param array<string,mixed> $metric @return array<string,string> */
    private function displayMetric(array $metric): array
    {
        return array_map(static fn (mixed $value): string => $value === null || $value === '' ? 'Not Available' : (string) $value, $metric);
    }

    private function canonicalKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            $url = $path === '' ? '/' : $path;
            if ($query !== '') {
                $url .= '?' . $query;
            }
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }
        $url = preg_replace('#/+#', '/', $url) ?: '/';
        return rtrim($url, '/') === '' ? '/' : rtrim($url, '/');
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'Not Available') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function primaryEngine(array $row): string
    {
        foreach (($row['metric_trends'] ?? []) as $engine => $unused) {
            return (string) $engine;
        }
        return 'Not Available';
    }

    /** @param list<string> $types */
    private function suggestedAction(array $types): string
    {
        if (in_array('Missing Landing Page', $types, true)) {
            return 'Assign a primary landing page';
        }
        if (in_array('On-page SEO Gap', $types, true)) {
            return 'Review title, description, H1, canonical, and robots';
        }
        if (in_array('Submission Gap', $types, true)) {
            return 'Submit the primary URL through official.seo.baidu-submit';
        }
        if (in_array('Near Page-One', $types, true) || in_array('Low CTR', $types, true)) {
            return 'Review snippet and on-page content manually';
        }
        if (in_array('Potential Cannibalization', $types, true)) {
            return 'Review competing pages and choose a primary landing page';
        }
        return 'Review manually';
    }

    private function lastMetricPeriod(array $row): string
    {
        $latest = '';
        foreach (($row['metric_trends'] ?? []) as $windows) {
            if (is_array($windows)) {
                $latest = max($latest, (string) ($windows['latest_period_end'] ?? ''));
            }
        }
        return $latest !== '' ? $latest : 'Not Available';
    }

    private function tableExists(string $table): bool
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

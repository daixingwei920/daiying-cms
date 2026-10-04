<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchEngine;

use PDO;

final class SearchEngineDataRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @param list<array<string,mixed>> $rows @return array{inserted:int,updated:int,skipped:int} */
    public function importMetrics(array $rows, string $source = 'manual_import'): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $keyword = $this->cleanKeyword((string) ($row['keyword'] ?? ''));
            $engine = $this->engine((string) ($row['search_engine'] ?? ''));
            $periodStart = $this->cleanDate((string) ($row['period_start'] ?? ''));
            $periodEnd = $this->cleanDate((string) ($row['period_end'] ?? ''));
            if ($keyword === '' || $engine === '' || $periodStart === '' || $periodEnd === '') {
                $skipped++;
                continue;
            }
            $url = $this->cleanUrlPath((string) ($row['url_path'] ?? $row['url'] ?? ''));
            $existing = $this->metricId($keyword, $url, $engine, $periodStart, $periodEnd, $source);
            $payload = [
                ':keyword' => $keyword,
                ':url_path' => $url,
                ':search_engine' => $engine,
                ':impressions' => $this->nullableInt($row['impressions'] ?? null),
                ':clicks' => $this->nullableInt($row['clicks'] ?? null),
                ':ctr' => $this->nullableDecimal($row['ctr'] ?? null),
                ':average_position' => $this->nullableDecimal($row['average_position'] ?? null),
                ':period_start' => $periodStart,
                ':period_end' => $periodEnd,
                ':source' => $source,
                ':created_at' => gmdate('c'),
            ];
            if ($existing > 0) {
                $stmt = $this->pdo->prepare('UPDATE cms_seo_keyword_metrics SET impressions = :impressions, clicks = :clicks, ctr = :ctr, average_position = :average_position, created_at = :created_at WHERE id = :id');
                $stmt->execute([
                    ':id' => $existing,
                    ':impressions' => $payload[':impressions'],
                    ':clicks' => $payload[':clicks'],
                    ':ctr' => $payload[':ctr'],
                    ':average_position' => $payload[':average_position'],
                    ':created_at' => $payload[':created_at'],
                ]);
                $updated++;
                continue;
            }

            $stmt = $this->pdo->prepare('INSERT INTO cms_seo_keyword_metrics (keyword, url_path, search_engine, impressions, clicks, ctr, average_position, period_start, period_end, source, created_at) VALUES (:keyword, :url_path, :search_engine, :impressions, :clicks, :ctr, :average_position, :period_start, :period_end, :source, :created_at)');
            $stmt->execute($payload);
            $inserted++;
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** @return list<array<string,string>> */
    public function metricsForKeyword(string $keyword): array
    {
        $stmt = $this->pdo->prepare('SELECT keyword, url_path, search_engine, impressions, clicks, ctr, average_position, period_start, period_end, source, created_at FROM cms_seo_keyword_metrics WHERE keyword = :keyword ORDER BY period_end DESC, search_engine ASC, url_path ASC');
        $stmt->execute([':keyword' => $this->cleanKeyword($keyword)]);

        return array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function metricId(string $keyword, string $url, string $engine, string $periodStart, string $periodEnd, string $source): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_seo_keyword_metrics WHERE keyword = :keyword AND url_path = :url_path AND search_engine = :search_engine AND period_start = :period_start AND period_end = :period_end AND source = :source LIMIT 1');
        $stmt->execute([':keyword' => $keyword, ':url_path' => $url, ':search_engine' => $engine, ':period_start' => $periodStart, ':period_end' => $periodEnd, ':source' => $source]);
        return (int) $stmt->fetchColumn();
    }

    private function engine(string $engine): string
    {
        $engine = strtolower(trim($engine));
        if (!in_array($engine, ['baidu', 'google', 'manual'], true)) {
            return '';
        }
        return $engine;
    }

    private function cleanKeyword(string $keyword): string
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags($keyword)) ?? $keyword);
        return function_exists('mb_substr') ? mb_substr($keyword, 0, 120, 'UTF-8') : substr($keyword, 0, 120);
    }

    private function cleanUrlPath(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 512 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return $url;
    }

    private function cleanDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return preg_match('/^\d+$/', (string) $value) === 1 ? (int) $value : null;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = rtrim((string) $value, '%');
        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1 ? $value : null;
    }
}

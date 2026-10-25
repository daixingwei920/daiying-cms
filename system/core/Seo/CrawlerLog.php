<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;

/** Bounded local aggregate ledger. UA identification is never source authentication. */
final class CrawlerLog
{
    private const MAX_BYTES = 524288;
    private const MAX_ROWS = 2000;
    public function __construct(private readonly string $root, private readonly Settings $settings) {}

    public function record(Request $request, Response $response): void
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) { return; }
        $route = $this->route($request->path);
        if ($route === null) { return; }
        $agent = CrawlerRegistry::identify((string) ($request->server['HTTP_USER_AGENT'] ?? ''));
        if ($agent === null) { return; }
        $add = (bool) $this->settings->get('seo.crawler_log_enabled', false);
        $outcome = 'io_error';
        $this->update($add ? ['day' => gmdate('Y-m-d'), 'agent' => $agent, 'route' => $route,
            'status' => $response->status(), 'verification' => 'source_unverified', 'hits' => 1] : null, $outcome);
        if ($add) { (new CrawlerObservation($this->root, $this->settings))->record($outcome); }
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array { return $this->update(null); }
    public function prune(): void { $this->update(null); (new CrawlerObservation($this->root, $this->settings))->prune(); }

    private function route(string $path): ?string
    {
        $path = Request::normalizePath($path);
        if (in_array($path, ['/', '/articles', '/robots.txt', '/sitemap.xml', '/llms.txt'], true)) { return $path; }
        if (preg_match('#^/(articles|category|tag)/[^/]+$#', $path, $m)) { return '/' . $m[1] . '/{slug}'; }
        // Only named public routes; arbitrary paths could contain personal data.
        return null;
    }

    /** @param array<string,mixed>|null $add @return list<array<string,mixed>> */
    private function update(?array $add, ?string &$outcome = null): array
    {
        $outcome = 'io_error';
        $path = $this->root . '/storage/logs/seo-crawlers.json';
        if (is_link($this->root . '/storage') || is_link(dirname($path)) || is_link($path)) { $outcome = 'unsafe_path'; return []; }
        if (file_exists($path) && !is_file($path)) { return []; }
        if (!is_file($path) && $add === null) { return []; }
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) { return []; }
        $file = @fopen($path, 'c+b');
        if ($file === false) { return []; }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) { $outcome = 'lock_busy'; return []; }
            @chmod($path, 0600);
            $size = fstat($file)['size'] ?? 0;
            if ($size > self::MAX_BYTES) { $outcome = 'byte_limit'; return []; }
            $raw = stream_get_contents($file, self::MAX_BYTES + 1);
            $data = $raw === '' ? [] : json_decode((string) $raw, true);
            if (!is_array($data) || !array_is_list($data) || count($data) > self::MAX_ROWS) { $outcome = 'invalid_ledger'; return []; }
            $days = max(1, min(30, (int) $this->settings->get('seo.crawler_retention_days', 7)));
            $floor = gmdate('Y-m-d', time() - ($days - 1) * 86400);
            $today = gmdate('Y-m-d');
            $rows = array_values(array_filter($data, static fn ($r): bool => is_array($r)
                && is_string($r['day'] ?? null) && $r['day'] >= $floor && $r['day'] <= $today));
            if ($add !== null) {
                $outcome = 'stored';
                $found = false;
                foreach ($rows as &$row) {
                    if (($row['day'] ?? '') === $add['day'] && ($row['agent'] ?? '') === $add['agent']
                        && ($row['route'] ?? '') === $add['route'] && ($row['status'] ?? 0) === $add['status']) {
                        if ((int) ($row['hits'] ?? 0) >= 10000000) { $outcome = 'hit_cap'; }
                        $row['hits'] = min(10000000, (int) ($row['hits'] ?? 0) + 1); $found = true; break;
                    }
                }
                unset($row);
                if (!$found && count($rows) < self::MAX_ROWS) { $rows[] = $add; }
                elseif (!$found) { $outcome = 'row_limit'; }
            }
            $encoded = json_encode($rows, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) { $outcome = 'byte_limit'; return []; }
            if ($encoded !== $raw) {
                rewind($file);
                if (fwrite($file, $encoded) !== strlen($encoded) || !ftruncate($file, strlen($encoded)) || !fflush($file)) { $outcome = 'io_error'; return []; }
            }
            return $rows;
        } catch (\Throwable) { $outcome = 'io_error'; return []; }
        finally { fclose($file); }
    }
}

<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Official\NovelCollector\BqgSpaAdapter;
use Official\NovelCollector\CatalogUrlDiscoverer;
use Official\NovelCollector\ContentQualityAnalyzer;
use Official\NovelCollector\HtmlSanitizer;
use Official\NovelCollector\NovelAutoDetector;
use Official\NovelCollector\NovelRepository;
use Official\NovelCollector\QueueManager;
use Official\NovelCollector\SafeHttpClient;
use Official\NovelCollector\TxtImportExport;

require_once __DIR__ . '/src/NovelSystem.php';

return static function (PluginContext $context): void {
    $http = new SafeHttpClient();
    $detector = new NovelAutoDetector();
    $sanitizer = new HtmlSanitizer();
    $qualityAnalyzer = new ContentQualityAnalyzer();
    $queue = new QueueManager();
    $txt = new TxtImportExport();
    $dataStore = method_exists($context, 'data') ? $context->data() : null;
    $formalRepo = null;
    if (method_exists($context, 'pdo')) {
        try {
            $pdo = $context->pdo();
            if ($pdo instanceof \PDO) {
                $formalRepo = new NovelRepository($pdo);
            }
        } catch (\Throwable) {
            $formalRepo = null;
        }
    }
    $fileStoreDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'daiying_novel_collector_' . substr(hash('sha256', __DIR__), 0, 16);
    $param = static function ($request, string $key, string $default = ''): string {
        foreach (['input', 'query', 'get'] as $method) {
            if (is_object($request) && method_exists($request, $method)) {
                $value = $request->{$method}($key);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            $query = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $parsed);
                if (isset($parsed[$key]) && $parsed[$key] !== '') {
                    return (string) $parsed[$key];
                }
            }
        }
        return (string) ($_GET[$key] ?? $default);
    };
    $html = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $novelUrl = static fn (string $jobId): string => '/novels/book?job_id=' . rawurlencode($jobId);
    $novelChapterUrl = static fn (string $jobId, int $chapter): string => '/novels/chapter?job_id=' . rawurlencode($jobId) . '&chapter=' . rawurlencode((string) max(1, $chapter));
    $novelSearchUrl = static fn (string $query = ''): string => '/novels/search' . ($query !== '' ? '?q=' . rawurlencode($query) : '');
    $loadCatalog = static function (string $url) use ($http, $detector): array {
        $bqgBookId = BqgSpaAdapter::bookIdFromUrl($url);
        try {
            $res = $http->get($url);
        } catch (\Throwable $e) {
            if ($bqgBookId === null || !BqgSpaAdapter::isPotentialSpaHost($url)) {
                throw $e;
            }
            $res = ['url' => $url, 'status' => 0, 'headers' => [], 'body' => ''];
        }
        if ($bqgBookId !== null && (BqgSpaAdapter::looksLikeSite($url, (string) ($res['body'] ?? '')) || BqgSpaAdapter::isPotentialSpaHost($url))) {
            $loadJson = static function (string $endpoint, array $plainQuery, array $encryptedPayload) use ($http, $url): array {
                foreach ([BqgSpaAdapter::centralApiUrl($endpoint, $plainQuery), BqgSpaAdapter::plainApiUrl($url, $endpoint, $plainQuery), BqgSpaAdapter::encryptedApiUrl($endpoint, $encryptedPayload)] as $apiUrl) {
                    try {
                        $apiRes = $http->get($apiUrl, ['Referer' => $url]);
                    } catch (\Throwable) {
                        continue;
                    }
                    $decoded = json_decode((string) ($apiRes['body'] ?? ''), true);
                    if (is_array($decoded) && $decoded !== []) {
                        return $decoded;
                    }
                }
                return [];
            };
            $book = $loadJson('book', ['id' => $bqgBookId], ['id' => $bqgBookId]);
            $bookList = $loadJson('booklist', ['id' => $bqgBookId], ['id' => $bqgBookId]);
            if ($book !== [] && $bookList !== []) {
                $detected = BqgSpaAdapter::catalogFromApi($url, $book, $bookList);
                $detected['chapter_count'] = count($detected['chapters'] ?? []);
                return $detected;
            }
        }
        $detected = $detector->detect($url, $res['body']);
        if (is_string($detected['full_list_url'] ?? null) && $detected['full_list_url'] !== '') {
            $full = $http->get((string) $detected['full_list_url'], ['Referer' => $url]);
            $detected['chapters'] = $detector->expandQuanbenJsonp($url, $full['body'], $detected['chapters']);
        }
        $detected['chapter_count'] = count($detected['chapters'] ?? []);
        return $detected;
    };
    $discoverCatalogUrls = static function (string $url, int $max = 20) use ($http): array {
        return CatalogUrlDiscoverer::discover($url, $max, static fn (string $target): array => $http->get($target));
    };
    $pageShell = static function (string $title, string $body): string {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#172033}.wrap{max-width:1100px;margin:0 auto;padding:28px 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:20px;margin:0 0 18px}.topline{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}label{display:block;font-weight:650;margin:12px 0 6px}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px}button,.button{display:inline-block;background:#1f6feb;color:#fff;border:0;border-radius:6px;padding:10px 14px;text-decoration:none;margin:12px 8px 0 0}.button.secondary{background:#475467}.button.ghost{background:#eef2f7;color:#27364a}.muted{color:#667085}.tag{display:inline-block;background:#eef4ff;color:#1f4b99;border-radius:999px;padding:4px 9px;margin:3px}.ok{color:#027a48;font-weight:700}.fail{color:#b42318;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border-bottom:1px solid #eaecf0;padding:10px;text-align:left;vertical-align:top}.cover-thumb{display:grid;width:54px;height:76px;place-items:center;object-fit:cover;border-radius:6px;background:linear-gradient(160deg,#263241,#bf3450);border:1px solid #d8dee8;color:#fff;font-weight:800;text-align:center}.book-head{display:flex;gap:18px;align-items:flex-start}.book-head img,.book-head .cover-thumb{width:128px;height:178px;object-fit:cover;border-radius:8px;background:linear-gradient(160deg,#263241,#bf3450);border:1px solid #d8dee8}.chapter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-top:12px}.chapter-card{display:block;border:1px solid #e4e7ec;border-radius:6px;background:#fff;color:#172033;text-decoration:none;padding:10px;min-height:58px}.chapter-card small{display:block;color:#667085;margin-top:4px}.reader{font-size:18px;line-height:1.9;max-width:820px;margin-left:auto;margin-right:auto}.reader p{margin:0 0 1em}.reader-controls{position:sticky;top:0;z-index:5;background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:10px;margin-bottom:12px}.reader[data-theme=eye]{background:#f5f4df;color:#1f2a20}.reader[data-theme=night]{background:#111827;color:#e5e7eb}.progressbar{height:6px;background:#e4e7ec;border-radius:999px;overflow:hidden}.progressbar span{display:block;height:100%;width:0;background:#1f6feb}pre{white-space:pre-wrap;background:#111827;color:#f9fafb;border-radius:8px;padding:14px;overflow:auto}@media(max-width:640px){.wrap{padding:18px 12px 84px}.reader{font-size:17px}.book-head{display:block}.book-head img,.book-head .cover-thumb{width:96px;height:136px;margin-bottom:12px}.chapter-grid{grid-template-columns:1fr}.mobile-reader-bar{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #d8dee8;padding:8px 10px;display:flex;justify-content:space-around;z-index:8}.mobile-reader-bar .button{margin:0;padding:8px 10px}}</style><main class="wrap">' . $body . '</main>';
    };
    $fileKey = static fn (string $value): string => preg_replace('/[^a-zA-Z0-9_.-]/', '_', $value) ?: 'item';
    $filePut = static function (string $bucket, string $key, array $value) use ($fileStoreDir, $fileKey): bool {
        $dir = $fileStoreDir . DIRECTORY_SEPARATOR . $fileKey($bucket);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        return @file_put_contents($dir . DIRECTORY_SEPARATOR . $fileKey($key) . '.json', $json, LOCK_EX) !== false;
    };
    $fileAll = static function (string $bucket) use ($fileStoreDir, $fileKey): array {
        $dir = $fileStoreDir . DIRECTORY_SEPARATOR . $fileKey($bucket);
        if (!is_dir($dir)) {
            return [];
        }
        $rows = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $rows[pathinfo($file, PATHINFO_FILENAME)] = $decoded;
            }
        }
        return $rows;
    };
    $storeAll = static function (string $bucket) use ($dataStore, $fileAll): array {
        $rows = [];
        if ($dataStore !== null && method_exists($dataStore, 'all')) {
            try {
                $dataRows = $dataStore->all($bucket);
                foreach (is_array($dataRows) ? $dataRows : [] as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = (string) ($row['data_key'] ?? $row['key'] ?? $index);
                    if ($key === '' || isset($rows[$key])) {
                        continue;
                    }
                    $payload = $row['payload_json'] ?? $row['value'] ?? $row;
                    if (is_string($payload)) {
                        $decoded = json_decode($payload, true);
                        $payload = is_array($decoded) ? $decoded : $payload;
                    }
                    if (is_array($payload)) {
                        $rows[$key] = $payload;
                    }
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }
        return array_replace($fileAll($bucket), $rows);
    };
    $storeGet = static function (string $bucket, string $key) use ($storeAll): ?array {
        $rows = $storeAll($bucket);
        if (isset($rows[$key]) && is_array($rows[$key])) {
            if (isset($rows[$key]['value']) && is_array($rows[$key]['value'])) {
                return $rows[$key]['value'];
            }
            return $rows[$key];
        }
        foreach ($rows as $rowKey => $row) {
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                $row = is_array($decoded) ? $decoded : $row;
            }
            if ((string) $rowKey === $key && is_array($row)) {
                if (isset($row['value']) && is_string($row['value'])) {
                    $decoded = json_decode($row['value'], true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
                if (isset($row['value']) && is_array($row['value'])) {
                    return $row['value'];
                }
                return $row;
            }
            if (is_array($row) && isset($row['key'], $row['value']) && (string) $row['key'] === $key && is_array($row['value'])) {
                return $row['value'];
            }
            if (is_array($row) && isset($row['key'], $row['value']) && (string) $row['key'] === $key && is_string($row['value'])) {
                $decoded = json_decode($row['value'], true);
                return is_array($decoded) ? $decoded : null;
            }
            if (is_array($row) && isset($row['id'], $row['data']) && (string) $row['id'] === $key && is_string($row['data'])) {
                $decoded = json_decode($row['data'], true);
                return is_array($decoded) ? $decoded : null;
            }
            if (is_array($row) && (($row['job_id'] ?? $row['id'] ?? $row['chunk_id'] ?? '') === $key)) {
                return $row;
            }
        }
        return null;
    };
    $normalizeStoredRow = static function ($row): array {
        if (is_object($row)) {
            $row = json_decode(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', true);
        }
        if (is_string($row)) {
            $decoded = json_decode($row, true);
            $row = is_array($decoded) ? $decoded : [];
        }
        if (is_array($row)) {
            foreach ($row as $key => $value) {
                if (is_object($value)) {
                    $row[$key] = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', true);
                }
            }
        }
        if (is_array($row) && isset($row['value'])) {
            $value = $row['value'];
            if (is_object($value)) {
                $value = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', true);
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            }
            return is_array($value) ? $value : [];
        }
        if (is_array($row) && isset($row['data']) && is_string($row['data'])) {
            $decoded = json_decode($row['data'], true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($row) ? $row : [];
    };
    $latestJob = static function () use ($storeAll, $normalizeStoredRow): ?array {
        $jobs = [];
        foreach ($storeAll('novel_collector_jobs') as $row) {
            $job = $normalizeStoredRow($row);
            if (($job['job_id'] ?? '') !== '') {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')));
        return $jobs[0] ?? null;
    };
    $storePut = static function (string $bucket, string $key, array $value) use ($dataStore, $filePut): bool {
        $stored = false;
        if ($dataStore === null || !method_exists($dataStore, 'put')) {
            return $filePut($bucket, $key, $value);
        }
        try {
            $dataStore->put($bucket, $key, $value);
            $stored = true;
        } catch (\Throwable) {
            $stored = false;
        }
        return $filePut($bucket, $key, $value) || $stored;
    };
    $buildJob = static function (string $url) use ($loadCatalog): array {
        $detected = $loadCatalog($url);
        $chapters = $detected['chapters'] ?? [];
        $seenUrls = [];
        $seenIds = [];
        foreach ($chapters as $chapter) {
            $chapterUrl = (string) ($chapter['url'] ?? '');
            $chapterId = (string) ($chapter['source_chapter_id'] ?? '');
            error_log('[NovelCollector] Book: ' . (string) ($detected['title'] ?? $url) . ' Chapter: ' . (string) ($chapter['title'] ?? '') . ' URL: ' . $chapterUrl . ' chapter_id=' . $chapterId . ' source_book_id=' . (string) ($chapter['source_book_id'] ?? ''));
            if ($chapterUrl === '') {
                throw new \RuntimeException('章节地址异常：章节 URL 为空。');
            }
            $urlKey = strtolower($chapterUrl);
            if (isset($seenUrls[$urlKey])) {
                throw new \RuntimeException('章节地址异常：多个章节解析到了相同地址。');
            }
            $seenUrls[$urlKey] = true;
            if ($chapterId !== '') {
                if (isset($seenIds[$chapterId])) {
                    throw new \RuntimeException('章节地址异常：多个章节解析到了相同 chapter_id。');
                }
                $seenIds[$chapterId] = true;
            }
        }
        $jobId = 'novel_' . substr(hash('sha256', $url . '|' . (string) ($detected['chapter_count'] ?? 0)), 0, 16);
        return [[
            'job_id' => $jobId,
            'status' => 'pending',
            'mode' => 'full_collect',
            'catalog_url' => $url,
            'title' => (string) ($detected['title'] ?? ''),
            'author' => (string) ($detected['author'] ?? ''),
            'description' => (string) ($detected['description'] ?? ''),
            'cover_url' => (string) ($detected['cover_url'] ?? ''),
            'cover' => (string) ($detected['cover_url'] ?? ''),
            'chapter_count' => count($chapters),
            'resume_cursor' => 0,
            'collected_count' => 0,
            'failed_count' => 0,
            'incremental_revision_window' => 10,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ], $chapters];
    };
    $persistJob = static function (array $job, array $chapters) use ($storePut): bool {
        $jobId = (string) ($job['job_id'] ?? '');
        if ($jobId === '') {
            return false;
        }
        $stored = $storePut('novel_collector_jobs', $jobId, $job);
        foreach (array_chunk($chapters, 100) as $chunkIndex => $chunk) {
            $stored = $storePut('novel_collector_job_chunks', $jobId . '_' . $chunkIndex, [
                'chunk_id' => $jobId . '_' . $chunkIndex,
                'job_id' => $jobId,
                'offset' => $chunkIndex * 100,
                'items' => array_map(static fn (array $chapter): array => [
                    'title' => (string) ($chapter['title'] ?? ''),
                    'url' => (string) ($chapter['url'] ?? ''),
                    'source_chapter_id' => (string) ($chapter['source_chapter_id'] ?? $chapter['url'] ?? ''),
                    'source_book_id' => (string) ($chapter['source_book_id'] ?? ''),
                    'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                    'status' => 'pending',
                ], $chunk),
            ]) && $stored;
        }
        return $stored;
    };
    $notFoundPage = static function (string $title = '队列不存在', string $catalogUrl = '') use ($pageShell, $html): string {
        return $pageShell($title, '<h1>队列不存在</h1>
<section class="panel">
  <p class="muted">当前链接里的 job_id 没有在本地插件数据中找到。请用目录 URL 重新建立队列；新版按钮会同时携带 URL，即使队列索引丢失也能恢复执行。</p>
  <form method="get" action="/admin/novel-collector/jobs/create">
    <label>小说目录页 URL</label>
    <input name="url" type="url" value="' . $html($catalogUrl) . '" placeholder="https://example.com/book/list.html" required>
    <button type="submit">重建采集队列</button>
  </form>
  <a class="button secondary" href="/admin/novel-collector/jobs">返回队列</a>
</section>');
    };
    $legacyNotFoundPage = static function (string $title = '队列不存在') use ($pageShell): string {
        return $pageShell($title, <<<'HTML'
<h1>队列不存在</h1>
<section class="panel">
  <p class="muted">当前链接里的 job_id 没有在本地插件数据中找到。请用目录 URL 重新建立队列；新版按钮会同时携带 URL，即使队列索引丢失也能恢复执行。</p>
  <form method="get" action="/admin/novel-collector/jobs/create">
    <label>小说目录页 URL</label>
    <input name="url" type="url" placeholder="https://example.com/book/list.html" required>
    <button type="submit">重建采集队列</button>
  </form>
  <a class="button secondary" href="/admin/novel-collector/jobs">返回队列</a>
</section>
HTML);
    };
    $loadJobItems = static function (string $jobId) use ($storeAll, $normalizeStoredRow): array {
        $items = [];
        foreach ($storeAll('novel_collector_job_chunks') as $key => $chunk) {
            if (is_array($chunk) && isset($chunk['key'])) {
                $key = (string) $chunk['key'];
            }
            $chunk = $normalizeStoredRow($chunk);
            if (!is_array($chunk) || !str_starts_with((string) $key, $jobId . '_')) {
                continue;
            }
            foreach (($chunk['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        return $items;
    };
    $loadCollectedChapters = static function (string $jobId) use ($storeAll, $normalizeStoredRow): array {
        $chapters = [];
        foreach ($storeAll('novel_chapters_local') as $row) {
            $row = $normalizeStoredRow($row);
            if (is_array($row) && (string) ($row['job_id'] ?? '') === $jobId) {
                $chapters[] = $row;
            }
        }
        usort($chapters, static fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        return $chapters;
    };
    $loadCollectedChapterIndex = static function (string $jobId) use ($storeAll, $normalizeStoredRow): array {
        $chapters = [];
        foreach ($storeAll('novel_chapter_index_local') as $row) {
            $row = $normalizeStoredRow($row);
            if (is_array($row) && (string) ($row['job_id'] ?? '') === $jobId) {
                $chapters[] = $row;
            }
        }
        usort($chapters, static fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        return $chapters;
    };
    $loadNovelSummaries = static function () use ($storeAll, $normalizeStoredRow, $formalRepo): array {
        $novels = [];
        if ($formalRepo instanceof NovelRepository) {
            foreach ($formalRepo->publicNovels(200) as $formalNovel) {
                $jobId = (string) ($formalNovel['job_id'] ?? '');
                if ($jobId !== '') {
                    $novels[$jobId] = $formalNovel;
                }
            }
        }
        $jobTitles = [];
        foreach ($storeAll('novel_collector_jobs') as $rowKey => $row) {
            $job = $normalizeStoredRow($row);
            $jobId = (string) ($job['job_id'] ?? (is_string($rowKey) ? $rowKey : ''));
            if ($jobId === '' || (($job['title'] ?? '') === '' && ($job['catalog_url'] ?? '') === '')) {
                continue;
            }
            $jobTitles[$jobId] = [
                'title' => (string) ($job['title'] ?? ''),
                'author' => (string) ($job['author'] ?? ''),
                'catalog_url' => (string) ($job['catalog_url'] ?? ''),
                'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
                'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
            ];
            $novels[$jobId] = [
                'job_id' => $jobId,
                'title' => (string) ($job['title'] ?? ''),
                'author' => (string) ($job['author'] ?? ''),
                'catalog_url' => (string) ($job['catalog_url'] ?? ''),
                'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
                'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
                'chapter_count' => max((int) ($job['collected_count'] ?? 0), (int) ($job['chapter_count'] ?? 0)),
            ];
        }
        foreach ($storeAll('novels_local') as $rowKey => $row) {
            $novel = $normalizeStoredRow($row);
            $jobId = (string) ($novel['job_id'] ?? (is_string($rowKey) ? $rowKey : ''));
            if ($jobId === '' || (($novel['title'] ?? '') === '' && (int) ($novel['chapter_count'] ?? 0) <= 0)) {
                continue;
            }
            $novels[$jobId] = array_merge($novels[$jobId] ?? ['job_id' => $jobId], [
                'job_id' => $jobId,
                'title' => (string) ($novel['title'] ?? ($novels[$jobId]['title'] ?? '')),
                'author' => (string) ($novel['author'] ?? ($novels[$jobId]['author'] ?? '')),
                'catalog_url' => (string) ($novel['catalog_url'] ?? ($novels[$jobId]['catalog_url'] ?? '')),
                'cover' => (string) ($novel['cover'] ?? $novel['cover_url'] ?? ($novels[$jobId]['cover'] ?? '')),
                'cover_url' => (string) ($novel['cover_url'] ?? $novel['cover'] ?? ($novels[$jobId]['cover_url'] ?? '')),
                'chapter_count' => max((int) ($novel['chapter_count'] ?? 0), (int) ($novels[$jobId]['chapter_count'] ?? 0)),
            ]);
        }
        uasort($novels, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return $novels;
    };
    $sendTxtDownload = static function (string $jobId) use ($txt, $storeGet, $storePut, $loadCollectedChapters, $fileStoreDir, $fileKey, $formalRepo) {
        if (str_starts_with($jobId, 'formal_') && $formalRepo instanceof NovelRepository) {
            $formalId = (int) substr($jobId, 7);
            $formalNovel = $formalRepo->publicNovel($formalId);
            if ($formalNovel !== null) {
                $chapters = $formalRepo->publicChapters($formalId);
                $body = $txt->exportTxt($formalNovel, $chapters);
                $safeTitle = trim((string) ($formalNovel['title'] ?? 'novel'));
                $safeTitle = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $safeTitle) ?: 'novel';
                $filename = trim(mb_substr($safeTitle, 0, 80), '_-') . '.txt';
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
                    header('X-Daiying-Export-Source: formal');
                }
                if (class_exists('\\Cms\\Core\\Http\\Response') && method_exists('\\Cms\\Core\\Http\\Response', 'text')) {
                    return \Cms\Core\Http\Response::text($body);
                }
                return \Cms\Core\Http\Response::html('<pre>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>');
            }
        }
        $novel = $storeGet('novels_local', $jobId) ?? $storeGet('novel_collector_jobs', $jobId) ?? ['title' => 'novel', 'author' => ''];
        $chapters = $loadCollectedChapters($jobId);
        $novel['id'] = $novel['id'] ?? $jobId;
        $cacheKey = $txt->cacheKey($novel, $chapters);
        $safeTitle = trim((string) ($novel['title'] ?? 'novel'));
        $safeTitle = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $safeTitle) ?: 'novel';
        $safeTitle = trim($safeTitle, '_-') ?: 'novel';
        $filename = mb_substr($safeTitle, 0, 80) . '.txt';
        $cacheDir = $fileStoreDir . DIRECTORY_SEPARATOR . 'txt_exports';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $fileKey($jobId . '_' . $cacheKey) . '.txt';
        $cacheHit = is_file($cachePath);
        $body = $cacheHit ? (string) @file_get_contents($cachePath) : '';
        if ($body === '') {
            $body = $txt->exportTxt($novel, $chapters);
            @file_put_contents($cachePath, $body, LOCK_EX);
            $cacheHit = false;
        }
        $storePut('novel_txt_export_cache', $jobId, [
            'job_id' => $jobId,
            'cache_key' => $cacheKey,
            'filename' => $filename,
            'path' => $cachePath,
            'chapter_count' => count($chapters),
            'bytes' => strlen($body),
            'cache_hit' => $cacheHit,
            'generated_at' => gmdate('c'),
        ]);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('X-Daiying-Export-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
        }
        if (class_exists('\\Cms\\Core\\Http\\Response') && method_exists('\\Cms\\Core\\Http\\Response', 'text')) {
            return \Cms\Core\Http\Response::text($body);
        }
        return \Cms\Core\Http\Response::html('<pre>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>');
    };
    $fetchChapterWithRetry = static function (array $item) use ($http, $detector, $sanitizer, $qualityAnalyzer): array {
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                if ($attempt > 1) {
                    usleep(250000 * $attempt);
                }
                $res = $http->get((string) $item['url']);
                error_log('[NovelCollector] Chapter request title=' . (string) ($item['title'] ?? '') . ' requested_url=' . (string) ($res['requested_url'] ?? $item['url']) . ' final_url=' . (string) ($res['final_url'] ?? $res['url'] ?? '') . ' http_status=' . (string) ($res['http_status'] ?? $res['status'] ?? '') . ' content_type=' . (string) ($res['content_type'] ?? '') . ' response_length=' . (string) ($res['response_length'] ?? strlen((string) ($res['body'] ?? ''))) . ' redirect_count=' . (string) ($res['redirect_count'] ?? 0));
                $body = $detector->extractChapterBody((string) $item['url'], $res['body']);
                $clean = $sanitizer->clean($body);
                if (mb_strlen($clean['plaintext']) < 200) {
                    throw new \RuntimeException('Chapter body is abnormally short.');
                }
                $clean['quality'] = $qualityAnalyzer->assertAcceptable($clean, $res, $item);
                $clean['hash'] = (string) ($clean['quality']['fingerprint'] ?? $clean['hash']);
                return $clean;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        throw new \RuntimeException($lastError?->getMessage() ?: 'HTTP request failed.');
    };
    $generateCronToken = static fn (): string => bin2hex(random_bytes(24));
    $loadAutoSettings = static function () use ($storeGet): array {
        return array_merge([
            'enabled' => false,
            'interval_seconds' => 60,
            'batch_size' => 50,
            'last_tick_at' => '',
            'last_job_id' => '',
            'cron_token' => '',
        ], $storeGet('novel_collector_auto', 'settings') ?? []);
    };
    $ensureAutoCronToken = static function (array $settings) use ($storePut, $generateCronToken): array {
        if (strlen((string) ($settings['cron_token'] ?? '')) < 32) {
            $settings['cron_token'] = $generateCronToken();
            $settings['updated_at'] = gmdate('c');
            $storePut('novel_collector_auto', 'settings', $settings);
        }
        return $settings;
    };
    $pickRunnableJob = static function () use ($storeAll, $normalizeStoredRow): ?array {
        $jobs = [];
        foreach ($storeAll('novel_collector_jobs') as $row) {
            $job = $normalizeStoredRow($row);
            if (($job['job_id'] ?? '') === '' || ($job['status'] ?? '') === 'completed') {
                continue;
            }
            $jobs[] = $job;
        }
        usort($jobs, static fn (array $a, array $b): int => strcmp((string) ($a['updated_at'] ?? $a['created_at'] ?? ''), (string) ($b['updated_at'] ?? $b['created_at'] ?? '')));
        return $jobs[0] ?? null;
    };
    $runAutoBatch = static function (array $job, int $limit) use ($storePut, $loadJobItems, $buildJob, $persistJob, $fetchChapterWithRetry, $formalRepo): array {
        $jobId = (string) ($job['job_id'] ?? '');
        $catalogUrl = (string) ($job['catalog_url'] ?? '');
        $items = $loadJobItems($jobId);
        if ($items === [] && $catalogUrl !== '') {
            [, $chapters] = $buildJob($catalogUrl);
            $persistJob($job, $chapters);
            $items = array_map(static fn (array $chapter): array => [
                'title' => (string) ($chapter['title'] ?? ''),
                'url' => (string) ($chapter['url'] ?? ''),
                'source_chapter_id' => (string) ($chapter['source_chapter_id'] ?? $chapter['url'] ?? ''),
                'source_book_id' => (string) ($chapter['source_book_id'] ?? ''),
                'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                'status' => 'pending',
            ], $chapters);
        }
        $formalNovelId = (int) ($job['formal_novel_id'] ?? 0);
        if ($formalRepo instanceof NovelRepository && $formalNovelId <= 0) {
            try {
                $formalNovelId = $formalRepo->saveNovel($job + ['source_url' => $catalogUrl, 'catalog_url' => $catalogUrl]);
                $job['formal_novel_id'] = $formalNovelId;
            } catch (\Throwable $e) {
                $job['formal_write_error'] = $e->getMessage();
            }
        }
        $cursor = (int) ($job['resume_cursor'] ?? 0);
        $processed = [];
        $errors = [];
        $batchHashes = [];
        $lastPlaintext = '';
        $similarStreak = 0;
        $job['status'] = 'running';
        for ($i = 0; $i < $limit && $cursor < count($items); $i++, $cursor++) {
            $item = $items[$cursor];
            try {
                usleep(180000);
                $clean = $fetchChapterWithRetry($item);
                $hash = (string) ($clean['hash'] ?? '');
                if ($hash !== '' && isset($batchHashes[$hash])) {
                    throw new \RuntimeException('suspected_duplicate_content: current batch contains repeated chapter body.');
                }
                if ($formalRepo instanceof NovelRepository && $formalNovelId > 0 && $formalRepo->hasDuplicateChapterContent($formalNovelId, $hash, (int) ($item['sort_order'] ?? 0), (string) ($item['source_chapter_id'] ?? $item['url'] ?? ''))) {
                    throw new \RuntimeException('suspected_duplicate_content: content hash already exists for a different chapter.');
                }
                if ($lastPlaintext !== '' && ContentQualityAnalyzer::similarity($lastPlaintext, (string) ($clean['plaintext'] ?? '')) > 0.95) {
                    $similarStreak++;
                    if ($similarStreak >= 2) {
                        throw new \RuntimeException('suspected_duplicate_content: consecutive chapters are more than 95% similar.');
                    }
                } else {
                    $similarStreak = 0;
                }
                $batchHashes[$hash] = true;
                $lastPlaintext = (string) ($clean['plaintext'] ?? '');
                $chapter = [
                    'job_id' => $jobId,
                    'novel_key' => $jobId,
                    'title' => (string) $item['title'],
                    'sort_order' => (int) $item['sort_order'],
                    'source_url' => (string) $item['url'],
                    'source_chapter_id' => (string) ($item['source_chapter_id'] ?? $item['url']),
                    'content' => $clean['html'],
                    'content_plaintext' => $clean['plaintext'],
                    'content_hash' => $clean['hash'],
                    'content_quality' => (string) ($clean['quality']['quality'] ?? 'ok'),
                    'word_count' => mb_strlen($clean['plaintext']),
                    'collected_at' => gmdate('c'),
                ];
                $storePut('novel_chapters_local', $jobId . '_' . (string) $chapter['sort_order'], $chapter);
                $storePut('novel_chapter_index_local', $jobId . '_' . (string) $chapter['sort_order'], [
                    'job_id' => $jobId,
                    'formal_novel_id' => $formalNovelId,
                    'title' => $chapter['title'],
                    'sort_order' => $chapter['sort_order'],
                    'source_url' => $chapter['source_url'],
                    'content_hash' => $chapter['content_hash'],
                    'content_quality' => $chapter['content_quality'],
                    'word_count' => $chapter['word_count'],
                    'collected_at' => $chapter['collected_at'],
                ]);
                if ($formalRepo instanceof NovelRepository && $formalNovelId > 0) {
                    $chapter['formal_chapter_id'] = $formalRepo->saveChapter($formalNovelId, $item, $clean);
                }
                $processed[] = $chapter;
                $job['collected_count'] = max((int) ($job['collected_count'] ?? 0), $cursor + 1);
                unset($job['last_error']);
            } catch (\Throwable $e) {
                $job['failed_count'] = (int) ($job['failed_count'] ?? 0) + 1;
                $job['last_error'] = 'Chapter #' . ($cursor + 1) . ': ' . $e->getMessage();
                $errors[] = $job['last_error'];
                $storePut('novel_failed_chapters_local', $jobId . '_' . (string) ($cursor + 1), [
                    'job_id' => $jobId,
                    'formal_novel_id' => $formalNovelId,
                    'title' => (string) ($item['title'] ?? ''),
                    'sort_order' => (int) ($item['sort_order'] ?? ($cursor + 1)),
                    'source_url' => (string) ($item['url'] ?? ''),
                    'source_chapter_id' => (string) ($item['source_chapter_id'] ?? ''),
                    'error' => $e->getMessage(),
                    'failed_at' => gmdate('c'),
                ]);
                if (str_contains($e->getMessage(), 'suspected_duplicate_content')) {
                    $job['status'] = 'paused';
                    $job['last_error'] = $e->getMessage();
                    break;
                }
            }
        }
        $job['resume_cursor'] = $cursor;
        $job['updated_at'] = gmdate('c');
        if (($job['status'] ?? '') !== 'paused') {
            $job['status'] = $cursor >= count($items) ? 'completed' : 'running';
        }
        $storePut('novel_collector_jobs', $jobId, $job);
        $storePut('novels_local', $jobId, [
            'job_id' => $jobId,
            'formal_novel_id' => $formalNovelId,
            'title' => (string) ($job['title'] ?? ''),
            'author' => (string) ($job['author'] ?? ''),
            'description' => (string) ($job['description'] ?? ''),
            'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
            'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
            'catalog_url' => $catalogUrl,
            'status' => (string) ($job['status'] ?? 'serializing'),
            'chapter_count' => (int) ($job['collected_count'] ?? 0),
            'updated_at' => gmdate('c'),
        ]);
        return ['job' => $job, 'processed' => $processed, 'errors' => $errors, 'remaining' => max(0, count($items) - $cursor)];
    };
    $runAutoTick = static function (array $settings, int $loops = 1, bool $force = false) use ($storePut, $pickRunnableJob, $runAutoBatch): array {
        $loops = max(1, min(10, $loops));
        if (empty($settings['enabled']) && !$force) {
            return ['status' => 'disabled', 'settings' => $settings, 'loops' => 0, 'processed_total' => 0, 'error_total' => 0, 'results' => []];
        }
        $last = strtotime((string) ($settings['last_tick_at'] ?? '')) ?: 0;
        $interval = max(20, (int) ($settings['interval_seconds'] ?? 60));
        if (!$force && $last > 0 && time() - $last < $interval) {
            return ['status' => 'waiting', 'wait_seconds' => $interval - (time() - $last), 'settings' => $settings, 'loops' => 0, 'processed_total' => 0, 'error_total' => 0, 'results' => []];
        }
        $job = $pickRunnableJob();
        $results = [];
        for ($loop = 0; $loop < $loops; $loop++) {
            if ($job === null) {
                break;
            }
            $result = $runAutoBatch($job, max(1, min(200, (int) ($settings['batch_size'] ?? 50))));
            $results[] = $result;
            $job = ((int) ($result['remaining'] ?? 0) > 0) ? $result['job'] : $pickRunnableJob();
        }
        $settings['last_tick_at'] = gmdate('c');
        if ($results !== []) {
            $lastResult = end($results);
            $settings['last_job_id'] = (string) ($lastResult['job']['job_id'] ?? '');
        }
        $storePut('novel_collector_auto', 'settings', $settings);
        $processedTotal = array_sum(array_map(static fn (array $item): int => count($item['processed'] ?? []), $results));
        $errorTotal = array_sum(array_map(static fn (array $item): int => count($item['errors'] ?? []), $results));
        return [
            'status' => $results === [] ? 'idle' : 'ran',
            'settings' => $settings,
            'loops' => count($results),
            'processed_total' => $processedTotal,
            'error_total' => $errorTotal,
            'result' => $results !== [] ? end($results) : null,
            'results' => $results,
        ];
    };
    $compactAutoTick = static function (array $tick): array {
        $settings = (array) ($tick['settings'] ?? []);
        unset($settings['cron_token']);
        $compactResult = static function (?array $result): ?array {
            if ($result === null) {
                return null;
            }
            $job = (array) ($result['job'] ?? []);
            return [
                'job' => [
                    'job_id' => (string) ($job['job_id'] ?? ''),
                    'title' => (string) ($job['title'] ?? ''),
                    'status' => (string) ($job['status'] ?? ''),
                    'resume_cursor' => (int) ($job['resume_cursor'] ?? 0),
                    'chapter_count' => (int) ($job['chapter_count'] ?? 0),
                ],
                'processed_count' => count($result['processed'] ?? []),
                'error_count' => count($result['errors'] ?? []),
                'errors' => array_slice(array_map('strval', (array) ($result['errors'] ?? [])), 0, 5),
                'remaining' => (int) ($result['remaining'] ?? 0),
            ];
        };
        return [
            'status' => (string) ($tick['status'] ?? ''),
            'wait_seconds' => (int) ($tick['wait_seconds'] ?? 0),
            'settings' => $settings,
            'loops' => (int) ($tick['loops'] ?? 0),
            'processed_total' => (int) ($tick['processed_total'] ?? 0),
            'error_total' => (int) ($tick['error_total'] ?? 0),
            'result' => $compactResult(isset($tick['result']) && is_array($tick['result']) ? $tick['result'] : null),
        ];
    };

    if (method_exists($context, 'adminMenu')) {
        $context->adminMenu('小说采集', '/admin/novel-collector', 'novel_collector.manage');
    }

    if (method_exists($context, 'frontRoute')) {
        $context->frontRoute('GET', '/novel-collector/cron', static function ($request) use ($param, $loadAutoSettings, $ensureAutoCronToken, $runAutoTick, $compactAutoTick): \Cms\Core\Http\Response {
            $settings = $ensureAutoCronToken($loadAutoSettings());
            $expected = (string) ($settings['cron_token'] ?? '');
            $provided = $param($request, 'token');
            if ($expected === '' || !hash_equals($expected, $provided)) {
                return \Cms\Core\Http\Response::json(['ok' => false, 'error' => 'invalid_token'], 403);
            }
            $result = $runAutoTick($settings, max(1, min(10, (int) $param($request, 'loops', '1'))), $param($request, 'force', '0') === '1');
            return \Cms\Core\Http\Response::json(['ok' => in_array($result['status'], ['ran', 'idle', 'waiting'], true), 'cron' => true] + $compactAutoTick($result));
        });
        $context->frontRoute('GET', '/novels', static function ($request) use ($html, $pageShell, $loadNovelSummaries, $novelUrl, $novelSearchUrl) {
            $rows = '';
            foreach ($loadNovelSummaries() as $novel) {
                $jobId = (string) ($novel['job_id'] ?? '');
                $cover = (string) ($novel['cover'] ?? $novel['cover_url'] ?? '');
                $coverCell = $cover !== '' ? '<img class="cover-thumb" src="' . $html($cover) . '" alt="">' : '<span class="cover-thumb" aria-hidden="true">' . $html(mb_substr((string) ($novel['title'] ?? '书'), 0, 2)) . '</span>';
                $rows .= '<tr><td>' . $coverCell . '</td><td><a href="' . $html($novelUrl($jobId)) . '">' . $html((string) ($novel['title'] ?? '')) . '</a></td><td>' . $html((string) ($novel['author'] ?? '')) . '</td><td>' . $html((string) ($novel['chapter_count'] ?? 0)) . '</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="4" class="muted">暂无已采小说。</td></tr>';
            }
            $body = '<div class="topline"><h1>小说书库</h1><a class="button secondary" href="/novels/bookshelf">我的书架</a></div><section class="panel"><form method="get" action="' . $html($novelSearchUrl()) . '"><label>搜索小说</label><input name="q" placeholder="书名或作者"><button type="submit">搜索</button></form></section><section class="panel"><p class="muted">这里显示采集后的小说，可直接作为前台导航菜单 URL 使用：/novels</p><table><tr><th>封面</th><th>书名</th><th>作者</th><th>章节</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说', $body));
        });
        $context->frontRoute('GET', '/novels/search', static function ($request) use ($param, $html, $pageShell, $loadNovelSummaries, $novelUrl): \Cms\Core\Http\Response {
            $query = trim($param($request, 'q'));
            $rows = '';
            foreach ($loadNovelSummaries() as $novel) {
                $haystack = mb_strtolower((string) ($novel['title'] ?? '') . ' ' . (string) ($novel['author'] ?? ''), 'UTF-8');
                if ($query !== '' && !str_contains($haystack, mb_strtolower($query, 'UTF-8'))) {
                    continue;
                }
                $jobId = (string) ($novel['job_id'] ?? '');
                $cover = (string) ($novel['cover'] ?? $novel['cover_url'] ?? '');
                $coverCell = $cover !== '' ? '<img class="cover-thumb" src="' . $html($cover) . '" alt="">' : '<span class="cover-thumb" aria-hidden="true">' . $html(mb_substr((string) ($novel['title'] ?? '书'), 0, 2)) . '</span>';
                $rows .= '<tr><td>' . $coverCell . '</td><td><a href="' . $html($novelUrl($jobId)) . '">' . $html((string) ($novel['title'] ?? '')) . '</a></td><td>' . $html((string) ($novel['author'] ?? '')) . '</td><td>' . (int) ($novel['chapter_count'] ?? 0) . '</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="4" class="muted">没有找到匹配小说。</td></tr>';
            }
            $body = '<h1>小说搜索</h1><section class="panel"><form method="get" action="/novels/search"><label>关键词</label><input name="q" value="' . $html($query) . '" placeholder="书名或作者"><button type="submit">搜索</button><a class="button secondary" href="/novels">返回书库</a></form></section><section class="panel"><table><tr><th>封面</th><th>书名</th><th>作者</th><th>章节</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说搜索', $body));
        });
        $context->frontRoute('GET', '/novels/bookshelf', static function ($request) use ($pageShell): \Cms\Core\Http\Response {
            $body = <<<'HTML'
<div class="topline"><h1>我的书架</h1><a class="button secondary" href="/novels">返回书库</a></div>
<section class="panel">
  <table><thead><tr><th>书名</th><th>最近阅读</th><th>更新时间</th><th>操作</th></tr></thead><tbody data-bookshelf-list><tr><td colspan="4" class="muted">书架为空。</td></tr></tbody></table>
</section>
<script>
(function(){
  var list = document.querySelector("[data-bookshelf-list]");
  var store = JSON.parse(localStorage.getItem("daiying_novel_bookshelf") || "{}");
  var progress = JSON.parse(localStorage.getItem("daiying_novel_reading_progress") || "{}");
  var rows = Object.keys(store).map(function(id){
    var item = store[id] || {};
    var p = progress[id] || {};
    var href = p.chapterUrl || item.url || "/novels/book?job_id=" + encodeURIComponent(id);
    return "<tr><td><a href=\"" + href + "\">" + esc(item.title || id) + "</a></td><td>" + esc(p.chapterTitle || "未开始") + "</td><td>" + esc(item.updatedAt || "") + "</td><td><button type=\"button\" data-remove=\"" + esc(id) + "\">删除</button></td></tr>";
  });
  list.innerHTML = rows.length ? rows.join("") : "<tr><td colspan=\"4\" class=\"muted\">书架为空。</td></tr>";
  list.addEventListener("click", function(event){
    var id = event.target && event.target.getAttribute("data-remove");
    if (!id) return;
    delete store[id];
    localStorage.setItem("daiying_novel_bookshelf", JSON.stringify(store));
    location.reload();
  });
  function esc(value){ return String(value).replace(/[&<>"']/g, function(c){ return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[c]; }); }
})();
</script>
HTML;
            return \Cms\Core\Http\Response::html($pageShell('我的书架', $body));
        });
        $context->frontRoute('GET', '/novels/book', static function ($request) use ($param, $html, $pageShell, $loadNovelSummaries, $loadCollectedChapters, $loadCollectedChapterIndex, $novelChapterUrl, $formalRepo) {
            $jobId = $param($request, 'job_id');
            $formalId = str_starts_with($jobId, 'formal_') ? (int) substr($jobId, 7) : 0;
            $novel = null;
            if ($formalId > 0 && $formalRepo instanceof NovelRepository) {
                $novel = $formalRepo->publicNovel($formalId);
            } else {
                $novels = $loadNovelSummaries();
                $novel = $novels[$jobId] ?? null;
            }
            if ($novel === null) {
                return \Cms\Core\Http\Response::html($pageShell('小说不存在', '<h1>小说不存在</h1><section class="panel"><p class="muted">没有找到这个小说，可能还没有采集成功。</p><a class="button secondary" href="/novels">返回小说书库</a></section>'));
            }
            $chapters = [];
            if ($formalId > 0 && $formalRepo instanceof NovelRepository) {
                $chapters = $formalRepo->publicChapterIndex($formalId);
            }
            if ($chapters === []) {
                $chapters = $loadCollectedChapterIndex($jobId);
            }
            if ($chapters === [] && !str_starts_with($jobId, 'formal_')) {
                $chapters = array_map(static fn (array $chapter): array => [
                    'title' => (string) ($chapter['title'] ?? ''),
                    'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                    'word_count' => (int) ($chapter['word_count'] ?? mb_strlen((string) ($chapter['content_plaintext'] ?? ''))),
                ], $loadCollectedChapters($jobId));
            }
            $pageSize = 100;
            $page = max(1, (int) $param($request, 'page', '1'));
            $latestOnly = $param($request, 'latest') === '1';
            $totalChapters = count($chapters);
            $shownChapters = $latestOnly ? array_slice($chapters, -100) : array_slice($chapters, ($page - 1) * $pageSize, $pageSize);
            $pageCount = max(1, (int) ceil(max(1, $totalChapters) / $pageSize));
            $cards = '';
            foreach ($shownChapters as $chapter) {
                $sort = (string) (int) ($chapter['sort_order'] ?? 0);
                $cards .= '<a class="chapter-card" href="' . $html($novelChapterUrl($jobId, (int) $sort)) . '">' . $html((string) ($chapter['title'] ?? '未命名章节')) . '<small>#' . $html($sort) . ' · ' . $html((string) ($chapter['word_count'] ?? 0)) . ' 字</small></a>';
            }
            if ($cards === '') {
                $cards = '<p class="muted">还没有可阅读章节，请先在后台采集一批章节。</p>';
            }
            $firstChapter = $chapters[0]['sort_order'] ?? 1;
            $latestChapter = $chapters !== [] ? (int) ($chapters[array_key_last($chapters)]['sort_order'] ?? $firstChapter) : 1;
            $pager = $latestOnly ? '<a class="button secondary" href="/novels/book?job_id=' . rawurlencode($jobId) . '">查看全部目录</a>' : '<a class="button secondary" href="/novels/book?job_id=' . rawurlencode($jobId) . '&page=' . max(1, $page - 1) . '">上一页</a><a class="button secondary" href="/novels/book?job_id=' . rawurlencode($jobId) . '&page=' . min($pageCount, $page + 1) . '">下一页</a><a class="button secondary" href="/novels/book?job_id=' . rawurlencode($jobId) . '&latest=1">最近 100 章</a><form method="get" action="/novels/book" style="display:inline-block"><input type="hidden" name="job_id" value="' . $html($jobId) . '"><input name="page" type="number" min="1" max="' . $pageCount . '" value="' . $page . '" style="width:88px"><button type="submit">跳转</button></form>';
            $cover = (string) ($novel['cover'] ?? $novel['cover_url'] ?? '');
            $coverHtml = $cover !== '' ? '<img src="' . $html($cover) . '" alt="">' : '<span class="cover-thumb" aria-hidden="true">' . $html(mb_substr((string) ($novel['title'] ?? '书'), 0, 4)) . '</span>';
            $body = '<h1>' . $html((string) ($novel['title'] ?? $jobId)) . '</h1><section class="panel book-head" data-novel-book data-job-id="' . $html($jobId) . '" data-title="' . $html((string) ($novel['title'] ?? $jobId)) . '" data-url="/novels/book?job_id=' . rawurlencode($jobId) . '">' . $coverHtml . '<div><p><strong>作者：</strong>' . $html((string) ($novel['author'] ?? '')) . '</p><p><strong>已采章节：</strong>' . $html((string) $totalChapters) . '</p><p data-continue-wrap hidden><a class="button" data-continue-link href="' . $html($novelChapterUrl($jobId, (int) $firstChapter)) . '">继续阅读</a></p><a class="button" href="' . $html($novelChapterUrl($jobId, (int) $firstChapter)) . '">开始阅读</a><button type="button" data-bookshelf-add>加入书架</button><a class="button secondary" href="/novels/export.txt?job_id=' . rawurlencode($jobId) . '">TXT 下载</a><a class="button secondary" href="/novels">返回书库</a></div></section><section class="panel"><h2>章节目录</h2><p class="muted">' . ($latestOnly ? '正在显示最近 100 章' : '第 ' . $page . ' / ' . $pageCount . ' 页') . '，最新章节 #' . $latestChapter . '</p>' . $pager . '<div class="chapter-grid">' . $cards . '</div></section><script>(function(){var box=document.querySelector("[data-novel-book]"); if(!box) return; var id=box.dataset.jobId,title=box.dataset.title,url=box.dataset.url; var shelf=JSON.parse(localStorage.getItem("daiying_novel_bookshelf")||"{}"); var progress=JSON.parse(localStorage.getItem("daiying_novel_reading_progress")||"{}"); var p=progress[id]; if(p&&p.chapterUrl){var wrap=document.querySelector("[data-continue-wrap]"),link=document.querySelector("[data-continue-link]"); if(wrap&&link){wrap.hidden=false; link.href=p.chapterUrl; link.textContent="继续阅读 " + (p.chapterTitle||"上次章节");}} var btn=document.querySelector("[data-bookshelf-add]"); if(btn){btn.textContent=shelf[id]?"已在书架":"加入书架"; btn.addEventListener("click",function(){shelf[id]={title:title,url:url,updatedAt:(new Date()).toISOString()}; localStorage.setItem("daiying_novel_bookshelf",JSON.stringify(shelf)); btn.textContent="已在书架";});}})();</script>';
            return \Cms\Core\Http\Response::html($pageShell((string) ($novel['title'] ?? '小说目录'), $body));
        });
        $context->frontRoute('GET', '/novels/export.txt', static function ($request) use ($param, $sendTxtDownload) {
            return $sendTxtDownload($param($request, 'job_id'));
        });
        $context->frontRoute('GET', '/novels/chapter', static function ($request) use ($param, $html, $pageShell, $storeGet, $loadNovelSummaries, $loadCollectedChapters, $novelUrl, $novelChapterUrl, $formalRepo) {
            $jobId = $param($request, 'job_id');
            $sort = max(1, (int) $param($request, 'chapter', '1'));
            $formalId = str_starts_with($jobId, 'formal_') ? (int) substr($jobId, 7) : 0;
            $chapter = null;
            if ($formalId > 0 && $formalRepo instanceof NovelRepository) {
                $chapter = $formalRepo->publicChapter($formalId, $sort);
            }
            if ($chapter === null) {
                $chapter = $storeGet('novel_chapters_local', $jobId . '_' . (string) $sort);
            }
            if ($chapter === null) {
                foreach ($loadCollectedChapters($jobId) as $candidate) {
                    if ((int) ($candidate['sort_order'] ?? 0) === $sort) {
                        $chapter = $candidate;
                        break;
                    }
                }
            }
            if ($chapter === null) {
                return \Cms\Core\Http\Response::html($pageShell('章节不存在', '<h1>章节不存在</h1><section class="panel"><p class="muted">这个章节还没有采集到本地。</p><a class="button secondary" href="/novels/book?job_id=' . rawurlencode($jobId) . '">返回目录</a></section>'));
            }
            $novel = ['title' => $jobId];
            if ($formalId > 0 && $formalRepo instanceof NovelRepository) {
                $novel = $formalRepo->publicNovel($formalId) ?? $novel;
            } else {
                $novels = $loadNovelSummaries();
                $novel = $novels[$jobId] ?? $novel;
            }
            $chapterSorts = [];
            if ($formalId > 0 && $formalRepo instanceof NovelRepository) {
                $chapterSorts = $formalRepo->publicChapterSorts($formalId);
            } else {
                $chapterSorts = array_map(static fn (array $row): int => (int) ($row['sort_order'] ?? 0), $loadCollectedChapters($jobId));
            }
            $chapterSorts = array_values(array_unique(array_filter($chapterSorts, static fn (int $value): bool => $value > 0)));
            sort($chapterSorts);
            $currentIndex = array_search($sort, $chapterSorts, true);
            $prevSort = $currentIndex !== false && isset($chapterSorts[$currentIndex - 1]) ? (int) $chapterSorts[$currentIndex - 1] : 0;
            $nextSort = $currentIndex !== false && isset($chapterSorts[$currentIndex + 1]) ? (int) $chapterSorts[$currentIndex + 1] : 0;
            $prevUrl = $prevSort > 0 ? $novelChapterUrl($jobId, $prevSort) : '';
            $nextUrl = $nextSort > 0 ? $novelChapterUrl($jobId, $nextSort) : '';
            $prev = $prevUrl !== '' ? '<a class="button ghost" href="' . $html($prevUrl) . '">上一章</a>' : '';
            $next = $nextUrl !== '' ? '<a class="button ghost" href="' . $html($nextUrl) . '">下一章</a>' : '';
            $content = (string) ($chapter['content'] ?? '');
            if ($content === '') {
                $content = implode('', array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', preg_split('/\R+/u', (string) ($chapter['content_plaintext'] ?? '')) ?: []));
            }
            $title = (string) ($chapter['title'] ?? '未命名章节');
            $bookUrl = $novelUrl($jobId);
            $continueAction = $nextUrl !== '' ? '<p><a class="button" href="' . $html($nextUrl) . '">继续下一章</a></p>' : '<p><a class="button" href="' . $html($bookUrl) . '">返回目录</a></p>';
            $mobileNext = $nextUrl !== '' ? '<a class="button ghost" href="' . $html($nextUrl) . '">下一章</a>' : '';
            $body = '<h1>' . $html($title) . '</h1><section class="reader-controls" data-reader-controls><div class="topline"><span><strong>' . $html((string) ($novel['title'] ?? $jobId)) . '</strong> · #' . $html((string) $sort) . '</span><span data-reader-percent>0%</span></div><div class="progressbar"><span data-reader-progress></span></div><p><button type="button" data-theme="day">日间</button><button type="button" data-theme="eye">护眼</button><button type="button" data-theme="night">夜间</button><button type="button" data-font="-">字号 -</button><button type="button" data-font="+">字号 +</button><button type="button" data-width="-">窄一点</button><button type="button" data-width="+">宽一点</button><button type="button" data-fullscreen>全屏</button></p>' . $prev . $next . '<a class="button secondary" href="' . $html($bookUrl) . '">目录</a></section><section class="panel reader" data-reader data-job-id="' . $html($jobId) . '" data-chapter="' . $html((string) $sort) . '" data-chapter-title="' . $html($title) . '" data-book-title="' . $html((string) ($novel['title'] ?? $jobId)) . '" data-book-url="' . $html($bookUrl) . '">' . $content . '</section>' . $continueAction . '<nav class="mobile-reader-bar">' . ($prevUrl !== '' ? '<a class="button ghost" href="' . $html($prevUrl) . '">上一章</a>' : '') . '<a class="button secondary" href="' . $html($bookUrl) . '">目录</a>' . $mobileNext . '</nav><script>(function(){var reader=document.querySelector("[data-reader]"); if(!reader) return; var id=reader.dataset.jobId, chapter=reader.dataset.chapter, key="daiying_novel_reader_settings"; var settings=JSON.parse(localStorage.getItem(key)||"{\"theme\":\"day\",\"font\":18,\"line\":1.9,\"width\":820,\"autoNext\":false}"); function apply(){reader.dataset.theme=settings.theme||"day"; reader.style.fontSize=(settings.font||18)+"px"; reader.style.lineHeight=String(settings.line||1.9); reader.style.maxWidth=(settings.width||820)+"px"; localStorage.setItem(key,JSON.stringify(settings));} apply(); document.addEventListener("click",function(e){var t=e.target;if(!t) return; var theme=t.getAttribute("data-theme"); if(theme){settings.theme=theme; apply();} var font=t.getAttribute("data-font"); if(font){settings.font=Math.max(14,Math.min(28,(settings.font||18)+(font==="+"?1:-1))); apply();} var width=t.getAttribute("data-width"); if(width){settings.width=Math.max(620,Math.min(1040,(settings.width||820)+(width==="+"?60:-60))); apply();} if(t.hasAttribute("data-fullscreen")&&document.documentElement.requestFullscreen){document.documentElement.requestFullscreen();}}); function save(){var max=Math.max(1,document.documentElement.scrollHeight-window.innerHeight); var pos=Math.max(0,window.scrollY||document.documentElement.scrollTop||0); var percent=Math.min(100,Math.round(pos/max*100)); var bar=document.querySelector("[data-reader-progress]"), label=document.querySelector("[data-reader-percent]"); if(bar) bar.style.width=percent+"%"; if(label) label.textContent=percent+"%"; var progress=JSON.parse(localStorage.getItem("daiying_novel_reading_progress")||"{}"); progress[id]={chapter:chapter,chapterTitle:reader.dataset.chapterTitle,chapterUrl:location.pathname+location.search,bookTitle:reader.dataset.bookTitle,bookUrl:reader.dataset.bookUrl,scrollY:pos,percent:percent,updatedAt:(new Date()).toISOString()}; localStorage.setItem("daiying_novel_reading_progress",JSON.stringify(progress)); var shelf=JSON.parse(localStorage.getItem("daiying_novel_bookshelf")||"{}"); shelf[id]=shelf[id]||{title:reader.dataset.bookTitle,url:reader.dataset.bookUrl}; shelf[id].updatedAt=(new Date()).toISOString(); localStorage.setItem("daiying_novel_bookshelf",JSON.stringify(shelf));} var saved=JSON.parse(localStorage.getItem("daiying_novel_reading_progress")||"{}")[id]; if(saved&&String(saved.chapter)===String(chapter)&&saved.scrollY>0){setTimeout(function(){window.scrollTo(0,saved.scrollY);},80);} window.addEventListener("scroll",function(){window.requestAnimationFrame(save);},{passive:true}); window.addEventListener("beforeunload",save); save();})();</script>';
            return \Cms\Core\Http\Response::html($pageShell((string) ($chapter['title'] ?? '小说阅读'), $body));
        });
    }

    if (method_exists($context, 'adminRoute')) {
        $context->adminRoute('GET', '/admin/novel-collector', static function ($request) use ($pageShell) {
            return \Cms\Core\Http\Response::html($pageShell('小说采集', <<<'HTML'
  <h1>小说采集</h1>
  <section class="panel">
    <h2>目录 URL 自动识别</h2>
    <form method="get" action="/admin/novel-collector/detect">
      <label>小说目录页 URL</label>
      <input name="url" type="url" placeholder="https://example.com/book/123/" required>
      <button type="submit">识别并预检</button>
    </form>
    <p class="muted">采集优先级：Adapter → 自动识别 → 已保存站点规则 → CSS/XPath 手工规则。低置信度不会启动完整采集。</p>
    <a class="button secondary" href="/admin/novel-collector/jobs">队列管理</a>
    <a class="button secondary" href="/admin/novel-collector/novels">已采小说</a>
    <a class="button secondary" href="/admin/novel-collector/auto">自动采集</a>
    <a class="button secondary" href="/admin/novel-collector/site">全站发现</a>
  </section>
  <section class="panel">
    <h2>本地测试执行流</h2>
    <p><span class="tag">识别</span><span class="tag">三章预检</span><span class="tag">创建队列</span><span class="tag">分批采集</span><span class="tag">TXT 导出</span></p>
  </section>
HTML));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/legacy', static fn ($request) => \Cms\Core\Http\Response::html(<<<'HTML'
<!doctype html><meta charset="utf-8"><title>小说采集</title>
<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#172033}.wrap{max-width:1100px;margin:0 auto;padding:28px 20px}.panel{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:20px;margin:0 0 18px}label{display:block;font-weight:650;margin:12px 0 6px}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px}button,.button{display:inline-block;background:#1f6feb;color:#fff;border:0;border-radius:6px;padding:10px 14px;text-decoration:none;margin-top:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.muted{color:#667085}.tag{display:inline-block;background:#eef4ff;color:#1f4b99;border-radius:999px;padding:4px 9px;margin:3px}</style>
<main class="wrap">
  <h1>小说采集</h1>
  <section class="panel">
    <h2>目录 URL 自动识别</h2>
    <form method="get" action="/admin/novel-collector/detect">
      <label>小说目录页 URL</label>
      <input name="url" type="url" placeholder="https://example.com/book/123/" required>
      <button type="submit">识别并预检</button>
    </form>
    <p class="muted">采集优先级：Adapter → 自动识别 → 已保存站点规则 → CSS/XPath 手工规则。低置信度不会启动完整采集。</p>
  </section>
  <section class="grid">
    <div class="panel"><h2>三章预检</h2><p>第一章、中间章节、最新章节；检查正文长度、乱码、重复、登录页和错误页。</p></div>
    <div class="panel"><h2>队列与断点</h2><p><span class="tag">pending</span><span class="tag">running</span><span class="tag">paused</span><span class="tag">failed</span><span class="tag">completed</span><span class="tag">cancelled</span></p></div>
    <div class="panel"><h2>增量追更</h2><p>重新读取目录，只采新增章节；默认复查最近 10 章修订，不自动删除来源缺失章节。</p></div>
    <div class="panel"><h2>TXT 导入导出</h2><p>支持中文/英文章节标题拆章、UTF-8 TXT 导出和导出缓存 stale 标记。</p></div>
  </section>
</main>
HTML), 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/auto', static function ($request) use ($html, $pageShell, $loadAutoSettings, $ensureAutoCronToken, $pickRunnableJob) {
            $settings = $ensureAutoCronToken($loadAutoSettings());
            $job = $pickRunnableJob();
            $enabled = !empty($settings['enabled']);
            $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($request->server['HTTP_HOST'] ?? 'book.daixingwei.cn')) ?: 'book.daixingwei.cn';
            $cronUrl = 'https://' . $host . '/novel-collector/cron?token=' . rawurlencode((string) ($settings['cron_token'] ?? ''));
            $cronCommand = "curl -fsS '" . $cronUrl . "' >/dev/null";
            $body = '<h1>自动采集</h1><section class="panel"><p><strong>状态：</strong><span class="tag">' . ($enabled ? 'enabled' : 'disabled') . '</span></p><p><strong>间隔：</strong>' . $html((string) ($settings['interval_seconds'] ?? 60)) . ' 秒</p><p><strong>每批：</strong>' . $html((string) ($settings['batch_size'] ?? 50)) . ' 章</p><p><strong>上次执行：</strong>' . $html((string) ($settings['last_tick_at'] ?? '')) . '</p><p><strong>待执行队列：</strong>' . $html((string) ($job['title'] ?? '无')) . '</p><form method="get" action="/admin/novel-collector/auto/save"><label>开启自动采集</label><select name="enabled"><option value="1"' . ($enabled ? ' selected' : '') . '>开启</option><option value="0"' . (!$enabled ? ' selected' : '') . '>关闭</option></select><label>间隔秒数</label><input name="interval_seconds" type="number" min="20" max="86400" value="' . $html((string) ($settings['interval_seconds'] ?? 60)) . '"><label>每批章节数</label><input name="batch_size" type="number" min="1" max="200" value="' . $html((string) ($settings['batch_size'] ?? 50)) . '"><button type="submit">保存设置</button><a class="button secondary" href="/admin/novel-collector/auto/tick?force=1">立即执行一批</a><a class="button secondary" href="/admin/novel-collector/auto/tick?force=1&loops=5">连续执行 5 批</a><a class="button secondary" href="/admin/novel-collector/site">全站发现</a><a class="button secondary" href="/admin/novel-collector/jobs">队列管理</a></form><p class="muted">后台按钮适合临时手动跑；真正无人值守请在宝塔计划任务里定时执行下面的 URL 或命令。</p><label>宝塔计划任务 URL</label><input readonly value="' . $html($cronUrl) . '"><label>Shell 命令</label><input readonly value="' . $html($cronCommand) . '"><p class="muted">建议宝塔任务每 1 分钟执行一次。插件会按“间隔秒数”判断是否真正采集，token 不要公开。</p></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说自动采集', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/auto/save', static function ($request) use ($param, $storePut, $pageShell, $html, $loadAutoSettings, $ensureAutoCronToken) {
            $existing = $ensureAutoCronToken($loadAutoSettings());
            $settings = [
                'enabled' => $param($request, 'enabled', '0') === '1',
                'interval_seconds' => max(20, min(86400, (int) $param($request, 'interval_seconds', '60'))),
                'batch_size' => max(1, min(200, (int) $param($request, 'batch_size', '50'))),
                'last_tick_at' => (string) ($existing['last_tick_at'] ?? ''),
                'last_job_id' => (string) ($existing['last_job_id'] ?? ''),
                'cron_token' => (string) ($existing['cron_token'] ?? ''),
                'updated_at' => gmdate('c'),
            ];
            $storePut('novel_collector_auto', 'settings', $settings);
            return \Cms\Core\Http\Response::html($pageShell('自动采集已保存', '<h1>自动采集已保存</h1><section class="panel"><p><strong>状态：</strong>' . ($settings['enabled'] ? '开启' : '关闭') . '</p><p><strong>间隔：</strong>' . $html((string) $settings['interval_seconds']) . ' 秒</p><p><strong>每批：</strong>' . $html((string) $settings['batch_size']) . ' 章</p><a class="button" href="/admin/novel-collector/auto">返回自动采集</a></section>'));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/auto/tick', static function ($request) use ($param, $pageShell, $html, $loadAutoSettings, $ensureAutoCronToken, $runAutoTick, $compactAutoTick) {
            $settings = $ensureAutoCronToken($loadAutoSettings());
            $force = $param($request, 'force', '0') === '1';
            $loops = max(1, min(10, (int) $param($request, 'loops', '1')));
            $tick = $runAutoTick($settings, $loops, $force);
            if ($tick['status'] === 'disabled') {
                return \Cms\Core\Http\Response::html($pageShell('自动采集未开启', '<h1>自动采集未开启</h1><section class="panel"><p class="muted">自动采集当前关闭。</p><a class="button" href="/admin/novel-collector/auto">返回设置</a></section>'));
            }
            if ($tick['status'] === 'waiting') {
                $wait = (int) ($tick['wait_seconds'] ?? 0);
                return \Cms\Core\Http\Response::html($pageShell('自动采集等待中', '<h1>自动采集等待中</h1><section class="panel"><p>距离下次执行还需 ' . $html((string) $wait) . ' 秒。</p><a class="button secondary" href="/admin/novel-collector/auto">返回设置</a></section>'));
            }
            if ($tick['status'] === 'idle') {
                return \Cms\Core\Http\Response::html($pageShell('没有可执行队列', '<h1>没有可执行队列</h1><section class="panel"><p class="muted">请先创建单本队列，或用全站发现批量创建队列。</p><a class="button" href="/admin/novel-collector/site">全站发现</a><a class="button secondary" href="/admin/novel-collector/auto">返回设置</a></section>'));
            }
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json($compactAutoTick($tick));
            }
            $result = (array) ($tick['result'] ?? []);
            $errors = (array) ($result['errors'] ?? []);
            $body = '<h1>自动采集执行</h1><section class="panel"><p><strong>书名：</strong>' . $html((string) ($result['job']['title'] ?? '')) . '</p><p><strong>执行批次：</strong>' . $html((string) ($tick['loops'] ?? 0)) . ' 批</p><p><strong>本次采集：</strong>' . $html((string) ($tick['processed_total'] ?? 0)) . ' 章</p><p><strong>失败：</strong>' . $html((string) ($tick['error_total'] ?? 0)) . ' 章</p><p><strong>剩余：</strong>' . $html((string) ($result['remaining'] ?? 0)) . ' 章</p><p><strong>状态：</strong><span class="tag">' . $html((string) ($result['job']['status'] ?? '')) . '</span></p>' . ($errors !== [] ? '<p class="fail">' . $html(implode('; ', $errors)) . '</p>' : '') . '<a class="button" href="/admin/novel-collector/auto/tick?force=1">继续执行一批</a><a class="button secondary" href="/admin/novel-collector/auto/tick?force=1&loops=5">连续执行 5 批</a><a class="button secondary" href="/admin/novel-collector/auto">返回自动采集</a><a class="button secondary" href="/novels">前台小说</a></section>';
            return \Cms\Core\Http\Response::html($pageShell('自动采集执行', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/site', static function ($request) use ($pageShell) {
            return \Cms\Core\Http\Response::html($pageShell('全站发现', <<<'HTML'
<h1>全站发现</h1>
<section class="panel">
  <form method="get" action="/admin/novel-collector/site/discover">
    <label>站点首页或分类页 URL</label>
    <input name="url" type="url" placeholder="https://example.com/category/" required>
    <label>最多发现数量</label>
    <input name="max" type="number" min="1" max="500" value="50">
    <button type="submit">发现小说目录</button>
  </form>
  <p class="muted">只会收集同域名、同端口下疑似小说目录页的链接；每本入队前仍会执行识别和三章预检。</p>
  <a class="button secondary" href="/admin/novel-collector">返回小说采集</a>
</section>
HTML));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/site/discover', static function ($request) use ($param, $html, $pageShell, $discoverCatalogUrls) {
            $url = $param($request, 'url');
            $max = max(1, min(500, (int) $param($request, 'max', '50')));
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing url.</p>');
            }
            $found = $discoverCatalogUrls($url, $max);
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['seed_url' => $url, 'found' => $found]);
            }
            $rows = '';
            foreach ($found as $item) {
                $rows .= '<tr><td>' . $html((string) ($item['title'] ?? '')) . '</td><td><code>' . $html((string) $item['url']) . '</code></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="2" class="muted">没有发现符合规则的小说目录链接。</td></tr>';
            }
            $body = '<h1>全站发现结果</h1><section class="panel"><p><strong>发现：</strong>' . count($found) . ' 本</p><a class="button" href="/admin/novel-collector/site/create?url=' . rawurlencode($url) . '&max=' . rawurlencode((string) $max) . '">预检并批量建队列</a><a class="button secondary" href="/admin/novel-collector/site/discover?format=json&url=' . rawurlencode($url) . '&max=' . rawurlencode((string) $max) . '">查看 JSON</a><a class="button secondary" href="/admin/novel-collector/site">返回</a></section><section class="panel"><table><tr><th>标题</th><th>URL</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('全站发现结果', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/site/create', static function ($request) use ($param, $html, $pageShell, $discoverCatalogUrls, $loadCatalog, $http, $detector, $sanitizer, $buildJob, $persistJob, $storePut, $formalRepo) {
            $url = $param($request, 'url');
            $max = max(1, min(500, (int) $param($request, 'max', '50')));
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing url.</p>');
            }
            $created = [];
            $skipped = [];
            foreach ($discoverCatalogUrls($url, $max) as $item) {
                $catalogUrl = (string) ($item['url'] ?? '');
                try {
                    $detected = $loadCatalog($catalogUrl);
                    $preflight = $detector->preflight($detected['chapters'] ?? [], static function (string $chapterUrl) use ($http, $detector, $sanitizer): array {
                        $res = $http->get($chapterUrl);
                        $body = $detector->extractChapterBody($chapterUrl, $res['body']);
                        return ['response' => $res, 'clean' => $sanitizer->clean($body)];
                    });
                    if (empty($preflight['pass'])) {
                        $skipped[] = ['url' => $catalogUrl, 'reason' => implode('; ', $preflight['errors'] ?? ['preflight failed'])];
                        continue;
                    }
                    [$job, $chapters] = $buildJob($catalogUrl);
                    $jobId = (string) ($job['job_id'] ?? '');
                    $job['site_seed_url'] = $url;
                    $job['preflight_passed'] = true;
                    if ($formalRepo instanceof NovelRepository) {
                        try {
                            $formalNovelId = $formalRepo->saveNovel($job + ['source_url' => $catalogUrl, 'catalog_url' => $catalogUrl]);
                            $job['formal_novel_id'] = $formalNovelId;
                        } catch (\Throwable $e) {
                            $job['formal_write_error'] = $e->getMessage();
                        }
                    }
                    $persistJob($job, $chapters);
                    $storePut('novels_local', $jobId, [
                        'job_id' => $jobId,
                        'formal_novel_id' => (int) ($job['formal_novel_id'] ?? 0),
                        'title' => (string) ($job['title'] ?? ''),
                        'author' => (string) ($job['author'] ?? ''),
                        'description' => (string) ($job['description'] ?? ''),
                        'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
                        'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
                        'catalog_url' => $catalogUrl,
                        'status' => (string) ($job['status'] ?? 'pending'),
                        'chapter_count' => 0,
                        'updated_at' => gmdate('c'),
                    ]);
                    $created[] = $job;
                } catch (\Throwable $e) {
                    $skipped[] = ['url' => $catalogUrl, 'reason' => $e->getMessage()];
                }
            }
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['seed_url' => $url, 'created' => $created, 'skipped' => $skipped]);
            }
            $rows = '';
            foreach ($created as $job) {
                $rows .= '<tr><td>' . $html((string) ($job['title'] ?? '')) . '</td><td>' . $html((string) ($job['chapter_count'] ?? 0)) . '</td><td>' . $html((string) ($job['job_id'] ?? '')) . '</td></tr>';
            }
            foreach ($skipped as $skip) {
                $rows .= '<tr><td><code>' . $html((string) $skip['url']) . '</code></td><td colspan="2" class="fail">' . $html((string) $skip['reason']) . '</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="3" class="muted">没有创建新的采集队列。</td></tr>';
            }
            $body = '<h1>批量建队列完成</h1><section class="panel"><p><strong>已入队：</strong>' . count($created) . ' 本</p><p><strong>跳过：</strong>' . count($skipped) . ' 本</p><a class="button" href="/admin/novel-collector/auto">开启自动采集</a><a class="button secondary" href="/admin/novel-collector/jobs">队列管理</a><a class="button secondary" href="/admin/novel-collector/site/create?format=json&url=' . rawurlencode($url) . '&max=' . rawurlencode((string) $max) . '">查看 JSON</a><a class="button secondary" href="/admin/novel-collector/site">返回全站发现</a></section><section class="panel"><table><tr><th>书名/URL</th><th>章节/原因</th><th>队列 ID</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('批量建队列完成', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/detect', static function ($request) use ($param, $html, $loadCatalog, $pageShell) {
            $url = $param($request, 'url');
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing url.</p>');
            }
            $detected = $loadCatalog($url);
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json($detected);
            }
            $chapters = $detected['chapters'] ?? [];
            $rows = '';
            foreach (array_slice($chapters, 0, 8) as $chapter) {
                $rows .= '<tr><td>' . $html((string) ($chapter['sort_order'] ?? '')) . '</td><td>' . $html((string) ($chapter['title'] ?? '')) . '</td><td>' . $html((string) ($chapter['url'] ?? '')) . '</td></tr>';
            }
            $body = '<h1>识别结果</h1><section class="panel"><p><strong>书名：</strong>' . $html((string) ($detected['title'] ?? '')) . '</p><p><strong>作者：</strong>' . $html((string) ($detected['author'] ?? '')) . '</p><p><strong>状态：</strong>' . $html((string) ($detected['status'] ?? '')) . '</p><p><strong>章节数：</strong>' . $html((string) ($detected['chapter_count'] ?? 0)) . '</p><p><strong>置信度：</strong>' . $html((string) ($detected['confidence'] ?? 0)) . ' / strategy: ' . $html((string) ($detected['strategy'] ?? '')) . '</p><a class="button" href="/admin/novel-collector/preflight?url=' . rawurlencode($url) . '">运行三章预检</a><a class="button secondary" href="/admin/novel-collector/detect?format=json&url=' . rawurlencode($url) . '">查看 JSON</a><a class="button secondary" href="/admin/novel-collector">返回</a></section><section class="panel"><h2>章节样例</h2><table><tr><th>#</th><th>标题</th><th>URL</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说识别结果', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/preflight', static function ($request) use ($http, $detector, $sanitizer, $param, $html, $loadCatalog, $pageShell) {
            $url = $param($request, 'url');
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing url.</p>');
            }
            $detected = $loadCatalog($url);
            $preflight = $detector->preflight($detected['chapters'] ?? [], static function (string $chapterUrl) use ($http, $detector, $sanitizer): array {
                $res = $http->get($chapterUrl);
                $body = $detector->extractChapterBody($chapterUrl, $res['body']);
                return ['response' => $res, 'clean' => $sanitizer->clean($body)];
            });
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['book' => $detected, 'preflight' => $preflight]);
            }
            $rows = '';
            foreach (($preflight['samples'] ?? []) as $sample) {
                $rows .= '<tr><td>' . $html((string) ($sample['title'] ?? '')) . '</td><td>' . $html((string) ($sample['length'] ?? 0)) . '</td><td>' . $html((string) ($sample['quality'] ?? '')) . ' / ' . $html((string) ($sample['quality_score'] ?? 0)) . '</td><td>' . $html((string) ($sample['http_status'] ?? 0)) . '</td><td>' . $html((string) ($sample['final_url'] ?? '')) . '</td><td>' . $html((string) ($sample['hash'] ?? '')) . '</td><td>' . $html((string) ($sample['url'] ?? '')) . '</td></tr>';
            }
            $errors = '';
            foreach (($preflight['errors'] ?? []) as $error) {
                $errors .= '<li>' . $html((string) $error) . '</li>';
            }
            $status = ($preflight['pass'] ?? false) ? '<span class="ok">PRECHECK PASS</span>' : '<span class="fail">PRECHECK FAIL</span>';
            $createButton = ($preflight['pass'] ?? false) ? '<a class="button" href="/admin/novel-collector/jobs/create?url=' . rawurlencode($url) . '">创建采集队列</a>' : '';
            $body = '<h1>三章预检</h1><section class="panel"><p><strong>书名：</strong>' . $html((string) ($detected['title'] ?? '')) . '</p><p><strong>章节数：</strong>' . $html((string) ($detected['chapter_count'] ?? 0)) . '</p><p><strong>结果：</strong>' . $status . '</p>' . ($errors !== '' ? '<ul>' . $errors . '</ul>' : '') . $createButton . '<a class="button secondary" href="/admin/novel-collector/detect?url=' . rawurlencode($url) . '">返回识别结果</a><a class="button secondary" href="/admin/novel-collector/preflight?format=json&url=' . rawurlencode($url) . '">查看 JSON</a></section><section class="panel"><h2>抽样章节</h2><table><tr><th>标题</th><th>正文长度</th><th>质量</th><th>HTTP</th><th>最终 URL</th><th>Hash</th><th>URL</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说三章预检', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/jobs/create', static function ($request) use ($param, $html, $buildJob, $persistJob, $pageShell, $storeGet, $storePut, $loadJobItems, $formalRepo) {
            $url = $param($request, 'url');
            if ($url === '') {
                return \Cms\Core\Http\Response::html('<p>Missing url.</p>');
            }
            [$job, $chapters] = $buildJob($url);
            $jobId = (string) $job['job_id'];
            $formalStatus = '<span class="tag">PluginDataStore only</span>';
            if ($formalRepo instanceof NovelRepository) {
                try {
                    $formalNovelId = $formalRepo->saveNovel($job + ['source_url' => $url, 'catalog_url' => $url]);
                    $job['formal_novel_id'] = $formalNovelId;
                    $formalStatus = '<span class="ok">正式小说已创建/复用：#' . $html((string) $formalNovelId) . '</span>';
                } catch (\Throwable $e) {
                    $job['formal_write_error'] = $e->getMessage();
                    $formalStatus = '<span class="fail">正式写入失败：' . $html($e->getMessage()) . '</span>';
                }
            }
            $stored = $persistJob($job, $chapters);
            $storePut('novels_local', $jobId, [
                'job_id' => $jobId,
                'formal_novel_id' => (int) ($job['formal_novel_id'] ?? 0),
                'title' => (string) ($job['title'] ?? ''),
                'author' => (string) ($job['author'] ?? ''),
                'description' => (string) ($job['description'] ?? ''),
                'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
                'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
                'catalog_url' => $url,
                'status' => (string) ($job['status'] ?? 'pending'),
                'chapter_count' => 0,
                'updated_at' => gmdate('c'),
            ]);
            $verified = $storeGet('novel_collector_jobs', $jobId) !== null && count($loadJobItems($jobId)) > 0;
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['stored' => $stored, 'verified' => $verified, 'job' => $job]);
            }
            $storageStatus = $verified ? '<span class="ok">已保存并验证</span>' : '<span class="fail">未验证到持久化，执行按钮将使用 URL 自动重建</span>';
            $body = '<h1>采集队列</h1><section class="panel"><p><strong>队列 ID：</strong>' . $html($jobId) . '</p><p><strong>书名：</strong>' . $html($job['title']) . '</p><p><strong>作者：</strong>' . $html($job['author']) . '</p><p><strong>章节任务：</strong>' . $html((string) $job['chapter_count']) . '</p><p><strong>状态：</strong><span class="tag">pending</span></p><p><strong>队列存储：</strong>' . $storageStatus . '</p><p><strong>正式内容：</strong>' . $formalStatus . '</p><p class="muted">可信官方版会写入正式 Novel / Volume / Chapter / Author 表；本地 API 版仅写 PluginDataStore。</p><a class="button" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . rawurlencode($url) . '&limit=50&auto=1">自动连续采集</a><a class="button secondary" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . rawurlencode($url) . '&limit=10">开始采集 10 章</a><a class="button secondary" href="/admin/novel-collector/jobs/status?job_id=' . rawurlencode($jobId) . '&url=' . rawurlencode($url) . '">查看状态</a><a class="button secondary" href="/admin/novel-collector/jobs/create?format=json&url=' . rawurlencode($url) . '">查看队列 JSON</a><a class="button secondary" href="/admin/novel-collector">返回</a></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说采集队列', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/jobs', static function ($request) use ($html, $pageShell, $storeAll, $normalizeStoredRow) {
            $rows = '';
            foreach ($storeAll('novel_collector_jobs') as $rowKey => $job) {
                $job = $normalizeStoredRow($job);
                if ($job === []) {
                    continue;
                }
                $jobId = (string) ($job['job_id'] ?? (is_string($rowKey) ? $rowKey : ''));
                if ($jobId === '') {
                    continue;
                }
                $linkUrl = rawurlencode((string) ($job['catalog_url'] ?? ''));
                $cursor = (string) ($job['resume_cursor'] ?? 0);
                $rows .= '<tr><td>' . $html($jobId) . '</td><td>' . $html((string) ($job['title'] ?? '')) . '</td><td>' . $html((string) ($job['status'] ?? '')) . '</td><td>' . $html($cursor) . ' / ' . $html((string) ($job['chapter_count'] ?? 0)) . '</td><td><a class="button secondary" href="/admin/novel-collector/jobs/status?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '">状态</a><a class="button" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '&limit=50&auto=1">自动连续</a><a class="button secondary" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '&limit=10">继续采集</a></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="5" class="muted">还没有队列。请先识别目录并创建采集队列。</td></tr>';
            }
            $body = '<h1>队列管理</h1><section class="panel"><a class="button secondary" href="/admin/novel-collector">返回</a></section><section class="panel"><table><tr><th>队列 ID</th><th>书名</th><th>状态</th><th>进度</th><th>操作</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说队列管理', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/jobs/status', static function ($request) use ($param, $html, $pageShell, $storeGet, $latestJob, $loadCollectedChapters, $buildJob, $persistJob, $notFoundPage) {
            $jobId = $param($request, 'job_id');
            $catalogUrl = $param($request, 'url', $param($request, 'catalog_url'));
            $job = $jobId !== '' ? $storeGet('novel_collector_jobs', $jobId) : $latestJob();
            if ($job === null && $catalogUrl !== '') {
                [$job, $chapters] = $buildJob($catalogUrl);
                $persistJob($job, $chapters);
            }
            $jobId = (string) ($job['job_id'] ?? $jobId);
            if ($job === null) {
                return \Cms\Core\Http\Response::html($notFoundPage('队列不存在', $catalogUrl));
            }
            $chapters = array_slice($loadCollectedChapters($jobId), -10);
            $rows = '';
            foreach ($chapters as $chapter) {
                $rows .= '<tr><td>' . $html((string) ($chapter['sort_order'] ?? '')) . '</td><td>' . $html((string) ($chapter['title'] ?? '')) . '</td><td>' . $html((string) ($chapter['word_count'] ?? 0)) . '</td><td>' . $html((string) ($chapter['content_hash'] ?? '')) . '</td></tr>';
            }
            $linkUrl = rawurlencode((string) ($job['catalog_url'] ?? $catalogUrl));
            $cursor = (string) ($job['resume_cursor'] ?? 0);
            $body = '<h1>队列状态</h1><section class="panel"><p><strong>队列 ID：</strong>' . $html($jobId) . '</p><p><strong>书名：</strong>' . $html((string) ($job['title'] ?? '')) . '</p><p><strong>状态：</strong><span class="tag">' . $html((string) ($job['status'] ?? 'unknown')) . '</span></p><p><strong>进度：</strong>' . $html($cursor) . ' / ' . $html((string) ($job['chapter_count'] ?? 0)) . '</p><p><strong>已采：</strong>' . $html((string) ($job['collected_count'] ?? 0)) . '，失败：' . $html((string) ($job['failed_count'] ?? 0)) . '</p><p class="fail">' . $html((string) ($job['last_error'] ?? '')) . '</p><a class="button" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '&limit=50&auto=1">自动连续采集</a><a class="button secondary" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '&limit=10">继续采集 10 章</a><a class="button secondary" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($cursor) . '&limit=50">采集 50 章</a><a class="button secondary" href="/admin/novel-collector/export.txt?job_id=' . rawurlencode($jobId) . '">TXT 导出</a><a class="button secondary" href="/admin/novel-collector/jobs">返回队列</a></section><section class="panel"><h2>最近采集章节</h2><table><tr><th>#</th><th>标题</th><th>字数</th><th>Hash</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说队列状态', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/jobs/run', static function ($request) use ($param, $html, $pageShell, $storeGet, $storePut, $latestJob, $loadJobItems, $buildJob, $persistJob, $notFoundPage, $fetchChapterWithRetry, $formalRepo) {
            $jobId = $param($request, 'job_id');
            $catalogUrl = $param($request, 'url', $param($request, 'catalog_url'));
            $limit = max(1, min(50, (int) $param($request, 'limit', '10')));
            $autoRun = $param($request, 'auto', '0') === '1';
            $job = $jobId !== '' ? $storeGet('novel_collector_jobs', $jobId) : $latestJob();
            $fallbackItems = [];
            if ($job === null && $catalogUrl !== '') {
                [$job, $chapters] = $buildJob($catalogUrl);
                $persistJob($job, $chapters);
                $fallbackItems = array_map(static fn (array $chapter): array => [
                    'title' => (string) ($chapter['title'] ?? ''),
                    'url' => (string) ($chapter['url'] ?? ''),
                    'source_chapter_id' => (string) ($chapter['source_chapter_id'] ?? $chapter['url'] ?? ''),
                    'source_book_id' => (string) ($chapter['source_book_id'] ?? ''),
                    'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                    'status' => 'pending',
                ], $chapters);
            }
            $jobId = (string) ($job['job_id'] ?? $jobId);
            if ($job === null) {
                return \Cms\Core\Http\Response::html($notFoundPage('队列不存在', $catalogUrl));
            }
            $items = $loadJobItems($jobId);
            if ($items === [] && $fallbackItems !== []) {
                $items = $fallbackItems;
            }
            if ($items === [] && $catalogUrl !== '') {
                [, $chapters] = $buildJob($catalogUrl);
                $persistJob($job, $chapters);
                $items = array_map(static fn (array $chapter): array => [
                    'title' => (string) ($chapter['title'] ?? ''),
                    'url' => (string) ($chapter['url'] ?? ''),
                    'source_chapter_id' => (string) ($chapter['source_chapter_id'] ?? $chapter['url'] ?? ''),
                    'source_book_id' => (string) ($chapter['source_book_id'] ?? ''),
                    'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                    'status' => 'pending',
                ], $chapters);
            }
            if ($items === []) {
                return \Cms\Core\Http\Response::html($notFoundPage('队列任务为空', $catalogUrl));
            }
            $formalNovelId = (int) ($job['formal_novel_id'] ?? 0);
            if ($formalRepo instanceof NovelRepository && $formalNovelId <= 0) {
                try {
                    $formalNovelId = $formalRepo->saveNovel($job + ['source_url' => (string) ($job['catalog_url'] ?? $catalogUrl), 'catalog_url' => (string) ($job['catalog_url'] ?? $catalogUrl)]);
                    $job['formal_novel_id'] = $formalNovelId;
                } catch (\Throwable $e) {
                    $job['formal_write_error'] = $e->getMessage();
                }
            }
            $cursor = max((int) ($job['resume_cursor'] ?? 0), (int) $param($request, 'cursor', '0'));
            $processed = [];
            $errors = [];
            $batchHashes = [];
            $lastPlaintext = '';
            $similarStreak = 0;
            $job['status'] = 'running';
            for ($i = 0; $i < $limit && $cursor < count($items); $i++, $cursor++) {
                $item = $items[$cursor];
                try {
                    usleep(180000);
                    $clean = $fetchChapterWithRetry($item);
                    $hash = (string) ($clean['hash'] ?? '');
                    if ($hash !== '' && isset($batchHashes[$hash])) {
                        throw new \RuntimeException('suspected_duplicate_content: current batch contains repeated chapter body.');
                    }
                    if ($formalRepo instanceof NovelRepository && $formalNovelId > 0 && $formalRepo->hasDuplicateChapterContent($formalNovelId, $hash, (int) ($item['sort_order'] ?? 0), (string) ($item['source_chapter_id'] ?? $item['url'] ?? ''))) {
                        throw new \RuntimeException('suspected_duplicate_content: content hash already exists for a different chapter.');
                    }
                    if ($lastPlaintext !== '' && ContentQualityAnalyzer::similarity($lastPlaintext, (string) ($clean['plaintext'] ?? '')) > 0.95) {
                        $similarStreak++;
                        if ($similarStreak >= 2) {
                            throw new \RuntimeException('suspected_duplicate_content: consecutive chapters are more than 95% similar.');
                        }
                    } else {
                        $similarStreak = 0;
                    }
                    $batchHashes[$hash] = true;
                    $lastPlaintext = (string) ($clean['plaintext'] ?? '');
                    $chapter = [
                        'job_id' => $jobId,
                        'novel_key' => $jobId,
                        'title' => (string) $item['title'],
                        'sort_order' => (int) $item['sort_order'],
                        'source_url' => (string) $item['url'],
                        'source_chapter_id' => (string) ($item['source_chapter_id'] ?? $item['url']),
                        'content' => $clean['html'],
                        'content_plaintext' => $clean['plaintext'],
                        'content_hash' => $clean['hash'],
                        'content_quality' => (string) ($clean['quality']['quality'] ?? 'ok'),
                        'word_count' => mb_strlen($clean['plaintext']),
                        'collected_at' => gmdate('c'),
                    ];
                    $storePut('novel_chapters_local', $jobId . '_' . (string) $chapter['sort_order'], $chapter);
                    $storePut('novel_chapter_index_local', $jobId . '_' . (string) $chapter['sort_order'], [
                        'job_id' => $jobId,
                        'formal_novel_id' => $formalNovelId,
                        'title' => $chapter['title'],
                        'sort_order' => $chapter['sort_order'],
                        'source_url' => $chapter['source_url'],
                        'content_hash' => $chapter['content_hash'],
                        'content_quality' => $chapter['content_quality'],
                        'word_count' => $chapter['word_count'],
                        'collected_at' => $chapter['collected_at'],
                    ]);
                    if ($formalRepo instanceof NovelRepository && $formalNovelId > 0) {
                        $chapter['formal_chapter_id'] = $formalRepo->saveChapter($formalNovelId, $item, $clean);
                    }
                    $processed[] = $chapter;
                    $job['collected_count'] = max((int) ($job['collected_count'] ?? 0), $cursor + 1);
                    unset($job['last_error']);
                } catch (\Throwable $e) {
                    $job['failed_count'] = (int) ($job['failed_count'] ?? 0) + 1;
                    $job['last_error'] = 'Chapter #' . ($cursor + 1) . ': ' . $e->getMessage();
                    $errors[] = $job['last_error'];
                    $storePut('novel_failed_chapters_local', $jobId . '_' . (string) ($cursor + 1), [
                        'job_id' => $jobId,
                        'formal_novel_id' => $formalNovelId,
                        'title' => (string) ($item['title'] ?? ''),
                        'sort_order' => (int) ($item['sort_order'] ?? ($cursor + 1)),
                        'source_url' => (string) ($item['url'] ?? ''),
                        'source_chapter_id' => (string) ($item['source_chapter_id'] ?? ''),
                        'error' => $e->getMessage(),
                        'failed_at' => gmdate('c'),
                    ]);
                    if (str_contains($e->getMessage(), 'suspected_duplicate_content')) {
                        $job['status'] = 'paused';
                        $job['last_error'] = $e->getMessage();
                        break;
                    }
                }
            }
            $job['resume_cursor'] = $cursor;
            $job['updated_at'] = gmdate('c');
            if (($job['status'] ?? '') !== 'paused') {
                $job['status'] = $cursor >= count($items) ? 'completed' : 'running';
            }
            $storePut('novel_collector_jobs', $jobId, $job);
            $storePut('novels_local', $jobId, [
                'job_id' => $jobId,
                'formal_novel_id' => $formalNovelId,
                'title' => (string) ($job['title'] ?? ''),
                'author' => (string) ($job['author'] ?? ''),
                'description' => (string) ($job['description'] ?? ''),
                'cover' => (string) ($job['cover'] ?? $job['cover_url'] ?? ''),
                'cover_url' => (string) ($job['cover_url'] ?? $job['cover'] ?? ''),
                'catalog_url' => (string) ($job['catalog_url'] ?? $catalogUrl),
                'status' => (string) ($job['status'] ?? 'serializing'),
                'chapter_count' => (int) ($job['collected_count'] ?? 0),
                'updated_at' => gmdate('c'),
            ]);
            if ($param($request, 'format') === 'json') {
                return \Cms\Core\Http\Response::json(['job' => $job, 'processed' => $processed, 'errors' => $errors, 'remaining' => max(0, count($items) - $cursor)]);
            }
            $rows = '';
            foreach ($processed as $chapter) {
                $rows .= '<tr><td>' . $html((string) $chapter['sort_order']) . '</td><td>' . $html($chapter['title']) . '</td><td>' . $html((string) $chapter['word_count']) . '</td></tr>';
            }
            $linkUrl = rawurlencode((string) ($job['catalog_url'] ?? $catalogUrl));
            $nextCursor = (string) ($job['resume_cursor'] ?? $cursor);
            $remaining = max(0, count($items) - $cursor);
            $nextRunUrl = '/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($nextCursor) . '&limit=' . $limit . '&auto=1';
            $autoContinue = $autoRun && $remaining > 0 ? '<p class="ok">自动连续采集中，下一批即将开始...</p><script>setTimeout(function(){location.href=' . json_encode($nextRunUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';},900);</script>' : '';
            $formalLine = $formalRepo instanceof NovelRepository ? '<p><strong>正式 Novel ID：</strong>' . ($formalNovelId > 0 ? $html((string) $formalNovelId) : '<span class="fail">' . $html((string) ($job['formal_write_error'] ?? '未写入')) . '</span>') . '</p>' : '<p><strong>正式内容：</strong><span class="tag">当前运行环境未开放 PDO</span></p>';
            $body = '<h1>队列执行</h1><section class="panel"><p><strong>本次采集：</strong>' . count($processed) . ' 章</p><p><strong>本次跳过失败：</strong>' . count($errors) . ' 章</p><p><strong>当前进度：</strong>' . $html($nextCursor) . ' / ' . $html((string) ($job['chapter_count'] ?? count($items))) . '</p><p><strong>剩余：</strong>' . $html((string) $remaining) . ' 章</p><p><strong>状态：</strong><span class="tag">' . $html((string) $job['status']) . '</span></p>' . $formalLine . $autoContinue . ($errors !== [] ? '<p class="fail">' . $html(implode('; ', $errors)) . '</p>' : '') . '<a class="button" href="' . $html($nextRunUrl) . '">自动连续采集</a><a class="button secondary" href="/admin/novel-collector/jobs/run?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($nextCursor) . '&limit=' . $limit . '">继续采集</a><a class="button secondary" href="/admin/novel-collector/jobs/status?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '&cursor=' . rawurlencode($nextCursor) . '">查看状态</a><a class="button secondary" href="/admin/novel-collector/novels">已采小说</a></section><section class="panel"><h2>本次章节</h2><table><tr><th>#</th><th>标题</th><th>字数</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说队列执行', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/novels', static function ($request) use ($html, $pageShell, $loadNovelSummaries) {
            $novels = $loadNovelSummaries();
            $rows = '';
            foreach ($novels as $novel) {
                $jobId = (string) ($novel['job_id'] ?? '');
                $linkUrl = rawurlencode((string) ($novel['catalog_url'] ?? ''));
                $rows .= '<tr><td>' . $html((string) ($novel['title'] ?? '')) . '</td><td>' . $html((string) ($novel['author'] ?? '')) . '</td><td>' . $html((string) ($novel['chapter_count'] ?? 0)) . '</td><td><a class="button" href="/admin/novel-collector/novels/show?job_id=' . rawurlencode($jobId) . '">目录</a><a class="button secondary" href="/admin/novel-collector/jobs/status?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '">队列</a><a class="button secondary" href="/admin/novel-collector/export.txt?job_id=' . rawurlencode($jobId) . '">TXT</a></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="4" class="muted">还没有可显示的已采小说。请先创建队列并至少采集一批章节。</td></tr>';
            }
            $body = '<h1>已采小说</h1><section class="panel"><a class="button secondary" href="/admin/novel-collector">返回</a></section><section class="panel"><table><tr><th>书名</th><th>作者</th><th>已采章节</th><th>操作</th></tr>' . $rows . '</table></section>';
            return \Cms\Core\Http\Response::html($pageShell('已采小说', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/novels/show', static function ($request) use ($param, $html, $pageShell, $loadNovelSummaries, $loadCollectedChapters, $loadCollectedChapterIndex) {
            $jobId = $param($request, 'job_id');
            $novels = $loadNovelSummaries();
            $novel = $novels[$jobId] ?? null;
            if ($novel === null) {
                return \Cms\Core\Http\Response::html($pageShell('小说不存在', '<h1>小说不存在</h1><section class="panel"><p class="muted">没有找到这个 job_id 对应的已采小说。</p><a class="button secondary" href="/admin/novel-collector/novels">返回已采小说</a></section>'));
            }
            $chapters = $loadCollectedChapterIndex($jobId);
            if ($chapters === []) {
                $chapters = array_map(static fn (array $chapter): array => [
                    'job_id' => $jobId,
                    'title' => (string) ($chapter['title'] ?? ''),
                    'sort_order' => (int) ($chapter['sort_order'] ?? 0),
                    'word_count' => (int) ($chapter['word_count'] ?? mb_strlen((string) ($chapter['content_plaintext'] ?? ''))),
                    'content_hash' => (string) ($chapter['content_hash'] ?? ''),
                ], $loadCollectedChapters($jobId));
            }
            $cards = '';
            foreach ($chapters as $chapter) {
                $sort = (string) (int) ($chapter['sort_order'] ?? 0);
                $cards .= '<a class="chapter-card" href="/admin/novel-collector/novels/chapter?job_id=' . rawurlencode($jobId) . '&chapter=' . rawurlencode($sort) . '">' . $html((string) ($chapter['title'] ?? '未命名章节')) . '<small>#' . $html($sort) . ' · ' . $html((string) ($chapter['word_count'] ?? 0)) . ' 字</small></a>';
            }
            if ($cards === '') {
                $cards = '<p class="muted">还没有可阅读章节，请先回队列继续采集。</p>';
            }
            $linkUrl = rawurlencode((string) ($novel['catalog_url'] ?? ''));
            $body = '<h1>' . $html((string) ($novel['title'] ?? $jobId)) . '</h1><section class="panel"><p><strong>作者：</strong>' . $html((string) ($novel['author'] ?? '')) . '</p><p><strong>已采章节：</strong>' . $html((string) count($chapters)) . '</p><a class="button" href="/admin/novel-collector/jobs/status?job_id=' . rawurlencode($jobId) . '&url=' . $linkUrl . '">队列状态</a><a class="button secondary" href="/admin/novel-collector/export.txt?job_id=' . rawurlencode($jobId) . '">TXT 导出</a><a class="button secondary" href="/admin/novel-collector/novels">返回列表</a></section><section class="panel"><h2>章节目录</h2><div class="chapter-grid">' . $cards . '</div></section>';
            return \Cms\Core\Http\Response::html($pageShell('小说章节目录', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/novels/chapter', static function ($request) use ($param, $html, $pageShell, $storeGet, $loadNovelSummaries, $loadCollectedChapters) {
            $jobId = $param($request, 'job_id');
            $sort = max(1, (int) $param($request, 'chapter', '1'));
            $chapter = $storeGet('novel_chapters_local', $jobId . '_' . (string) $sort);
            if ($chapter === null) {
                foreach ($loadCollectedChapters($jobId) as $candidate) {
                    if ((int) ($candidate['sort_order'] ?? 0) === $sort) {
                        $chapter = $candidate;
                        break;
                    }
                }
            }
            if ($chapter === null) {
                return \Cms\Core\Http\Response::html($pageShell('章节不存在', '<h1>章节不存在</h1><section class="panel"><p class="muted">这个章节还没有采集到本地。</p><a class="button secondary" href="/admin/novel-collector/novels/show?job_id=' . rawurlencode($jobId) . '">返回目录</a></section>'));
            }
            $novels = $loadNovelSummaries();
            $novel = $novels[$jobId] ?? ['title' => $jobId];
            $prev = $sort > 1 ? '<a class="button ghost" href="/admin/novel-collector/novels/chapter?job_id=' . rawurlencode($jobId) . '&chapter=' . rawurlencode((string) ($sort - 1)) . '">上一章</a>' : '';
            $next = '<a class="button ghost" href="/admin/novel-collector/novels/chapter?job_id=' . rawurlencode($jobId) . '&chapter=' . rawurlencode((string) ($sort + 1)) . '">下一章</a>';
            $content = (string) ($chapter['content'] ?? '');
            if ($content === '') {
                $content = implode('', array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', preg_split('/\R+/u', (string) ($chapter['content_plaintext'] ?? '')) ?: []));
            }
            $body = '<h1>' . $html((string) ($chapter['title'] ?? '未命名章节')) . '</h1><section class="panel"><p><strong>书名：</strong>' . $html((string) ($novel['title'] ?? $jobId)) . '</p><p><strong>章节：</strong>#' . $html((string) $sort) . ' · ' . $html((string) ($chapter['word_count'] ?? 0)) . ' 字</p>' . $prev . $next . '<a class="button secondary" href="/admin/novel-collector/novels/show?job_id=' . rawurlencode($jobId) . '">返回目录</a></section><section class="panel reader">' . $content . '</section>';
            return \Cms\Core\Http\Response::html($pageShell('小说章节阅读', $body));
        }, 'novel_collector.manage', false);
        $context->adminRoute('GET', '/admin/novel-collector/export.txt', static function ($request) use ($param, $sendTxtDownload) {
            return $sendTxtDownload($param($request, 'job_id'));
        }, 'novel_collector.export', false);
    }

    if (method_exists($context, 'data')) {
        $context->data()->put('api', 'novel_core_contract', [
            'entities' => ['Novel', 'Volume', 'Chapter', 'Author', 'Bookshelf', 'ReadingProgress'],
            'collector_priority' => ['adapter', 'auto_detector', 'saved_site_rule', 'manual_css_xpath'],
            'preflight_required' => ['first_chapter', 'middle_random_chapter', 'latest_chapter'],
            'incremental_revision_window' => 10,
            'uninstall_policy' => 'retain_formal_content',
        ]);
    }

    unset($sanitizer, $queue, $txt);
};

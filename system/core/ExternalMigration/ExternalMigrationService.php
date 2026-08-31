<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

use Cms\Core\Content\ContentRepository;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\UrlMapping\UrlMappingRepository;
use PDO;
use Throwable;
use ZipArchive;

final class ExternalMigrationService
{
    /** @param list<MigrationAdapterInterface> $adapters */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentRepository $content,
        private readonly UrlMappingRepository $urlMap,
        private readonly MigrationRepository $repo,
        private readonly array $adapters,
        private readonly ?MediaLibrary $media = null,
    ) {
    }

    public static function defaultAdapters(): array
    {
        return [
            new ZBlogMigrationAdapter(),
            new WordPressMigrationAdapter(),
            new TypechoMigrationAdapter(),
            new EmlogMigrationAdapter(),
            new HaloMigrationAdapter(),
        ];
    }

    /** @return array<string,mixed> */
    public function scanFile(string $path, string $sourceHint = ''): array
    {
        $realPath = realpath($path);
        if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
            throw new MigrationException('迁移文件不存在或不可读取。');
        }
        $isZip = str_ends_with(strtolower($realPath), '.zip');
        $maxSize = $isZip ? 536870912 : 52428800;
        $size = filesize($realPath);
        if (!is_int($size) || $size <= 0 || $size > $maxSize) {
            throw new MigrationException($isZip ? '迁移包为空或超过 512MB。' : '迁移文件为空或超过 50MB。');
        }
        $payload = file_get_contents($realPath);
        if (!is_string($payload)) {
            throw new MigrationException('迁移文件读取失败。');
        }

        if ($isZip) {
            try {
                $package = (new MigrationPackageReader())->read($realPath);
                $scan = $this->scanPackage($package, $payload);
                $adapterId = 'migration-package';
            } catch (MigrationException $exception) {
                $archive = $this->migrationPayloadFromZipArchive($realPath);
                $adapter = $this->adapterFor($sourceHint, $archive['filename'], $archive['payload']);
                $scan = $adapter->scan($archive['filename'], $archive['payload']);
                $adapterId = $adapter->id();
            }
        } else {
            $adapter = $this->adapterFor($sourceHint, basename($realPath), $payload);
            $scan = $adapter->scan(basename($realPath), $payload);
            $adapterId = $adapter->id();
        }
        $jobId = $this->repo->createJob(
            $adapterId,
            (string) ($scan['source_system'] ?? $adapterId),
            (string) ($scan['source_site_id'] ?? $adapterId . ':' . substr(hash('sha256', $payload), 0, 16)),
            (string) ($scan['source_version'] ?? ''),
            basename($realPath),
            hash('sha256', $payload),
            $scan,
            $this->defaultMapping()
        );

        return ['job_id' => $jobId, 'adapter_id' => $adapterId, 'scan' => $scan];
    }

    /** @return array<string,mixed> */
    public function dryRunFile(int $jobId, string $path, string $sourceHint = ''): array
    {
        $package = $this->packageFromFile($path, $sourceHint);
        $result = [
            'contents' => count($package['contents'] ?? []),
            'media' => count($package['media'] ?? []),
            'redirects' => count($package['redirects'] ?? []),
            'warnings' => [],
        ];
        foreach (($package['contents'] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['title'] ?? '')) === '') {
                $result['warnings'][] = '发现标题为空的内容，正式迁移时会跳过。';
                continue;
            }
            $sourceId = (string) ($item['source_id'] ?? '');
            $system = (string) ($package['source_system'] ?? 'unknown');
            $siteId = (string) ($package['site']['source_site_id'] ?? $system . ':default');
            if ($sourceId !== '') {
                $this->repo->record($jobId, $system, $siteId, 'content', $sourceId, 'Pending', null, null, (string) ($item['source_url'] ?? '') ?: null);
            }
        }
        $this->repo->updateJob($jobId, 'DryRunReady', ['dry_run_json' => $result]);

        return $result;
    }

    /** @return array<string,mixed> */
    public function migrateFile(int $jobId, string $path, string $sourceHint = '', string $strategy = 'skip'): array
    {
        $strategy = in_array($strategy, ['skip', 'update', 'duplicate'], true) ? $strategy : 'skip';
        $package = $this->packageFromFile($path, $sourceHint);
        $system = (string) ($package['source_system'] ?? 'unknown');
        $siteId = (string) ($package['site']['source_site_id'] ?? $system . ':default');
        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'media_created' => 0, 'redirects' => 0, 'authors' => 0, 'comments_pending' => 0];
        $mediaUrlMap = $this->importMedia($jobId, $system, $siteId, $package, $report);
        $sanitizer = new MigrationHtmlSanitizer();

        $this->repo->updateJob($jobId, 'Running', ['strategy' => $strategy, 'started_at' => gmdate('c')]);
        $this->recordAuthors($jobId, $system, $siteId, $package, $report);
        foreach (($package['contents'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceId = (string) ($item['source_id'] ?? '');
            try {
                $title = trim((string) ($item['title'] ?? ''));
                if ($sourceId === '' || $title === '') {
                    throw new MigrationException('内容缺少 source_id 或标题。');
                }
                $existing = $this->repo->findRecord($system, $siteId, 'content', $sourceId);
                if ($existing !== null && $strategy === 'skip' && (int) ($existing['target_id'] ?? 0) > 0) {
                    $report['skipped']++;
                    continue;
                }

                $type = in_array((string) ($item['type'] ?? 'article'), ['article', 'page'], true) ? (string) $item['type'] : 'article';
                $status = in_array((string) ($item['status'] ?? 'draft'), ['draft', 'published'], true) ? (string) $item['status'] : 'draft';
                $slug = $this->slug((string) ($item['slug'] ?? ''), $title, $sourceId);
                if ($strategy === 'duplicate') {
                    $slug .= '-' . substr(hash('sha256', $sourceId . microtime(true)), 0, 6);
                }
                $html = $sanitizer->sanitize((string) ($item['content_html'] ?? ''), $mediaUrlMap);
                $blocks = [['type' => 'html', 'data' => ['html' => $html]]];
                $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                $meta['excerpt'] = (string) ($item['excerpt'] ?? '');
                $meta['source_platform'] = $system;
                $meta['source_id'] = $sourceId;
                $categories = $this->stringList($item['categories'] ?? []);
                $tags = $this->stringList($item['tags'] ?? []);

                if ($existing !== null && $strategy === 'update' && (int) ($existing['target_id'] ?? 0) > 0) {
                    $targetId = (int) $existing['target_id'];
                    $this->content->update($targetId, $type, $title, $slug, $blocks, $status, $meta, $categories, $tags);
                    $report['updated']++;
                } else {
                    $targetId = $this->content->create($type, $title, $slug, $blocks, $status, $meta, $categories, $tags);
                    $this->applySourceTimestamps($targetId, $item);
                    $report['created']++;
                }

                $targetUrl = $this->targetUrl($type, $slug, $targetId);
                $sourceUrl = trim((string) ($item['source_url'] ?? ''));
                if ($sourceUrl !== '') {
                    $this->recordUrlMapping($sourceUrl, $targetUrl, $system);
                    $report['redirects']++;
                }
                $this->repo->record($jobId, $system, $siteId, 'content', $sourceId, 'Imported', 'content', $targetId, $sourceUrl !== '' ? $sourceUrl : null, $targetUrl, ['type' => $type]);
            } catch (Throwable $exception) {
                $report['failed']++;
                $this->repo->record($jobId, $system, $siteId, 'content', $sourceId !== '' ? $sourceId : 'invalid-' . $report['failed'], 'Failed', null, null, null, null, [], 'content_import_failed', $exception->getMessage());
            }
        }
        $this->recordPackageRedirects($jobId, $system, $siteId, $package, $report);
        $this->recordCommentsAsPendingFeature($jobId, $system, $siteId, $package, $report);
        $status = $report['failed'] > 0 ? 'CompletedWithErrors' : 'Completed';
        $this->repo->updateJob($jobId, $status, ['report_json' => $report, 'completed_at' => gmdate('c')]);

        return $report;
    }

    /** @return array<string,mixed> */
    public function retryFailedFile(int $jobId, string $path, string $sourceHint = ''): array
    {
        return $this->migrateFile($jobId, $path, $sourceHint, 'update');
    }

    /** @return array<string,mixed> */
    public function resumeFile(int $jobId, string $path, string $sourceHint = '', string $strategy = 'skip'): array
    {
        $this->repo->updateJob($jobId, 'Resumed', ['started_at' => gmdate('c')]);
        return $this->migrateFile($jobId, $path, $sourceHint, $strategy);
    }

    /** @return array<string,mixed> */
    public function rollback(int $jobId): array
    {
        $deleted = 0;
        $skipped = 0;
        foreach ($this->repo->recordsForJob($jobId, 1000) as $record) {
            if ((string) ($record['target_type'] ?? '') !== 'content' || (int) ($record['target_id'] ?? 0) <= 0 || (string) ($record['status'] ?? '') !== 'Imported') {
                $skipped++;
                continue;
            }
            try {
                $this->content->delete((int) $record['target_id']);
                $this->repo->record($jobId, (string) $record['source_system'], (string) $record['source_site_id'], (string) $record['source_type'], (string) $record['source_id'], 'RolledBack', (string) ($record['target_type'] ?? ''), (int) ($record['target_id'] ?? 0), (string) ($record['source_url'] ?? '') ?: null, (string) ($record['target_url'] ?? '') ?: null);
                $deleted++;
            } catch (Throwable $exception) {
                $this->repo->record($jobId, (string) $record['source_system'], (string) $record['source_site_id'], (string) $record['source_type'], (string) $record['source_id'], 'Failed', (string) ($record['target_type'] ?? ''), (int) ($record['target_id'] ?? 0), (string) ($record['source_url'] ?? '') ?: null, (string) ($record['target_url'] ?? '') ?: null, [], 'rollback_failed', $exception->getMessage());
            }
        }
        $report = ['deleted' => $deleted, 'skipped' => $skipped];
        $this->repo->updateJob($jobId, 'RolledBack', ['report_json' => $report, 'completed_at' => gmdate('c')]);

        return $report;
    }

    /** @return array<string,mixed> */
    private function packageFromFile(string $path, string $sourceHint): array
    {
        $realPath = realpath($path);
        if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
            throw new MigrationException('迁移文件不存在或不可读取。');
        }
        if (str_ends_with(strtolower($realPath), '.zip')) {
            try {
                return (new MigrationPackageReader())->read($realPath);
            } catch (MigrationException) {
                $archive = $this->migrationPayloadFromZipArchive($realPath);
                return $this->adapterFor($sourceHint, $archive['filename'], $archive['payload'])->toPackage($archive['filename'], $archive['payload']);
            }
        }
        $payload = file_get_contents($realPath);
        if (!is_string($payload)) {
            throw new MigrationException('迁移文件读取失败。');
        }

        return $this->adapterFor($sourceHint, basename($realPath), $payload)->toPackage(basename($realPath), $payload);
    }

    /** @return array{filename:string,payload:string} */
    private function migrationPayloadFromZipArchive(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new MigrationException('当前服务器未启用 PHP ZipArchive 扩展，无法读取 ZIP 迁移文件。');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new MigrationException('ZIP 迁移文件打开失败。');
        }
        try {
            if ($zip->numFiles <= 0 || $zip->numFiles > 20000) {
                throw new MigrationException('ZIP 迁移文件数量不在允许范围内。');
            }
            $payload = '';
            $xmlPayload = '';
            $jsonPayload = '';
            $personalDataExport = str_starts_with(strtolower(basename($zipPath)), 'wp-personal-data-file-');
            $sqlFiles = 0;
            $totalBytes = 0;
            $processedEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if (!$this->safeZipEntryName($entry)) {
                    throw new MigrationException('ZIP 迁移文件包含不安全路径。');
                }
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($extension, ['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'exe', 'dll', 'bat', 'cmd', 'com', 'scr'], true)) {
                    throw new MigrationException('ZIP 迁移文件包含不允许的可执行文件。');
                }
                if ($this->skipMigrationArchiveEntry($entry)) {
                    continue;
                }
                $entryKey = $this->normalizedZipEntryKey($entry);
                if (isset($processedEntries[$entryKey])) {
                    continue;
                }
                $processedEntries[$entryKey] = true;
                $size = (int) ($stat['size'] ?? 0);
                $totalBytes += $size;
                if ($totalBytes > 52428800) {
                    throw new MigrationException('ZIP 内 SQL 内容超过 50MB，已拒绝迁移。');
                }
                $content = $zip->getFromIndex($i);
                if (!is_string($content) || trim($content) === '') {
                    continue;
                }
                $entryExtension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if ($entryExtension === 'xml' && $this->looksLikeWordPressWxr($content)) {
                    $xmlPayload = $content;
                    continue;
                }
                if ($entryExtension === 'json') {
                    if ($this->looksLikeWordPressPersonalDataExport($entry, $content)) {
                        $personalDataExport = true;
                    } elseif ($jsonPayload === '') {
                        $jsonPayload = $content;
                    }
                    continue;
                }
                if ($entryExtension !== 'sql') {
                    continue;
                }
                $normalized = $this->normalizedSqlInserts($content);
                if ($normalized === '') {
                    continue;
                }
                $payload .= "\n-- source: " . basename($entry) . "\n" . $normalized;
                $sqlFiles++;
            }
            if ($xmlPayload !== '') {
                return ['filename' => basename($zipPath, '.zip') . '.xml', 'payload' => $xmlPayload];
            }
            if ($jsonPayload !== '') {
                foreach ($this->adapters as $adapter) {
                    if ($adapter->supports(basename($zipPath, '.zip') . '.json', $jsonPayload)) {
                        return ['filename' => basename($zipPath, '.zip') . '.json', 'payload' => $jsonPayload];
                    }
                }
            }
            if ($personalDataExport) {
                throw new MigrationException('这是 WordPress 个人数据导出包，不是站点内容导出包；它通常不包含文章、页面、分类或媒体。请在 WordPress 后台进入“工具 → 导出”，选择“所有内容”，下载 .xml 文件后上传。');
            }
            if ($sqlFiles === 0 || trim($payload) === '') {
                throw new MigrationException('ZIP 中没有可识别的旧站内容。请上传 WordPress WXR XML，或包含 Z-BlogPHP、WordPress、Typecho、Emlog、Halo 内容表的 SQL/ZIP 备份。');
            }

            return ['filename' => basename($zipPath, '.zip') . '.sql', 'payload' => $payload];
        } finally {
            $zip->close();
        }
    }

    private function skipMigrationArchiveEntry(string $entry): bool
    {
        if (str_ends_with($entry, '/')) {
            return true;
        }
        $base = basename($entry);
        if ($base === '' || str_starts_with($base, '._') || str_starts_with($entry, '__MACOSX/')) {
            return true;
        }

        return !in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), ['sql', 'xml', 'json'], true);
    }

    private function normalizedZipEntryKey(string $entry): string
    {
        return strtolower(ltrim(str_replace('\\', '/', $entry), './'));
    }

    private function safeZipEntryName(string $entry): bool
    {
        return $entry !== ''
            && !str_starts_with($entry, '/')
            && !str_contains($entry, '\\')
            && !str_contains($entry, "\0")
            && !str_contains($entry, '../')
            && !str_contains('/' . $entry, '/../')
            && !str_ends_with($entry, '/..');
    }

    private function normalizedSqlInserts(string $sql): string
    {
        $sql = ltrim($sql, "\xEF\xBB\xBF");
        $schemas = $this->sqlCreateTableColumns($sql);
        $result = '';
        foreach ($this->sqlInsertStatements($sql) as $statement) {
            if (preg_match('/^INSERT\s+INTO\s+`?([^`\s(]+)`?(?:\s*\(([^)]*)\))?\s+VALUES\s*(.*)$/is', $statement, $match) !== 1) {
                continue;
            }
            $table = trim((string) $match[1], "` \t\r\n");
            if (!$this->looksLikeSupportedCmsTable($table)) {
                continue;
            }
            $columnsSql = trim((string) ($match[2] ?? ''));
            $columns = $columnsSql !== ''
                ? array_map(static fn (string $column): string => trim($column, " `\t\r\n"), explode(',', $columnsSql))
                : ($schemas[strtolower($table)] ?? []);
            $columns = array_values(array_filter($columns, static fn (string $column): bool => $column !== ''));
            if ($columns === []) {
                continue;
            }
            $quotedColumns = implode(',', array_map(static fn (string $column): string => '`' . str_replace('`', '', $column) . '`', $columns));
            $result .= 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . $quotedColumns . ') VALUES ' . trim((string) $match[3]) . ";\n";
        }

        return $result;
    }

    /** @return list<string> */
    private function sqlInsertStatements(string $sql): array
    {
        $statements = [];
        $offset = 0;
        $length = strlen($sql);
        while (($start = stripos($sql, 'INSERT', $offset)) !== false) {
            $inString = false;
            $escape = false;
            for ($i = $start; $i < $length; $i++) {
                $char = $sql[$i];
                if ($inString) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }
                    if ($char === '\\') {
                        $escape = true;
                        continue;
                    }
                    if ($char === "'") {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === "'") {
                    $inString = true;
                    continue;
                }
                if ($char === ';') {
                    $statements[] = trim(substr($sql, $start, $i - $start));
                    $offset = $i + 1;
                    continue 2;
                }
            }
            break;
        }

        return $statements;
    }

    /** @return array<string,list<string>> */
    private function sqlCreateTableColumns(string $sql): array
    {
        $schemas = [];
        preg_match_all('/CREATE\s+TABLE\s+`?([^`\s(]+)`?\s*(.*?);/is', $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $table = strtolower(trim((string) $match[1], "` \t\r\n"));
            $columns = [];
            preg_match_all('/`([^`]+)`\s+(?:tinyint|smallint|mediumint|int|bigint|varchar|char|text|mediumtext|longtext|datetime|timestamp|date|time|decimal|float|double|blob|mediumblob|longblob|json)\b/i', (string) $match[2], $columnMatches);
            foreach ($columnMatches[1] ?? [] as $column) {
                $columns[] = (string) $column;
            }
            if ($table !== '' && $columns !== []) {
                $schemas[$table] = $columns;
            }
        }

        return $schemas;
    }

    private function looksLikeSupportedCmsTable(string $table): bool
    {
        $table = strtolower($table);
        foreach (['zbp_', 'typecho_', 'emlog_', 'wp_', 'halo_'] as $prefix) {
            if (str_contains($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeWordPressWxr(string $payload): bool
    {
        return str_contains($payload, '<rss') && (str_contains($payload, '<wp:wxr_version') || str_contains($payload, 'wordpress.org/export/'));
    }

    private function looksLikeWordPressPersonalDataExport(string $entry, string $payload): bool
    {
        if (basename($entry) !== 'export.json') {
            return false;
        }
        $lower = strtolower($payload);
        return str_contains($payload, '个人数据导出')
            || str_contains($lower, 'personal data export')
            || str_contains($payload, '报告生成者')
            || str_contains($lower, 'export report');
    }

    /** @return array<string,mixed> */
    private function scanPackage(array $package, string $payload): array
    {
        return [
            'source_system' => (string) ($package['source_system'] ?? 'migration-package'),
            'source_version' => (string) ($package['source_version'] ?? ''),
            'source_site_id' => (string) ($package['site']['source_site_id'] ?? 'package:' . substr(hash('sha256', $payload), 0, 16)),
            'counts' => [
                'users' => count($package['users'] ?? []),
                'categories' => count($package['categories'] ?? []),
                'tags' => count($package['tags'] ?? []),
                'contents' => count($package['contents'] ?? []),
                'media' => count($package['media'] ?? []),
                'comments' => count($package['comments'] ?? []),
                'redirects' => count($package['redirects'] ?? []),
            ],
        ];
    }

    private function adapterFor(string $sourceHint, string $filename, string $payload): MigrationAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($sourceHint !== '' && $adapter->id() === $sourceHint) {
                return $adapter;
            }
            if ($adapter->supports($filename, $payload)) {
                return $adapter;
            }
        }

        throw new MigrationException('暂不支持这个迁移来源。');
    }

    /** @param array<string,mixed> $package @param array<string,int> $report @return array<string,string> */
    private function importMedia(int $jobId, string $system, string $siteId, array $package, array &$report): array
    {
        $map = [];
        if ($this->media === null) {
            return $map;
        }
        foreach (($package['media'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceId = (string) ($item['source_id'] ?? $item['id'] ?? '');
            $sourceUrl = trim((string) ($item['source_url'] ?? $item['url'] ?? ''));
            $localPath = trim((string) ($item['local_path'] ?? $item['path'] ?? ''));
            if ($sourceId === '') {
                $sourceId = $sourceUrl !== '' ? substr(hash('sha256', $sourceUrl), 0, 16) : substr(hash('sha256', $localPath), 0, 16);
            }
            try {
                if ($sourceUrl !== '' && !$this->safeImportSourceUrl($sourceUrl)) {
                    throw new MigrationException('媒体 URL 不安全，已拒绝。');
                }
                if ($localPath === '' || str_contains($localPath, "\0") || !is_file($localPath)) {
                    $this->repo->record($jobId, $system, $siteId, 'media', $sourceId, 'Skipped', null, null, $sourceUrl !== '' ? $sourceUrl : null, null, [], 'media_not_localized', '远程媒体未自动抓取，请先下载到本地包后再迁移。');
                    continue;
                }
                $mediaId = $this->media->registerLocalFile($localPath, $this->mediaOriginalName($item, $sourceUrl, $localPath));
                $targetUrl = '/media/' . $mediaId;
                if ($sourceUrl !== '') {
                    $map[$sourceUrl] = $targetUrl;
                }
                $report['media_created']++;
                $this->repo->record($jobId, $system, $siteId, 'media', $sourceId, 'Imported', 'media', $mediaId, $sourceUrl !== '' ? $sourceUrl : null, $targetUrl);
            } catch (MediaException|MigrationException $exception) {
                $this->repo->record($jobId, $system, $siteId, 'media', $sourceId, 'Failed', null, null, $sourceUrl !== '' ? $sourceUrl : null, null, [], 'media_import_failed', $exception->getMessage());
            }
        }

        return $map;
    }

    /** @param array<string,mixed> $item */
    private function mediaOriginalName(array $item, string $sourceUrl, string $localPath): string
    {
        foreach (['original_name', 'filename', 'name', 'title'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                if ($extension === '') {
                    $localExtension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
                    if ($localExtension !== '') {
                        $value .= '.' . $localExtension;
                    }
                }
                return $value;
            }
        }

        if ($sourceUrl !== '') {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            $basename = is_string($path) ? basename(rawurldecode($path)) : '';
            if ($basename !== '' && $basename !== '/' && pathinfo($basename, PATHINFO_EXTENSION) !== '') {
                return $basename;
            }
        }

        return basename($localPath);
    }

    /** @param array<string,mixed> $package @param array<string,int> $report */
    private function recordAuthors(int $jobId, string $system, string $siteId, array $package, array &$report): void
    {
        foreach (($package['users'] ?? []) as $user) {
            if (!is_array($user)) {
                continue;
            }
            $sourceId = (string) ($user['source_id'] ?? $user['id'] ?? $user['uid'] ?? $user['ID'] ?? $user['user_id'] ?? $user['mem_ID'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $metadata = [
                'display_name' => (string) ($user['display_name'] ?? $user['name'] ?? $user['mem_Name'] ?? ''),
                'email_hash' => ($user['email'] ?? $user['mem_Email'] ?? '') !== '' ? hash('sha256', strtolower((string) ($user['email'] ?? $user['mem_Email'] ?? ''))) : '',
                'note' => '作者元信息已记录；密码、哈希和会话不会迁入。',
            ];
            $this->repo->record($jobId, $system, $siteId, 'author', $sourceId, 'Imported', 'author_metadata', null, null, null, $metadata);
            $report['authors']++;
        }
    }

    /** @param array<string,mixed> $package @param array<string,int> $report */
    private function recordCommentsAsPendingFeature(int $jobId, string $system, string $siteId, array $package, array &$report): void
    {
        foreach (($package['comments'] ?? []) as $comment) {
            if (!is_array($comment)) {
                continue;
            }
            $sourceId = (string) ($comment['source_id'] ?? $comment['id'] ?? $comment['coid'] ?? $comment['cid'] ?? $comment['comment_id'] ?? $comment['comm_ID'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $metadata = [
                'content_source_id' => (string) ($comment['content_source_id'] ?? $comment['post_id'] ?? $comment['comm_LogID'] ?? ''),
                'author' => (string) ($comment['author'] ?? $comment['comm_Name'] ?? ''),
                'status' => (string) ($comment['status'] ?? $comment['comm_IsChecking'] ?? ''),
                'note' => 'Core 暂无评论模块，评论已保留为 pending_feature 记录。',
            ];
            $this->repo->record($jobId, $system, $siteId, 'comment', $sourceId, 'PendingFeature', null, null, null, null, $metadata, 'pending_feature', 'Core 暂无评论模块，暂不写入前台评论。');
            $report['comments_pending']++;
        }
    }

    /** @param array<string,mixed> $package @param array<string,int> $report */
    private function recordPackageRedirects(int $jobId, string $system, string $siteId, array $package, array &$report): void
    {
        foreach (($package['redirects'] ?? []) as $redirect) {
            if (!is_array($redirect)) {
                continue;
            }
            $sourceUrl = trim((string) ($redirect['source_url'] ?? $redirect['from'] ?? ''));
            $targetUrl = trim((string) ($redirect['target_url'] ?? $redirect['to'] ?? ''));
            if ($sourceUrl === '' || $targetUrl === '') {
                continue;
            }
            $sourceId = substr(hash('sha256', $sourceUrl . '>' . $targetUrl), 0, 24);
            try {
                $this->recordUrlMapping($sourceUrl, $targetUrl, $system);
                $this->repo->record($jobId, $system, $siteId, 'redirect', $sourceId, 'Imported', 'url_mapping', null, $sourceUrl, $targetUrl);
                $report['redirects']++;
            } catch (Throwable $exception) {
                $this->repo->record($jobId, $system, $siteId, 'redirect', $sourceId, 'Failed', null, null, $sourceUrl, $targetUrl, [], 'redirect_failed', $exception->getMessage());
            }
        }
    }

    private function slug(string $slug, string $title, string $sourceId): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            $slug = trim($title);
        }
        if ($slug === '') {
            $slug = 'content-' . $sourceId;
        }

        return $slug;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param array<string,mixed> $item */
    private function applySourceTimestamps(int $targetId, array $item): void
    {
        $publishedAt = (string) ($item['published_at'] ?? '');
        $updatedAt = (string) ($item['updated_at'] ?? '');
        if ($publishedAt === '' && $updatedAt === '') {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE cms_contents SET published_at = COALESCE(:published_at, published_at), updated_at = COALESCE(:updated_at, updated_at) WHERE id = :id');
        $stmt->execute([
            ':id' => $targetId,
            ':published_at' => $publishedAt !== '' ? $publishedAt : null,
            ':updated_at' => $updatedAt !== '' ? $updatedAt : null,
        ]);
    }

    private function targetUrl(string $type, string $slug, int $id): string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '/content/' . $id;
        }

        return $type === 'article' ? '/articles/' . rawurlencode($slug) : '/' . rawurlencode($slug);
    }

    private function recordUrlMapping(string $sourceUrl, string $targetUrl, string $platform): void
    {
        $sources = [$sourceUrl];
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && !in_array($path, $sources, true)) {
            $sources[] = $path;
        }
        foreach ($sources as $source) {
            try {
                $this->urlMap->record($source, $targetUrl, 301, $platform);
            } catch (Throwable) {
            }
        }
    }

    private function safeImportSourceUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /** @return array<string,mixed> */
    private function defaultMapping(): array
    {
        return [
            'status' => ['published' => 'published', 'draft' => 'draft'],
            'type' => ['article' => 'article', 'page' => 'page'],
            'strategy' => 'skip',
        ];
    }
}

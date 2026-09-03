<?php

declare(strict_types=1);

use Cms\Core\Content\BlockSanitizer;
use Cms\Core\ExternalMigration\MigrationHtmlSanitizer;

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

$options = getopt('', [
    'zblog-root:',
    'cms-config::',
    'apply',
    'reset-existing',
    'copy-uploads',
    'help',
]);

if (isset($options['help']) || !isset($options['zblog-root'])) {
    fwrite(STDOUT, <<<TXT
Daiying CMS Z-BlogPHP direct importer

Usage:
  php scripts/import_zblog_mysql.php --zblog-root=/path/to/zblog [--cms-config=config/app.php] [--apply] [--reset-existing] [--copy-uploads]

Default mode is dry-run. Use --apply to write into the configured Daiying CMS database.

TXT);
    exit(isset($options['help']) ? 0 : 1);
}

$zblogRoot = rtrim((string) $options['zblog-root'], '/');
$zblogConfigPath = $zblogRoot . '/zb_users/c_option.php';
$cmsConfigPath = isset($options['cms-config'])
    ? (string) $options['cms-config']
    : dirname(__DIR__) . '/config/app.php';
$apply = isset($options['apply']);
$resetExisting = isset($options['reset-existing']);
$copyUploads = isset($options['copy-uploads']);

if (!is_file($zblogConfigPath)) {
    fail('Z-Blog config not found: ' . $zblogConfigPath);
}
if (!is_file($cmsConfigPath)) {
    fail('Daiying CMS config not found: ' . $cmsConfigPath);
}

$zblogConfig = require $zblogConfigPath;
$cmsConfig = require $cmsConfigPath;

if (!is_array($zblogConfig) || !is_array($cmsConfig)) {
    fail('Invalid config file.');
}

$zblog = zblogPdo($zblogConfig);
$cms = cmsPdo($cmsConfig);
$prefix = (string) ($zblogConfig['ZC_MYSQL_PRE'] ?? 'zbp_');
$now = gmdate('c');
$sanitizer = new MigrationHtmlSanitizer();

ensureTables($cms);

$categories = fetchAll($zblog, 'SELECT * FROM `' . $prefix . 'category` ORDER BY cate_ID ASC');
$tags = fetchAll($zblog, 'SELECT * FROM `' . $prefix . 'tag` ORDER BY tag_ID ASC');
$posts = fetchAll($zblog, 'SELECT * FROM `' . $prefix . 'post` ORDER BY log_ID ASC');
$comments = fetchAll($zblog, 'SELECT * FROM `' . $prefix . 'comment` ORDER BY comm_ID ASC');
$uploads = fetchAll($zblog, 'SELECT * FROM `' . $prefix . 'upload` ORDER BY ul_ID ASC');

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'zblog_root' => $zblogRoot,
    'posts' => count($posts),
    'categories' => count($categories),
    'tags' => count($tags),
    'comments' => count($comments),
    'uploads' => count($uploads),
    'created_contents' => 0,
    'updated_contents' => 0,
    'created_terms' => 0,
    'created_media' => 0,
    'created_comments' => 0,
    'skipped' => 0,
    'warnings' => [],
];

if (!$apply) {
    output($report);
    exit(0);
}

$cms->beginTransaction();
try {
    if ($resetExisting) {
        resetPreviousImport($cms);
    }

    $categoryTermIds = [];
    foreach ($categories as $category) {
        $name = trim((string) ($category['cate_Name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $termId = upsertTerm($cms, 'category', $name, (string) ($category['cate_Alias'] ?? ''), $now, $report);
        $categoryTermIds[(string) ($category['cate_ID'] ?? '')] = $termId;
    }

    $tagNames = [];
    foreach ($tags as $tag) {
        $id = (string) ($tag['tag_ID'] ?? '');
        $name = trim((string) ($tag['tag_Name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $tagNames[$id] = $name;
        upsertTerm($cms, 'tag', $name, (string) ($tag['tag_Alias'] ?? ''), $now, $report);
    }

    $contentIdsBySource = [];
    foreach ($posts as $post) {
        $sourceId = (string) ($post['log_ID'] ?? '');
        $title = trim((string) ($post['log_Title'] ?? ''));
        if ($sourceId === '' || $title === '') {
            $report['skipped']++;
            continue;
        }

        $existingId = findImportedContent($cms, $sourceId);
        $type = ((string) ($post['log_Type'] ?? '0')) === '1' ? 'page' : 'article';
        $status = ((string) ($post['log_Status'] ?? '0')) === '0' ? 'published' : 'draft';
        $slug = uniqueContentSlug($cms, $type, slug((string) ($post['log_Alias'] ?? ''), $title, $sourceId), $existingId);
        $html = $sanitizer->sanitize((string) ($post['log_Content'] ?? $post['log_Intro'] ?? ''));
        $blocks = [['type' => 'html', 'data' => ['html' => $html]]];
        $blocks = BlockSanitizer::sanitize($blocks, ['html']);
        $publishedAt = timestampToIso($post['log_PostTime'] ?? null) ?? $now;
        $updatedAt = timestampToIso($post['log_UpdateTime'] ?? null) ?? $publishedAt;
        $meta = [
            'excerpt' => (string) ($post['log_Intro'] ?? ''),
            'source_platform' => 'zblogphp',
            'source_id' => $sourceId,
            'zblog_alias' => (string) ($post['log_Alias'] ?? ''),
            'zblog_status' => (string) ($post['log_Status'] ?? ''),
            'zblog_type' => (string) ($post['log_Type'] ?? ''),
            'views' => (int) ($post['log_ViewNums'] ?? 0),
            'comments_count' => (int) ($post['log_CommNums'] ?? 0),
        ];

        if ($existingId > 0) {
            updateContent($cms, $existingId, $type, $title, $slug, $status, $blocks, $meta, $publishedAt, $updatedAt);
            $contentId = $existingId;
            $report['updated_contents']++;
        } else {
            $contentId = insertContent($cms, $type, $title, $slug, $status, $blocks, $meta, $publishedAt, $updatedAt);
            $report['created_contents']++;
        }
        $contentIdsBySource[$sourceId] = $contentId;

        replaceContentTerms($cms, $contentId, contentTerms($cms, $post, $categoryTermIds, $tagNames, $now, $report), $now);
    }

    foreach ($uploads as $upload) {
        $mediaId = importUploadIndex($cms, $zblogRoot, $upload, $now, $copyUploads, $report);
        if ($mediaId <= 0) {
            continue;
        }
    }

    $commentIdsBySource = [];
    foreach ($comments as $comment) {
        $sourceId = (string) ($comment['comm_ID'] ?? '');
        $contentSourceId = (string) ($comment['comm_LogID'] ?? '');
        if ($sourceId === '' || !isset($contentIdsBySource[$contentSourceId])) {
            continue;
        }
        $commentIdsBySource[$sourceId] = upsertComment($cms, $comment, $contentIdsBySource[$contentSourceId], $commentIdsBySource, $now);
        $report['created_comments']++;
    }

    $cms->commit();
} catch (Throwable $exception) {
    $cms->rollBack();
    fail($exception->getMessage());
}

output($report);

function zblogPdo(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $config['ZC_MYSQL_SERVER'],
        (int) ($config['ZC_MYSQL_PORT'] ?? 3306),
        (string) $config['ZC_MYSQL_NAME'],
        (string) ($config['ZC_MYSQL_CHARSET'] ?? 'utf8mb4')
    );

    return new PDO($dsn, (string) $config['ZC_MYSQL_USERNAME'], (string) $config['ZC_MYSQL_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function cmsPdo(array $config): PDO
{
    $db = is_array($config['database'] ?? null) ? $config['database'] : [];
    $dsn = (string) ($db['dsn'] ?? '');
    if ($dsn === '') {
        fail('Daiying CMS database DSN is empty.');
    }
    if (str_starts_with($dsn, 'sqlite:')) {
        $path = substr($dsn, 7);
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    return new PDO($dsn, (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function ensureTables(PDO $pdo): void
{
    foreach (['cms_contents', 'cms_terms', 'cms_content_terms', 'cms_media', 'cms_comments'] as $table) {
        if (!tableExists($pdo, $table)) {
            fail('Daiying CMS table is missing, run migrations first: ' . $table);
        }
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function fetchAll(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll();
}

function findImportedContent(PDO $pdo, string $sourceId): int
{
    $stmt = $pdo->prepare("SELECT id, meta_json FROM cms_contents WHERE meta_json LIKE :needle ORDER BY id ASC");
    $stmt->execute([':needle' => '%"source_platform":"zblogphp"%']);
    foreach ($stmt->fetchAll() as $row) {
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        if (is_array($meta) && (string) ($meta['source_id'] ?? '') === $sourceId) {
            return (int) $row['id'];
        }
    }
    return 0;
}

function insertContent(PDO $pdo, string $type, string $title, string $slug, string $status, array $blocks, array $meta, string $publishedAt, string $updatedAt): int
{
    $columns = tableColumns($pdo, 'cms_contents');
    $data = [
        'content_type' => $type,
        'title' => $title,
        'slug' => $slug,
        'status' => $status,
        'blocks_json' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $publishedAt,
        'updated_at' => $updatedAt,
        'published_at' => $status === 'published' ? $publishedAt : null,
    ];
    if (in_array('scheduled_at', $columns, true)) {
        $data['scheduled_at'] = null;
    }
    insertRow($pdo, 'cms_contents', $data);
    return (int) $pdo->lastInsertId();
}

function updateContent(PDO $pdo, int $id, string $type, string $title, string $slug, string $status, array $blocks, array $meta, string $publishedAt, string $updatedAt): void
{
    $stmt = $pdo->prepare(
        'UPDATE cms_contents SET content_type=:type, title=:title, slug=:slug, status=:status, blocks_json=:blocks, meta_json=:meta, updated_at=:updated_at, published_at=:published_at WHERE id=:id'
    );
    $stmt->execute([
        ':id' => $id,
        ':type' => $type,
        ':title' => $title,
        ':slug' => $slug,
        ':status' => $status,
        ':blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':updated_at' => $updatedAt,
        ':published_at' => $status === 'published' ? $publishedAt : null,
    ]);
}

function upsertTerm(PDO $pdo, string $taxonomy, string $name, string $slug, string $now, array &$report): int
{
    $slug = termSlug($slug, $name);
    $stmt = $pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy=:taxonomy AND slug=:slug LIMIT 1');
    $stmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    insertRow($pdo, 'cms_terms', [
        'taxonomy' => $taxonomy,
        'name' => $name,
        'slug' => $slug,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $report['created_terms']++;
    return (int) $pdo->lastInsertId();
}

function contentTerms(PDO $pdo, array $post, array $categoryTermIds, array $tagNames, string $now, array &$report): array
{
    $ids = [];
    $categoryId = (string) ($post['log_CateID'] ?? '');
    if (isset($categoryTermIds[$categoryId])) {
        $ids[] = $categoryTermIds[$categoryId];
    }
    if (preg_match_all('/\{(\d+)\}/', (string) ($post['log_Tag'] ?? ''), $matches)) {
        foreach ($matches[1] as $tagId) {
            $name = $tagNames[(string) $tagId] ?? '';
            if ($name === '') {
                continue;
            }
            $ids[] = upsertTerm($pdo, 'tag', $name, '', $now, $report);
        }
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

function replaceContentTerms(PDO $pdo, int $contentId, array $termIds, string $now): void
{
    $pdo->prepare('DELETE FROM cms_content_terms WHERE content_id=:content_id')->execute([':content_id' => $contentId]);
    foreach ($termIds as $termId) {
        $stmt = $pdo->prepare('INSERT INTO cms_content_terms (content_id, term_id, created_at) VALUES (:content_id, :term_id, :created_at)');
        $stmt->execute([':content_id' => $contentId, ':term_id' => $termId, ':created_at' => $now]);
    }
}

function importUploadIndex(PDO $pdo, string $zblogRoot, array $upload, string $now, bool $copyUploads, array &$report): int
{
    $name = (string) ($upload['ul_Name'] ?? '');
    if ($name === '') {
        return 0;
    }
    $year = gmdate('Y', (int) ($upload['ul_PostTime'] ?? time()));
    $month = gmdate('m', (int) ($upload['ul_PostTime'] ?? time()));
    $relativePath = 'zb_users/upload/' . $year . '/' . $month . '/' . $name;
    $sourcePath = $zblogRoot . '/' . $relativePath;
    if (!is_file($sourcePath)) {
        $report['warnings'][] = 'Upload file missing: ' . $relativePath;
    }
    $targetRelativePath = $relativePath;
    if ($copyUploads && is_file($sourcePath)) {
        $targetRelativePath = 'uploads/zblog/' . $year . '/' . $month . '/' . $name;
        $targetPath = dirname(__DIR__) . '/public/' . $targetRelativePath;
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }
        copy($sourcePath, $targetPath);
    }
    $hash = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : hash('sha256', 'zblog-upload:' . ($upload['ul_ID'] ?? '') . ':' . $targetRelativePath);
    if (mediaExists($pdo, $hash, $targetRelativePath)) {
        return 0;
    }
    $mime = (string) ($upload['ul_MimeType'] ?? 'application/octet-stream');
    insertMedia($pdo, [
        'storage_provider' => $copyUploads ? 'local' : 'legacy_zblog',
        'media_type' => mediaType($mime),
        'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
        'original_name' => (string) ($upload['ul_SourceName'] ?? $name),
        'relative_path' => $targetRelativePath,
        'byte_size' => (int) ($upload['ul_Size'] ?? (is_file($sourcePath) ? filesize($sourcePath) : 0)),
        'sha256_hash' => $hash,
        'metadata_json' => json_encode([
            'source_platform' => 'zblogphp',
            'source_id' => (string) ($upload['ul_ID'] ?? ''),
            'legacy_relative_path' => $relativePath,
            'legacy_log_id' => (string) ($upload['ul_LogID'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => timestampToIso($upload['ul_PostTime'] ?? null) ?? $now,
        'updated_at' => $now,
        'storage_key' => $targetRelativePath,
        'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        'title' => (string) ($upload['ul_SourceName'] ?? $name),
        'status' => 'Active',
    ]);
    $report['created_media']++;
    return (int) $pdo->lastInsertId();
}

function mediaExists(PDO $pdo, string $hash, string $relativePath): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cms_media WHERE sha256_hash=:hash OR relative_path=:relative_path');
    $stmt->execute([':hash' => $hash, ':relative_path' => $relativePath]);
    return (int) $stmt->fetchColumn() > 0;
}

function insertMedia(PDO $pdo, array $data): void
{
    $columns = tableColumns($pdo, 'cms_media');
    $data = array_intersect_key($data, array_flip($columns));
    insertRow($pdo, 'cms_media', $data);
}

function upsertComment(PDO $pdo, array $comment, int $contentId, array $commentIdsBySource, string $now): int
{
    $sourceId = (string) ($comment['comm_ID'] ?? '');
    $stmt = $pdo->prepare('SELECT id FROM cms_comments WHERE body=:body AND content_id=:content_id AND author_name=:author_name LIMIT 1');
    $stmt->execute([
        ':body' => trim(strip_tags((string) ($comment['comm_Content'] ?? ''))),
        ':content_id' => $contentId,
        ':author_name' => trim(strip_tags((string) ($comment['comm_Name'] ?? ''))),
    ]);
    $existing = (int) $stmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }
    $parentSourceId = (string) ($comment['comm_ParentID'] ?? '');
    insertRow($pdo, 'cms_comments', [
        'content_id' => $contentId,
        'parent_id' => $commentIdsBySource[$parentSourceId] ?? null,
        'user_id' => null,
        'author_name' => trim(strip_tags((string) ($comment['comm_Name'] ?? 'Z-Blog 用户'))) ?: 'Z-Blog 用户',
        'author_email' => filter_var((string) ($comment['comm_Email'] ?? ''), FILTER_VALIDATE_EMAIL) ? strtolower((string) $comment['comm_Email']) : null,
        'author_url' => filter_var((string) ($comment['comm_HomePage'] ?? ''), FILTER_VALIDATE_URL) ? (string) $comment['comm_HomePage'] : null,
        'body' => trim(strip_tags((string) ($comment['comm_Content'] ?? ''))),
        'status' => ((string) ($comment['comm_IsChecking'] ?? '0')) === '0' ? 'approved' : 'pending',
        'ip_hash' => hashNullable((string) ($comment['comm_IP'] ?? '')),
        'user_agent_hash' => hashNullable((string) ($comment['comm_Agent'] ?? '')),
        'created_at' => timestampToIso($comment['comm_PostTime'] ?? null) ?? $now,
        'updated_at' => $now,
    ]);
    return (int) $pdo->lastInsertId();
}

function insertRow(PDO $pdo, string $table, array $data): void
{
    $columns = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
}

function resetPreviousImport(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT id FROM cms_contents WHERE meta_json LIKE '%\"source_platform\":\"zblogphp\"%'");
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM cms_content_terms WHERE content_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cms_comments WHERE content_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cms_contents WHERE id=:id')->execute([':id' => $id]);
    }
    $pdo->exec("DELETE FROM cms_media WHERE metadata_json LIKE '%\"source_platform\":\"zblogphp\"%'");
}

function tableColumns(PDO $pdo, string $table): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
    }
    return array_map(static fn (array $row): string => (string) $row['Field'], $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
}

function slug(string $slug, string $title, string $sourceId): string
{
    $slug = trim($slug) !== '' ? trim($slug) : trim($title);
    $slug = trim(preg_replace('/[^\p{Han}a-zA-Z0-9_-]+/u', '-', $slug) ?: '', '-_');
    return $slug !== '' ? $slug : 'zblog-' . $sourceId;
}

function termSlug(string $slug, string $name): string
{
    return slug($slug, $name, substr(hash('sha256', $name), 0, 8));
}

function uniqueContentSlug(PDO $pdo, string $type, string $slug, int $existingId): string
{
    $base = $slug;
    $suffix = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM cms_contents WHERE content_type=:type AND slug=:slug LIMIT 1');
        $stmt->execute([':type' => $type, ':slug' => $slug]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0 || $id === $existingId) {
            return $slug;
        }
        $slug = $base . '-' . $suffix++;
    }
}

function mediaType(string $mime): string
{
    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    return 'file';
}

function timestampToIso(mixed $value): ?string
{
    $time = (int) $value;
    return $time > 0 ? gmdate('c', $time) : null;
}

function hashNullable(string $value): string
{
    return $value !== '' ? hash('sha256', $value) : '';
}

function output(array $report): void
{
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

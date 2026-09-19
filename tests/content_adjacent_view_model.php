<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Logging\FileLogger;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$root = sys_get_temp_dir() . '/daiying-content-adjacent-' . bin2hex(random_bytes(4));
mkdir($root . '/storage/logs', 0777, true);
mkdir($root . '/content/uploads', 0777, true);
$db = $root . '/cms.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000004_export_import_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_09_08_000004_content_foundation_safety.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/../system/migrations/' . $migrationFile;
    $migration->up($pdo);
}

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$olderId = $repo->create('article', 'Older Article', 'older-文章', [['type' => 'paragraph', 'data' => ['text' => 'older body']]], 'published', ['seo_description' => 'Older excerpt']);
$currentId = $repo->create('article', 'Current Article', 'current', [['type' => 'paragraph', 'data' => ['text' => 'current body']]], 'published');
$tieNewerId = $repo->create('article', 'Tie Newer Article', 'tie-newer', [['type' => 'paragraph', 'data' => ['text' => 'tie body']]], 'published');
$newerId = $repo->create('article', 'Newer Article', 'newer', [['type' => 'paragraph', 'data' => ['text' => 'newer body']]], 'published');
$repo->create('page', 'Published Page', 'page-one', [['type' => 'paragraph', 'data' => ['text' => 'page body']]], 'published');
$draftId = $repo->create('article', 'Draft Article', 'draft-one', [['type' => 'paragraph', 'data' => ['text' => 'draft body']]], 'draft');

$pdo->prepare('UPDATE cms_contents SET published_at = ? WHERE id = ?')->execute(['2026-01-01T00:00:00+00:00', $olderId]);
$pdo->prepare('UPDATE cms_contents SET published_at = ? WHERE id = ?')->execute(['2026-01-02T00:00:00+00:00', $currentId]);
$pdo->prepare('UPDATE cms_contents SET published_at = ? WHERE id = ?')->execute(['2026-01-02T00:00:00+00:00', $tieNewerId]);
$pdo->prepare('UPDATE cms_contents SET published_at = ? WHERE id = ?')->execute(['2026-01-03T00:00:00+00:00', $newerId]);
$pdo->prepare('UPDATE cms_contents SET published_at = ? WHERE id = ?')->execute(['2026-01-04T00:00:00+00:00', $draftId]);

$adjacent = $repo->adjacentPublishedArticles($currentId, '2026-01-02T00:00:00+00:00');
$check(($adjacent['previous']['id'] ?? 0) === $olderId, 'previous article is the nearest earlier published article');
$check(($adjacent['next']['id'] ?? 0) === $tieNewerId, 'next article uses id tie-breaker when published_at is equal');
$check($repo->adjacentPublishedArticles($olderId, '2026-01-01T00:00:00+00:00')['previous'] === null, 'earliest article has no previous item');
$check($repo->adjacentPublishedArticles($newerId, '2026-01-03T00:00:00+00:00')['next'] === null, 'latest article ignores newer draft content');

$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db, 'username' => '', 'password' => '', 'options' => []],
    'site' => ['name' => 'Adjacent Test'],
    'comments' => ['enabled' => false],
]);
$controller = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$reflection = new ReflectionMethod(ContentFrontController::class, 'viewModel');

$current = $repo->find($currentId);
$detail = $reflection->invoke($controller, $current, false, null, true);
$check(array_key_exists('previous', $detail) && array_key_exists('next', $detail), 'article detail ViewModel exposes previous and next keys');
$check(($detail['previous']['url'] ?? '') === '/articles/older-%E6%96%87%E7%AB%A0', 'previous URL follows article permalink encoding');
$check(($detail['previous']['content_type'] ?? '') === 'article' && ($detail['next']['content_type'] ?? '') === 'article', 'adjacent content_type is article');
$check(($detail['previous']['excerpt'] ?? '') === 'Older excerpt', 'adjacent optional excerpt can be exposed from content metadata');

$listItem = $reflection->invoke($controller, $current, false, null, false);
$check(!array_key_exists('previous', $listItem) && !array_key_exists('next', $listItem), 'list/search ViewModel contract is not changed by adjacent fields');

@unlink($db);
@unlink($root . '/storage/logs/app.log');
@rmdir($root . '/storage/logs');
@rmdir($root . '/storage');
@rmdir($root . '/content/uploads');
@rmdir($root . '/content');
@rmdir($root);

if ($failures > 0) {
    echo 'content_adjacent_view_model tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'content_adjacent_view_model tests PASS' . PHP_EOL;

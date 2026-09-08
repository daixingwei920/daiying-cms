<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Rest\ApiV1Controller;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$db = sys_get_temp_dir() . '/daiying-rest-v1-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_08_29_000001_members_comments_schema.php',
    '2026_09_08_000004_content_foundation_safety.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/../system/migrations/' . $migrationFile;
    $migration->up($pdo);
}
$now = gmdate('c');
$pdo->prepare('INSERT INTO cms_contents (content_type, title, slug, status, blocks_json, meta_json, created_at, updated_at, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['article', 'Hello REST', 'hello-rest', 'published', '[{"type":"paragraph","data":{"text":"Hello"}}]', '{"api_key":"sk_live_secret","description":"safe"}', $now, $now, $now]);
$pdo->prepare('INSERT INTO cms_contents (content_type, title, slug, status, blocks_json, meta_json, created_at, updated_at, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['page', 'About', 'about', 'published', '[]', '{}', $now, $now, $now]);
$pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['category', 'News', 'news', $now, $now]);
$pdo->prepare('INSERT INTO cms_terms (taxonomy, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['tag', 'CMS', 'cms', $now, $now]);
$pdo->prepare('INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, title, alt_text, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['local', 'image', 'image/png', 'demo.png', '2026/09/demo.png', '2026/09/demo.png', 12, hash('sha256', 'demo'), '{}', 'png', 'Demo', 'Demo alt', 'Active', $now, $now]);
$pdo->prepare('INSERT INTO cms_admin_users (email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['admin@example.com', password_hash('secret', PASSWORD_DEFAULT), 'Admin', $now, $now]);
$pdo->prepare('INSERT INTO cms_front_users (email, password_hash, display_name, status, created_at, updated_at, last_login_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute(['reader@example.com', password_hash('secret', PASSWORD_DEFAULT), 'Reader', 'active', $now, $now, $now]);
$pdo->prepare('INSERT INTO cms_comments (content_id, parent_id, user_id, author_name, author_email, author_url, body, status, ip_hash, user_agent_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([1, null, null, 'Alice', 'alice@example.com', null, 'Nice post', 'pending', '', '', $now, $now]);

$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db],
    'site' => ['name' => 'REST Site', 'url' => 'https://example.com'],
    'app' => ['version' => '1.2.29'],
    'api' => ['admin_token_sha256' => hash('sha256', 'rest-secret')],
]);
$controller = new ApiV1Controller($settings, dirname(__DIR__));

$index = json_decode($controller->index(new Request('GET', '/api/v1'))->body(), true);
$check(($index['ok'] ?? false) === true && ($index['data']['versions']['rest_api_version'] ?? '') === '1.0', 'REST index exposes API versions');
$check(($index['data']['resources']['comments'] ?? '') === '/api/v1/comments', 'REST index exposes comments resource');
$check(($index['data']['resources']['users'] ?? '') === '/api/v1/users', 'REST index exposes users resource');

$contents = json_decode($controller->contents(new Request('GET', '/api/v1/contents', ['type' => 'article']))->body(), true);
$check(($contents['ok'] ?? false) === true && ($contents['data']['items'][0]['title'] ?? '') === 'Hello REST', 'REST contents endpoint lists published articles');
$encoded = json_encode($contents, JSON_UNESCAPED_SLASHES) ?: '';
$check(!str_contains($encoded, 'sk_live_secret'), 'REST content serialization redacts secret-looking metadata');

$pages = json_decode($controller->contents(new Request('GET', '/api/v1/pages'))->body(), true);
$check(($pages['data']['items'][0]['type'] ?? '') === 'page', 'REST pages endpoint lists published pages');

$categories = json_decode($controller->categories(new Request('GET', '/api/v1/categories'))->body(), true);
$check(($categories['data']['items'][0]['slug'] ?? '') === 'news', 'REST categories endpoint lists categories');

$tags = json_decode($controller->tags(new Request('GET', '/api/v1/tags'))->body(), true);
$check(($tags['data']['items'][0]['slug'] ?? '') === 'cms', 'REST tags endpoint lists tags');

$media = json_decode($controller->media(new Request('GET', '/api/v1/media', ['type' => 'image']))->body(), true);
$check(($media['data']['items'][0]['filename'] ?? '') === 'demo.png', 'REST media endpoint lists active media');

$unauthorized = $controller->settings(new Request('GET', '/api/v1/settings'));
$unauthorizedBody = json_decode($unauthorized->body(), true);
$check($unauthorized->status() === 401 && ($unauthorizedBody['error']['code'] ?? '') === 'unauthorized', 'REST settings endpoint requires auth');

$authorized = json_decode($controller->settings(new Request('GET', '/api/v1/settings', [], [], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$check(($authorized['ok'] ?? false) === true && ($authorized['data']['site']['name'] ?? '') === 'REST Site', 'REST settings endpoint accepts configured Bearer token hash');

$jsonBody = Request::captureBody('POST', [], 'application/json', '{"title":"JSON Title","blocks":[]}');
$check(($jsonBody['title'] ?? '') === 'JSON Title', 'REST request parser accepts JSON bodies');

$unauthorizedWrite = $controller->contents(new Request('POST', '/api/v1/contents', [], ['title' => 'Nope']));
$unauthorizedWriteBody = json_decode($unauthorizedWrite->body(), true);
$check($unauthorizedWrite->status() === 401 && ($unauthorizedWriteBody['error']['code'] ?? '') === 'unauthorized', 'REST content writes require auth');

$created = $controller->contents(new Request('POST', '/api/v1/contents', [], [
    'title' => 'Created by REST',
    'slug' => 'created-by-rest',
    'status' => 'draft',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Draft']]],
    'meta' => ['description' => 'safe'],
    'categories' => ['News'],
    'tags' => ['API'],
], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']));
$createdBody = json_decode($created->body(), true);
$createdId = (int) ($createdBody['data']['item']['id'] ?? 0);
$check($created->status() === 201 && $createdId > 0, 'REST contents endpoint creates drafts with auth');

$updated = $controller->contents(new Request('PATCH', '/api/v1/contents/' . $createdId, [], [
    'title' => 'Updated by REST',
    'status' => 'published',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Published']]],
], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']));
$updatedBody = json_decode($updated->body(), true);
$check(($updatedBody['data']['item']['title'] ?? '') === 'Updated by REST', 'REST contents endpoint updates content with auth');

$single = json_decode($controller->contents(new Request('GET', '/api/v1/contents/' . $createdId))->body(), true);
$check(($single['data']['item']['status'] ?? '') === 'published', 'REST contents endpoint reads single published content');

$deleted = json_decode($controller->contents(new Request('DELETE', '/api/v1/contents/' . $createdId, [], [], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$check(($deleted['data']['status'] ?? '') === 'trash', 'REST contents endpoint deletes to trash by default');

$termCreated = json_decode($controller->categories(new Request('POST', '/api/v1/categories', [], ['name' => 'REST Category', 'slug' => 'rest-category'], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$termId = (int) ($termCreated['data']['item']['id'] ?? 0);
$check($termId > 0 && ($termCreated['data']['item']['slug'] ?? '') === 'rest-category', 'REST categories endpoint creates terms with auth');

$termUpdated = json_decode($controller->categories(new Request('PATCH', '/api/v1/categories/' . $termId, [], ['name' => 'REST Category Updated', 'slug' => 'rest-category-updated'], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$check(($termUpdated['data']['item']['name'] ?? '') === 'REST Category Updated', 'REST categories endpoint updates terms with auth');

$commentsUnauthorized = $controller->comments(new Request('GET', '/api/v1/comments'));
$check($commentsUnauthorized->status() === 401, 'REST comments endpoint requires auth');

$comments = json_decode($controller->comments(new Request('GET', '/api/v1/comments', ['status' => 'pending'], [], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$check(($comments['data']['items'][0]['author_email'] ?? '') === 'a***@example.com', 'REST comments endpoint redacts author email');

$commentUpdated = json_decode($controller->comments(new Request('PATCH', '/api/v1/comments/1', [], ['status' => 'approved'], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$check(($commentUpdated['data']['status'] ?? '') === 'approved', 'REST comments endpoint updates moderation status');

$users = json_decode($controller->users(new Request('GET', '/api/v1/users', [], [], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']))->body(), true);
$encodedUsers = json_encode($users, JSON_UNESCAPED_SLASHES) ?: '';
$check(str_contains($encodedUsers, 'a***@example.com') && str_contains($encodedUsers, 'r***@example.com'), 'REST users endpoint redacts user emails');
$check(!str_contains($encodedUsers, 'password_hash') && !str_contains($encodedUsers, 'secret'), 'REST users endpoint does not expose password hashes');

$mediaWrite = $controller->media(new Request('POST', '/api/v1/media', [], [], ['HTTP_AUTHORIZATION' => 'Bearer rest-secret']));
$mediaWriteBody = json_decode($mediaWrite->body(), true);
$check($mediaWrite->status() === 501 && ($mediaWriteBody['error']['code'] ?? '') === 'not_implemented', 'REST media upload remains explicitly not implemented');

echo "REST API v1 tests PASS\n";

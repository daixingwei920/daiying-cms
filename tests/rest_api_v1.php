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

$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db],
    'site' => ['name' => 'REST Site', 'url' => 'https://example.com'],
    'app' => ['version' => '1.2.29'],
    'api' => ['admin_token_sha256' => hash('sha256', 'rest-secret')],
]);
$controller = new ApiV1Controller($settings, dirname(__DIR__));

$index = json_decode($controller->index(new Request('GET', '/api/v1'))->body(), true);
$check(($index['ok'] ?? false) === true && ($index['data']['versions']['rest_api_version'] ?? '') === '1.0', 'REST index exposes API versions');

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

$write = $controller->contents(new Request('POST', '/api/v1/contents'));
$writeBody = json_decode($write->body(), true);
$check($write->status() === 501 && ($writeBody['error']['code'] ?? '') === 'not_implemented', 'REST write endpoints return stable not implemented response');

echo "REST API v1 tests PASS\n";

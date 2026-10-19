<?php

declare(strict_types=1);

define('TEST_SOURCE_ROOT', dirname(__DIR__));
define('CMS_ROOT', sys_get_temp_dir() . '/cms-editor-utf8-' . bin2hex(random_bytes(6)));
mkdir(CMS_ROOT, 0700);
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentException;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;

register_shutdown_function(static function (): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir(CMS_ROOT);
});
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "[PASS] $message\n";
};
$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . CMS_ROOT . '/fixture.sqlite'],
    'site' => ['url' => 'https://isolated.example', 'currency' => 'USD'],
]);
$pdo = ConnectionFactory::make($settings);
$migrations = array_map(static fn (string $path) => require $path, glob(TEST_SOURCE_ROOT . '/system/migrations/*.php'));
(new MigrationRunner($pdo, $migrations))->run();
$auth = new AdminAuthenticator($pdo);
$auth->createAdmin('editor@example.invalid', 'Synthetic-editor-test-only!', 'Fixture Owner');
$check($auth->attempt('editor@example.invalid', 'Synthetic-editor-test-only!', '127.0.0.1'), 'Native administrator authentication');
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults(), [], CMS_ROOT);
$id = $repo->create('article', 'Article 41 isolated fixture', 'article-41', [['type' => 'paragraph', 'data' => ['text' => 'Original']]]);
$pdo->exec('UPDATE cms_contents SET id = 41 WHERE id = ' . $id);
$png = CMS_ROOT . '/fixture.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII='));
$mediaId = (new MediaLibrary($pdo, CMS_ROOT . '/content/uploads'))->uploadLocalFile($png, 'fixture.png', 1);
$admin = new AdminController($settings, new FileLogger(CMS_ROOT . '/test.log'), CMS_ROOT);
$canonical = 'https://www.daiyingcms.com/articles/article-41';
$sentence = '插件包需要 manifest 校验。';
$second = 'English 与中文包装；无需替换汉字。';
$save = static function (array $blocks, string $url = '') use ($admin, $canonical) {
    return $admin->contentUpdate(new Request('POST', '/admin/content/edit/41', [], [
        '_csrf' => CsrfToken::get(), 'content_type' => 'article',
        'title' => 'Article 41 isolated fixture', 'slug' => 'article-41',
        'status' => 'draft', 'content_action' => 'save',
        'canonical_url' => $url !== '' ? $url : $canonical, 'blocks' => $blocks,
    ]));
};
foreach (['LF' => "\n", 'CRLF' => "\r\n", 'CR' => "\r", 'VT' => "\x0B", 'FF' => "\x0C", 'NEL' => "\u{0085}", 'LS' => "\u{2028}", 'PS' => "\u{2029}"] as $name => $newline) {
    $response = $save([
        ['type' => 'paragraph', 'data' => ['text' => $sentence]],
        ['type' => 'unordered_list', 'data' => ['items_text' => $sentence . $newline . $second]],
        ['type' => 'ordered_list', 'data' => ['items_text' => 'ASCII package' . $newline . $sentence]],
        ['type' => 'table', 'data' => ['rows_text' => '插件包 | manifest' . $newline . $second . ' | 包']],
        ['type' => 'gallery', 'data' => ['gallery_captions_text' => $mediaId . ' | ' . $sentence . $newline . $mediaId . ' | ' . $second]],
    ]);
    if ($response->status() !== 302) {
        fwrite(STDERR, 'Editor status: ' . $response->status() . "\n" . file_get_contents(CMS_ROOT . '/test.log'));
    }
    $check($response->status() === 302 && ($response->headers()['Location'] ?? '') === '/admin/content', "$name native editor update succeeds");
    $item = $repo->find(41);
    $blocks = $item['blocks'];
    $check($item['meta']['canonical_url'] === $canonical && $blocks[0]['data']['text'] === $sentence, "$name full HTTPS Canonical and paragraph persist");
    $check($blocks[1]['data']['items'] === [$sentence, $second] && $blocks[2]['data']['items'] === ['ASCII package', $sentence], "$name Chinese and ASCII lists retain exact items without phantom entries");
    $check($blocks[3]['data']['rows'] === [['插件包', 'manifest'], [$second, '包']], "$name table rows retain exact Chinese cells");
    $check(array_column($blocks[4]['data']['items'], 'caption') === [$sentence, $second], "$name gallery captions retain exact Chinese lines without phantom entries");
    $repo->update(41, 'article', 'Article 41 isolated fixture', 'article-41', [
        ['type' => 'unordered_list', 'data' => ['items' => $sentence . $newline . $second]],
    ], 'draft', ['canonical_url' => $canonical]);
    $check($repo->find(41)['blocks'][0]['data']['items'] === [$sentence, $second], "$name direct repository string-list contract preserves UTF-8");
}
foreach ([
    ['type' => 'unordered_list', 'data' => ['items_text' => "bad\x85text"]],
    ['type' => 'table', 'data' => ['rows_text' => "bad\x85text | value"]],
    ['type' => 'gallery', 'data' => ['gallery_captions_text' => $mediaId . " | bad\x85text"]],
] as $block) {
    $before = $repo->find(41);
    $response = $save([$block]);
    $check($response->status() === 422 && str_contains($response->body(), 'Content JSON payload is invalid.'), $block['type'] . ' invalid UTF-8 is rejected rather than erased');
    $check($repo->find(41) === $before, $block['type'] . ' invalid UTF-8 preserves saved article');
}
$before = $repo->find(41);
try {
    $repo->update(41, 'article', 'Invalid', 'article-41', [['type' => 'ordered_list', 'data' => ['items' => "bad\x85text"]]], 'draft');
    $check(false, 'Invalid direct string-list must fail');
} catch (ContentException $exception) {
    $check($exception->getMessage() === 'Content JSON payload is invalid.' && $repo->find(41) === $before, 'Invalid direct string-list is rejected without body changes');
}
$response = $save([['type' => 'paragraph', 'data' => ['text' => $sentence]]], '/articles/article-41');
$check($response->status() === 422 && $repo->find(41) === $before, 'Relative Canonical remains rejected without body changes');
$response = $admin->contentUpdate(new Request('POST', '/admin/content/edit/41', [], ['_csrf' => 'wrong']));
$check($response->status() === 400 && $repo->find(41) === $before, 'CSRF protection remains enforced');
$auth->logout();
$check($save([])->headers()['Location'] === '/admin/login' && $repo->find(41) === $before, 'Unauthenticated saves remain blocked');
echo "Content editor UTF-8 regression: $checks PASS\n";

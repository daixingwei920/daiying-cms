<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentException;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Import\ImportException;
use Cms\Core\Import\RemoteImageLocalizer;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\MediaStorageProviderInterface;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function media_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function media_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function media_copy_dir(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
        } else {
            copy((string) $item->getPathname(), $destination);
        }
    }
}

function media_write(string $path, string $content): string
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
    return $path;
}

function media_assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
        media_check(false, $message);
    } catch (MediaException|ContentException|ImportException) {
        media_check(true, $message);
    }
}

final class TestMediaStorageProvider implements MediaStorageProviderInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function id(): string
    {
        return 'test-local';
    }

    public function put(string $sourcePath, string $storageKey, bool $move): void
    {
        $target = $this->path($storageKey);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        $move ? rename($sourcePath, $target) : copy($sourcePath, $target);
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->path($storageKey));
    }

    public function path(string $storageKey): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($storageKey, '/');
    }
}

function media_png_1x1(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: '';
}

function media_png_huge_header(): string
{
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 50000, 50000, 8, 2, 0, 0, 0)) . $chunk('IEND', '');
}

function media_gd_image_bytes(string $extension, int $red = 24, int $green = 92, int $blue = 160, int $width = 4, int $height = 3): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD extension is required for image derivative tests.');
    }
    $image = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($image, $red, $green, $blue);
    imagefilledrectangle($image, 0, 0, $width, $height, $color);
    ob_start();
    if ($extension === 'jpg') {
        imagejpeg($image, null, 86);
    } elseif ($extension === 'png') {
        imagepng($image, null, 6);
    } elseif ($extension === 'avif') {
        imageavif($image, null, 45);
    } else {
        imagewebp($image, null, 82);
    }
    $bytes = (string) ob_get_clean();

    return $bytes;
}

function media_zip(string $path): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types></Types>');
    $zip->addFromString('word/document.xml', '<document/>');
    $zip->close();
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-media-batch4-' . bin2hex(random_bytes(4));
media_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'content/themes', 'content/plugins', 'content/uploads', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
media_copy_dir(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
media_copy_dir(CMS_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/media.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Media Site', 'url' => 'https://media.example.test', 'id' => 'media-site', 'secret' => 'media-secret'];
$config['theme'] = ['active' => 'default', 'settings' => ['default' => []]];
$config['media'] = ['max_files' => 5, 'max_file_bytes' => 1048576, 'max_total_bytes' => 2097152, 'quota_bytes' => 5242880, 'max_image_pixels' => 1000000];
media_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
file_put_contents($root . '/storage/installed.lock', '{}');

$library = new MediaLibrary($pdo, $root . '/content/uploads', $config['media']);
$tmp = $root . '/storage/tmp';
$png = media_write($tmp . '/photo.png', media_png_1x1());
$wav = media_write($tmp . '/sound.wav', "RIFF\x24\0\0\0WAVEfmt ");
$mp4 = media_write($tmp . '/clip.mp4', "\0\0\0\x18ftypmp42\0\0\0\0mp42isom");
$pdf = media_write($tmp . '/file.pdf', "%PDF-1.4\n%test\n");
media_zip($tmp . '/office.docx');

$imageId = $library->registerLocalFile($png, 'photo.png');
$audioId = $library->registerLocalFile($wav, 'sound.wav');
$videoId = $library->registerLocalFile($mp4, 'clip.mp4');
$pdfId = $library->registerLocalFile($pdf, 'file.pdf');
$officeId = $library->registerLocalFile($tmp . '/office.docx', 'office.docx');
media_check($imageId > 0 && $audioId > 0 && $videoId > 0 && $pdfId > 0 && $officeId > 0, 'uploads valid image, audio, video, PDF and Office attachment files');

$image = $library->find($imageId);
media_check(($image['original_name'] ?? '') === 'photo.png' && ($image['storage_key'] ?? '') !== 'photo.png' && ($image['width'] ?? 0) === 1 && ($image['height'] ?? 0) === 1, 'stores safe metadata, random storage key and image dimensions');
media_check($library->registerLocalFile($png, 'photo-copy.png') === $imageId, 'deduplicates media by SHA-256 hash');

$privateMarker = 'GPS_SECRET_SHOULD_NOT_SURVIVE';
$jpegId = $library->registerLocalFile(media_write($tmp . '/private.jpg', media_gd_image_bytes('jpg') . $privateMarker), 'private.jpg');
$jpeg = $library->find($jpegId) ?: [];
$jpegFile = $library->fileForResponse($jpegId);
$jpegBytes = (string) file_get_contents($jpegFile['path']);
media_check(($jpeg['metadata']['privacy']['exif_stripped'] ?? false) === true && !str_contains($jpegBytes, $privateMarker), 'strips private image metadata by re-encoding supported uploads before storage');
$jpegThumb = $library->fileForResponse($jpegId, 'thumbnail');
$thumbSize = getimagesize($jpegThumb['path']);
media_check(($jpegThumb['media']['mime_type'] ?? '') === 'image/webp' && is_array($thumbSize) && (int) $thumbSize[0] <= 320 && (int) $thumbSize[1] <= 320, 'generates safe WebP thumbnail derivatives for image uploads');
$jpegView = $library->viewModel($jpegId);
media_check(($jpegView['thumbnail_url'] ?? '') === '/media/' . $jpegId . '?variant=thumbnail' && isset($jpegView['derivatives']['small']), 'exposes stable media derivative URLs without changing local media IDs');

$avifId = $library->registerLocalFile(media_write($tmp . '/modern.avif', media_gd_image_bytes('avif')), 'modern.avif');
$avif = $library->find($avifId) ?: [];
media_check(($avif['media_type'] ?? '') === 'image' && ($avif['mime_type'] ?? '') === 'image/avif' && ($avif['extension'] ?? '') === 'avif', 'accepts AVIF image uploads through the Core Media Library');

$remoteLocalizer = new RemoteImageLocalizer();
$remoteImageBytes = media_png_1x1() . 'remote-image';
$remoteId = $remoteLocalizer->localize(
    'https://93.184.216.34/imported/remote-photo.png',
    $library,
    $tmp,
    1048576,
    static fn (string $url): array => ['body' => $remoteImageBytes, 'content_type' => 'image/png', 'final_url' => $url],
);
$remoteImage = $library->find($remoteId);
media_check($remoteId > 0 && ($remoteImage['media_type'] ?? '') === 'image' && ($remoteImage['original_name'] ?? '') === 'remote-photo.png', 'localizes safe remote images through Core Media Library');
media_check($remoteLocalizer->localize('https://93.184.216.34/imported/remote-photo.png', $library, $tmp, 1048576, static fn (string $url): array => ['body' => $remoteImageBytes, 'content_type' => 'image/png', 'final_url' => $url]) === $remoteId, 'remote image localization deduplicates by SHA-256 hash');
$remoteAvifId = $remoteLocalizer->localize('https://93.184.216.34/imported/remote-modern.avif', $library, $tmp, 1048576, static fn (string $url): array => ['body' => media_gd_image_bytes('avif'), 'content_type' => 'image/avif', 'final_url' => $url]);
media_check(($library->find($remoteAvifId)['mime_type'] ?? '') === 'image/avif', 'localizes safe remote AVIF images through the same media privacy and storage pipeline');
media_assert_throws(static fn () => $remoteLocalizer->localize('https://93.184.216.34/redirect.png', $library, $tmp, 1048576, static fn (): array => ['body' => media_png_1x1(), 'content_type' => 'image/png', 'final_url' => 'http://127.0.0.1/private.png']), 'remote image localization rechecks final redirected URL for SSRF');
media_assert_throws(static fn () => $remoteLocalizer->localize('https://93.184.216.34/readme.txt', $library, $tmp, 1048576, static fn (string $url): array => ['body' => 'not an image', 'content_type' => 'text/plain', 'final_url' => $url]), 'remote image localization rejects non-image content types before media ingest');
media_assert_throws(static fn () => $remoteLocalizer->localize('http://127.0.0.1/private.png', $library, $tmp, 1048576, static fn (string $url): array => ['body' => media_png_1x1(), 'content_type' => 'image/png', 'final_url' => $url]), 'remote image localization blocks private origin URLs');

media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/evil.jpg', '<?php echo 1;'), 'evil.jpg'), 'rejects disguised extension uploads');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/shell.php.jpg', media_png_1x1()), 'shell.php.jpg'), 'rejects dangerous double extensions');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/hack.php', '<?php'), 'hack.php'), 'rejects PHP uploads');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/page.html', '<h1>x</h1>'), 'page.html'), 'rejects HTML uploads');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/app.js', 'alert(1)'), 'app.js'), 'rejects JavaScript uploads');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/vector.svg', '<svg><script>alert(1)</script></svg>'), 'vector.svg'), 'rejects SVG uploads');
media_assert_throws(static fn () => $library->registerLocalFile(media_write($tmp . '/mismatch.png', '%PDF-1.4'), 'mismatch.png'), 'rejects MIME mismatches');
$missingFileinfo = new MediaLibrary($pdo, $root . '/content/uploads', $config['media'] + ['fileinfo_available' => false]);
try {
    $missingFileinfo->registerLocalFile(media_write($tmp . '/needs-fileinfo.png', media_png_1x1()), 'needs-fileinfo.png');
    media_check(false, 'Fileinfo absence is rejected without Fatal Error');
} catch (MediaException $exception) {
    $message = $exception->getMessage();
    media_check(str_contains($message, '当前服务器未启用 PHP Fileinfo 扩展') && !str_contains($message, 'Class "finfo"') && !str_contains($message, $root), 'Fileinfo absence returns a Chinese safe error without leaking internals');
}
$traversalId = $library->registerLocalFile(media_write($tmp . '/safe-traversal.png', media_png_1x1() . 'x'), '../../traversal.png');
media_check(!str_contains((string) $library->find($traversalId)['original_name'], '..') && !str_contains((string) $library->find($traversalId)['original_name'], '/'), 'normalizes path traversal filenames');
media_assert_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads', ['max_file_bytes' => 4]))->uploadLocalFile(media_write($tmp . '/too-big.pdf', '%PDF-1.4 too big'), 'too-big.pdf', 1), 'rejects oversized files');
media_assert_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads', ['max_image_pixels' => 100]))->uploadLocalFile(media_write($tmp . '/huge.png', media_png_huge_header()), 'huge.png', 1), 'rejects image pixel bombs');
$badTemp = media_write($tmp . '/bad-temp.png', '%PDF-1.4');
media_assert_throws(static fn () => $library->uploadLocalFile($badTemp, 'bad-temp.png', 1), 'rejects invalid temporary uploads');
media_check(!is_file($badTemp), 'cleans temporary files after validation failure');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();
$controller = new AdminController($settings, new FileLogger($root . '/storage/logs/app.log'), $root);
$adminUploadTmp = media_write($tmp . '/admin-note.txt', 'admin upload audit note');
$_POST = ['_csrf' => $csrf];
$_FILES = ['media_files' => ['name' => ['admin-note.txt'], 'tmp_name' => [$adminUploadTmp], 'size' => [filesize($adminUploadTmp)], 'error' => [UPLOAD_ERR_OK]]];
$adminUpload = $controller->mediaUpload();
$_POST = [];
$_FILES = [];
$index = $controller->mediaIndex();
$uploadAuditJson = (string) $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'media.uploaded' AND actor_id = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
$uploadAudit = json_decode($uploadAuditJson, true);
media_check($index->status() === 200 && $adminUpload->status() === 302 && str_contains($index->body(), 'media-upload') && str_contains($index->body(), 'photo.png') && str_contains($index->body(), 'admin-note.txt') && is_array($uploadAudit) && ($uploadAudit['count'] ?? 0) === 1 && ($uploadAudit['total_bytes'] ?? 0) === strlen('admin upload audit note'), 'renders usable admin media library with upload form/list and audits successful uploads');
$pickerBlocks = [
    ['type' => 'image', 'data' => ['media_id' => $imageId]],
    ['type' => 'gallery', 'data' => ['media_ids_text' => $imageId . ', ' . $traversalId]],
    ['type' => 'audio', 'data' => ['media_id' => $audioId]],
    ['type' => 'video', 'data' => ['media_id' => $videoId, 'poster_media_id' => $imageId]],
    ['type' => 'attachment', 'data' => ['media_id' => $pdfId]],
];
$pickerForm = $controller->contentStore(new Request('POST', '/admin/content', [], ['_csrf' => $csrf, 'block_action' => 'copy:0', 'blocks' => $pickerBlocks]));
$pickerBody = $pickerForm->body();
media_check(
    $pickerForm->status() === 200
    && substr_count($pickerBody, '从媒体库选择') >= 5
    && str_contains($pickerBody, 'id="media-picker-modal"')
    && str_contains($pickerBody, 'data-media-type="image"')
    && str_contains($pickerBody, 'data-media-type="audio"')
    && str_contains($pickerBody, 'data-media-type="video"')
    && str_contains($pickerBody, 'data-media-type="attachment"')
    && str_contains($pickerBody, 'window.CMS_MEDIA_PICKER_ITEMS')
    && str_contains($pickerBody, 'photo.png')
    && str_contains($pickerBody, 'sound.wav')
    && str_contains($pickerBody, 'clip.mp4')
    && str_contains($pickerBody, 'file.pdf')
    && !str_contains($pickerBody, '从媒体库复制媒体 ID'),
    'renders unified Media Picker controls for image, gallery, audio, video and attachment content blocks'
);
$detail = $controller->mediaDetail(new Request('GET', '/admin/media/detail/' . $imageId));
$missingDetail = $controller->mediaDetail(new Request('GET', '/admin/media/detail/999999'));
media_check(
    $detail->status() === 200
    && str_contains($detail->body(), '媒体详情')
    && str_contains($detail->body(), '/media/' . $imageId)
    && str_contains($detail->body(), 'admin-danger')
    && str_contains($detail->body(), '确定要永久删除这个媒体文件吗')
    && $missingDetail->status() === 404
    && str_contains($missingDetail->body(), '媒体文件不存在')
    && !str_contains($missingDetail->body(), 'Not Found'),
    'renders media detail, copyable URL and confirmed dangerous delete actions'
);
$saveMeta = $controller->mediaUpdate(new Request('POST', '/admin/media/detail/' . $imageId, [], ['_csrf' => $csrf, 'action' => 'save', 'title' => 'Hero', 'description' => '<b>Desc</b>', 'alt_text' => '<script>x</script>Alt']));
media_check($saveMeta->status() === 302 && ($library->find($imageId)['alt_text'] ?? '') === 'Alt' && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'media.updated' AND actor_id = 1")->fetchColumn() === 1, 'updates title, description and alt text safely with audit');
$badCsrf = $controller->mediaUpdate(new Request('POST', '/admin/media/detail/' . $imageId, [], ['_csrf' => 'bad', 'action' => 'save']));
$methodBlocked = $controller->mediaUpdate(new Request('GET', '/admin/media/detail/' . $imageId, [], ['_csrf' => $csrf, 'action' => 'mark_deleted']));
unset($_SESSION['admin_user']);
$noAuth = $controller->mediaUpload();
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
media_check($badCsrf->status() === 403 && $methodBlocked->status() === 405 && ($methodBlocked->headers()['Allow'] ?? '') === 'POST' && $noAuth->status() === 302, 'enforces POST, CSRF and administrator permission on media operations');

$configNoFileinfo = $config;
$configNoFileinfo['media']['fileinfo_available'] = false;
media_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configNoFileinfo, true) . ";\n");
$settingsNoFileinfo = Settings::load($root);
$controllerNoFileinfo = new AdminController($settingsNoFileinfo, new FileLogger($root . '/storage/logs/app.log'), $root);
$uploadTmp = media_write($tmp . '/admin-no-fileinfo.png', media_png_1x1());
$_POST = ['_csrf' => $csrf];
$_FILES = ['media_files' => ['name' => ['admin-no-fileinfo.png'], 'tmp_name' => [$uploadTmp], 'size' => [filesize($uploadTmp)], 'error' => [UPLOAD_ERR_OK]]];
$uploadMissingFileinfo = $controllerNoFileinfo->mediaUpload();
media_check($uploadMissingFileinfo->status() === 400 && str_contains($uploadMissingFileinfo->body(), '当前服务器未启用 PHP Fileinfo 扩展') && !str_contains($uploadMissingFileinfo->body(), 'Class &quot;finfo&quot;') && !str_contains($uploadMissingFileinfo->body(), $root), '/admin/media/upload fails safely with a Chinese Fileinfo error');
$_POST = [];
$_FILES = [];
media_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
media_assert_throws(static fn () => $repo->create('article', 'Mismatch', 'media-mismatch', [['type' => 'image', 'data' => ['media_id' => $audioId]]], 'draft'), 'rejects media_id type mismatches in content blocks');
$contentId = $repo->create('article', 'Media Article', 'media-article', [
    ['type' => 'image', 'data' => ['media_id' => $imageId, 'alt' => '<b>Alt</b>', 'caption' => '<script>x</script>Caption', 'alignment' => 'center']],
    ['type' => 'gallery', 'data' => ['media_ids' => [$imageId, $traversalId], 'items' => [['media_id' => $imageId, 'alt' => 'A', 'caption' => 'C']], 'columns' => 2]],
    ['type' => 'audio', 'data' => ['media_id' => $audioId, 'title' => 'Audio', 'controls' => true, 'preload' => 'metadata']],
    ['type' => 'video', 'data' => ['media_id' => $videoId, 'poster_media_id' => $imageId, 'controls' => true, 'preload' => 'metadata']],
    ['type' => 'attachment', 'data' => ['media_id' => $pdfId, 'display_name' => 'Download PDF']],
], 'published');
media_check(count($library->references($imageId)) >= 2 && count($library->references($pdfId)) === 1, 'creates media reference records when content is saved');
$editMedia = $controller->contentEdit(new Request('GET', '/admin/content/edit/' . $contentId));
$editBody = $editMedia->body();
media_check(
    $editMedia->status() === 200
    && str_contains($editBody, 'data-media-picker-input="media_id"')
    && str_contains($editBody, 'data-media-picker-input="media_ids_text"')
    && str_contains($editBody, 'value="' . $imageId . ', ' . $traversalId . '"')
    && str_contains($editBody, 'photo.png')
    && str_contains($editBody, '下载链接预览'),
    'reopens saved media blocks with Media Picker selections and preserves legacy media_ids arrays'
);

$app = Application::boot($root);
$front = $app->handle(new Request('GET', '/articles/media-article'));
media_check($front->status() === 200 && str_contains($front->body(), '<h1>Media Article</h1>'), 'renders media Article route through the active theme');
media_check(str_contains($front->body(), '<audio controls preload="metadata">'), 'renders HTML5 audio block through the active theme');
media_check(str_contains($front->body(), '<video') && str_contains($front->body(), 'preload="metadata"') && str_contains($front->body(), '/media/' . $videoId), 'renders HTML5 video block through the active theme');
media_check(str_contains($front->body(), 'media-gallery'), 'renders gallery block through the active theme');
media_check(str_contains($front->body(), 'Download PDF'), 'renders attachment block through the active theme');
media_check(!str_contains(strtolower($front->body()), '<script>x</script>') && str_contains($front->body(), 'Caption'), 'escapes alt and caption output');
$attachment = $app->handle(new Request('GET', '/media/' . $pdfId . '/file.pdf'));
$mediaWrongMethod = $app->handle(new Request('POST', '/media/' . $pdfId . '/file.pdf'));
$mediaBadId = $app->handle(new Request('GET', '/media/not-a-number'));
media_check(
    $attachment->status() === 200
    && str_contains($attachment->headers()['Content-Disposition'] ?? '', 'attachment')
    && (($attachment->headers()['X-Content-Type-Options'] ?? '') === 'nosniff')
    && $mediaWrongMethod->status() === 405
    && ($mediaWrongMethod->headers()['Allow'] ?? '') === 'GET, HEAD, OPTIONS'
    && ($mediaWrongMethod->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($mediaWrongMethod->body(), '请求方法不被允许')
    && !str_contains($mediaWrongMethod->body(), 'Method Not Allowed')
    && $mediaBadId->status() === 404
    && ($mediaBadId->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($mediaBadId->body(), '媒体文件不存在或暂不可用')
    && !str_contains($mediaBadId->body(), 'Not Found'),
    'serves attachments with safe headers and localizes public media method/not-found errors'
);
$audioModel = $library->viewModel($audioId);
media_check(($audioModel['url'] ?? '') === '/media/' . $audioId && ($audioModel['download_url'] ?? '') === '/media/' . $audioId . '?download=1', 'generates extensionless controlled media URLs to avoid web server static-file interception');
$storageProvider = new TestMediaStorageProvider($root . '/storage/media-provider');
$providerLibrary = new MediaLibrary($pdo, $root . '/content/uploads', $config['media'], null, $storageProvider);
$providerMediaId = $providerLibrary->registerLocalFile(media_write($tmp . '/provider-photo.png', media_gd_image_bytes('png', 200, 40, 20, 5, 4)), 'provider-photo.png');
$providerMedia = $providerLibrary->find($providerMediaId) ?? [];
$providerFile = $providerLibrary->fileForResponse($providerMediaId);
media_check(($providerMedia['storage_provider'] ?? '') === 'test-local' && str_contains($providerFile['path'], '/storage/media-provider/') && is_file($providerFile['path']), 'Media Library writes new media through the configured storage provider while preserving local media IDs');
media_check(($providerLibrary->viewModel($providerMediaId)['available'] ?? false) === true && ($providerLibrary->viewModel($providerMediaId)['url'] ?? '') === '/media/' . $providerMediaId, 'Media Library resolves provider-backed media through the stable controlled media URL');
$providerThumb = $providerLibrary->fileForResponse($providerMediaId, 'thumbnail');
$providerStorageKey = (string) ($providerMedia['storage_key'] ?? '');
$providerLibrary->hardDelete($providerMediaId);
media_check($providerStorageKey !== '' && !$storageProvider->exists($providerStorageKey) && !is_file($providerThumb['path']) && $providerLibrary->find($providerMediaId) === null, 'Media Library hard delete removes unreferenced provider-backed media and generated derivatives through the storage provider');
$audioRoute = $app->handle(new Request('GET', '/media/' . $audioId));
media_check($audioRoute->status() === 200 && str_starts_with((string) ($audioRoute->headers()['Content-Type'] ?? ''), 'audio/') && $audioRoute->body() !== '', 'serves audio through extensionless controlled media route');
$videoFile = $library->fileForResponse($videoId);
$videoSize = filesize($videoFile['path']);
$head = $app->handle(new Request('HEAD', '/media/' . $videoId));
media_check($head->status() === 200 && $head->body() === '' && ($head->headers()['Accept-Ranges'] ?? '') === 'bytes' && (int) ($head->headers()['Content-Length'] ?? 0) === $videoSize, 'supports HEAD on controlled media route without outputting file body');
$range = $app->handle(new Request('GET', '/media/' . $videoId . '/clip.mp4', [], [], ['HTTP_RANGE' => 'bytes=0-3']));
media_check($range->status() === 206 && ($range->headers()['Accept-Ranges'] ?? '') === 'bytes' && ($range->headers()['Content-Range'] ?? '') === 'bytes 0-3/' . $videoSize && (int) ($range->headers()['Content-Length'] ?? 0) === 4 && strlen($range->body()) === 4, 'supports valid byte Range requests with 206 and correct range headers');
$suffixRange = $app->handle(new Request('GET', '/media/' . $videoId . '/clip.mp4', [], [], ['HTTP_RANGE' => 'bytes=-4']));
media_check($suffixRange->status() === 206 && str_starts_with((string) ($suffixRange->headers()['Content-Range'] ?? ''), 'bytes ' . max(0, ((int) $videoSize) - 4) . '-'), 'supports suffix byte Range requests for player seeking and resume');
$invalidRange = $app->handle(new Request('GET', '/media/' . $videoId . '/clip.mp4', [], [], ['HTTP_RANGE' => 'bytes=' . ($videoSize + 100) . '-' . ($videoSize + 200)]));
media_check($invalidRange->status() === 416 && ($invalidRange->headers()['Content-Range'] ?? '') === 'bytes */' . $videoSize && $invalidRange->body() === '', 'returns 416 for invalid media Range requests');
$adminMarkDeleted = $controller->mediaUpdate(new Request('POST', '/admin/media/detail/' . $officeId, [], ['_csrf' => $csrf, 'action' => 'mark_deleted']));
$deletedRange = $app->handle(new Request('GET', '/media/' . $officeId . '/office.docx', [], [], ['HTTP_RANGE' => 'bytes=0-3']));
media_check($adminMarkDeleted->status() === 302 && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'media.mark_deleted' AND actor_id = 1")->fetchColumn() === 1 && $deletedRange->status() === 404 && str_contains($deletedRange->body(), '媒体文件不存在或暂不可用') && !str_contains($deletedRange->body(), 'Not Found'), 'Range requests cannot bypass admin-deleted media status checks and return localized not-found text with audit');

$referencedMarkDelete = $controller->mediaUpdate(new Request('POST', '/admin/media/detail/' . $imageId, [], ['_csrf' => $csrf, 'action' => 'mark_deleted']));
$hardDeleteReferencedBlocked = false;
try {
    $library->hardDelete($pdfId);
} catch (MediaException) {
    $hardDeleteReferencedBlocked = true;
}
media_check($hardDeleteReferencedBlocked && $referencedMarkDelete->status() === 400 && str_contains($referencedMarkDelete->body(), '媒体仍被内容引用') && ($library->find($imageId)['status'] ?? '') === 'Active', 'blocks admin and library media delete while media is referenced by content');
$repo->update($contentId, 'article', 'Media Article', 'media-article', [['type' => 'paragraph', 'data' => ['text' => 'No media now']]], 'published');
$adminHardDelete = $controller->mediaUpdate(new Request('POST', '/admin/media/detail/' . $pdfId, [], ['_csrf' => $csrf, 'action' => 'hard_delete']));
media_check($adminHardDelete->status() === 302 && $library->find($pdfId) === null && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'media.hard_deleted' AND actor_id = 1")->fetchColumn() === 1, 'allows admin hard delete after references are removed and records audit');

$missingId = $repo->create('article', 'Missing Media', 'missing-media', [['type' => 'image', 'data' => ['media_id' => $imageId, 'alt' => 'Missing']]], 'published');
$missingPathInfo = $library->fileForResponse($imageId);
unlink($missingPathInfo['path']);
$missing = $app->handle(new Request('GET', '/articles/missing-media'));
media_check($missingId > 0 && $missing->status() === 200 && str_contains($missing->body(), 'Image unavailable'), 'missing media file renders a safe placeholder without breaking the page');
media_check(is_file($root . '/content/uploads/.htaccess') && is_file($root . '/content/uploads/upload-security.nginx.conf'), 'ships upload directory script execution protections for Apache and Nginx');

media_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " media security and player checks failed.\n");
    exit(1);
}

echo "Media security and player tests passed.\n";

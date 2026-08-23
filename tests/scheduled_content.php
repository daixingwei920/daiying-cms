<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentScheduler;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function scheduled_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function scheduled_remove(string $path): void
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

function scheduled_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        $dest = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
        } else {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy((string) $item->getPathname(), $dest);
        }
    }
}

function scheduled_run_cli(string $root, string $now): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge($_SERVER, ['CMS_ROOT_OVERRIDE' => $root]);
    $process = proc_open([PHP_BINARY, 'cli.php', 'publish-scheduled-content', $now], $descriptor, $pipes, CMS_SOURCE_ROOT, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start scheduler CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('Scheduler CLI failed: ' . $stderr);
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Scheduler CLI returned invalid JSON: ' . $stdout);
    }

    return $decoded;
}

function scheduled_run_cron_script(string $root, string $now): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge($_SERVER, ['CMS_ROOT_OVERRIDE' => $root]);
    $process = proc_open([PHP_BINARY, 'scripts/publish_scheduled_content.php', $now], $descriptor, $pipes, CMS_SOURCE_ROOT, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start scheduled publish script.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('Scheduled publish script failed: ' . $stderr);
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Scheduled publish script returned invalid JSON: ' . $stdout);
    }

    return $decoded;
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-scheduled-content-' . bin2hex(random_bytes(4));
scheduled_remove($root);
foreach (['config', 'storage/logs', 'storage/database', 'content/uploads', 'content/plugins', 'system/admin', 'system/recovery'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
scheduled_copy(CMS_SOURCE_ROOT . '/system/core', $root . '/system/core');
scheduled_copy(CMS_SOURCE_ROOT . '/system/migrations', $root . '/system/migrations');
scheduled_copy(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
scheduled_copy(CMS_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/scheduled.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Scheduled Site', 'url' => 'https://scheduled.example.test', 'id' => 'scheduled-site', 'secret' => 'scheduled-secret'];
$config['theme'] = ['active' => 'default', 'settings' => ['default' => []]];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
file_put_contents($root . '/storage/installed.lock', '{}');

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob($root . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$future = gmdate('c', strtotime('2030-01-01T12:00:00+00:00'));
$due = gmdate('c', strtotime('2030-01-01T12:00:01+00:00'));
$scheduledId = $repo->create('article', 'Scheduled Article', 'scheduled-article', [['type' => 'paragraph', 'data' => ['text' => 'Visible after scheduler.']]], 'scheduled', ['scheduled_at' => $future]);
$draftId = $repo->create('article', 'Draft Article', 'draft-scheduler', [['type' => 'paragraph', 'data' => ['text' => 'Draft.']]], 'draft', ['scheduled_at' => $future]);
$archivedId = $repo->create('article', 'Archived Article', 'archived-scheduler', [['type' => 'paragraph', 'data' => ['text' => 'Archived.']]], 'archived', ['scheduled_at' => $future]);

$app = Application::boot($root);
$before = $app->handle(new Request('GET', '/articles/scheduled-article'));
scheduled_check($before->status() === 404, 'scheduled content is not publicly accessible before publish time');
$sitemapBefore = $app->handle(new Request('GET', '/sitemap.xml'));
scheduled_check($sitemapBefore->status() === 200 && !str_contains($sitemapBefore->body(), 'scheduled-article'), 'scheduled content is excluded from sitemap before publish time');

$early = (new ContentScheduler($pdo))->publishDue(gmdate('c', strtotime('2030-01-01T11:59:59+00:00')));
scheduled_check($early['published'] === 0 && $early['events'] === 0 && (string) $repo->find($scheduledId)['status'] === 'scheduled', 'scheduler does not publish content before scheduled_at');

$cli = scheduled_run_cli($root, $due);
$published = $repo->find($scheduledId);
scheduled_check($cli['published'] === 1 && $cli['events'] === 1 && $cli['content_ids'] === [$scheduledId], 'scheduler CLI publishes due scheduled content and records one event');
scheduled_check((string) $published['status'] === 'published' && (string) $published['published_at'] === $due, 'scheduler sets published status and published_at exactly once');
$after = Application::boot($root)->handle(new Request('GET', '/articles/scheduled-article'));
scheduled_check($after->status() === 200 && str_contains($after->body(), 'Visible after scheduler.'), 'scheduled content is publicly accessible after scheduler publishes it');
$sitemapAfter = Application::boot($root)->handle(new Request('GET', '/sitemap.xml'));
scheduled_check($sitemapAfter->status() === 200 && str_contains($sitemapAfter->body(), 'scheduled-article'), 'published scheduled content appears in sitemap after publish');

$repeat = scheduled_run_cli($root, gmdate('c', strtotime('2030-01-01T12:05:00+00:00')));
$publishedAgain = $repo->find($scheduledId);
$eventCount = (int) $pdo->query("SELECT COUNT(*) FROM cms_content_events WHERE event_type = 'content.scheduled_published' AND content_id = " . $scheduledId)->fetchColumn();
scheduled_check($repeat['published'] === 0 && $repeat['events'] === 0 && (string) $publishedAgain['published_at'] === $due && $eventCount === 1, 'repeated scheduler runs are idempotent and do not duplicate events or change published_at');
scheduled_check((string) $repo->find($draftId)['status'] === 'draft' && (string) $repo->find($archivedId)['status'] === 'archived', 'scheduler does not publish draft or archived content');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$controller = new AdminController($settings, new FileLogger($root . '/storage/logs/app.log'), $root);
$form = $controller->contentCreate();
scheduled_check($form->status() === 200 && str_contains($form->body(), 'name="scheduled_at"'), 'admin content form exposes scheduled publish time field');
$csrf = CsrfToken::get();
$adminCreate = $controller->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Admin Scheduled',
    'slug' => 'admin-scheduled',
    'status' => 'scheduled',
    'scheduled_at' => '2030-01-02T00:00:00+00:00',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Admin scheduled']]],
    'content_action' => 'save',
]));
scheduled_check($adminCreate->status() === 302 && (string) $pdo->query("SELECT scheduled_at FROM cms_contents WHERE slug = 'admin-scheduled'")->fetchColumn() === '2030-01-02T00:00:00+00:00', 'admin can create scheduled content with persisted scheduled_at');
$cron = scheduled_run_cron_script($root, '2030-01-02T00:00:01+00:00');
scheduled_check($cron['published'] === 1 && (string) $pdo->query("SELECT status FROM cms_contents WHERE slug = 'admin-scheduled'")->fetchColumn() === 'published', 'stable scheduled publish cron script publishes due admin-created content');
unset($_SESSION['admin_user']);

scheduled_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " scheduled content checks failed.\n");
    exit(1);
}

echo "Scheduled content tests passed.\n";

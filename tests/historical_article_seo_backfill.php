<?php

declare(strict_types=1);

// This former feature suite now verifies retirement and historical-data retention.
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Routing\BasePath;
use Cms\Core\Routing\Router;
use Cms\Core\Support\View;

$root = sys_get_temp_dir() . '/cms-historical-retirement-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
    echo 'PASS ' . $label . PHP_EOL;
};
try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $migrations = [];
    foreach (['2026_08_12_000001_core_schema.php', '2026_08_12_000002_content_media_schema.php',
        '2026_08_12_000008_media_release_schema.php', '2026_08_12_000011_content_scheduler_schema.php',
        '2026_10_03_000001_seo_keyword_system_p1.php', '2026_10_03_000002_seo_keyword_center_p2.php',
        '2026_10_06_000001_historical_article_seo_backfill.php'] as $file) {
        $migrations[] = require dirname(__DIR__) . '/system/migrations/' . $file;
    }
    $runner = new MigrationRunner($pdo, $migrations);
    $runner->run();
    $pdo->exec("INSERT INTO cms_contents(content_type,title,slug,status,blocks_json,meta_json,created_at,updated_at)
        VALUES ('article','Historical article','historical-article','published','[{\"type\":\"paragraph\",\"data\":{\"text\":\"Owner content\"}}]',
        '{\"seo_title\":\"Owner title\",\"canonical_url\":\"https://isolated.invalid/articles/historical-article\"}','historical','historical')");
    $pdo->exec("INSERT INTO cms_historical_article_seo_jobs(content_id,status,proposed_meta_json,proposed_blocks_json,created_at,updated_at)
        VALUES (1,'generated','{\"seo_title\":\"Pending suggestion\"}','[{\"type\":\"paragraph\",\"data\":{\"text\":\"Pending draft\"}}]','historical','historical')");
    $pdo->exec("INSERT INTO cms_historical_article_seo_logs(content_id,action,status,message,created_at)
        VALUES (1,'generate_ai','ok','Historical audit record','historical')");
    $snapshot = static function () use ($pdo): array {
        $rows = [];
        foreach (['cms_contents','cms_historical_article_seo_jobs','cms_historical_article_seo_logs','cms_core_migrations'] as $table) {
            $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
        }
        return $rows;
    };
    $before = $snapshot();
    $runner->run();
    $assert($snapshot() === $before, 'published migrations remain repeatable and retain historical data');

    $router = new Router();
    $calls = 0;
    $retired = ['/admin/seo/historical-backfill','/admin/seo/historical-backfill/action',
        '/admin/seo/historical-backfill/future','/admin/seo/content-quality','/admin/seo/content-quality/future'];
    foreach ($retired as $path) {
        foreach (['GET','POST','PUT','PATCH','DELETE','OPTIONS'] as $method) {
            $router->route($method, $path, static function () use (&$calls): Response { $calls++; return Response::text('must not run', 599); });
        }
    }
    $router->get('/admin/seo/content-quality-other', static fn (): Response => Response::text('neighbor', 200));
    $class = new ReflectionClass(Application::class);
    $app = $class->newInstanceWithoutConstructor();
    foreach (['rootPath'=>$root,'settings'=>Settings::fromArray([]),'logger'=>new FileLogger($root.'/app.log'),
        'router'=>$router,'installed'=>true,'pluginRuntime'=>new PluginRuntimeRegistry()] as $name=>$value) {
        $class->getProperty($name)->setValue($app, $value);
    }
    foreach (['','/cms'] as $base) {
        BasePath::setCurrent($base);
        View::setBasePath($base);
        foreach (['anonymous','admin'] as $identity) {
            $_SESSION = $identity === 'admin' ? ['admin_user'=>['id'=>1,'email'=>'isolated@example.invalid']] : [];
            $requests = 0;
            foreach ($retired as $path) {
                foreach (['GET','POST','HEAD','PUT','PATCH','DELETE','OPTIONS'] as $method) {
                    $response = $app->handle(new Request($method, $base.$path.'/', [], ['action'=>'apply','content_id'=>1,'regenerate'=>'1']));
                    if ($response->status() !== 410 || ($response->headers()['Cache-Control'] ?? '') !== 'private, no-store') {
                        throw new RuntimeException('Retired request reached routing: '.$base.$path.' '.$method);
                    }
                    $requests++;
                }
            }
            $assert($requests === 35, ($base ?: '/') . ' ' . $identity . ' all retired paths and methods return 410/no-store');
        }
        $_SERVER['REQUEST_URI'] = $base.'/admin/seo/keywords';
        $html = View::page('Keywords', 'Isolated test');
        $assert(!str_contains($html,'historical-backfill') && !str_contains($html,'content-quality')
            && !str_contains($html,'历史文章 SEO 补全') && !str_contains($html,'历史内容优化'), ($base ?: '/') . ' retired menus absent');
        $assert(str_contains($html,$base.'/admin/seo/keywords') && str_contains($html,$base.'/admin/seo/crawlers')
            && str_contains($html,$base.'/admin/seo/search-engines') && str_contains($html,$base.'/admin/settings/ai'), ($base ?: '/') . ' shared SEO and AI settings menus retained');
    }
    BasePath::setCurrent(''); View::setBasePath('');
    $assert($calls === 0, 'retirement guard precedes any old or plugin-supplied route');
    $assert($app->handle(new Request('GET','/admin/seo/content-quality-other'))->status() === 200, 'unrelated neighboring routes remain reachable');
    $assert($snapshot() === $before, 'articles, manual SEO, pending drafts, logs and migration records unchanged');
    $assert(!class_exists(Cms\Core\Seo\Historical\HistoricalArticleSeoBackfillService::class), 'retired optimizer service no longer autoloads');
    foreach (['seoHistoricalBackfill','seoContentQuality','seoHistoricalBackfillAction'] as $method) {
        $assert(!method_exists(AdminController::class,$method), 'removed controller '.$method);
    }
    foreach (['contentCreate','contentEdit','contentUpdate','contentAiWrite','seoKeywordIndex','seoSearchEngines'] as $method) {
        $assert(method_exists(AdminController::class,$method), 'shared controller retained '.$method);
    }
    echo 'Historical optimizer retirement: '.$checks.' checks PASS'.PHP_EOL;
} finally {
    BasePath::setCurrent(''); View::setBasePath(''); unset($_SERVER['REQUEST_URI']); $_SESSION = [];
    foreach (glob($root.'/*') ?: [] as $file) { unlink($file); }
    rmdir($root);
}

<?php

declare(strict_types=1);

define('CORE_SOURCE', dirname(__DIR__));
$root = sys_get_temp_dir() . '/cms-sitemap-sdk-' . bin2hex(random_bytes(5));
mkdir($root . '/system', 0700, true);
copy(CORE_SOURCE . '/system/official-plugins.php', $root . '/system/official-plugins.php');
define('CMS_ROOT', $root);
require CORE_SOURCE . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\{Request, Response};
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\{BlockRegistry, PluginContext, PluginDataStore, PluginException, PluginLifecycle, PluginManager, PluginManifest, PluginRuntimeRegistry};
use Cms\Core\Routing\{BasePath, Router};
use Cms\Core\Recovery\RunMode;
use Cms\Core\Support\PublicApiRegistry;

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS $message\n";
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (PluginException) { check(true, $message); return; }
    check(false, $message);
}
function locs(Response $response, string $rootName): array {
    $doc = new DOMDocument();
    check($response->status() === 200 && $doc->loadXML($response->body()) && $doc->documentElement->localName === $rootName, "Valid $rootName response");
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    return array_map(static fn (DOMNode $n): string => $n->textContent, iterator_to_array($xp->query('//s:loc')));
}
function manifest(string $id, array $caps = ['seo.extend']): PluginManifest {
    return PluginManifest::fromArray(['plugin_id'=>$id, 'name'=>$id, 'version'=>'1.0.0', 'author'=>'Test', 'core'=>['min'=>'1.2.90'], 'php'=>'8.3.0', 'entry'=>'plugin.php', 'capabilities'=>$caps]);
}
mkdir($root . '/content/plugins', 0700, true);
mkdir($root . '/storage/logs', 0700, true);
$log = new FileLogger($root . '/storage/logs/test.log');
$db = $root . '/site.sqlite';
$database = ['dsn' => 'sqlite:' . $db];
if ($file = getenv('CMS_MYSQL_TEST_CONFIG')) {
    $c = require $file;
    if (($c['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $c['database'] ?? '')
        || (int) ($c['port'] ?? 0) < 1024 || (int) $c['port'] > 65535) {
        throw new RuntimeException('Disposable loopback MySQL required');
    }
    $database = ['dsn' => 'mysql:host=127.0.0.1;port=' . (int) $c['port'] . ';dbname=' . $c['database'] . ';charset=utf8mb4', 'username' => $c['user'], 'password' => $c['password']];
}
$pdo = new PDO($database['dsn'], $database['username'] ?? null, $database['password'] ?? null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
if ($file && (int) $pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('MySQL fixture must be empty; existing tables are never dropped');
}
(new MigrationRunner($pdo, array_map(static fn ($f) => require $f, glob(CORE_SOURCE . '/system/migrations/*.php'))))->run();
$runtime = new PluginRuntimeRegistry();
$context = new PluginContext(manifest('vendor.good'), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'vendor.good'), null, $runtime);
$noSeo = new PluginContext(manifest('vendor.other', []), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'vendor.other'), null, $runtime);
$invoked = 0;
$mapHandler = static function () use (&$invoked): Response { $invoked++; return new Response('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://site.invalid/public</loc></url></urlset>', 200, ['Content-Type'=>'application/xml']); };
try {
    rejects(fn () => $context->registerSitemap('/missing.xml'), 'Missing route rejected');
    $context->frontRoute('GET', '/owned.xml', $mapHandler);
    rejects(fn () => $noSeo->registerSitemap('/owned.xml'), 'Capability required');
    $other = new PluginContext(manifest('vendor.other'), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'vendor.other'), null, $runtime);
    rejects(fn () => $other->registerSitemap('/owned.xml'), 'Cannot claim another plugin route');
    $context->adminRoute('GET', '/admin-map.xml', $mapHandler);
    $context->frontRoute('POST', '/post-map.xml', $mapHandler);
    $context->frontRoute('GET', '/cap-map.xml', $mapHandler, 'content.read');
    $context->frontRoute('GET', '/csrf-map.xml', $mapHandler, null, true);
    foreach (['/admin-map.xml','/post-map.xml','/cap-map.xml','/csrf-map.xml'] as $path) {
        rejects(fn () => $context->registerSitemap($path), 'Non-public GET rejected: ' . $path);
    }
    foreach (['https://evil.invalid/a.xml','//evil.invalid/a.xml','/owned.xml?q=a&x=b','/owned.xml#x','/../owned.xml','/a/../owned.xml','/a/./owned.xml','/%2e%2e/owned.xml',"/bad\n.xml",'/bad\\x.xml','/x//a.xml','/x/{page}.xml','owned.xml','/owned.txt','/admin/export.xml','/api/private.xml','/preview/one.xml','/workspace/one.xml','/login/export.xml','/' . str_repeat('a', 510) . '.xml'] as $path) {
        rejects(fn () => $context->registerSitemap($path), 'Invalid/private path rejected: ' . json_encode($path));
    }
    foreach (['/sitemap.xml','/robots.txt'] as $path) {
        rejects(fn () => $context->frontRoute('GET', $path, $mapHandler), 'Core discovery route cannot be overridden');
    }
    $context->registerSitemap('/owned.xml');
    $context->registerSitemap('/owned.xml');
    check($runtime->sitemaps() === ['/owned.xml'=>'vendor.good'], 'Duplicate announcement is idempotent');
    check($invoked === 0, 'Registration does not execute plugin handlers');
    $other->frontRoute('GET', '/another.xml', $mapHandler);
    $other->registerSitemap('/another.xml');
    check(array_keys($runtime->sitemaps()) === ['/another.xml','/owned.xml'], 'Multiple owners sorted deterministically');
    $runtime->removeSitemaps('vendor.other');
    $runtime->removeSitemaps('vendor.good');
    check($runtime->sitemaps() === [], 'Owner removal clears only sitemap registrations');

    $limited = new PluginRuntimeRegistry();
    for ($i=0; $i<32; $i++) {
        $owner = 'vendor.limit' . intdiv($i,32);
        $path = '/map-' . $i . '.xml';
        $limited->route($owner, 'GET', $path, $mapHandler);
        $limited->sitemap($owner, $path);
    }
    $limited->route('vendor.limit0', 'GET', '/overflow-owner.xml', $mapHandler);
    rejects(fn () => $limited->sitemap('vendor.limit0','/overflow-owner.xml'), 'Per-owner registration bounded');
    for ($i=32; $i<512; $i++) {
        $owner = 'vendor.limit' . intdiv($i,32);
        $path = '/map-' . $i . '.xml';
        $limited->route($owner, 'GET', $path, $mapHandler);
        $limited->sitemap($owner, $path);
    }
    $limited->route('vendor.extra', 'GET', '/overflow-global.xml', $mapHandler);
    rejects(fn () => $limited->sitemap('vendor.extra','/overflow-global.xml'), 'Global registration bounded');
    check(count($limited->sitemaps()) === 512, 'Global limit leaves valid maps intact');

    $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';
    foreach (['vendor.good','vendor.disabled','vendor.failed','vendor.dependent'] as $id) {
        $dir = $root . '/content/plugins/' . $id;
        mkdir($dir,0700);
        $m = ['plugin_id'=>$id,'name'=>$id,'version'=>'1.0.0','author'=>'Test','core'=>['min'=>'1.2.90'],'php'=>'8.3.0','entry'=>'plugin.php','capabilities'=>['seo.extend']];
        if ($id === 'vendor.dependent') { $m['dependencies'] = ['vendor.missing']; }
        file_put_contents($dir . '/plugin.json', json_encode($m));
        $path = '/' . str_replace('.', '-', $id) . '.xml';
        file_put_contents($dir . '/plugin.php', '<?php return static function ($c): void { $c->frontRoute("GET", ' . var_export($path,true) . ', static fn() => new \\Cms\\Core\\Http\\Response(' . var_export($xml,true) . ',200,["Content-Type"=>"application/xml"])); $c->registerSitemap(' . var_export($path,true) . ');' . ($id === 'vendor.failed' ? 'throw new \\RuntimeException("synthetic boot failure");' : '') . '};');
    }
    $runtime = new PluginRuntimeRegistry();
    $manager = new PluginManager($root . '/content/plugins', $pdo, $log, new EventDispatcher(), new BlockRegistry(), $runtime);
    $manager->syncDiscovered();
    foreach (['vendor.good','vendor.failed','vendor.dependent'] as $id) { $manager->setStatus($id,PluginLifecycle::ENABLED); }
    check($manager->bootEnabled() === 1, 'Only healthy enabled plugin boots; missing dependency skipped');
    check($runtime->sitemaps() === ['/vendor-good.xml'=>'vendor.good'], 'Failed boot removes partial map; disabled/dependency maps omitted');
    $pdo->exec("INSERT INTO cms_contents(content_type,title,slug,status,blocks_json,meta_json,created_at,updated_at) VALUES('article','Public','public','published','[]','{}','2026-11-11','2026-11-11'),('article','Draft','draft','draft','[]','{}','2026-11-11','2026-11-11'),('article','Hidden','hidden','published','[]','{\"robots_index\":false}','2026-11-11','2026-11-11')");
    $make = static function (string $url='https://site.invalid',bool $index=true) use ($database): Settings { return Settings::fromArray(['database'=>$database, 'site'=>['url'=>$url], 'seo'=>['robots_index'=>$index,'ai_search_policy'=>'allow','ai_training_policy'=>'disallow']]); };
    $settings = $make();
    BasePath::setCurrent('');
    $front = new ContentFrontController($root,$settings,$log,$runtime);
    $router = new Router();
    (new ReflectionMethod(Application::class,'registerCoreRoutes'))->invoke(null,$router,$settings,$root,$log,RunMode::NORMAL,$runtime,new EventDispatcher());
    $index = $router->dispatch(new Request('GET','/sitemap.xml',[],[],['HTTP_HOST'=>'evil.invalid']));
    check(locs($index,'sitemapindex') === ['https://site.invalid/sitemap.xml?core=1','https://site.invalid/vendor-good.xml'], 'Core wiring emits ordered same-site index independent of Host');
    $core = $router->dispatch(new Request('GET','/sitemap.xml',['core'=>'1']));
    check(locs($core,'urlset') === ['https://site.invalid/','https://site.invalid/articles','https://site.invalid/articles/public'], 'Core child keeps published URLs and excludes draft/noindex');
    check(locs($router->dispatch(new Request('GET','/vendor-good.xml')),'urlset') === [], 'Plugin child remains separately served through Core route guard');
    check($router->dispatch(new Request('HEAD','/sitemap.xml'))->body() === $index->body(), 'HEAD uses the same index route');
    check($router->dispatch(new Request('POST','/sitemap.xml'))->status() === 405, 'Core discovery remains GET/HEAD only');
    check(locs($front->sitemap(new Request('GET','/sitemap.xml',['core'=>['1']])),'sitemapindex') !== [], 'Malformed core selector cannot bypass index');
    $robots = $router->dispatch(new Request('GET','/robots.txt'))->body();
    check(substr_count($robots,'Sitemap: https://site.invalid/vendor-good.xml') === 1 && str_contains($robots,'Disallow: /admin') && str_contains($robots,'User-agent: GPTBot') && str_contains($robots,'User-agent: OAI-SearchBot'), 'Robots advertises once and retains existing policies');
    check($robots === (new ContentFrontController($root,$settings,$log))->robots()->body() . "Sitemap: https://site.invalid/vendor-good.xml\n", 'Original robots bytes are preserved before appended map');
    $oldCore = (new ContentFrontController($root,$settings,$log))->sitemap()->body();
    check($oldCore === $core->body(), 'Core-only XML bytes unchanged at root sites');
    $manager->setStatus('vendor.good',PluginLifecycle::DISABLED);
    check($front->sitemap()->body() === $oldCore && !str_contains($front->robots()->body(),'vendor-good.xml'), 'Live disable removes discovery without reboot');
    check($router->dispatch(new Request('GET','/vendor-good.xml'))->status() === 404, 'Disabled child route remains inaccessible');
    $manager->setStatus('vendor.good',PluginLifecycle::ENABLED);
    $pdo->exec("UPDATE cms_plugins SET capabilities_json='[]' WHERE plugin_id='vendor.good'");
    check($front->sitemap()->body() === $oldCore, 'Revoked capability removes announcement');
    $pdo->exec("UPDATE cms_plugins SET capabilities_json='[\"seo.extend\"]' WHERE plugin_id='vendor.good'");
    check(locs($front->sitemap(),'sitemapindex') !== [], 'Existing capability can announce again');
    $blocked = new ContentFrontController($root,$make(index:false),$log,$runtime);
    check(locs($blocked->sitemap(),'urlset') === [] && locs($blocked->sitemap(new Request('GET','/sitemap.xml',['core'=>'1'])),'urlset') === [], 'Global noindex empties root and Core child');
    check(!str_contains($blocked->robots()->body(),'vendor-good.xml') && str_contains($blocked->robots()->body(),'Disallow: /'), 'Global noindex hides plugin announcements');
    BasePath::setCurrent('/cms');
    foreach (['https://site.invalid/cms','https://site.invalid'] as $siteUrl) {
        $sub = new ContentFrontController($root,$make($siteUrl),$log,$runtime);
        check(locs($sub->sitemap(),'sitemapindex') === ['https://site.invalid/cms/sitemap.xml?core=1','https://site.invalid/cms/vendor-good.xml'], 'Subdirectory index prefixes exactly once');
        check(in_array('https://site.invalid/cms/articles/public',locs($sub->sitemap(new Request('GET','/sitemap.xml',['core'=>'1'])),'urlset'),true), 'Subdirectory Core child prefixes exactly once');
        check(str_contains($sub->robots()->body(),'Sitemap: https://site.invalid/cms/vendor-good.xml') && !str_contains($sub->robots()->body(),'/cms/cms/'), 'Subdirectory robots follows canonical site URL');
    }
    BasePath::setCurrent('');
    $pdo->exec('ALTER TABLE cms_plugins RENAME TO plugins_unavailable');
    check($front->sitemap()->body() === $oldCore && !str_contains($front->robots()->body(),'vendor-good.xml'), 'Unavailable lifecycle store hides announcements and keeps Core map');
    $contract = PublicApiRegistry::contract('seo.sitemaps');
    check($contract['stability'] === 'experimental' && $contract['capabilities'] === ['seo.extend'] && $contract['class'] === 'Cms\\Core\\Plugin\\PluginContext', 'Candidate API declared without a new capability');
    check($invoked === 0, 'Discovery and all validations never dispatch untrusted map handlers');
    echo "plugin_sitemap_registry PASS: $checks checks\n";
} finally {
    BasePath::setCurrent('');
    $pdo = null;
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}

<?php

declare(strict_types=1);

$source = dirname(__DIR__);
$root = sys_get_temp_dir() . '/cms-issue9-' . bin2hex(random_bytes(5));
foreach (['config', 'storage/logs', 'content/uploads', 'content/plugins', 'content/themes'] as $dir) { mkdir($root . '/' . $dir, 0700, true); }
mkdir($root . '/system');
foreach (['core','migrations'] as $dir) { symlink($source . '/system/' . $dir, $root . '/system/' . $dir); }
copy($source . '/system/official-plugins.php', $root . '/system/official-plugins.php');
copy($source . '/system/core-manifest.json', $root . '/system/core-manifest.json');
foreach (['default', 'safe'] as $theme) { if (is_dir($source . '/content/themes/' . $theme)) { symlink($source . '/content/themes/' . $theme, $root . '/content/themes/' . $theme); } }
define('CMS_ROOT', $root);
require $source . '/system/core/Bootstrap/autoload.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
register_shutdown_function(static function () use ($root): void {
    if (getenv('CMS_ISSUE9_KEEP_FIXTURE') === '1') { return; }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { is_link($entry->getPathname()) || !$entry->isDir() ? unlink($entry->getPathname()) : rmdir($entry->getPathname()); } rmdir($root);
});

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\{ContentRepository, ContentTypeRegistry};
use Cms\Core\Http\{Request, Response};
use Cms\Core\Seo\{SeoPresentation, SeoHeadRenderer, SeoExtensionRegistry, CrawlerLog};
use Cms\Core\Auth\{AdminAuthenticator, FrontUserAuthenticator};
use Cms\Core\Admin\AdminController;
use Cms\Core\Security\CsrfToken;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); } $checks++; echo 'PASS ' . $message . PHP_EOL;
};
$config = require $source . '/config/app.php';
$config['app']['debug'] = true;
$config['database'] = ['dsn' => 'sqlite:' . $root . '/site.sqlite'];
if ($file = getenv('CMS_MYSQL_TEST_CONFIG')) {
    $c = require $file;
    if (($c['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $c['database'] ?? '')) { throw new RuntimeException('Disposable MySQL required'); }
    $config['database'] = ['dsn' => 'mysql:host=127.0.0.1;port=' . (int) $c['port'] . ';dbname=' . $c['database'] . ';charset=utf8mb4', 'username' => $c['user'], 'password' => $c['password']];
}

$config['site'] = ['name' => 'Synthetic Site', 'url' => 'https://example.invalid', 'base_path' => ''];
$config['seo'] = ['robots_index' => true, 'title_template' => '{title} | {site_name}', 'description_template' => 'About {title}', 'keywords_template' => 'site, {site_name}', 'schema_enabled' => true, 'llms_enabled' => true, 'ai_search_policy' => 'allow', 'ai_training_policy' => 'disallow', 'crawler_log_enabled' => true, 'crawler_retention_days' => 7];
$browserConfig = $config;
$save = static function () use ($root, &$config): void { file_put_contents($root . '/config/app.php', '<?php return ' . var_export($config, true) . ';'); };
$save(); touch($root . '/storage/installed.lock');
$p = new PDO($config['database']['dsn'], $config['database']['username'] ?? null, $config['database']['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$migrations = array_map(static fn ($file) => require $file, glob($source . '/system/migrations/*.php'));
(new Cms\Core\Migration\MigrationRunner($p, $migrations))->run();
$repo = new ContentRepository($p, ContentTypeRegistry::defaults(), [], $root);
$ids = [];
foreach (['public', 'explicit', 'draft', 'noindex', 'paid', 'foreign', 'malformed'] as $slug) {
    $ids[$slug] = $repo->create('article', 'Synthetic ' . $slug, $slug, [['type' => 'paragraph', 'data' => ['text' => 'body-secret-' . $slug]]], $slug === 'draft' ? 'draft' : 'published');
}
$setMeta = static function (string $slug, array $meta) use ($p, $ids): void { $p->prepare('UPDATE cms_contents SET meta_json=? WHERE id=?')->execute([json_encode($meta), $ids[$slug]]); };
$setMeta('noindex', ['robots_index' => false]);
$setMeta('paid', ['paid_content_enabled' => true, 'paid_content_price_minor' => 100, 'paid_content_currency' => 'USD', 'paid_content_preview_blocks' => 0]);
$setMeta('foreign', ['canonical_url' => 'https://foreign.invalid/alias']);
$setMeta('explicit', ['seo_title' => 'Explicit title', 'seo_description' => 'Explicit description', 'seo_keywords' => 'explicit']);
$p->prepare('UPDATE cms_contents SET meta_json=? WHERE id=?')->execute(['{broken', $ids['malformed']]);
$boot = static function () use ($root): Application { $_SERVER['REQUEST_URI'] = '/health'; $app=Application::boot($root); set_exception_handler(static function(Throwable $e):void { fwrite(STDERR,$e->getMessage().' at '.$e->getLine().PHP_EOL); exit(1); }); return $app; };
$app = $boot();
$get = static function (string $path, string $ua = '') use (&$app): Response { return $app->handle(new Request('GET', $path, [], [], ['HTTP_USER_AGENT' => $ua, 'HTTP_HOST' => 'untrusted.invalid'])); };
$html = $get('/articles/public')->body();
$doc = new DOMDocument(); @$doc->loadHTML('<?xml encoding="UTF-8">' . $html); $xp = new DOMXPath($doc);
$check($xp->evaluate('string(//title)') === 'Synthetic public | Synthetic Site', 'Actual installed theme title template rendered');
$check($xp->evaluate('string(//meta[@name="description"]/@content)') === 'About Synthetic public', 'Description template reaches actual head');
$check((int) $xp->evaluate('count(//title)') === 1 && (int) $xp->evaluate('count(//meta[@name="keywords"])') === 1, 'TDK tags are not duplicated');
$schemas = $xp->query('//script[@type="application/ld+json"]');
$check($schemas->length === 1 && json_decode($schemas->item(0)->textContent, true)['@type'] === 'Article', 'Actual article JSON-LD parses as Article');
$check(!str_contains($schemas->item(0)->textContent, 'body-secret') && !str_contains($html, 'untrusted.invalid'), 'Schema excludes article body; request Host cannot influence URL');
$explicit = $get('/articles/explicit')->body();
$check(str_contains($explicit, '<title>Explicit title</title>') && str_contains($explicit, 'Explicit description'), 'Explicit content TDK overrides global templates');
$check(!str_contains($get('/articles/noindex')->body(), 'data-cms-seo='), 'Noindex article excluded from generated Schema');
$check(!str_contains($get('/articles/paid')->body(), 'data-cms-seo='), 'Paid article excluded from generated Schema');
$check($get('/articles/draft')->status() === 404, 'Draft not publicly readable');
$home = $get('/')->body();
$check(str_contains($home, '"@type":"WebSite"') && str_contains($home, '<title>Synthetic Site | Synthetic Site</title>'), 'Homepage provider fallback renders actual WebSite and TDK');
$check(str_contains($get('/articles')->body(), '"@type":"CollectionPage"'), 'Archive outputs CollectionPage');
$llms = $get('/llms.txt');
$check($llms->status() === 200 && str_starts_with($llms->headers()['Content-Type'], 'text/plain'), 'llms.txt actual route returns text');
$check(str_contains($llms->body(), '/articles/public') && str_contains($llms->body(), '/articles/explicit'), 'llms lists public articles');
foreach (['/articles/draft', '/articles/noindex', '/articles/paid', '/articles/foreign', '/articles/malformed', 'body-secret', 'password_hash'] as $secret) { $check(!str_contains($llms->body(), $secret), 'llms excludes ' . $secret); }
$robots = $get('/robots.txt')->body();
$check(str_contains($robots, "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /"), 'Training crawler disallow output');
$check(str_contains($robots, "User-agent: OAI-SearchBot\nUser-agent: Claude-SearchBot\nDisallow: /admin") && str_contains($robots, 'Disallow: /preview/'), 'Specific search allow retains private exclusions');
$check($app->handle(new Request('POST', '/llms.txt'))->status() === 405, 'llms is read-only');
$check($app->handle(new Request('HEAD', '/llms.txt'))->status() === 200, 'HEAD dispatch supported');
$reservedId = $repo->create('page', 'Reserved', 'llms.txt', []);
$check($repo->find($reservedId)['slug'] !== 'llms.txt', 'llms.txt content slug cannot shadow the endpoint');

$settings = Settings::fromArray($config); $log = new CrawlerLog($root, $settings);
$app->handle(new Request('GET', '/articles/public', ['token' => 'QUERY-SECRET'], [], ['HTTP_USER_AGENT' => 'spoof GPTBot/1.0 RAW-UA-SECRET', 'REMOTE_ADDR' => '198.51.100.200', 'HTTP_AUTHORIZATION' => 'Bearer SECRET']));
$rows = $log->rows();
$check(count($rows) === 1 && $rows[0]['route'] === '/articles/{slug}' && $rows[0]['verification'] === 'source_unverified', 'Spoofed bot is an unverified aggregate on coarse public route');
$raw = file_get_contents($root . '/storage/logs/seo-crawlers.json');
foreach (['QUERY-SECRET', 'RAW-UA-SECRET', '198.51.100.200', 'Bearer', '/articles/public'] as $private) { $check(!str_contains($raw, $private), 'Crawler ledger excludes ' . $private); }
$log->record(new Request('GET', '/preview/secret', [], [], ['HTTP_USER_AGENT' => 'GPTBot']), Response::html('secret'));
$log->record(new Request('GET', '/articles/public', [], [], ['HTTP_USER_AGENT' => 'FakeGPTBotSuffix']), Response::html(''));
$check($log->rows()[0]['hits'] === 1, 'Private paths and token-substring impersonation not collected');
$path = $root . '/storage/logs/seo-crawlers.json';
$full = [];
for ($i=0; $i<2000; $i++) { $row=$rows[0]; $row['status']=$i; $full[]=$row; }
file_put_contents($path,json_encode($full));
$log->record(new Request('GET','/articles/public',[],[],['HTTP_USER_AGENT'=>'Googlebot']),Response::html(''));
$check(count($log->rows())===2000 && filesize($path)<=524288,'Hard aggregate row and byte caps retained under new identity');
file_put_contents($path,json_encode($rows));
$disabledConfig=$config; $disabledConfig['seo']['crawler_log_enabled']=false;
(new CrawlerLog($root,Settings::fromArray($disabledConfig)))->record(new Request('GET','/articles/public',[],[],['HTTP_USER_AGENT'=>'GPTBot']),Response::html(''));
$check($log->rows()[0]['hits']===1,'Disabling collection prevents new hit increments');

$stale = $rows[0]; $stale['day'] = '2000-01-01'; file_put_contents($path, json_encode([$stale, $rows[0]]));
$check(count($log->rows()) === 1 && !str_contains(file_get_contents($path), '2000-01-01'), 'Retention physically prunes expired rows');
$lock = fopen($path, 'r+'); flock($lock, LOCK_EX | LOCK_NB); $start = microtime(true);
$log->record(new Request('GET', '/articles/public', [], [], ['HTTP_USER_AGENT' => 'GPTBot']), Response::html(''));
$check(microtime(true) - $start < 0.2, 'Locked ledger drops collection without blocking requests'); flock($lock, LOCK_UN); fclose($lock);
file_put_contents($path, str_repeat('x', 524289));
$check($get('/articles/public', 'GPTBot')->status() === 200, 'Oversize ledger cannot fail public request');
file_put_contents($path, '{corrupt'); $check($get('/articles/public', 'GPTBot')->status() === 200, 'Corrupt ledger cannot fail public request');
file_put_contents($path, json_encode($rows));

$baseSeo = ['title' => '<unsafe>', 'description' => 'summary', 'canonical' => 'https://example.invalid/a', 'robots' => 'index,follow'];
$meta = (new SeoPresentation($settings))->metadata($baseSeo, []);
$rendered = SeoHeadRenderer::render('<html><head><title>old</title></head><body></body></html>', 'content', ['seo' => $meta, 'content' => ['status' => 'published', 'content_type' => 'article']]);
$check(str_contains(SeoHeadRenderer::render('<html><head><title>old</title></head><body><svg><title>Diagram label</title></svg></body></html>', 'content', ['seo' => $meta]), '<title>Diagram label</title>'), 'SEO edits never remove body SVG titles');
$check(!str_contains($rendered, '<unsafe>') && str_contains($rendered, '&lt;unsafe&gt;'), 'TDK interpolation escapes HTML');
$check(SeoHeadRenderer::render($rendered, 'content', ['seo' => $meta, 'content' => ['status' => 'published']]) === $rendered, 'Repeated head enhancement is idempotent');
$check(!str_contains(SeoHeadRenderer::render('<html><head></head></html>', 'content', ['seo' => $meta, 'content' => ['status' => 'draft']]), 'application/ld+json'), 'Draft view cannot emit schema');
$check(!str_contains(SeoHeadRenderer::render('<html><head></head></html>', 'content', ['seo' => $meta, 'content' => ['status' => 'published'], 'preview' => true]), 'application/ld+json'), 'Preview view cannot emit schema');
SeoExtensionRegistry::registerJsonLd('example.broken', static function (): array { throw new RuntimeException('broken plugin'); });
SeoExtensionRegistry::registerMeta('example.meta', static fn (array $ctx): array => ['twitter:card' => 'summary', 'robots' => 'unsafe_override']);
SeoExtensionRegistry::registerJsonLd('example.schema', static fn (array $ctx): array => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'plugin </script><script>alert(1)</script>']);
$pluginHtml = $get('/articles/public')->body();
$check(str_contains($pluginHtml, 'name="twitter:card"') && !str_contains($pluginHtml, 'unsafe_override'), 'Public plugin meta output is allowlisted; broken schema provider isolated');
$check(substr_count($pluginHtml, 'application/ld+json') === 1 && str_contains($pluginHtml, 'plugin \\u003C/script'), 'Plugin JSON-LD actually renders, suppresses same-type default and escapes script terminator');

// Real config persistence, administrator guard, CSRF and capability negatives.
$adminAuth = new AdminAuthenticator($p); $adminAuth->createAdmin('owner@test.invalid', 'synthetic-password', 'Owner');
$frontAuth = new FrontUserAuthenticator($p); $member = $frontAuth->register('member@test.invalid', 'synthetic-password', 'Member');
$admin = new AdminController($settings, new Cms\Core\Logging\FileLogger($root . '/app.log'), $root);
$_SESSION = [];
$check($admin->seoCrawlers()->status() === 302, 'Anonymous statistics denied');
$frontAuth->loginUser(['id' => $member, 'email' => 'member@test.invalid', 'display_name' => 'Member']);
$check($admin->seoCrawlers()->status() === 302, 'Normal member statistics denied');
$adminAuth->attempt('owner@test.invalid', 'synthetic-password', '127.0.0.1');
$check($admin->seoCrawlers()->status() === 200 && str_contains($admin->seoCrawlers()->body(), '来源未验证'), 'Founder can view truthful crawler statistics');
$filtered = $app->handle(new Request('GET', '/admin/seo/crawlers', ['platform' => 'domestic', 'agent' => '360Spider', 'status' => '4']));
$check($filtered->status() === 200 && str_contains($filtered->body(), '360搜索') && str_contains($filtered->body(), 'value="domestic" selected'), 'Native admin route receives domestic crawler query filters');
$invalidFilter = $app->handle(new Request('GET', '/admin/seo/crawlers', ['agent' => ['nested' => 'bad']]));
$check($invalidFilter->status() === 400 && ($invalidFilter->headers()['Cache-Control'] ?? '') === 'private, no-store', 'Malformed native statistics query fails privately without a server error');
$_SESSION['admin_user']['capabilities'] = ['content.create'];
$check($admin->siteSettings()->status() === 403 && $admin->seoCrawlers()->status() === 403, 'Limited administrator settings and statistics denied');
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings'))->status() === 403, 'Limited administrator cannot save settings');
$_SESSION['admin_user']['capabilities'] = ['settings.manage'];
$beforeConfig = file_get_contents($root . '/config/app.php');
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings', [], ['site_name' => 'forged']))->status() === 403 && file_get_contents($root . '/config/app.php') === $beforeConfig, 'CSRF rejection preserves config');
$oldBody = ['_csrf' => CsrfToken::get(), 'site_name' => 'Synthetic Site', 'site_url' => 'https://example.invalid', 'robots_index' => '1'];
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings', [], $oldBody))->status() === 302, 'Old settings form submission accepted');
$saved = require $root . '/config/app.php';
$check($saved['seo'] === $config['seo'], 'Old form preserves all SEO GEO options and keys');
$body = $oldBody + ['seo_geo_section' => '1', 'seo_title_template' => '{site_name}: {title}', 'seo_description_template' => '', 'seo_keywords_template' => '', 'seo_schema_enabled' => '1', 'seo_llms_enabled' => '1', 'seo_ai_search_policy' => 'disallow', 'seo_ai_training_policy' => 'inherit', 'seo_crawler_retention_days' => '3'];
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings', [], $body))->status() === 302, 'New section persists via native settings controller');
$saved = require $root . '/config/app.php';
$check($saved['seo']['title_template'] === '{site_name}: {title}' && $saved['seo']['crawler_retention_days'] === 3 && $saved['seo']['crawler_log_enabled'] === false, 'Templates, policy, retention and checkbox disable persisted');
$body['seo_title_template'] = '{execute_php}';
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings', [], $body))->status() === 422, 'Unknown template variable rejected');
$body['seo_title_template'] = ''; $body['seo_crawler_retention_days'] = '999';
$check($admin->siteSettingsSave(new Request('POST', '/admin/settings', [], $body))->status() === 422, 'Unbounded retention rejected');

// Root/subdirectory behavior and disabled/default compatibility.
$_SESSION = []; $config['site']['base_path'] = '/cms'; $config['site']['url'] = 'https://example.invalid/cms'; $save(); $app = $boot();
$sub = $get('/cms/llms.txt');
$check(str_contains($sub->body(), 'https://example.invalid/cms/articles/public') && !str_contains($sub->body(), '/cms/cms/'), 'llms subdirectory URL exactly once');
$check(str_contains($get('/cms/robots.txt')->body(), 'Disallow: /cms/admin'), 'Specific bot private exclusions follow subdirectory');
$config['seo']['robots_index'] = false; $save(); $app = $boot();
$check(!str_contains($get('/cms/llms.txt')->body(), '/articles/public'), 'Global noindex empties llms items');
$blockedRobots = $get('/cms/robots.txt')->body();
$check(str_contains($blockedRobots, "Disallow: /\n") && !str_contains($blockedRobots, 'OAI-SearchBot'), 'Global noindex wins over specific allows');
$config['seo'] = ['robots_index' => true]; $save(); $app = $boot();
$check($get('/cms/llms.txt')->status() === 404 && !str_contains($get('/cms/articles/public')->body(), 'data-cms-seo='), 'Legacy config defaults keep new outputs off');
$check(!str_contains($get('/cms/articles/public')->body(), '| Synthetic Site'), 'Empty templates preserve old theme title behavior');
if (getenv('CMS_ISSUE9_KEEP_FIXTURE') === '1') {
    $config = $browserConfig;
    $config['site']['url'] = getenv('CMS_ISSUE9_FIXTURE_URL') ?: 'http://127.0.0.1:18799';
    $save(); echo 'BROWSER_FIXTURE=' . $root . PHP_EOL;
}
echo "Checks: $checks; real provider requests: 0; production writes: 0\n";

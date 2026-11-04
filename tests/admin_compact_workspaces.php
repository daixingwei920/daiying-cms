<?php

declare(strict_types=1);

// Isolated rendering/field-contract checks. No signing, network or production data.
define('TEST_SOURCE_ROOT', dirname(__DIR__));
define('CMS_ROOT', sys_get_temp_dir() . '/cms-compact-ui-' . bin2hex(random_bytes(5)));
mkdir(CMS_ROOT, 0700);
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Advertising\AdRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Seo\CrawlerStatistics;
use Cms\Core\Support\View;

register_shutdown_function(static function (): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir(CMS_ROOT);
});
$settings = Settings::fromArray(['app' => ['env' => 'testing'], 'database' => ['dsn' => 'sqlite:' . CMS_ROOT . '/fixture.sqlite'], 'security' => ['encryption_key' => 'isolated-ui-only'], 'site' => ['url' => 'https://example.invalid'], 'seo' => ['crawler_retention_days' => 7, 'crawler_log_enabled' => true]]);
$pdo = ConnectionFactory::make($settings);
(new MigrationRunner($pdo, array_map(static fn ($file) => require $file, glob(TEST_SOURCE_ROOT . '/system/migrations/*.php'))))->run();
// Renderer fixture session only; authentication/CSRF are exercised by existing native tests.
$_SESSION = ['admin_user' => ['id' => 1, 'email' => 'fixture@example.invalid', 'display_name' => '隔离预览', 'capabilities' => ['*', 'admin.super']]];
PaymentProviderRegistry::clear();
PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
$controller = new AdminController($settings, new FileLogger(CMS_ROOT . '/ui.log'), CMS_ROOT);
$render = static fn (string $method, mixed ...$args): string => (string) (new ReflectionMethod(AdminController::class, $method))->invoke($controller, ...$args);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
$parse = static function (string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($dom);
};
$attack = '<script>alert("fixture")</script>';
$ads = new AdRepository($pdo);
$ads->saveAdsTxt('google.com, pub-fixture, DIRECT, fixture');
$ads->replaceSlots([
    ['slot_key' => 'home_top', 'label' => '首页顶部', 'placement' => '首页', 'html' => '<p>隔离广告测试</p>' . $attack, 'enabled' => true],
    ['slot_key' => 'article_bottom', 'label' => '文章底部', 'placement' => '文章', 'html' => '<p>第二个广告</p>', 'enabled' => false],
]);
$adHtml = $render('adForm', $ads);
$xp = $parse($adHtml);
$check($xp->query('//section[contains(@class,"compact-ad-slot")]')->length === 3, 'two ads and exactly one blank slot render');
foreach (['slot_key', 'label', 'placement', 'html', 'enabled'] as $field) {
    $check($xp->query('//*[@name="slots[0][' . $field . ']"]')->length === 1, 'ad field preserved: ' . $field);
}
$check($xp->evaluate('string(//textarea[@name="slots[1][html]"])') === '<p>隔离广告测试</p>' . $attack, 'collapsed editor preserves complete original HTML');
$check(!str_contains($adHtml, $attack), 'preview cannot inject script');
$check($xp->evaluate('string(//input[@name="_csrf"]/@value)') === CsrfToken::get(), 'ad form keeps CSRF');
$long = '/articles/' . str_repeat('%E4%B8%AD%E6%96%87-long-url-', 15);
$now = gmdate('c');
(new Cms\Core\UrlMapping\UrlMappingRepository($pdo))->record($long, '/articles/new-fixture', 301, 'fixture');
(new ExportPackageBuilder(CMS_ROOT, $pdo, '1.2.93'))->build('isolated-ui-fixture');
$pages = [];
foreach (['payments' => ['paymentsIndex', '/admin/payments'], 'providers' => ['paymentProviders', '/admin/payments/providers'], 'transfer' => ['transferIndex', '/admin/transfer']] as $name => [$method, $path]) {
    $_SERVER['REQUEST_URI'] = $path;
    $response = $controller->$method(new Request('GET', $path));
    $check($response->status() === 200, $name . ' native renderer returns 200');
    $pages[$name] = $response->body();
}
$xp = $parse($pages['payments']);
foreach (['q', 'status', 'provider_id', 'subject_type', 'currency', 'created_from', 'created_to'] as $field) {
    $check($xp->query('//form[@method="get"]//*[@name="' . $field . '"]')->length === 1, 'payment filter retained: ' . $field);
}
$xp = $parse($pages['providers']);
foreach (['provider_id', 'provider_settings_form', 'display_name', 'status', 'default_provider', 'manual_instructions', 'public_config_json', '_csrf'] as $field) {
    $check($xp->query('//form[@action="/admin/payments/providers/save"]//*[@name="' . $field . '"]')->length === 1, 'provider field retained: ' . $field);
}
$check(substr_count($pages['transfer'], 'php cli.php ') === 6, 'all six restore commands retained');
$check(str_contains($pages['transfer'], $long), 'full encoded mapping URL retained');
$check(str_contains($pages['transfer'], 'onsubmit="return confirm('), 'restore/delete confirmations retained');
$opportunity = ['keyword' => 'PHP CMS', 'primary_url' => $long, 'engine' => 'Not Available', 'type' => 'Missing Landing Page, On-page SEO Gap', 'score' => 55, 'evidence' => str_repeat('Primary landing page is not assigned; ', 8) . $attack, 'suggested_action' => 'Assign a primary landing page', 'last_metric_period' => 'Not Available', 'last_baidu_submission' => 'Not Available', 'index_evidence' => 'unknown'];
$keyword = $render('seoKeywordIndexHtml', [['keyword'=>'PHP CMS','primary_url'=>$long,'status'=>'active','bindings'=>[]]], ['total'=>291, 'active'=>291, 'conflicts'=>24], [], array_fill(0, 3, $opportunity));
$check(str_contains($keyword, View::escape($opportunity['evidence'])) && !str_contains($keyword, $attack), 'full escaped evidence retained inside details');
$check(str_contains($keyword, 'Not Available') && str_contains($keyword, 'unknown'), 'missing data and index state preserved');
$search = $render('seoSearchEnginesHtml', ['status'=>'not_configured'], [['url'=>$long,'status'=>'submitted','http_status'=>200,'created_at'=>$now]], '', ['message'=>'未连接'], []);
$xp = $parse($search);
foreach (['enabled', 'property_url', 'client_id', 'client_secret', 'refresh_token', 'csv_text', 'url'] as $field) {
    $check($xp->query('//*[@name="' . $field . '"]')->length === 1, 'search field retained: ' . $field);
}
$check(str_contains($search, 'Submitted / Submission Accepted 不等于 Indexed'), 'submission evidence qualification retained');
$crawlerRows = [];
foreach (['Googlebot','bingbot','Baiduspider','GPTBot','OAI-SearchBot','ClaudeBot','Bytespider','PetalBot'] as $i => $agent) {
    for ($day=0; $day<7; $day++) { $crawlerRows[] = ['day'=>gmdate('Y-m-d', time()-$day*86400), 'agent'=>$agent, 'route'=>'/articles/{slug}', 'status'=>200, 'hits'=>($i+1)*($day+1)]; }
}
$crawler = new CrawlerStatistics($settings);
$crawlerHtml = $crawler->render($crawlerRows, new Request('GET', '/admin/seo/crawlers')) . $crawler->renderObservation([], new Request('GET', '/admin/seo/crawlers'));
foreach (['ads'=>['/admin/ads',$adHtml], 'keywords'=>['/admin/seo/keywords',$keyword], 'search'=>['/admin/seo/search-engines',$search], 'crawlers'=>['/admin/seo/crawlers',$crawlerHtml]] as $name => [$path,$body]) {
    $_SERVER['REQUEST_URI']=$path;
    $pages[$name]=View::page('隔离布局预览', $body);
}
if (($argv[1] ?? '') === '--preview') {
    $output = $argv[2];
    if (!is_dir($output)) { mkdir($output, 0755, true); }
    $links = '';
    foreach ($pages as $name=>$html) {
        $html = preg_replace('~<link rel="stylesheet" href="[^"]*admin\.css[^"]*">~', '<style>' . file_get_contents(TEST_SOURCE_ROOT . '/public/assets/admin/admin.css') . '</style>', $html);
        $html = preg_replace('~<script src="[^"]*admin\.js[^"]*" defer></script>~', '<script>' . file_get_contents(TEST_SOURCE_ROOT . '/public/assets/admin/admin.js') . '</script>', $html);
        $banner = '<div style="padding:10px;background:#fff4ce">隔离布局预览 · 合成数据 · <a href="index.html">返回七页目录</a></div>';
        $html = preg_replace('~(<main[^>]*>)~', '$1' . $banner, $html, 1);
        // Preview files cannot submit maintenance, payment, imports or external OAuth actions.
        $html = str_replace('</body>', '<script>document.addEventListener("submit",e=>e.preventDefault());document.querySelectorAll("a").forEach(a=>{if(!a.getAttribute("href").startsWith("#")&&a.getAttribute("href")!=="index.html")a.addEventListener("click",e=>e.preventDefault())});</script></body>', $html);
        file_put_contents($output . '/' . $name . '.html', $html);
        $links .= '<li><a href="' . $name . '.html">' . $name . '</a></li>';
    }
    file_put_contents($output.'/index.html','<!doctype html><meta charset="utf-8"><h1>七个后台页面 · 隔离布局预览</h1><p>仅合成数据，表单不提交。</p><ul>'.$links.'</ul>');
}
echo "Compact workspaces PASS ($checks checks; seven isolated pages; no network/signing/production writes)\n";

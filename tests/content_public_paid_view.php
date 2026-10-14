<?php

declare(strict_types=1);

$source = getenv('CMS_MAINTENANCE_SOURCE_ROOT') ?: dirname(__DIR__);
$sourceConfig = require $source . '/config/app.example.php';
require $source . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Payment\PaidContentService;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Recovery\RunMode;
use Cms\Core\Routing\Router;

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$ok) { $failures++; }
};
$root = sys_get_temp_dir() . '/daiying-public-paid-' . bin2hex(random_bytes(4));
define('CMS_ROOT', $root);
mkdir($root . '/storage/logs', 0700, true);
mkdir($root . '/content/uploads', 0700, true);
symlink($source . '/content/themes', $root . '/content/themes');
touch($root . '/storage/installed.lock');
$db = $root . '/cms.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$migrations = [];
foreach (glob($source . '/system/migrations/*.php') as $file) { $migrations[] = require $file; }
(new MigrationRunner($pdo, $migrations))->run();
$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db], 'app' => ['version' => $sourceConfig['app']['version']],
    'site' => ['name' => 'Isolated paid view', 'url' => 'https://isolated.example'],
    'comments' => ['enabled' => false], 'theme' => ['active' => 'default'],
    'security' => ['encryption_key' => 'synthetic-isolated-payment-test-key'],
]);
$logger = new FileLogger($root . '/storage/logs/app.log');
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults(), [], $root);
$controller = new ContentFrontController($root, $settings, $logger);
$viewMethod = new ReflectionMethod(ContentFrontController::class, 'viewModel');
$blocks = [
    ['type' => 'paragraph', 'data' => ['text' => 'PUBLIC TEST PREVIEW']],
    ['type' => 'paragraph', 'data' => ['text' => 'PRIVATE PAID TEST BODY 8294']],
];
$meta = ['paid_content_enabled' => true, 'paid_content_price_minor' => 100,
    'paid_content_currency' => 'USD', 'paid_content_preview_blocks' => 1, 'seo_description' => ''];
$id = $repo->create('article', 'Paid article', 'paid-article', $blocks, 'published', $meta, ['Paid category'], ['Paid tag']);
$storedBlocks = $repo->find($id)['blocks'];
$zeroId = $repo->create('article', 'Zero preview', 'zero-preview', $blocks, 'published', array_replace($meta, ['paid_content_preview_blocks' => 0]));
$freeId = $repo->create('article', 'Free article', 'free-article', [['type' => 'paragraph', 'data' => ['text' => 'FREE ARTICLE BODY']]], 'published');
$pageId = $repo->create('page', 'Free page', 'free-page', [['type' => 'paragraph', 'data' => ['text' => 'FREE PAGE BODY']]], 'published');
$safe = static fn (mixed $value): bool => !str_contains(json_encode($value, JSON_UNESCAPED_UNICODE), 'PRIVATE PAID TEST BODY 8294');
$view = $viewMethod->invoke($controller, $repo->find($id));
$check($safe($view) && count($view['content']['blocks']) === 1, 'S01 whole public ViewModel and raw blocks exclude locked marker.');
$check(!isset($view['content']['blocks_json'], $view['content']['meta_json'], $view['content']['meta']['preview_token']), 'S01 editor JSON and preview credentials are absent from theme data.');
$check(($view['public_excerpt'] ?? null) === 'PUBLIC TEST PREVIEW', 'S01 public_excerpt only uses public preview blocks.');
$zero = $viewMethod->invoke($controller, $repo->find($zeroId));
$check($safe($zero) && $zero['content']['blocks'] === [] && ($zero['public_excerpt'] ?? null) === '', 'S02 zero preview exposes no body and no automatic excerpt.');

$router = new Router();
(new ReflectionMethod(Application::class, 'registerCoreRoutes'))->invoke(null, $router, $settings, $root, $logger, RunMode::NORMAL, new PluginRuntimeRegistry(), new EventDispatcher());
foreach (['/', '/articles', '/search', '/category/paid-category', '/tag/paid-tag', '/articles/paid-article', '/articles/zero-preview'] as $path) {
    $response = $router->dispatch(new Request('GET', $path, $path === '/search' ? ['q' => 'Paid'] : [], [], []));
    $check($response->status() === 200 && $safe($response->body()), 'S05 default-theme route has no private marker: ' . $path);
}
$adjacent = $viewMethod->invoke($controller, $repo->find($freeId), false, null, true);
$check($safe($adjacent), 'S05 adjacent content excerpts are public.');

// Exercise the supported isolated Provider -> checkout -> Core authorization path.
PaymentProviderRegistry::register('test.public-paid', new class implements PaymentProviderInterface {
    public function providerId(): string { return 'test.public-paid'; }
    public function displayName(): string { return 'Isolated Provider'; }
    public function capabilities(): array { return ['payment.create']; }
    public function createPayment(object $command): PaymentResult {
        return new PaymentResult(true, 'test_paid', 'Isolated payment', false, '', ['status' => 'paid', 'provider_payment_id' => 'isolated-' . $command->idempotency_key]);
    }
    public function capturePayment(object $command): PaymentResult { throw new LogicException('Unused'); }
    public function cancelPayment(object $command): PaymentResult { throw new LogicException('Unused'); }
    public function refundPayment(object $command): PaymentResult { throw new LogicException('Unused'); }
    public function getPaymentStatus(object $command): PaymentResult { throw new LogicException('Unused'); }
});
(new PaymentProviderSettingsRepository($pdo, 'synthetic-isolated-payment-test-key'))->save('test.public-paid', 'Isolated Provider', 'enabled');
$paid = new PaidContentService($pdo, $settings);
$checkout = $paid->checkout($id, 'test.public-paid', 'public-paid-test');
parse_str((string) parse_url($checkout['content_url'], PHP_URL_QUERY), $query);
$token = (string) $query['payment_token'];
$request = new Request('GET', '/articles/paid-article', ['payment_token' => $token], [], []);
$authorized = $viewMethod->invoke($controller, $repo->find($id), false, $request);
$check($paid->isAuthorized($id, $token) && str_contains($authorized['rendered_blocks'], 'PRIVATE PAID TEST BODY 8294'), 'S03 valid Core checkout authorization renders full body.');
$check(($authorized['public_excerpt'] ?? null) === 'PUBLIC TEST PREVIEW', 'S03 unlocked body does not change public excerpt.');
$check(($controller->article($request)->headers()['Cache-Control'] ?? '') === 'private, no-store', 'S03 authorized response cannot enter public cache.');
$invalidPaid = $repo->find($id);
$invalidPaid['meta']['paid_content_preview_blocks'] = 'invalid';
$check($safe($viewMethod->invoke($controller, $invalidPaid, false, $request)), 'S04 corrupt paid configuration cannot unlock even with an otherwise valid token.');
foreach (['incorrect-token', $token . 'tampered'] as $badToken) {
    $bad = $viewMethod->invoke($controller, $repo->find($id), false, new Request('GET', '/articles/paid-article', ['payment_token' => $badToken], [], []));
    $check($safe($bad), 'S03 incorrect/tampered authorization remains locked.');
}
$authorizationId = (int) $checkout['authorization']['id'];
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = ? WHERE id = ?')->execute([gmdate('c', time() - 3600), $authorizationId]);
$check($safe($viewMethod->invoke($controller, $repo->find($id), false, $request)), 'S03 expired authorization remains locked.');
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = ?, status = ? WHERE id = ?')->execute([$checkout['authorization']['expires_at'], 'revoked', $authorizationId]);
$check($safe($viewMethod->invoke($controller, $repo->find($id), false, $request)), 'S03 revoked authorization remains locked.');

$corrupt = $repo->find($id);
$corrupt['meta']['paid_content_preview_blocks'] = 'invalid';
$check($safe($viewMethod->invoke($controller, $corrupt)), 'S04 invalid paid config remains locked.');
$malformed = $repo->find($id);
$malformed['meta'] = [];
$malformed['meta_json'] = '{"paid_content_enabled":true,';
$malformed['_meta_json_malformed'] = true;
$check($safe($viewMethod->invoke($controller, $malformed)), 'S04 malformed paid metadata remains locked.');
$pdo->exec('ALTER TABLE cms_payment_provider_settings RENAME TO unavailable_payment_provider_settings');
$pdo->exec('CREATE VIEW cms_payment_provider_settings AS SELECT * FROM unavailable_test_table');
$unavailable = $viewMethod->invoke($controller, $repo->find($id));
$check($safe($unavailable) && ($unavailable['paid_content']['available'] ?? true) === false,
    'S04 payment service/storage exception remains locked.');
$pdo->exec('DROP VIEW cms_payment_provider_settings');
$pdo->exec('ALTER TABLE unavailable_payment_provider_settings RENAME TO cms_payment_provider_settings');
PaymentProviderRegistry::clear();
$check($safe($viewMethod->invoke($controller, $repo->find($id))), 'S04 absent Provider remains locked.');

$preview = $repo->find($id);
$previewResponse = $controller->preview(new Request('GET', '/preview/' . $id, ['token' => $preview['meta']['preview_token']], [], []));
$check($previewResponse->status() === 200 && str_contains($previewResponse->body(), 'PRIVATE PAID TEST BODY 8294')
    && ($previewResponse->headers()['Cache-Control'] ?? '') === 'private, no-store', 'S03 authorized editorial preview is private and still renders body.');
$repo->patch($id, ['meta' => ['seo_description' => 'EXPLICIT PUBLIC SEO DESCRIPTION']]);
$explicit = $viewMethod->invoke($controller, $repo->find($id));
$check($safe($explicit) && ($explicit['public_excerpt'] ?? '') === 'EXPLICIT PUBLIC SEO DESCRIPTION', 'S06 explicitly public SEO description is retained.');
foreach ([$freeId => 'FREE ARTICLE BODY', $pageId => 'FREE PAGE BODY'] as $free => $body) {
    $freeView = $viewMethod->invoke($controller, $repo->find($free));
    $check(str_contains($freeView['rendered_blocks'], $body) && ($freeView['public_excerpt'] ?? '') === $body, 'S06 free article/page content and excerpt remain available.');
}
$check($repo->find($id)['blocks'] === $storedBlocks && str_contains($repo->find($id)['blocks_json'], 'PRIVATE PAID TEST BODY 8294'), 'S06 stored body remains untouched.');

unset($pdo, $repo, $controller, $paid, $router);
$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (new DirectoryIterator($path) as $file) { if (!$file->isDot()) { $cleanup($file->getPathname()); } }
    rmdir($path);
};
$cleanup($root);
exit($failures > 0 ? 1 : 0);

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Routing\BasePath;
use Cms\Core\Security\CsrfToken;

$controller = new AdminController(Settings::fromArray([]), new FileLogger(sys_get_temp_dir() . '/unused-update-flow.log'), dirname(__DIR__));
$render = static fn (array $plan, string $path = ''): string => (new ReflectionMethod(AdminController::class, 'updateVerificationHtml'))->invoke($controller, $plan, $path);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
};
$plan = ['to_version' => '1.2.95', 'from_version' => '1.2.94', 'file_count' => 425, 'migration_count' => 0];
$path = '/fixture/更新包 "&<script>fixture</script>.zip';
$html = $render($plan, $path);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
$form = '//form[@action="/admin/update/execute"]';
$check($xp->query($form)->length === 1, 'verified page has a direct execute POST form');
$check($xp->evaluate('string(' . $form . '/@method)') === 'post', 'continuation cannot execute through GET');
$check($xp->evaluate('string(' . $form . '//input[@name="package_path"]/@value)') === $path, 'exact prepared path survives HTML escaping');
$check(!str_contains($html, '<script>fixture</script>'), 'package path cannot inject HTML');
$check($xp->evaluate('string(' . $form . '//input[@name="_csrf"]/@value)') === CsrfToken::get(), 'continuation retains current CSRF token');
$check($xp->query($form . '//input[@name="confirmation"]')->length === 1 && $xp->evaluate('string(' . $form . '//input[@name="confirmation"]/@value)') === '', 'manual second confirmation remains blank');
$check($xp->evaluate('string(' . $form . '//button)') === '继续执行更新', 'direct continuation has a clear action label');
$href = $xp->evaluate('string(//a[contains(.,"返回系统更新")]/@href)');
parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
$check(($query['prepared_package'] ?? '') === $path && parse_url($href, PHP_URL_PATH) === '/admin/update', 'return link keeps exact package path in the existing prepared-state contract');
$check(str_contains($html, '更新尚未执行') && str_contains($html, '重新校验更新包'), 'verification remains distinct from execution and revalidation');
$check(!str_contains($render($plan), 'action="/admin/update/execute"'), 'metadata-only preview without a package cannot offer execution');
BasePath::setCurrent('/cms');
$subpath = $render($plan, '/fixture/update.zip');
$check(str_contains($subpath, 'action="/cms/admin/update/execute"') && str_contains($subpath, 'href="/cms/admin/update?prepared_package='), 'subdirectory installations retain scoped continuation and return URLs');
BasePath::setCurrent('');
echo "Admin update flow PASS ($checks checks)\n";

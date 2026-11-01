<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Admin\AdminDataView;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Support\View;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
};
$controller = new AdminController(Settings::fromArray([]), new FileLogger(sys_get_temp_dir() . '/cms-ui-unused.log'), dirname(__DIR__));
$render = static fn (string $method, array $value): string => (string) (new ReflectionMethod(AdminController::class, $method))->invoke($controller, $value);
$attack = '<script>alert("fixture")</script>';
$long = str_repeat('Long English explanation / 中文长说明。', 24) . $attack;
$report = ['status' => 'MANUAL_REVIEW', 'static_report' => ['status' => 'PASS', 'findings' => []], 'ai_report' => ['status' => 'MANUAL_REVIEW', 'risk_level' => 'LOW', 'findings' => [
    ['severity' => 'LOW', 'file' => 'content/plugins/example/' . str_repeat('path-', 24) . '.php', 'line' => 19, 'description' => $long, 'recommendation' => '核对完整实现，保留安全校验。' . $attack],
    ['severity' => 'HIGH', 'file' => 'plugin.php', 'line' => 42, 'message' => '隔离演示问题：需要核对能力声明', 'recommendation' => '此条为 UI 测试样例，不是真实审核结论。'],
]]];
$review = (string) (new ReflectionMethod(AdminController::class, 'reviewReportHtml'))->invoke($controller, 'sub_fixture', ['product_id' => 'example.plugin', 'version' => '0.1.0', 'package_type' => 'plugin'], $report);
$check(!str_contains($review, $attack) && str_contains($review, View::escape($attack)), 'untrusted review descriptions/recommendations are escaped');
$check(str_contains($review, View::escape($long)), 'full long bilingual issue descriptions remain available');
$check(substr_count($review, 'class="data-issue"') === 2, 'every finding has a separate expandable card');
$check(str_contains($review, '等待人工审核') && str_contains($review, '>PASS<'), 'static PASS does not replace final manual review status');
$check(!str_contains($review, '<table>'), 'review details no longer use the wide findings table');
$check(!str_contains($review, '<details open class="data-raw"') && str_contains($review, 'class="data-raw"'), 'raw report is available but collapsed initially');
$check(!str_contains($review, 'data-report-panel hidden'), 'without JavaScript all report sections remain accessible');
$failed = (string) (new ReflectionMethod(AdminController::class, 'reviewIssuesHtml'))->invoke($controller, ['status' => 'FAIL', 'summary' => $attack], []);
$check(str_contains($failed, 'FAIL') && str_contains($failed, View::escape($attack)), 'failure without findings remains visible and escaped');
$empty = (string) (new ReflectionMethod(AdminController::class, 'reviewIssuesHtml'))->invoke($controller, [], []);
$check(str_contains($empty, '暂未返回') && !str_contains($empty, '审核通过'), 'missing findings are not presented as approval');
$plan = ['release_id' => 'ISOLATED-UI-EXAMPLE', 'from_version' => '1.2.90', 'to_version' => '9.9.9-fixture',
    'file_count' => 356, 'migration_count' => 0, 'features' => ['admin_compact_density', $attack],
    'acceptance_gates' => ['content_delete_requires_post_csrf_and_permission'],
    'signature' => ['algorithm' => 'Ed25519', 'key_id' => 'fixture-public-id'],
    'compatibility' => ['current_integrity' => 'ok', 'database' => 'SQLite', 'php' => PHP_VERSION],
    'notes' => '仅为 UI 展示样例，未运行签名验证或执行更新。' . $attack];
$verify = $render('updateVerificationHtml', $plan);
$check(str_contains($verify, '未执行更新') && str_contains($verify, '完整验证计划 JSON'), 'verification distinguishes dry run from execution');
$check(str_contains($verify, 'Ed25519') && str_contains($verify, 'fixture-public-id'), 'verification retains signature and compatibility metadata');
$check(!str_contains($verify, $attack), 'update notes and capabilities cannot inject HTML');
$check(!str_contains($verify, '<details open><summary>完整验证计划 JSON'), 'verification JSON is collapsed');
$result = ['status' => 'Completed', 'release_id' => 'ISOLATED-UI-EXAMPLE', 'operation_id' => 'fixture-only', 'health' => ['status' => 'failed', 'checks' => ['fixture' => false]]];
$completed = $render('updateResultHtml', $result);
$check(str_contains($completed, '>failed<') && str_contains($completed, 'fixture-only'), 'execution view preserves failed health and operation identity');
Cms\Core\Routing\BasePath::setCurrent('/cms');
$subpath = (string) (new ReflectionMethod(AdminController::class, 'reviewReportHtml'))->invoke($controller, 'sub_fixture', [], $report);
$check(str_contains($subpath, 'href="/cms/admin/market/submissions"'), 'review action URLs respect subdirectory installation');
Cms\Core\Routing\BasePath::setCurrent('');
$facts = AdminDataView::facts(['标签' => $attack, '空值' => '']);
$check(!str_contains($facts, $attack) && str_contains($facts, '未提供'), 'metadata is escaped and missing values remain explicit');
if (($argv[1] ?? '') === '--preview') {
    $dir = $argv[2];
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $_SERVER['REQUEST_URI'] = '/admin/market/submissions/sub_fixture';
    $fixtureBanner = '<p class="admin-notice-warning">隔离 UI 演示 · 所有记录均为合成测试数据，未审核或执行真实更新。</p>';
    foreach (['review' => $review, 'verification' => $verify, 'result' => $completed] as $name => $body) {
        $page = View::page('隔离界面预览', $fixtureBanner . $body);
        $page = preg_replace('~<link rel="stylesheet" href="[^"]*admin\.css[^"]*">~', '<style>' . file_get_contents(dirname(__DIR__) . '/public/assets/admin/admin.css') . '</style>', $page);
        $page = preg_replace('~<script src="[^"]*admin\.js[^"]*" defer></script>~', '<script>' . file_get_contents(dirname(__DIR__) . '/public/assets/admin/admin.js') . '</script>', $page);
        file_put_contents($dir . '/' . $name . '.html', $page);
    }
}
echo "Admin data workspace PASS ($checks checks)\n";

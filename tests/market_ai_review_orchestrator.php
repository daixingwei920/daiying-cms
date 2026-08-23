<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\MarketServer\AiReviewOrchestrator;
use Cms\Core\MarketServer\AiReviewPolicy;
use Cms\Core\MarketServer\MarketConsoleController;
use Cms\Core\MarketServer\MarketServerRepository;
use Cms\Core\MarketServer\MockAiReviewClient;
use Cms\Core\MarketServer\OpenAiStructuredReviewClient;
use Cms\Core\MarketServer\PackageScanner;
use Cms\Core\MarketServer\ReviewState;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function market_ai_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function market_ai_remove(string $path): void
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

function market_ai_zip(string $path, string $pluginId, string $php): string
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create ZIP fixture.');
    }
    $manifest = [
        'plugin_id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'author' => 'AI Test',
        'entry' => 'plugin.php',
        'capabilities' => [],
    ];
    $zip->addFromString($pluginId . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
    $zip->addFromString($pluginId . '/plugin.php', $php);
    $zip->close();

    return $path;
}

$root = sys_get_temp_dir() . '/cms-market-ai-review-' . bin2hex(random_bytes(4));
market_ai_remove($root);
mkdir($root . '/storage/logs', 0755, true);
mkdir($root . '/storage/database', 0755, true);
$dbPath = $root . '/storage/database/market-ai.sqlite';
$settings = Settings::fromArray([
    'database' => [
        'dsn' => 'sqlite:' . $dbPath,
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$repo = new MarketServerRepository($pdo);
$developer = $repo->registerDeveloper('dev-ai', 'AI Developer', 'ai-dev@example.test', 'secret', 'Developer');
$project = $repo->createProject($developer->id, 'vendor.ai-safe', 'plugin', 'AI Safe Plugin');
$safeZip = market_ai_zip($root . '/safe.zip', 'vendor_ai_safe', "<?php\n// safe plugin fixture\n");
$safeVersion = $repo->submitVersion($project->id, '1.0.0', $safeZip, 'safe release');
$result = (new AiReviewOrchestrator(new MockAiReviewClient(), new MockAiReviewClient()))->review($repo, $safeVersion->id, new PackageScanner());

market_ai_check($result['scan_status'] === 'Passed', 'AI Review runs traditional package scan before model evidence');
market_ai_check($repo->versionStatus($safeVersion->id) === ReviewState::MANUAL_REVIEW, 'AI Review safe result still routes to manual review instead of approval');
market_ai_check(count($repo->aiReviewTasks($safeVersion->id)) === 1, 'AI Review creates a ChatGPT rules task');
market_ai_check(count($repo->aiReviewEvidence($safeVersion->id)) === 1, 'AI Review persists structured evidence');
$aggregate = $repo->aiRiskAggregate($safeVersion->id);
market_ai_check($aggregate['risk_level'] === 'low' && $aggregate['decision_suggestion'] === 'NEEDS_MANUAL_REVIEW', 'AI Review aggregate records low risk with manual review recommendation');

$openAiRequest = null;
$openAiHeaders = [];
$openAiClient = new OpenAiStructuredReviewClient('sk-test', 'gpt-5-mini', 'https://api.openai.com/v1/responses', static function (string $endpoint, array $headers, array $request) use (&$openAiRequest, &$openAiHeaders): array {
    $openAiRequest = $request + ['_endpoint' => $endpoint];
    $openAiHeaders = $headers;

    return [
        'id' => 'resp_test_123',
        'output_text' => json_encode([
            'decision_suggestion' => 'NEEDS_FIX',
            'risk_level' => 'medium',
            'violations' => [],
            'warnings' => ['Missing capability explanation.'],
            'manual_review_focus' => ['Check declared permissions.'],
            'required_fixes' => ['Explain the content.blocks capability.'],
            'confidence' => '0.81',
            'summary' => 'Structured OpenAI review evidence.',
        ], JSON_UNESCAPED_SLASHES),
    ];
});
$openAiEvidence = $openAiClient->review([
    'schema' => 'daiying.market.ai_review.v1',
    'review_type' => 'chatgpt_rules',
    'scan_findings' => [],
]);
market_ai_check(is_array($openAiRequest) && ($openAiRequest['_endpoint'] ?? '') === 'https://api.openai.com/v1/responses' && ($openAiRequest['text']['format']['type'] ?? '') === 'json_schema' && ($openAiRequest['text']['format']['strict'] ?? false) === true && ($openAiRequest['text']['format']['schema']['properties']['decision_suggestion']['enum'][0] ?? '') === 'NEEDS_MANUAL_REVIEW', 'OpenAI structured review adapter builds a strict Responses API JSON Schema request');
market_ai_check(in_array('Authorization: Bearer sk-test', $openAiHeaders, true) && !str_contains(json_encode($openAiRequest, JSON_UNESCAPED_SLASHES) ?: '', 'sk-test'), 'OpenAI structured review adapter keeps API key in headers only');
market_ai_check(($openAiEvidence['provider_request_id'] ?? '') === 'resp_test_123' && ($openAiEvidence['decision_suggestion'] ?? '') === 'NEEDS_FIX' && ($openAiEvidence['required_fixes'][0] ?? '') === 'Explain the content.blocks capability.', 'OpenAI structured review adapter parses Responses API output_text into Daiying evidence');

$policyDecision = (new AiReviewPolicy())->apply($repo, $safeVersion->id, ['decision_suggestion' => 'APPROVE_CANDIDATE', 'findings' => 0], 1, true, 0);
market_ai_check($policyDecision === 'NeedsManualReview' && $repo->versionStatus($safeVersion->id) === ReviewState::MANUAL_REVIEW, 'AI policy never auto-approves even when legacy auto_approve is enabled');

$dangerProject = $repo->createProject($developer->id, 'vendor.ai-danger', 'plugin', 'AI Danger Plugin');
$dangerZip = market_ai_zip($root . '/danger.zip', 'vendor_ai_danger', "<?php\nshell_exec('id');\n");
$dangerVersion = $repo->submitVersion($dangerProject->id, '1.0.0', $dangerZip, 'danger release');
$dangerResult = (new AiReviewOrchestrator(new MockAiReviewClient(), new MockAiReviewClient()))->review($repo, $dangerVersion->id, new PackageScanner());
market_ai_check($dangerResult['tasks'] === 2, 'High-risk scan findings trigger Codex code review task');
market_ai_check($repo->versionStatus($dangerVersion->id) === ReviewState::NEEDS_SECURITY_REVIEW, 'High-risk AI/Codex evidence routes to security review');
market_ai_check(count($repo->aiReviewEvidence($dangerVersion->id)) === 2, 'High-risk review persists both ChatGPT and Codex evidence');
market_ai_check($repo->aiRiskAggregate($dangerVersion->id)['needs_security_review'] === true, 'AI risk aggregation flags security reviewer involvement');

$_SESSION = ['admin_user' => ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin']];
(new AdminAuthenticator($pdo))->createAdmin('admin@example.test', 'admin-secret', 'Admin');
$csrf = CsrfToken::get();
$controller = new MarketConsoleController($settings);
$page = $controller->reviewQueue(new Request('GET', '/admin/market-server/review', ['evidence_version_id' => (string) $dangerVersion->id], [], []));
market_ai_check($page->status() === 200 && str_contains($page->body(), 'AI 审核证据') && str_contains($page->body(), 'NEEDS_SECURITY_REVIEW'), 'Review Admin page displays AI evidence panel and risk suggestion');

market_ai_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " market AI review checks failed.\n");
    exit(1);
}

echo "Market AI review orchestrator tests passed.\n";

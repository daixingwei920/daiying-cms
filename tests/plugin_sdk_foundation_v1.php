<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/Events/OrderPaidEvent.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceContracts.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';

use Cms\Core\Auth\FrontUserLoggedInEvent;
use Cms\Core\Auth\FrontUserService;
use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\Comment\CommentCreatedEvent;
use Cms\Core\Comment\CommentRepository;
use Cms\Core\Content\BlockRenderer;
use Cms\Core\Content\BlockSanitizer;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\PluginContentService;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\BlockRendererRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Security\PasswordHasher;
use Daiying\Commerce\CommerceRepository;
use Daiying\Commerce\Events\OrderPaidEvent;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}\n");
        return;
    }
    echo "[PASS] {$message}\n";
};

$mediaRoot = sys_get_temp_dir() . '/cms-content-test-' . bin2hex(random_bytes(4));
mkdir($mediaRoot, 0700, true);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_09_08_000004_content_foundation_safety.php',
    '2026_08_29_000001_members_comments_schema.php',
    '2026_09_26_000001_plugin_sdk_foundation_v1.php',
    '2026_10_03_000001_seo_keyword_system_p1.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/../system/migrations/' . $migrationFile;
    $migration->up($pdo);
}
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');

$jsonRequest = new Request('POST', '/webhook', [], Request::captureBody('POST', [], 'application/json', '{"ok":true}'), [], '{"ok":true}');
$xmlRequest = new Request('POST', '/webhook', [], Request::captureBody('POST', [], 'text/xml', '<xml><ok>1</ok></xml>'), [], '<xml><ok>1</ok></xml>');
$formRequest = new Request('POST', '/form', [], Request::captureBody('POST', [], 'application/x-www-form-urlencoded', 'a=1&b=two'), [], 'a=1&b=two');
$binaryRequest = new Request('POST', '/bin', [], Request::captureBody('POST', [], 'application/octet-stream', "\0A"), [], "\0A");
$emptyRequest = new Request('POST', '/empty', [], Request::captureBody('POST', [], 'application/json', ''), [], '');
$check($jsonRequest->body === ['ok' => true] && $jsonRequest->rawBody() === '{"ok":true}', 'Request keeps parsed JSON and exact raw body.');
$check($xmlRequest->body === [] && $xmlRequest->rawBody() === '<xml><ok>1</ok></xml>', 'Request keeps exact XML raw body without reparsing.');
$check($formRequest->body === ['a' => '1', 'b' => 'two'] && $formRequest->rawBody() === 'a=1&b=two', 'Request keeps form parsed body and raw body.');
$check($binaryRequest->body === [] && $binaryRequest->rawBody() === "\0A", 'Request keeps unknown/binary raw body safely.');
$check($emptyRequest->body === [] && $emptyRequest->rawBody() === '', 'Request supports empty raw body.');
$check($jsonRequest->withPath('/rewritten')->rawBody() === '{"ok":true}', 'Request::withPath preserves raw body.');

$types = ContentTypeRegistry::defaults();
$contentManifest = new PluginManifest('local.sdkcontent', 'SDK Content', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', ['content.read', 'content.write'], [], [], 'plugin', false, [], [], '');
$contentService = new PluginContentService($contentManifest, new ContentRepository($pdo, $types, [], $mediaRoot));
$created = $contentService->createDraft([
    'type' => 'article',
    'title' => 'Hello SDK',
    'slug' => 'Hello SDK',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Draft text']]],
]);
$check(($created['status'] ?? '') === 'draft' && ($created['slug'] ?? '') === 'hello-sdk', 'ContentService creates sanitized article drafts.');
$page = $contentService->createDraft(['type' => 'page', 'title' => 'About SDK', 'blocks' => []]);
$check(($page['content_type'] ?? '') === 'page', 'ContentService creates page drafts.');
$updated = $contentService->update((int) $created['id'], ['title' => 'Updated SDK']);
$published = $contentService->publish((int) $created['id']);
$list = $contentService->list(['page' => 1, 'per_page' => 10]);
$check(($updated['title'] ?? '') === 'Updated SDK' && ($published['status'] ?? '') === 'published' && $contentService->get((int) $created['id']) !== null && $list['total'] >= 2, 'ContentService supports get/list/update/publish.');
$blockedRead = new PluginContentService(new PluginManifest('local.noread', 'No Read', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new ContentRepository($pdo, $types, [], $mediaRoot));
$blocked = false;
try {
    $blockedRead->createDraft(['title' => 'Nope']);
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'ContentService fails closed without content.write.');
$invalidType = false;
try {
    $contentService->createDraft(['type' => 'unknown', 'title' => 'Bad']);
} catch (Throwable) {
    $invalidType = true;
}
$check($invalidType, 'ContentService rejects invalid content types.');
$invalidBlock = false;
try {
    $contentService->createDraft(['title' => 'Bad Block', 'blocks' => [['type' => 'unsafe_custom', 'data' => []]]]);
} catch (Throwable) {
    $invalidBlock = true;
}
$check($invalidBlock, 'ContentService rejects invalid blocks.');

$registrations = new BlockRegistry();
$registrations->register('local.sdkcontent', 'sdk_owned', 'Owned block');
$registrations->register('local.second', 'sdk_second', 'Second block');
$customRepo = new ContentRepository($pdo, $types, $registrations->all(), $mediaRoot);
$customService = new PluginContentService($contentManifest, $customRepo);
$custom = $customService->createDraft(['title' => 'Custom block', 'blocks' => [
    ['type' => 'sdk_owned', 'plugin_id' => 'local.sdkcontent', 'data' => ['title' => 'custom payload']],
    ['type' => 'sdk_second', 'data' => ['title' => 'second payload']],
]]);
$publishedCustom = $customService->publish((int) $custom['id']);
$check($publishedCustom['status'] === 'published' && $publishedCustom['blocks'][0]['plugin_id'] === 'local.sdkcontent'
    && $publishedCustom['blocks'][1]['plugin_id'] === 'local.second', 'B01/B06 custom draft/get/publish preserves canonical owners without resending blocks.');
$customService->update((int) $custom['id'], ['title' => 'Updated custom title']);
$check($customService->publish((int) $custom['id'])['blocks'] === $publishedCustom['blocks']
    && BlockSanitizer::sanitize(BlockSanitizer::sanitize($custom['blocks'], $registrations->all()), $registrations->all()) === $custom['blocks'],
    'B02 title-only update/publish and repeated sanitize preserve data and ownership.');
$legacyBlocks = $custom['blocks'];
unset($legacyBlocks[0]['plugin_id']);
$pdo->prepare('UPDATE cms_contents SET blocks_json = ? WHERE id = ?')->execute([json_encode($legacyBlocks), $custom['id']]);
$check($customService->publish((int) $custom['id'])['blocks'][0]['plugin_id'] === 'local.sdkcontent', 'B03 legacy ownerless registered block is repaired on save.');
foreach ([['type' => 'sdk_owned', 'plugin_id' => 'local.second', 'data' => []],
    ['type' => 'sdk_second', 'plugin_id' => 'local.sdkcontent', 'data' => []],
    ['type' => 'unknown_owned', 'plugin_id' => 'local.sdkcontent', 'data' => []]] as $forgedBlock) {
    $rejected = false;
    try { $customService->createDraft(['title' => 'Forged owner', 'blocks' => [$forgedBlock]]); }
    catch (Throwable) { $rejected = true; }
    $check($rejected, 'B04 unknown or cross-plugin forged block rejected: ' . $forgedBlock['type']);
}
$saved = $customRepo->find((int) $custom['id']);
$disabledService = new PluginContentService($contentManifest, new ContentRepository($pdo, $types, [], $mediaRoot));
$disabled = $disabledService->update((int) $custom['id'], ['title' => 'Plugin unavailable']);
$check($disabled['blocks'][0]['type'] === 'missing-extension' && $disabled['blocks'][0]['original'] === $saved['blocks'][0],
    'B05 disabled/missing/quarantined registration keeps original data in a nonexecuting placeholder.');
$again = $disabledService->update((int) $custom['id'], ['title' => 'Plugin still unavailable']);
$check($again['blocks'] === $disabled['blocks'], 'B05 missing-extension placeholder is stable across repeated saves.');
$check($customService->publish((int) $custom['id'])['blocks'] === $saved['blocks'], 'B05 restoring registration recovers the original blocks.');
$duplicateOwner = false;
try { $registrations->register('local.second', 'sdk_owned', 'Spoofed'); } catch (PluginException) { $duplicateOwner = true; }
$check($duplicateOwner, 'B06 registration cannot replace another plugin owner.');

$events = new EventDispatcher();
$loginEvents = [];
$events->listen(FrontUserLoggedInEvent::class, static function (object $event) use (&$loginEvents): void {
    $loginEvents[] = $event;
});
$pdo->prepare('INSERT INTO cms_front_users (email, password_hash, display_name, status, created_at, updated_at) VALUES (:email, :hash, :name, :status, :created_at, :updated_at)')
    ->execute([':email' => 'user@example.test', ':hash' => PasswordHasher::hash('secret-pass'), ':name' => 'SDK User', ':status' => 'active', ':created_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
$userId = (int) $pdo->lastInsertId();
$trustedAuth = new FrontUserService(new PluginManifest('official.auth', 'Auth', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'trusted_php', ['auth.read', 'auth.login', 'auth.external_identity'], [], [], 'plugin', true, [], [], ''), $pdo, new FrontUserAuthenticator($pdo, $events), $events);
$check(($trustedAuth->verifyCredentials('user@example.test', 'secret-pass')['id'] ?? 0) === $userId, 'FrontUserService verifies credentials without logging in.');
$trustedAuth->bindExternalIdentity($userId, 'wechat', 'openid-1', ['nickname' => 'Nick', 'secret' => 'drop-me']);
$check(($trustedAuth->findByExternalIdentity('wechat', 'openid-1')['id'] ?? 0) === $userId, 'FrontUserService binds and finds external identity.');
$collision = false;
try {
    $trustedAuth->bindExternalIdentity($userId, 'wechat', 'openid-1');
} catch (PluginException) {
    $collision = true;
}
$check($collision, 'External identity unique provider/subject collision fails closed.');
$trustedAuth->loginById($userId, ['provider' => 'wechat', 'external_subject' => 'openid-1']);
$check(($trustedAuth->current()['id'] ?? 0) === $userId && count($loginEvents) === 1, 'FrontUserService login uses Core session and dispatches login event.');
$untrusted = new FrontUserService(new PluginManifest('vendor.auth', 'Auth', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', ['auth.read', 'auth.login'], [], [], 'plugin', false, [], [], ''), $pdo, new FrontUserAuthenticator($pdo), new EventDispatcher());
$loginBlocked = false;
try {
    $untrusted->loginById($userId);
} catch (PluginException) {
    $loginBlocked = true;
}
$check($loginBlocked, 'auth.login requires trusted or bundled grant, not manifest text alone.');
$identityBlocked = false;
try {
    (new FrontUserService(new PluginManifest('vendor.noidentity', 'Auth', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', ['auth.read'], [], [], 'plugin', false, [], [], ''), $pdo, new FrontUserAuthenticator($pdo), new EventDispatcher()))->bindExternalIdentity($userId, 'github', 'sub');
} catch (PluginException) {
    $identityBlocked = true;
}
$check($identityBlocked, 'External identity writes require auth.external_identity.');

BlockRendererRegistry::reset();
$blockContext = new PluginContext(new PluginManifest('local.blocks', 'Blocks', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', ['blocks.register'], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.blocks'), null);
$blockContext->registerBlockRenderer('sdk_card', static function (array $block, array $context): string {
    return '<section data-content="' . (int) ($context['contentId'] ?? 0) . '">' . htmlspecialchars((string) ($block['data']['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</section>';
});
$html = (new BlockRenderer([], [], [], 77))->render([['type' => 'sdk_card', 'data' => ['title' => '<SDK>']]]);
$check(str_contains($html, '&lt;SDK&gt;') && str_contains($html, 'data-content="77"'), 'BlockRenderer renders plugin block renderers with context.');
$duplicateBlocked = false;
try {
    (new PluginContext(new PluginManifest('local.otherblocks', 'Blocks', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'api', ['blocks.register'], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.otherblocks'), null))->registerBlockRenderer('sdk_card', static fn (): string => '');
} catch (PluginException) {
    $duplicateBlocked = true;
}
$check($duplicateBlocked, 'BlockRendererRegistry rejects duplicate renderer ownership.');
$check((new BlockRenderer())->render([['type' => 'sdk_card', 'plugin_id' => 'local.otherblocks', 'data' => ['title' => 'forged']]]) === '', 'B04 renderer refuses a forged owner even outside storage.');
$check((new BlockRenderer())->render([['type' => 'missing_sdk_card', 'data' => ['title' => 'x']]]) === '', 'Missing plugin block renderer does not fatal.');

$commentEvents = [];
$commentDispatcher = new EventDispatcher();
$commentDispatcher->listen(CommentCreatedEvent::class, static function (object $event) use (&$commentEvents): void {
    $commentEvents[] = $event;
});
$commentId = (new CommentRepository($pdo, $commentDispatcher))->create(['content_id' => (int) $created['id'], 'author_name' => 'Reader', 'author_email' => 'reader@example.test', 'body' => 'Nice']);
$check($commentId > 0 && count($commentEvents) === 1 && $commentEvents[0]->commentId === $commentId, 'CommentRepository dispatches CommentCreatedEvent after persistence.');

foreach ([
    '001_commerce_core.php',
    '002_logistics_events.php',
    '003_price_transparency.php',
    '004_source_verification_governance.php',
    '005_ai_modules.php',
    '006_distribution_modules.php',
] as $commerceMigrationFile) {
    $commerceMigration = require __DIR__ . '/../content/plugins/official.commerce/migrations/' . $commerceMigrationFile;
    ($commerceMigration['up'])($pdo);
}
$commerceEvents = [];
$commerceDispatcher = new EventDispatcher();
$commerceDispatcher->listen(OrderPaidEvent::class, static function (object $event) use (&$commerceEvents): void {
    $commerceEvents[] = $event;
});
$commerce = new CommerceRepository($pdo, 'commerce-secret', [$commerceDispatcher, 'dispatch']);
$productId = $commerce->saveProduct(['name' => 'SDK Product', 'sku' => 'SDK-1', 'status' => 'active', 'price_minor' => 1200, 'currency' => 'CNY', 'stock_quantity' => 2]);
$actionId = $commerce->saveAction(['product_id' => $productId, 'action_type' => 'site_checkout', 'label' => 'Buy']);
$order = $commerce->createPendingOrder($productId, null, $actionId, 1, 'manual', 'sdk-order-1', 'claim', ['email' => 'buyer@example.test']);
$commerce->markOrderPaid((int) $order['id']);
$commerce->markOrderPaid((int) $order['id']);
$check(count($commerceEvents) === 1 && $commerceEvents[0]->orderId === (int) $order['id'] && $commerceEvents[0]->buyerEmail === 'buyer@example.test', 'Commerce dispatches OrderPaidEvent once on pending-to-paid transition.');

if ($failures > 0) {
    fwrite(STDERR, "Plugin SDK Foundation V1 tests FAILED: {$failures}\n");
    exit(1);
}

echo "Plugin SDK Foundation V1 tests PASS\n";

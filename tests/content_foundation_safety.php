<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Content\ContentAutosaveRepository;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentRevisionRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\CustomFieldDefinition;
use Cms\Core\Content\CustomFieldRegistry;
use Cms\Core\Content\SearchResourceRegistry;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Seo\SeoExtensionRegistry;
use Cms\Core\UrlMapping\RedirectManager;
use Cms\Core\UrlMapping\UrlMappingRepository;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000004_export_import_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_09_08_000004_content_foundation_safety.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/../system/migrations/' . $migrationFile;
    $migration->up($pdo);
}
$repeat = require __DIR__ . '/../system/migrations/2026_09_08_000004_content_foundation_safety.php';
$repeat->up($pdo);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');

$types = ContentTypeRegistry::defaults();
$types->register('product_note', 'Product Note', ['title', 'slug'], ['searchable' => true, 'rest_exposed' => false, 'revision_support' => true, 'taxonomy' => ['tag']]);
$check($types->has('product_note') && ($types->all()['product_note']['revision_support'] ?? false), 'ContentTypeRegistry supports capability metadata');

$fields = new CustomFieldRegistry();
$fields->register('article', new CustomFieldDefinition('rating', 'integer', true));
$fields->register('article', new CustomFieldDefinition('source_url', 'url', false));
$validated = $fields->validate('article', ['rating' => '5', 'source_url' => 'https://example.com/post']);
$check(($validated['rating'] ?? 0) === 5 && ($validated['source_url'] ?? '') === 'https://example.com/post', 'CustomFieldRegistry validates typed fields');
$selectFailed = false;
try {
    (new CustomFieldDefinition('kind', 'select', false, null, ['a', 'b']))->validate('c');
} catch (Throwable) {
    $selectFailed = true;
}
$check($selectFailed, 'CustomFieldDefinition rejects invalid select values');

$repo = new ContentRepository($pdo, $types);
$contentId = $repo->create('article', 'Original', 'original', [['type' => 'paragraph', 'data' => ['text' => 'one']]], 'draft', [], ['News'], ['CMS']);
$repo->update($contentId, 'article', 'Updated', 'updated', [['type' => 'paragraph', 'data' => ['text' => 'two']]], 'published', []);
$check($repo->revisionCount($contentId) === 1, 'ContentRepository records a revision before update');
$revisions = (new ContentRevisionRepository($pdo))->listForContent($contentId);
$check(($revisions[0]['title'] ?? '') === 'Original', 'Content revisions preserve previous content snapshot');
$repo->delete($contentId);
$trashed = $repo->find($contentId);
$check(($trashed['status'] ?? '') === 'trash' && ($trashed['deleted_at'] ?? '') !== '', 'Content delete defaults to trash');
$repo->restoreFromTrash($contentId, 'draft');
$restored = $repo->find($contentId);
$check(($restored['status'] ?? '') === 'draft' && ($restored['deleted_at'] ?? null) === null, 'Trashed content can be restored');
$repo->hardDelete($contentId);
$check($repo->find($contentId) === null, 'Content hardDelete permanently removes content when explicitly requested');

$autosaves = new ContentAutosaveRepository($pdo);
$autosaveId = $autosaves->save(null, 10, 'article', 'Draft autosave', 'draft-autosave', [['type' => 'paragraph', 'data' => ['text' => 'autosave']]], ['seo_title' => 'Draft']);
$latest = $autosaves->latest(null, 10);
$check($autosaveId > 0 && ($latest['title'] ?? '') === 'Draft autosave' && ($latest['meta']['seo_title'] ?? '') === 'Draft', 'Content autosave stores recoverable drafts per actor');
$autosaves->discard($autosaveId, 10);
$check($autosaves->latest(null, 10) === null, 'Content autosave can be discarded by owner');

SearchResourceRegistry::register('products', 'Products', 'official.commerce');
$check(isset(SearchResourceRegistry::all()['products']), 'SearchResourceRegistry allows plugin searchable resources');
SeoExtensionRegistry::registerJsonLd('unit.product', static fn (array $context): array => ['@type' => 'Product', 'name' => (string) ($context['name'] ?? '')]);
SeoExtensionRegistry::registerMeta('unit.twitter', static fn (array $context): array => ['twitter:card' => 'summary_large_image']);
$check((SeoExtensionRegistry::jsonLd(['name' => 'Widget'])[0]['name'] ?? '') === 'Widget', 'SEO JSON-LD extension registry works');
$check((SeoExtensionRegistry::meta([])['twitter:card'] ?? '') === 'summary_large_image', 'SEO meta extension registry works');

$redirects = new RedirectManager($pdo);
$redirects->save('/old', '/new', 301);
$redirects->hit('/old');
$mapping = (new UrlMappingRepository($pdo))->recent(1)[0] ?? [];
$check(($mapping['source_url'] ?? '') === '/old', 'RedirectManager records redirects through UrlMappingRepository');
$loopBlocked = false;
try {
    $redirects->save('/new', '/old', 301);
} catch (Throwable) {
    $loopBlocked = true;
}
$check($loopBlocked, 'RedirectManager blocks redirect loops');

$manifest = new PluginManifest('local.content', 'Content Ext', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', ['content.type', 'content.field', 'search.register', 'seo.extend'], [], [], 'plugin', false, [], [], '');
$context = new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.content'), null, null, null, false, '', null, null, null, null, null, $types, $fields);
$context->registerContentType('case_study', 'Case Study', ['title'], ['searchable' => true]);
$context->registerCustomField('case_study', new CustomFieldDefinition('client', 'text'));
$context->registerSearchResource('case_studies', 'Case Studies');
$context->registerSeoMeta('unit.case', static fn (array $context): array => ['og:type' => 'article']);
$check($types->has('case_study') && isset($fields->schema('case_study')['client']) && isset(SearchResourceRegistry::all()['case_studies']), 'PluginContext exposes content foundation registration APIs');

$blockedContext = new PluginContext(new PluginManifest('local.blocked', 'Blocked', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.blocked'), null, null, null, false, '', null, null, null, null, null, $types, $fields);
$blocked = false;
try {
    $blockedContext->registerSearchResource('blocked', 'Blocked');
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'PluginContext content/search registration requires declared capabilities');

echo "Content foundation safety tests PASS\n";

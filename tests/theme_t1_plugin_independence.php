<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('plugins');
$pdo = ConnectionFactory::make(Settings::load($root));
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$repo->create('article', '插件关闭也能显示', 'plugin-free', [
    ['type' => 'missing-extension', 'plugin_id' => 'optional_block', 'data' => []],
], 'published');

$app = theme_t1_app($root);
$withoutPlugins = $app->handle(new Request('GET', '/articles/plugin-free'));

$now = gmdate('c');
foreach (['official.commerce', 'official.cj-dropshipping', 'official.payment-fixture'] as $pluginId) {
    $pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at, source, review_status) VALUES (:id, :name, :version, :author, :status, :trust, :capabilities, :installed, :updated, :source, :review)')
        ->execute([':id' => $pluginId, ':name' => $pluginId, ':version' => '1.0.0', ':author' => 'Fixture', ':status' => 'Enabled', ':trust' => 'official', ':capabilities' => '[]', ':installed' => $now, ':updated' => $now, ':source' => 'fixture', ':review' => 'Approved']);
}
$withPlugins = theme_t1_app($root)->handle(new Request('GET', '/'));

theme_t1_check($withoutPlugins->status() === 200 && str_contains($withoutPlugins->body(), '此内容需要插件'), 'renders missing plugin block placeholder without plugin dependencies');
theme_t1_check($withPlugins->status() === 200 && str_contains($withPlugins->body(), '插件关闭也能显示'), 'renders home when Commerce, CJ and Payment fixture plugins are enabled');
theme_t1_check(!str_contains($withPlugins->body(), 'official.commerce') && !str_contains($withPlugins->body(), 'official.cj-dropshipping') && !str_contains($withPlugins->body(), 'official.payment'), 'theme does not hard-code Commerce, CJ or Payment plugin IDs');

theme_t1_remove($root);
echo "Theme T1 plugin independence tests passed.\n";

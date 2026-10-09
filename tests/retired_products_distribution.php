<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
};
foreach (['build_exact_commit_installer', 'build_full_install_package', 'release_parity_gate'] as $index => $script) {
    $source = (string) file_get_contents($root . '/scripts/' . $script . '.php');
    $start = strpos($source, 'function shouldPackage(string $file): bool');
    $end = strpos($source, "\nfunction ", $start + 1);
    $assert($start !== false && $end !== false, 'Packaging filter must exist: ' . $script);
    $function = 'retired_filter_' . $index;
    eval(str_replace('function shouldPackage(', 'function ' . $function . '(', substr($source, $start, $end - $start)));
    foreach (['content/plugins/official.novel-collector/plugin.json', 'content/themes/daiying_novel/theme.json', 'content/plugins/official.video-collector/plugin.json', 'content/themes/daiying-video/theme.json'] as $path) {
        $assert(!$function($path), 'Retired product must not be newly distributed: ' . $script);
    }
    foreach (['content/themes/default/theme.json', 'content/themes/daiying_media/theme.json', 'content/themes/safe/theme.json', 'content/plugins/official.mail/plugin.json', 'system/migrations/2026_09_07_000001_official_plugins_registry.php'] as $path) {
        $assert($function($path), 'Maintained products/history must remain eligible: ' . $path);
    }
}
require_once $root . '/system/core/Plugin/OfficialPluginRegistry.php';
$registry = new Cms\Core\Plugin\OfficialPluginRegistry($root);
$assert($registry->isTrustedBundled('official.novel-collector', $root . '/content/plugins/official.novel-collector'), 'Existing customer legacy trust must be preserved.');
$assert(in_array('novel_', $registry->tablePrefixes('official.novel-collector'), true), 'Legacy table ownership must be preserved.');
$assert($registry->isTrustedBundled('official.video-collector', $root . '/content/plugins/official.video-collector'), 'Existing video customer legacy trust must be preserved.');
$assert(in_array('video_', $registry->tablePrefixes('official.video-collector'), true), 'Legacy video table ownership must be preserved.');
$assert(!is_file($root . '/tests/theme_productization_contract.php'), 'Retired-only acceptance must not be required by Core.');
$assert(is_file($root . '/tests/retired/theme_productization_contract.php'), 'Retain historical productization assertions.');
foreach (['default', 'daiying_media', 'safe'] as $id) {
    $manifest = json_decode((string) file_get_contents($root . '/content/themes/' . $id . '/theme.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(($manifest['theme_id'] ?? '') === $id, 'Maintained theme manifest remains present: ' . $id);
}
foreach (['DAIYING_NOVEL = RETIRED', 'DAIYING_VIDEO = RETIRED'] as $status) {
    $assert(str_contains((string) file_get_contents($root . '/docs/retired-products.md'), $status), 'Explicit retirement decision must be recorded.');
}
echo "retired_products_distribution: PASS ($checks assertions)\n";

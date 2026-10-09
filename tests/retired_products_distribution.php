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
    foreach (['content/plugins/official.novel-collector/plugin.json', 'content/themes/daiying_novel/theme.json'] as $path) {
        $assert(!$function($path), 'Retired product must not be newly distributed: ' . $script);
    }
    foreach (['content/plugins/official.video-collector/plugin.json', 'content/themes/daiying-video/theme.json', 'content/themes/default/theme.json', 'content/plugins/official.mail/plugin.json', 'system/migrations/2026_09_07_000001_official_plugins_registry.php'] as $path) {
        $assert($function($path), 'Maintained products/history must remain eligible: ' . $path);
    }
}
require_once $root . '/system/core/Plugin/OfficialPluginRegistry.php';
$registry = new Cms\Core\Plugin\OfficialPluginRegistry($root);
$assert($registry->isTrustedBundled('official.novel-collector', $root . '/content/plugins/official.novel-collector'), 'Existing customer legacy trust must be preserved.');
$assert(in_array('novel_', $registry->tablePrefixes('official.novel-collector'), true), 'Legacy table ownership must be preserved.');
$contract = (string) file_get_contents($root . '/tests/theme_productization_contract.php');
$assert(!str_contains($contract, 'novel'), 'No retired product acceptance in shared gate.');
foreach (['Video theme version must be 1.0.0.', 'Video theme must require official.video-collector.', 'Video collector must register /videos/search.'] as $assertion) {
    $assert(str_contains($contract, $assertion), 'Retain maintained video assertion: ' . $assertion);
}
$assert(str_contains((string) file_get_contents($root . '/docs/retired-products.md'), 'DAIYING_NOVEL = RETIRED'), 'Explicit retirement decision must be recorded.');
echo "retired_products_distribution: PASS ($checks assertions)\n";

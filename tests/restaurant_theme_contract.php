<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('RESTAURANT_TEST_ROOT') ?: __DIR__ . '/..');

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Theme\ThemeManifest;
use Cms\Core\Theme\ThemeRuntime;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$themeDir = CMS_ROOT . '/content/themes/daiying-restaurant-storefront';
$manifest = json_decode((string) file_get_contents($themeDir . '/theme.json'), true);
$assert(is_array($manifest), 'Restaurant storefront theme manifest is valid JSON.');
$parsed = ThemeManifest::fromArray($manifest);
$assert($parsed->id === 'daiying-restaurant-storefront', 'Theme uses stable restaurant storefront id.');
$assert($parsed->name === '戴影餐厅外卖主题', 'Theme name matches the Owner-required Chinese product name.');
$assert(in_array('official.restaurant', $parsed->recommendedPlugins, true) && $parsed->requiredPlugins === [], 'Theme recommends restaurant plugin and can render without it.');

foreach (['storefront_image_url', 'desktop_focal_point', 'mobile_focal_point', 'show_house_favorites', 'show_menu', 'show_gallery', 'show_about', 'show_location'] as $setting) {
    $assert(isset($parsed->settingsSchema[$setting]), 'Theme setting exists: ' . $setting);
}

foreach (glob($themeDir . '/templates/*.php') ?: [] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    $assert($code === 0, basename($file) . ' passes PHP syntax.');
}

$themeFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $themeFiles[] = $file->getPathname();
    }
}
$combined = '';
foreach ($themeFiles as $file) {
    $combined .= "\n--- " . basename($file) . "\n" . (string) file_get_contents($file);
}

$assert(str_contains($combined, '/restaurant/api/storefront'), 'Theme consumes the public storefront API.');
$assert(str_contains($combined, '/restaurant/api/menu'), 'Theme consumes the public menu API.');
$assert(!preg_match('/restaurant_(locations|menu_items|order_tickets|print_jobs|ticket_items|print_devices|kitchen_stations)/', $combined), 'Theme does not directly reference restaurant private tables.');
$assert(!preg_match('/\\b(CURLOPT_|PDO|SELECT\\s+\\*)/i', $combined), 'Theme does not perform server-side plugin storage or network logic.');
$assert(str_contains($combined, "'zh-CN'") && str_contains($combined, "'en-US'") && str_contains($combined, "'es-US'"), 'Theme includes zh-CN, en-US, and es-US chrome translations.');
$assert(str_contains($combined, 'localStorage') && str_contains($combined, 'restaurant_locale'), 'Theme persists selected language.');
$assert(str_contains($combined, 'restaurant_service_mode'), 'Theme persists pickup/delivery preference.');
$assert(str_contains($combined, 'data-service-mode="delivery"') && str_contains($combined, 'data-fulfillment-time') && str_contains($combined, 'data-delivery-address'), 'Theme exposes delivery and preorder checkout controls.');
$assert(str_contains($combined, "'checkout.time'") && str_contains($combined, "'checkout.postal'") && str_contains($combined, "'checkout.deliveryFee'"), 'Preorder and delivery UI labels are translated.');
$assert(str_contains($combined, 'data-hero-image') && str_contains($combined, '--rs-hero-focus-mobile'), 'Theme supports storefront hero image and mobile focal point.');
$assert(str_contains($combined, 'data-category-list') && str_contains($combined, 'data-search-input'), 'Theme has category navigation and menu search.');
$assert(str_contains($combined, 'data-menu-section-list') && str_contains($combined, 'data-menu-section') && str_contains($combined, "'menuSection.kids'") && str_contains($combined, "'menuSection.dinner'"), 'Theme has translated lunch, dinner, and kids menu navigation.');
$assert(str_contains($combined, 'data-cart-bar'), 'Theme includes mobile sticky cart shell.');
$assert(substr_count($combined, "'cart.tax':") === 3 && substr_count($combined, "'tax.pending':") === 3, 'Tax and unconfigured-tax labels are present in Chinese, English, and Spanish.');
$assert(str_contains($combined, 'data-card-notes') && str_contains($combined, 'productNotes') && str_contains($combined, 'rememberNotes(item.id'), 'Each dish has its own note draft, transferred and cleared on successful add.');
$assert(str_contains($combined, 'data-addon-choice') && str_contains($combined, 'data-choose-addon') && str_contains($combined, "group.id !== 'special_requests'"), 'Theme uses a single Add picker and hides legacy free-removal presets without deleting business data.');

$runtime = new ThemeRuntime($parsed, $themeDir, [
    'restaurant_name' => 'Golden Noodle',
    'primary_color' => '#146c43',
    'secondary_color' => '#c2410c',
    'accent_color' => '#f2b705',
]);
$html = $runtime->render('home', ['site_name' => 'Golden Noodle']);
$assert(str_contains($html, 'Golden Noodle'), 'Theme home renders configured restaurant identity.');
$siteLogoHtml = $runtime->render('home', ['site_name' => 'Golden Noodle', 'site_logo_url' => '/media/site-logo.png']);
$assert(str_contains($siteLogoHtml, 'data-site-logo="/media/site-logo.png"'), 'Core site logo has priority in the theme.');
$assert(str_contains($html, 'data-storefront-api="/restaurant/api/storefront"'), 'Rendered home exposes storefront API endpoint.');
$assert(str_contains($html, 'data-cart-pricing') && str_contains($html, 'name="expected_total_minor"'), 'Rendered cart exposes server price breakdown and expected-total guard.');
$assert(str_contains($html, 'Daiying CMS Restaurant'), 'Rendered home includes Daiying restaurant footer attribution.');
$seoRuntime = new ThemeRuntime($parsed, $themeDir, [
    'restaurant_name' => 'Test Restaurant',
    'seo_address' => '100 Test Street',
    'seo_phone' => '555-0100',
    'seo_hours' => 'Mo-Fr 11:00-21:00',
    'canonical_url' => 'https://example.com/',
]);
$seoHtml = $seoRuntime->render('home');
$assert(str_contains($seoHtml, 'application/ld+json') && str_contains($seoHtml, '100 Test Street') && str_contains($seoHtml, 'https://example.com/'), 'Configured real Local SEO fields render server-side Restaurant schema.');
$assert(!str_contains($seoHtml, 'aggregateRating') && !str_contains($seoHtml, 'reviewCount'), 'Theme does not invent review or rating data.');

if ($failures > 0) {
    fwrite(STDERR, "restaurant_theme_contract failed with {$failures} failure(s).\n");
    exit(1);
}

echo "restaurant_theme_contract: PASS\n";

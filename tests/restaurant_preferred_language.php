<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('RESTAURANT_TEST_ROOT') ?: dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
foreach (['RestaurantRepository', 'RestaurantPrintService', 'RestaurantTicketService', 'RestaurantController'] as $class) {
    require CMS_ROOT . '/content/plugins/official.restaurant/src/' . $class . '.php';
}

use Cms\Core\Http\Request;
use Official\Restaurant\RestaurantController;
use Official\Restaurant\RestaurantPrintService;
use Official\Restaurant\RestaurantRepository;
use Official\Restaurant\RestaurantTicketService;

session_start();
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (glob(CMS_ROOT . '/content/plugins/official.restaurant/migrations/*.php') as $filename) {
    (require $filename)['up']($pdo);
}
$repo = new RestaurantRepository($pdo);
$printer = new RestaurantPrintService($repo);
$controller = new RestaurantController($repo, new RestaurantTicketService($repo, $printer), $printer);
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
    echo '[PASS] ' . $message . PHP_EOL;
};
$check($repo->defaultLocale() === 'en-US', 'Unconfigured restaurant defaults to English.');
$check($repo->storefrontPayload()['locale'] === 'en-US', 'Storefront uses English without a locale query.');
$check($repo->menuPayload()['locale'] === 'en-US', 'Menu uses English without a locale query.');
$check(str_contains($controller->storefrontSettings()->body(), 'value="en-US" selected'), 'Admin selector initially selects English.');
$settings = ['status' => 'closed', 'tax_rate_basis_points' => 1000, 'delivery_taxable' => false, 'gallery' => ['preserve-existing-value']];
foreach (['en-US', 'zh-CN', 'es-US'] as $locale) {
    $response = $controller->saveStorefrontSettings(new Request('POST', '/admin/restaurant/storefront/save', [], [
        'name' => 'ISOLATED LANGUAGE TEST', 'timezone' => 'America/New_York',
        'settings_json' => json_encode($settings), 'default_locale_present' => '1', 'default_locale' => $locale,
    ]));
    $stored = json_decode($repo->storefrontSettings()['settings_json'], true, 512, JSON_THROW_ON_ERROR);
    $check($response->status() === 302 && $repo->defaultLocale() === $locale, 'Admin saves preferred language: ' . $locale);
    $check($stored['gallery'] === $settings['gallery'] && $stored['tax_rate_basis_points'] === 1000 && $stored['delivery_taxable'] === false, 'Language save preserves unrelated settings: ' . $locale);
    $payload = json_decode($controller->apiStorefront(new Request('GET', '/restaurant/api/storefront'))->body(), true, 512, JSON_THROW_ON_ERROR);
    $check($payload['locale'] === $locale && $payload['default_locale'] === $locale, 'Public storefront follows configured preference: ' . $locale);
    $menu = json_decode($controller->apiMenu(new Request('GET', '/restaurant/api/menu'))->body(), true, 512, JSON_THROW_ON_ERROR);
    $check($menu['locale'] === $locale, 'Public menu follows configured preference: ' . $locale);
    $explicit = $repo->storefrontPayload('en-US');
    $check($explicit['locale'] === 'en-US' && $explicit['default_locale'] === $locale, 'Explicit customer language overrides default: ' . $locale);
    $check(str_contains($controller->storefrontSettings()->body(), 'value="' . $locale . '" selected'), 'Admin form restores saved selection: ' . $locale);
}
$before = $repo->storefrontSettings();
foreach (['fr-FR', '', '<script>', ['en-US']] as $invalid) {
    $rejected = false;
    try {
        $repo->saveStorefrontSettings(['name' => 'INVALID', 'settings' => $settings + ['default_locale' => $invalid]]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected && $repo->storefrontSettings() === $before, 'Invalid preference rejected without modifying stored settings.');
}
$response = $controller->saveStorefrontSettings(new Request('POST', '/admin/restaurant/storefront/save', [], [
    'name' => 'INVALID', 'settings_json' => '{}', 'default_locale_present' => '1', 'default_locale' => 'invalid',
]));
$check($response->status() === 400 && $repo->storefrontSettings() === $before, 'Admin rejects unsupported language without saving.');
$check($repo->defaultLocale(['default_locale' => 'unsupported']) === 'en-US', 'Legacy invalid preference safely falls back to English.');
echo 'Preferred language: ' . $assertions . ' assertions passed.' . PHP_EOL;

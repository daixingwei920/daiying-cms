<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Plugin\PluginContext;
use Daiying\Commerce\CommerceController;
use Daiying\Commerce\CommerceRepository;

require_once __DIR__ . '/src/CommerceRepository.php';
require_once __DIR__ . '/src/CommerceContracts.php';
require_once __DIR__ . '/src/GenericUrlVerificationProvider.php';
require_once __DIR__ . '/src/CommerceController.php';

return static function (PluginContext $context): void {
    $root = dirname(__DIR__, 3);
    $pdo = $context->pdo();
    $settings = Settings::load($root);
    $repo = new CommerceRepository($pdo);
    $controller = new CommerceController($repo, $pdo, $settings);

    $context->adminRoute('GET', '/admin/commerce', [$controller, 'adminDashboard'], 'commerce.manage', false);
    $context->adminRoute('GET', '/admin/commerce/products', [$controller, 'adminProducts'], 'commerce.manage', false);
    $context->adminRoute('GET', '/admin/commerce/products/new', [$controller, 'adminProductForm'], 'commerce.manage', false);
    $context->adminRoute('GET', '/admin/commerce/products/edit', [$controller, 'adminProductForm'], 'commerce.manage', false);
    $context->adminRoute('POST', '/admin/commerce/products/save', [$controller, 'adminSaveProduct'], 'commerce.manage', true);
    $context->adminRoute('POST', '/admin/commerce/products/status', [$controller, 'adminSetProductStatus'], 'commerce.manage', true);
    $context->adminRoute('POST', '/admin/commerce/variants/save', [$controller, 'adminSaveVariant'], 'commerce.manage', true);
    $context->adminRoute('POST', '/admin/commerce/actions/save', [$controller, 'adminSaveAction'], 'commerce.manage', true);
    $context->adminRoute('POST', '/admin/commerce/verification/save', [$controller, 'adminSaveVerification'], 'commerce.manage', true);
    $context->adminRoute('POST', '/admin/commerce/verification/run-url', [$controller, 'adminRunUrlVerification'], 'commerce.verify.write', true);
    $context->adminRoute('POST', '/admin/commerce/logistics/save', [$controller, 'adminSaveLogistics'], 'commerce.orders', true);
    $context->adminRoute('GET', '/admin/commerce/orders', [$controller, 'adminOrders'], 'commerce.orders', false);
    $context->adminRoute('GET', '/admin/commerce/orders/show', [$controller, 'adminOrderShow'], 'commerce.orders', false);
    $context->adminRoute('POST', '/admin/commerce/orders/sync', [$controller, 'adminSyncOrders'], 'commerce.orders', true);
    $context->adminRoute('POST', '/admin/commerce/orders/cancel', [$controller, 'adminCancelOrder'], 'commerce.orders', true);
    $context->adminRoute('POST', '/admin/commerce/orders/fulfill', [$controller, 'adminFulfillOrder'], 'commerce.orders', true);

    $context->frontRoute('GET', '/commerce', [$controller, 'storefront'], null, false);
    $context->frontRoute('GET', '/commerce/product', [$controller, 'productPage'], null, false);
    $context->frontRoute('POST', '/commerce/checkout', [$controller, 'checkout'], null, true);
    $context->frontRoute('GET', '/commerce/orders/complete', [$controller, 'completeOrder'], null, false);

    $context->adminMenu('Commerce', '/admin/commerce', 'commerce.manage');
};

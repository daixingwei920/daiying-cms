<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Cms\Core\Config\Settings;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Daiying\Commerce\CommerceRepository;
use Official\Restaurant\RestaurantController;
use Official\Restaurant\RestaurantCheckoutService;
use Official\Restaurant\RestaurantPrintService;
use Official\Restaurant\RestaurantRepository;
use Official\Restaurant\RestaurantTicketService;
use Official\Restaurant\RestaurantMenuImportService;

require_once dirname(__DIR__) . '/official.commerce/src/CommerceRepository.php';
require_once __DIR__ . '/src/RestaurantRepository.php';
foreach (['RestaurantPosRepository','RestaurantPosGateway','RestaurantPosService','RestaurantPosController'] as $class) { require_once __DIR__ . '/src/'.$class.'.php'; }
require_once __DIR__ . '/src/RestaurantTicketService.php';
require_once __DIR__ . '/src/RestaurantPrintService.php';
require_once __DIR__ . '/src/RestaurantCheckoutService.php';
require_once __DIR__ . '/src/RestaurantController.php';
require_once __DIR__ . '/src/RestaurantMenuImportService.php';

return static function (PluginContext $context): void {
    $root = dirname(__DIR__, 3);
    $settings = Settings::load($root);
    $repo = new RestaurantRepository($context->pdo());
    $posRepo = new \Official\Restaurant\RestaurantPosRepository($context->pdo());
    $printer = new RestaurantPrintService($repo, null, $posRepo, hash('sha256', rtrim((string)$settings->get('site.url', ''), '/')));
    $tickets = new RestaurantTicketService($repo, $printer);
    $commerce = new CommerceRepository($context->pdo(), (string) $settings->get('security.encryption_key', ''));
    $paymentSecret = (string) $settings->get('security.encryption_key', '');
    if ($paymentSecret === '') {
        $paymentSecret = hash('sha256', __DIR__);
    }
    $payments = new PaymentService($context->pdo(), new PaymentRepository($context->pdo()), $paymentSecret);
    $checkout = new RestaurantCheckoutService(
        $repo,
        $tickets,
        $commerce,
        $context->pdo(),
        $paymentSecret,
        $payments,
    );
    $ai = null;
    try {
        $ai = method_exists($context, 'ai') ? $context->ai() : null;
    } catch (\Cms\Core\Plugin\PluginException) {
    }
    $allowLoopbackBridge = preg_match('~^http://(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?/?$~', (string) $settings->get('site.url', '')) === 1;
    $controller = new RestaurantController($repo, $tickets, $printer, $checkout, $payments, new RestaurantMenuImportService($ai), $context->assetUrl('assets/menu-addons.js'), $allowLoopbackBridge, $context->assetUrl('assets/admin.css'), $context->assetUrl('assets/admin.js'), $posRepo);

    $posService = new \Official\Restaurant\RestaurantPosService($posRepo, $repo, $context->secrets(), (string)$settings->get('site.url', ''), [$checkout, 'orderReceiptForCart']);
    $posService->registerSchedule($context);
    $posController = new \Official\Restaurant\RestaurantPosController($posService, $repo, $context->assetUrl('assets/admin.css'));
    $context->adminRoute('GET', '/admin/restaurant/pos', [$posController, 'index'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/pos/action', [$posController, 'action'], 'restaurant.manage', true);
    foreach (['square', 'clover'] as $brand) {
        $context->adminRoute('GET', '/admin/restaurant/pos/'.$brand.'/callback', static fn(\Cms\Core\Http\Request $request) => $posController->callback($request, $brand), 'restaurant.manage', false);
    }

    $context->adminRoute('GET', '/admin/restaurant', [$controller, 'dashboard'], 'restaurant.orders', false);
    $context->adminRoute('GET', '/admin/restaurant/menu', [$controller, 'menu'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/menu/save', [$controller, 'saveMenuItem'], 'restaurant.manage', true);
    $context->adminRoute('GET', '/admin/restaurant/menu/import', [$controller, 'menuImport'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/menu/recognize', [$controller, 'recognizeMenu'], 'restaurant.manage', true);
    $context->adminRoute('POST', '/admin/restaurant/menu/import', [$controller, 'importMenu'], 'restaurant.manage', true);
    $context->adminRoute('GET', '/admin/restaurant/categories', [$controller, 'categories'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/categories/save', [$controller, 'saveCategory'], 'restaurant.manage', true);
    $context->adminRoute('GET', '/admin/restaurant/storefront', [$controller, 'storefrontSettings'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/storefront/save', [$controller, 'saveStorefrontSettings'], 'restaurant.manage', true);
    $context->adminRoute('GET', '/admin/restaurant/stations', [$controller, 'stations'], 'restaurant.manage', false);
    $context->adminRoute('POST', '/admin/restaurant/stations/save', [$controller, 'saveStation'], 'restaurant.manage', true);
    $context->adminRoute('GET', '/admin/restaurant/printers', [$controller, 'printers'], 'restaurant.print', false);
    $context->adminRoute('POST', '/admin/restaurant/printers/save', [$controller, 'savePrinter'], 'restaurant.print', true);
    $context->adminRoute('POST', '/admin/restaurant/printers/test', [$controller, 'testPrinter'], 'restaurant.print', true);
    $context->adminRoute('GET', '/admin/restaurant/print-jobs', [$controller, 'printJobs'], 'restaurant.print', false);
    $context->adminRoute('POST', '/admin/restaurant/print-jobs/retry', [$controller, 'retryPrintJob'], 'restaurant.print', true);
    $context->adminRoute('POST', '/admin/restaurant/print-jobs/confirm', [$controller, 'confirmPrintJob'], 'restaurant.print', true);
    $context->adminRoute('POST', '/admin/restaurant/tickets/create-test', [$controller, 'createTestTicket'], 'restaurant.orders', true);
    $context->adminRoute('POST', '/admin/restaurant/tickets/status', [$controller, 'updateTicketStatus'], 'restaurant.orders', true);
    $context->adminRoute('POST', '/admin/restaurant/orders/sync-paid', [$controller, 'syncPaidOrders'], 'restaurant.orders', true);

    $context->frontRoute('GET', '/restaurant', [$controller, 'publicMenu'], null, false);
    $context->frontRoute('GET', '/restaurant/api/storefront', [$controller, 'apiStorefront'], null, false);
    $context->frontRoute('GET', '/restaurant/api/menu', [$controller, 'apiMenu'], null, false);
    $context->frontRoute('GET', '/restaurant/api/menu/search', [$controller, 'apiMenu'], null, false);
    $context->frontRoute('GET', '/restaurant/api/cart', [$controller, 'apiCart'], null, false);
    $context->frontRoute('POST', '/restaurant/api/cart/add', [$controller, 'apiCartAdd'], null, true);
    $context->frontRoute('POST', '/restaurant/api/cart/remove', [$controller, 'apiCartRemove'], null, true);
    $context->frontRoute('POST', '/restaurant/api/cart/update', [$controller, 'apiCartUpdate'], null, true);
    $context->frontRoute('POST', '/restaurant/checkout', [$controller, 'checkout'], null, true);
    $context->frontRoute('GET', '/restaurant/checkout/complete', [$controller, 'completeCheckout'], null, false);
    $context->frontRoute('GET', '/restaurant/agent/jobs', [$controller, 'agentJobs'], null, false);
    $context->frontRoute('POST', '/restaurant/agent/jobs/report', [$controller, 'agentReport'], null, false);
    $context->frontRoute('GET', '/restaurant/bridge/bindings', [$controller, 'bridgeBindings'], null, false);
    $context->frontRoute('POST', '/restaurant/bridge/jobs/claim', [$controller, 'bridgeClaim'], null, false);
    $context->frontRoute('POST', '/restaurant/bridge/jobs/start', [$controller, 'bridgeStart'], null, false);
    $context->frontRoute('POST', '/restaurant/bridge/jobs/report', [$controller, 'bridgeReport'], null, false);

    $context->adminMenu('餐厅外卖', '/admin/restaurant', 'restaurant.orders');
};

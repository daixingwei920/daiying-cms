<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Daiying\AffiliateHub\AffiliateAdapterRegistry;
use Daiying\AffiliateHub\AffiliateConnectionRepository;
use Daiying\AffiliateHub\AffiliateController;
use Daiying\AffiliateHub\AffiliateRepository;
use Daiying\AffiliateHub\CjAffiliateAdapter;
use Daiying\AffiliateHub\FeedAffiliateAdapter;
use Daiying\AffiliateHub\FeedImportService;
use Daiying\AffiliateHub\ManualAffiliateAdapter;

require_once __DIR__ . '/src/AffiliateContracts.php';
require_once __DIR__ . '/src/AffiliateRepository.php';
require_once __DIR__ . '/src/AffiliateConnectionRepository.php';
require_once __DIR__ . '/src/CjAffiliateClient.php';
require_once __DIR__ . '/src/CjAffiliateAdapter.php';
require_once __DIR__ . '/src/FeedImportService.php';
require_once __DIR__ . '/src/AffiliateController.php';

return static function (PluginContext $context): void {
    AffiliateAdapterRegistry::register(new ManualAffiliateAdapter());
    AffiliateAdapterRegistry::register(new FeedAffiliateAdapter());
    $cjAdapter = new CjAffiliateAdapter();
    AffiliateAdapterRegistry::register($cjAdapter);

    $repository = new AffiliateRepository($context->pdo());
    $connections = new AffiliateConnectionRepository($context->pdo(), $context->secrets());
    $controller = new AffiliateController($repository, new FeedImportService(), $connections, $cjAdapter);

    $context->adminRoute('GET', '/admin/affiliate-hub', [$controller, 'dashboard'], 'affiliate.manage', false);
    $context->adminRoute('GET', '/admin/affiliate-hub/products', [$controller, 'products'], 'affiliate.manage', false);
    $context->adminRoute('GET', '/admin/affiliate-hub/products/new', [$controller, 'newProduct'], 'affiliate.manage', false);
    $context->adminRoute('POST', '/admin/affiliate-hub/products/save', [$controller, 'saveProduct'], 'affiliate.manage', true);
    $context->adminRoute('GET', '/admin/affiliate-hub/import', [$controller, 'importForm'], 'affiliate.manage', false);
    $context->adminRoute('POST', '/admin/affiliate-hub/import/preview', [$controller, 'previewImport'], 'affiliate.manage', true);
    $context->adminRoute('POST', '/admin/affiliate-hub/import/run', [$controller, 'runImport'], 'affiliate.manage', true);
    $context->adminRoute('GET', '/admin/affiliate-hub/cj', [$controller, 'cjSettings'], 'affiliate.manage', false);
    $context->adminRoute('POST', '/admin/affiliate-hub/cj/save', [$controller, 'saveCjSettings'], 'affiliate.manage', true);
    $context->adminRoute('POST', '/admin/affiliate-hub/cj/test', [$controller, 'testCjSettings'], 'affiliate.manage', true);
    $context->adminRoute('GET', '/admin/affiliate-hub/cj/search', [$controller, 'cjSearch'], 'affiliate.manage', false);
    $context->adminRoute('POST', '/admin/affiliate-hub/cj/import', [$controller, 'importCj'], 'affiliate.manage', true);
    $context->frontRoute('GET', '/go/affiliate', [$controller, 'go'], null, false);
    $context->adminMenu('联盟商城', '/admin/affiliate-hub', 'affiliate.manage');
};

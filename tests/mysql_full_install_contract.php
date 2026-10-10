<?php

declare(strict_types=1);

// Explicit opt-in: a disposable, empty loopback database and an isolated filesystem instance.
$configFile = getenv('CMS_MYSQL_TEST_CONFIG');
if (!$configFile) {
    echo "[SKIP] MySQL isolated test configuration is not supplied.\n";
    exit(0);
}
$config = require $configFile;
if (!is_array($config) || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $config['database'] ?? '')
    || ($config['host'] ?? '') !== '127.0.0.1' || !is_dir($config['root'] ?? '')) {
    throw new RuntimeException('Only an explicit disposable loopback MySQL fixture is allowed.');
}
$root = $config['root'];
define('CMS_ROOT', $root);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Install\InstallController;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Theme\LocalThemePackageInstaller;
use Cms\Core\Theme\ThemeManager;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $config['database'] . ';charset=utf8mb4', $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if ((int) $pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Fresh install test requires an empty database; it never drops existing tables.');
}
$checks = [];
function mysql_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
}
$_SESSION = [];
$settings = Settings::load($root);
$logger = new FileLogger($root . '/storage/logs/mysql-test.log');
$installer = new InstallController($root, $settings, $logger);
$password = bin2hex(random_bytes(16));
$body = ['_csrf' => CsrfToken::get(), 'db_driver' => 'mysql', 'mysql_host' => '127.0.0.1', 'mysql_port' => '3306', 'mysql_database' => $config['database'], 'mysql_username' => $config['user'], 'mysql_password' => $config['password'], 'site_name' => 'MySQL 中文 😀 Integration Test', 'site_url' => 'http://127.0.0.1:18989', 'email' => 'mysql-test@example.invalid', 'password' => $password, 'display_name' => '隔离管理员', 'install_action' => 'install'];
try {
    $bad = $body;
    $bad['_csrf'] = 'invalid';
    mysql_check($installer->store(new Request('POST', '/install', [], $bad))->status() === 400, 'install_csrf_rejected');
    $response = $installer->store(new Request('POST', '/install', [], $body));
    mysql_check($response->status() === 302 && is_file($root . '/storage/installed.lock'), 'official_install_controller_completed');
    $migrations = [];
    foreach (glob($root . '/system/migrations/*.php') as $file) {
        $migrations[] = require $file;
    }
    mysql_check((int) $pdo->query('SELECT COUNT(*) FROM cms_core_migrations')->fetchColumn() === count($migrations), 'all_official_migrations_applied');
    mysql_check((new MigrationRunner($pdo, $migrations))->run() === 0, 'migration_repeat_is_noop');
    mysql_check($installer->store(new Request('POST', '/install', [], $body))->status() === 302 && (int) $pdo->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn() === 1, 'completed_install_repeat_no_duplicate_admin');
    $auth = new AdminAuthenticator($pdo);
    mysql_check($auth->verifyCredentials('mysql-test@example.invalid', $password, '127.0.0.1') !== null, 'created_admin_credentials_valid');
    mysql_check($auth->verifyCredentials('mysql-test@example.invalid', 'wrong', '127.0.0.1') === null, 'wrong_admin_password_rejected');
    file_put_contents($root . '/storage/mysql-http-test-credentials.json', json_encode(['email' => 'mysql-test@example.invalid', 'password' => $password]));
    chmod($root . '/storage/mysql-http-test-credentials.json', 0600);
    $repo = new ContentRepository($pdo, ContentTypeRegistry::defaults(), [], $root);
    $id = $repo->create('article', 'MySQL 中文 😀 Integration Test', 'mysql-native', [['type' => 'paragraph', 'text' => '完整 MySQL 原生发布正文 😀']], 'draft', ['seo_title' => 'MySQL 独立 SEO', 'seo_description' => 'MySQL 描述', 'seo_keywords' => 'MySQL,中文', 'canonical_url' => 'http://127.0.0.1:18989/articles/mysql-native', 'paid_content_enabled' => true, 'paid_content_price_minor' => 1234, 'robots_follow' => false], ['分类 😀'], ['标签 😀']);
    $terms = $repo->termsForContent($id);
    $previewToken = $repo->find($id)['meta']['preview_token'];
    $repo->patch($id, ['status' => 'published', 'meta' => ['seo_keywords' => 'MySQL,保留'], 'expected_digest' => $repo->digest($id)]);
    $article = $repo->find($id);
    mysql_check($article['status'] === 'published' && $repo->termsForContent($id) === $terms, 'article_publish_terms_retained');
    mysql_check($article['meta']['seo_title'] === 'MySQL 独立 SEO' && $article['meta']['paid_content_price_minor'] === 1234 && $article['meta']['preview_token'] === $previewToken && !$article['meta']['robots_follow'], 'seo_paid_meta_utf8_retained');
    $image = imagecreatetruecolor(8, 8);
    imagepng($image, $root . '/storage/test.png');
    $media = new Cms\Core\Media\MediaLibrary($pdo, $root . '/content/uploads');
    $mid = $media->uploadLocalFile($root . '/storage/test.png', '原生测试.png', 1);
    mysql_check($mid > 0 && is_file($media->fileForResponse($mid)['path']), 'native_media_registered');
    $repo->patch($id, ['blocks' => [['type' => 'paragraph', 'text' => '完整 MySQL 原生发布正文 😀'], ['type' => 'image', 'media_id' => $mid, 'alt' => 'MySQL 原生图片 ALT']], 'expected_digest' => $repo->digest($id)]);
    $themeZip = $root . '/storage/test-theme.zip';
    $zip = new ZipArchive();
    $zip->open($themeZip, ZipArchive::CREATE);
    $zip->addFromString('mysql-test/theme.json', json_encode(['theme_id' => 'mysql-test', 'name' => 'MySQL Test', 'version' => '1.0.0', 'author' => 'Isolation', 'core' => ['min' => '1.2.0', 'max' => '1.999.999']]));
    $zip->addFromString('mysql-test/templates/home.php', '<h1>MySQL Test Theme</h1>');
    $zip->close();
    $theme = (new LocalThemePackageInstaller($root, Settings::load($root), $logger))->install($themeZip);
    $themeSettings = Settings::load($root)->all();
    $themeSettings['theme']['active'] = 'mysql-test';
    mysql_check($theme['theme_id'] === 'mysql-test' && (new ThemeManager($root . '/content/themes', Settings::fromArray($themeSettings), $logger))->active()->manifest->id === 'mysql-test', 'theme_zip_install_active_runtime');
    $plugins = new LocalPluginPackageInstaller($root, $pdo);
    $plugin = $plugins->installBundled('official.commerce', 1, true);
    mysql_check($plugin['enabled'] && $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id='official.commerce'")->fetchColumn() === 'Enabled', 'official_commerce_installed_enabled');
    require $root . '/content/plugins/official.commerce/src/CommerceContracts.php';
    require $root . '/content/plugins/official.commerce/src/CommerceRepository.php';
    $commerce = new Daiying\Commerce\CommerceRepository($pdo, 'isolation-only-encryption-key');
    $product = $commerce->saveProduct(['name' => '隔离商品 😀', 'sku' => 'MYSQL-TEST', 'status' => 'active', 'price_minor' => 1200, 'currency' => 'CNY', 'stock_quantity' => 5]);
    mysql_check($commerce->publicProduct($product)['name'] === '隔离商品 😀', 'commerce_product_native_save_read');
    $action = $commerce->saveAction(['product_id' => $product, 'action_type' => 'site_checkout', 'label' => '隔离购买', 'status' => 'active']);
    $order = $commerce->createPendingOrder($product, null, $action, 1, 'isolation-provider', 'mysql-order-test', 'isolation-only');
    try {
        $commerce->createPendingOrder($product, null, $action, 1, 'isolation-provider', 'mysql-order-test', 'isolation-only');
        throw new LogicException('Duplicate order unexpectedly accepted.');
    } catch (PDOException $error) {
        mysql_check(str_contains($error->getMessage(), '1062') && (int) $pdo->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn() === 1 && (int) $order['amount_minor'] === 1200, 'commerce_order_unique_constraint_and_rollback');
    }
    $commerce->cancelPendingOrder((int) $order['id'], 'isolated test cancellation');
    mysql_check((int) $commerce->product($product)['reserved_quantity'] === 0, 'commerce_cancel_restores_inventory');
    // Focused SQL compatibility test, not a payment settlement or production sale.
    $pdo->beginTransaction();
    $pdo->exec('UPDATE commerce_products SET reserved_quantity=1 WHERE id=' . $product);
    (new ReflectionMethod($commerce, 'completeInventorySale'))->invoke($commerce, $product, null, (int) $order['id'], 1);
    mysql_check((int) $commerce->product($product)['reserved_quantity'] === 0 && (int) $commerce->product($product)['sold_quantity'] === 1, 'commerce_inventory_sale_sql_native_prepares');
    $pdo->rollBack();
    mysql_check((int) $commerce->product($product)['sold_quantity'] === 0, 'inventory_sql_probe_transaction_rolled_back');
    mysql_check($pdo->query('SELECT COUNT(*) FROM cms_site_licenses')->fetchColumn() == 0 && $pdo->query('SELECT COUNT(*) FROM cms_commercial_licenses')->fetchColumn() == 0, 'license_structures_exist_without_customer_data');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE cms_contents SET title='must rollback' WHERE id=" . $id);
    $pdo->rollBack();
    mysql_check($repo->find($id)['title'] === 'MySQL 中文 😀 Integration Test', 'mysql_dml_transaction_rollback');
    // The prefix index must not truncate the stored URL or collapse distinct full-path matches.
    $path = '/' . str_repeat('中', 400);
    $stmt = $pdo->prepare("INSERT INTO cms_seo_keyword_metrics (keyword,search_engine,period_start,period_end,source,created_at,url_path) VALUES ('中文','google','','','test','',:path)");
    $stmt->execute([':path' => $path . '甲']);
    $stmt->execute([':path' => $path . '乙']);
    $stmt = $pdo->prepare('SELECT url_path FROM cms_seo_keyword_metrics WHERE url_path=:path');
    $stmt->execute([':path' => $path . '甲']);
    mysql_check($stmt->fetchColumn() === $path . '甲' && $stmt->fetchColumn() === false, 'seo_prefix_index_full_value_preserved');
    echo json_encode(['status' => 'PASS', 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'migrations' => count($migrations), 'tables' => (int) $pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn(), 'checks' => $checks, 'article_id' => $id, 'media_id' => $mid], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'FAIL', 'checks' => $checks, 'error' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

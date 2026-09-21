<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketApiClientInterface;
use Cms\Core\Market\MarketItem;
use Cms\Core\Market\MarketPagedSearchInterface;
use Cms\Core\Market\MarketSearchResult;
use Cms\Core\Routing\BasePath;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

final class MarketSearchPaginationClient implements MarketApiClientInterface, MarketPagedSearchInterface
{
    /** @var list<array{type:string,query:string,page:int,per_page:int}> */
    public array $calls = [];

    /** @var list<MarketItem> */
    private array $items;

    public function __construct()
    {
        $items = [];
        for ($i = 1; $i <= 45; $i++) {
            $id = sprintf('%02d', $i);
            $extensionId = $i === 1 ? 'official.commerce' : 'official.plugin.' . $id;
            $items[] = new MarketItem(
                'market.plugin.' . $id,
                $extensionId,
                'plugin',
                $i === 1 ? 'Daiying Commerce' : 'Plugin ' . $id,
                $i === 1 ? '1.2.0' : '1.0.' . $i,
                'Free',
                'published',
                ['cap' . $id],
                'plugin-' . $id,
                '',
                'stable',
                'pkg.plugin.' . $id,
                str_repeat((string) ($i % 10), 64),
                true,
                '',
                $extensionId,
                'Daiying',
                null,
                false,
                'FREE',
                '',
                '',
                '',
                '',
                '',
                $i === 2 ? 'Editorial market descriptionmatch package.' : '',
            );
        }
        for ($i = 1; $i <= 3; $i++) {
            $id = sprintf('%02d', $i);
            $items[] = new MarketItem(
                'market.payment.' . $id,
                'official.payment.' . $id,
                'payment_provider',
                'Payment Provider ' . $id,
                '1.0.' . $i,
                'Free',
                'published',
                ['payment'],
                'payment-' . $id,
                '',
                'stable',
                'pkg.payment.' . $id,
                str_repeat((string) ($i % 10), 64),
                true,
                '',
                'official.payment.' . $id,
                'Daiying',
            );
        }
        for ($i = 1; $i <= 8; $i++) {
            $id = sprintf('%02d', $i);
            $items[] = new MarketItem(
                'market.theme.' . $id,
                'official.theme.' . $id,
                'theme',
                'Theme ' . $id,
                '1.0.' . $i,
                'Free',
                'published',
                ['theme'],
                'theme-' . $id,
                '',
                'stable',
                'pkg.theme.' . $id,
                str_repeat((string) ($i % 10), 64),
                true,
                '',
                'official.theme.' . $id,
                'Daiying',
            );
        }
        $this->items = $items;
    }

    /** @return list<MarketItem> */
    public function search(string $type, string $query = '', bool $forceRefresh = false): array
    {
        return $this->searchPage($type, $query, 1, 100, $forceRefresh)->items;
    }

    public function searchPage(string $type, string $query = '', int $page = 1, int $perPage = 20, bool $forceRefresh = false): MarketSearchResult
    {
        $this->calls[] = ['type' => $type, 'query' => $query, 'page' => $page, 'per_page' => $perPage];
        $query = mb_strtolower(trim($query));
        $filtered = array_values(array_filter($this->items, static function (MarketItem $item) use ($type, $query): bool {
            if ($item->type !== $type) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [
                $item->name,
                $item->marketId,
                $item->extensionId,
                $item->packageId,
                $item->productId,
                $item->slug,
                $item->developerName,
                $item->description,
                implode(' ', $item->capabilities),
            ]));

            return str_contains($haystack, $query);
        }));
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        return MarketSearchResult::fromItems(array_slice($filtered, $offset, $perPage), $page, $perPage, count($filtered));
    }

    public function authorizeInstall(string $marketId, string $siteId, string $licenseKey = ''): InstallAuthorization
    {
        return new InstallAuthorization('token', 'https://example.test/package.zip', gmdate('c', time() + 3600), str_repeat('a', 64), $marketId);
    }

    /** @return array<string,mixed> */
    public function detail(string $marketId, string $version = '', bool $forceRefresh = false): array
    {
        return ['market_id' => $marketId, 'version' => $version];
    }

    /** @return array<string,mixed> */
    public function diagnostics(bool $forceRefresh = false): array
    {
        return ['api_status' => 'ok'];
    }

    public function clearCache(): void
    {
    }
}

/** @return array{root:string,controller:AdminController,client:MarketSearchPaginationClient} */
function market_search_pagination_fixture(): array
{
    $root = sys_get_temp_dir() . '/daiying-market-search-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/storage/logs', 0777, true);
    mkdir($root . '/content/plugins', 0777, true);
    mkdir($root . '/content/themes', 0777, true);
    $db = $root . '/cms.sqlite';
    file_put_contents($root . '/config/app.php', "<?php\nreturn [\n    'app' => ['version' => '1.2.67'],\n    'database' => ['dsn' => 'sqlite:" . addslashes($db) . "', 'username' => '', 'password' => '', 'options' => []],\n    'market' => ['items_per_page' => 20],\n];\n");
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE cms_admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_extension_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        extension_id TEXT NOT NULL,
        extension_type TEXT NOT NULL,
        source TEXT NOT NULL,
        market_id TEXT,
        version TEXT NOT NULL,
        installed_at TEXT NOT NULL,
        metadata_json TEXT
    )');
    $pdo->prepare('INSERT INTO cms_admin_users (email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['admin@example.test', password_hash('secret', PASSWORD_DEFAULT), 'Admin', gmdate('c'), gmdate('c')]);
    $pdo->prepare('INSERT INTO cms_extension_sources (extension_id, extension_type, source, market_id, version, installed_at, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['official.commerce', 'plugin', 'official_market', 'market.plugin.01', '1.0.0', gmdate('c'), '{}']);

    $_SESSION['admin_user'] = [
        'id' => 1,
        'email' => 'admin@example.test',
        'display_name' => 'Admin',
        'capabilities' => ['*', 'admin.super'],
    ];
    unset($_SESSION['admin_session_hash']);

    $settings = Settings::load($root);
    $client = new MarketSearchPaginationClient();

    return [
        'root' => $root,
        'controller' => new AdminController($settings, new FileLogger($root . '/storage/logs/app.log'), $root, $client),
        'client' => $client,
    ];
}

function market_body(Response $response): string
{
    if ($response->status() !== 200) {
        throw new RuntimeException('Unexpected response status ' . $response->status());
    }

    return $response->body();
}

$fixture = market_search_pagination_fixture();
$controller = $fixture['controller'];
$client = $fixture['client'];

$_GET = [];
BasePath::setCurrent('');
$body = market_body($controller->marketPlugins());
$check(str_contains($body, 'Daiying Commerce'), 'default plugin market first page includes first item');
$check(str_contains($body, '共 48 个项目，第 1 / 3 页'), 'combined plugin/payment market reports total and page count');
$check(str_contains($body, '/admin/market/plugins?page=2'), 'root deployment pagination URL points to page 2');
$check(!str_contains($body, 'Plugin 21'), 'default first page does not include page 2 items');
$check(str_contains($body, '有更新 1.2.0'), 'installed/update state survives pagination rendering');

$_GET = ['page' => '2'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, 'Plugin 21'), 'second page includes page 2 item');
$check(str_contains($body, '/admin/market/plugins'), 'second page includes previous/root market link');
$check(str_contains($body, '/admin/market/plugins?page=3'), 'second page includes next page link');

$_GET = ['page' => '999999'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, '第 3 / 3 页'), 'out-of-range page is clamped to last page');
$check(str_contains($body, 'Payment Provider 03'), 'last page includes trailing payment provider item');

$_GET = ['page' => 'abc'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, '第 1 / 3 页'), 'illegal page is normalized to first page');

$_GET = ['q' => 'commerce'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, 'Daiying Commerce'), 'search hit renders matching item');
$check(str_contains($body, '共 1 个项目，第 1 / 1 页'), 'search hit reports filtered total');

$_GET = ['q' => 'descriptionmatch'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, 'Plugin 02'), 'search can match market item description');

$_GET = ['q' => 'no-such-package'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, '没有找到匹配的市场项目'), 'empty search shows explicit empty state');

$_GET = ['q' => 'Plugin', 'page' => '2'];
$body = market_body($controller->marketPlugins());
$check(str_contains($body, 'Plugin 21'), 'search plus page keeps filtered items on requested page');
$check(str_contains($body, 'q=Plugin&amp;page=3') || str_contains($body, 'q=Plugin&page=3'), 'pagination preserves search query');

$_GET = [];
$body = market_body($controller->marketThemes());
$check(str_contains($body, 'Theme 01'), 'theme market uses same pagination contract');
$check(!str_contains($body, 'Daiying Commerce'), 'theme market does not include plugin entries');

$_GET = [];
BasePath::setCurrent('/daojia');
$body = market_body($controller->marketPlugins());
$check(str_contains($body, '/daojia/admin/market/plugins?page=2'), 'base-path pagination URL keeps deployment prefix');
$check(str_contains($body, 'action="/daojia/admin/market/authorize"'), 'base-path install form action keeps deployment prefix');
BasePath::setCurrent('');

$calledTypes = array_values(array_unique(array_map(static fn (array $call): string => $call['type'], $client->calls)));
$check(in_array('plugin', $calledTypes, true) && in_array('payment_provider', $calledTypes, true) && in_array('theme', $calledTypes, true), 'plugin/payment/theme search calls are issued by type');

if ($failures > 0) {
    echo "Market search pagination tests failed: {$failures}" . PHP_EOL;
    exit(1);
}

echo 'Market search pagination tests passed.' . PHP_EOL;

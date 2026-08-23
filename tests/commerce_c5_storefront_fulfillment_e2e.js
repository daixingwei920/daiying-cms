const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');
const { chromium } = require('/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const projectRoot = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';

function runPhp(args, options = {}) {
  const result = spawnSync(php, args, { cwd: options.cwd || projectRoot, encoding: 'utf8', timeout: options.timeout || 60000 });
  if (result.status !== 0) throw new Error(`PHP failed: php ${args.join(' ')}\n${result.stdout}\n${result.stderr}`);
  return result.stdout.trim();
}

function readDb(root, sql) {
  const code = `$root=${JSON.stringify(root)};$config=require $root."/config/app.php";$db=$config["database"];$pdo=new PDO($db["dsn"],$db["username"]??"",$db["password"]??"",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);echo (string)$pdo->query(${JSON.stringify(sql)})->fetchColumn();`;
  return runPhp(['-r', code], { timeout: 10000 });
}

async function waitForServer(baseUrl, output) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/health`);
      if ([200, 302, 500].includes(response.status)) return;
    } catch (_) {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Timed out waiting for PHP server\n${output.stderr}`);
}

async function clickAndWait(page, locator, timeout = 15000) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => null),
    locator.click(),
  ]);
}

async function expectText(page, text) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `Expected ${text}\n${body.slice(0, 1800)}`);
}

async function installAndLogin(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c5-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C5 Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c5-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c5-secret');
  await clickAndWait(page, page.locator('button[name="install_action"][value="test_database"]'), 20000);
  await expectText(page, '数据库连接测试通过');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/login`, { timeout: 20000 }),
    page.locator('button[name="install_action"][value="install"]').click(),
  ]);
  await page.goto(`${baseUrl}/admin/login`);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await clickAndWait(page, page.locator('button[type="submit"]'));
  await expectText(page, '管理后台');
}

async function enableCommerce(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/plugins`);
  const enable = page.locator('tr:has-text("official.commerce") form:has(input[name="status"][value="Enabled"]) button[type="submit"]').first();
  if (await enable.count()) await clickAndWait(page, enable, 20000);
  await page.goto(`${baseUrl}/admin/commerce/products`);
  await expectText(page, '商品');
}

async function createPublishedProduct(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/categories`);
  await page.fill('input[name="name"]', 'C5 Browser Category');
  await page.fill('input[name="slug"]', 'c5-browser-category');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/categories"] button[type="submit"]').first(), 20000);

  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await page.fill('input[name="title"]', 'C5 Browser Product');
  await page.fill('input[name="slug"]', 'c5-browser-product');
  await page.fill('textarea[name="summary"]', 'C5 browser summary');
  await page.locator('select[name="blocks[0][type]"]').selectOption('paragraph');
  await page.locator('textarea[name="blocks[0][data][text]"]').fill('C5 browser safe description');
  await page.locator('input[name="category_ids[]"]').first().check();
  await page.locator('input[name="variant_sku[]"]').first().fill('C5-BROWSER-SKU');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1200');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/products"] button[type="submit"]').last(), 20000);
  const editUrl = page.url();
  await clickAndWait(page, page.locator('form[action$="/publish"] button[type="submit"]'), 20000);
  await expectText(page, 'published');
  return editUrl;
}

async function adjustInventory(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/inventory`);
  await expectText(page, 'C5-BROWSER-SKU');
  const firstRow = page.locator('tbody tr').first();
  const variantId = Number((await firstRow.locator('td').first().innerText()).trim());
  await firstRow.locator('input[name="quantity_delta"]').fill('3');
  await firstRow.locator('input[name="reason"]').fill('browser c5 stock');
  await clickAndWait(page, firstRow.locator('button[type="submit"]'), 15000);
  return variantId;
}

async function postForm(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const params = new URLSearchParams(body);
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const text = await response.text();
    return { status: response.status, url: response.url, text };
  }, { url, body });
}

async function storefrontOrder(page, baseUrl, variantId) {
  await page.goto(`${baseUrl}/shop`);
  await expectText(page, 'C5 Browser Product');
  await page.goto(`${baseUrl}/shop/category/c5-browser-category`);
  await expectText(page, 'C5 Browser Product');
  await page.goto(`${baseUrl}/product/c5-browser-product`);
  await expectText(page, 'C5 browser safe description');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const add = await postForm(page, `${baseUrl}/cart/items`, { _csrf: csrf, variant_id: String(variantId), quantity: '1', version: '1', idempotency_key: 'c5-browser-cart-add' });
  assert.strictEqual(add.status, 200, add.text);
  const cart = JSON.parse(add.text);
  await page.goto(`${baseUrl}/checkout?cart_version=${cart.cart.version}`);
  const checkoutCsrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const address = { full_name: 'C5 Buyer', email: 'buyer@example.test', phone: '5550100', line1: '1 Browser St', line2: '', city: 'Boston', region: 'MA', postal_code: '02110', country_code: 'US' };
  const quote = await postForm(page, `${baseUrl}/checkout/quote`, { _csrf: checkoutCsrf, cart_version: String(cart.cart.version), ...address });
  assert.strictEqual(quote.status, 200, quote.text);
  const quoteHash = quote.text.match(/name="quote_hash" value="([^"]+)"/)[1];
  const csrf2 = quote.text.match(/name="_csrf" value="([^"]+)"/)[1];
  const placed = await postForm(page, `${baseUrl}/checkout/place-order`, { _csrf: csrf2, cart_version: String(cart.cart.version), quote_hash: quoteHash, idempotency_key: 'c5-browser-order', ...address });
  assert.strictEqual(placed.status, 200, placed.text);
  assert(placed.url.includes('/order/'), placed.url);
  return placed.url;
}

async function captureManual(page, baseUrl, orderId) {
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await expectText(page, 'pending_payment');
  await page.fill('form[action$="/payments/manual"] input[name="reference"]', 'C5-BROWSER-PAY');
  await clickAndWait(page, page.locator('form[action$="/payments/manual"] button[type="submit"]'), 20000);
  await expectText(page, 'paid');
}

async function fulfillOrder(page, baseUrl, orderId) {
  await page.goto(`${baseUrl}/admin/commerce/fulfillment`);
  await expectText(page, '配送与履约');
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/orders/${orderId}/fulfillment/submit"] button[type="submit"]`), 20000);
  await expectText(page, 'submitted');
  const shipmentId = Number(readDb(globalThis.fixtureRoot, `SELECT id FROM cms_commerce_shipments WHERE order_id = ${orderId} LIMIT 1`));
  await page.locator(`form[action="/admin/commerce/shipments/${shipmentId}/status"] select[name="status"]`).selectOption('delivered');
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/shipments/${shipmentId}/status"] button[type="submit"]`), 20000);
  await expectText(page, 'delivered');
  assert.strictEqual(Number(readDb(globalThis.fixtureRoot, "SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name IN ('commerce.fulfillment.requested.v1','commerce.fulfillment.updated.v1')")), 3);
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  globalThis.fixtureRoot = root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
  const output = { stdout: '', stderr: '' };
  server.stdout.on('data', (chunk) => { output.stdout += chunk.toString(); });
  server.stderr.on('data', (chunk) => { output.stderr += chunk.toString(); });
  let browser;
  let success = false;
  try {
    await waitForServer(baseUrl, output);
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 920 } });
    const admin = await context.newPage();
    admin.setDefaultTimeout(15000);
    await installAndLogin(admin, baseUrl);
    await enableCommerce(admin, baseUrl);
    await createPublishedProduct(admin, baseUrl);
    const variantId = await adjustInventory(admin, baseUrl);

    const visitor = await context.newPage();
    visitor.setDefaultTimeout(15000);
    const publicOrderUrl = await storefrontOrder(visitor, baseUrl, variantId);
    const orderId = Number(readDb(root, "SELECT id FROM cms_commerce_orders WHERE idempotency_key = 'c5-browser-order' LIMIT 1"));
    await captureManual(admin, baseUrl, orderId);
    await fulfillOrder(admin, baseUrl, orderId);
    await visitor.goto(publicOrderUrl);
    await expectText(visitor, 'Fulfillment delivered');
    await expectText(visitor, 'FAKE');

    assert.strictEqual(Number(readDb(root, "SELECT COUNT(*) FROM cms_audit_logs WHERE actor_id = (SELECT id FROM cms_admin_users WHERE email = 'admin@example.test') AND action LIKE 'commerce.fulfillment.%'")), 2);
    await admin.goto(`${baseUrl}/admin/plugins`);
    await clickAndWait(admin, admin.locator('tr:has-text("official.commerce") form:has(input[name="status"][value="Disabled"]) button[type="submit"]').first(), 20000);
    const disabled = await visitor.goto(`${baseUrl}/shop`);
    assert([404, 503].includes(disabled.status()), `disabled storefront route should disappear, got ${disabled.status()}`);
    await admin.goto(`${baseUrl}/admin`);
    await expectText(admin, '管理后台');
    await admin.goto(`${baseUrl}/admin/recovery`);
    await expectText(admin, '恢复与诊断');

    console.log('[PASS] storefront /shop, category and product routes render published product through real HTTP');
    console.log('[PASS] visitor creates checkout order from product page route without Fixture Helper');
    console.log('[PASS] admin captures manual payment and submits Fake fulfillment through real HTTP POST');
    console.log('[PASS] admin marks shipment delivered and public order page shows fulfillment/tracking');
    console.log('[PASS] disabling Commerce removes storefront while CMS admin and Recovery remain reachable');
    success = true;
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
    runPhp(['tests/browser_release_e2e_fixture.php', 'cleanup', root], { timeout: 60000 });
    if (!success) {
      console.error(output.stderr);
    }
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

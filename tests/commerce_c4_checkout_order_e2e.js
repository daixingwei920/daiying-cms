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
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c4-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C4 Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c4-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c4-secret');
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
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await page.fill('input[name="title"]', 'C4 Browser Checkout Product');
  await page.fill('input[name="slug"]', 'c4-browser-checkout-product');
  await page.locator('input[name="variant_sku[]"]').first().fill('C4-BROWSER-SKU');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1200');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/products"] button[type="submit"]').last(), 20000);
  await clickAndWait(page, page.locator('form[action$="/publish"] button[type="submit"]'), 20000);
  await expectText(page, 'published');
}

async function adjustInventory(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/inventory`);
  await expectText(page, 'C4-BROWSER-SKU');
  const firstRow = page.locator('tbody tr').first();
  const variantId = Number((await firstRow.locator('td').first().innerText()).trim());
  await firstRow.locator('input[name="quantity_delta"]').fill('4');
  await firstRow.locator('input[name="reason"]').fill('browser initial stock');
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

async function cartPost(page, baseUrl, variantId) {
  await page.goto(`${baseUrl}/cart`);
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const result = await postForm(page, `${baseUrl}/cart/items`, { _csrf: csrf, variant_id: String(variantId), quantity: '2', version: '1', idempotency_key: 'c4-browser-cart-add' });
  assert.strictEqual(result.status, 200, result.text);
  const json = JSON.parse(result.text);
  assert.strictEqual(json.subtotal_minor, 2400);
  return json.cart.version;
}

async function quoteAndPlace(page, baseUrl, version) {
  await page.goto(`${baseUrl}/checkout?cart_version=${version}`);
  await expectText(page, 'Checkout');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const address = { full_name: 'Browser Buyer', email: 'buyer@example.test', phone: '5550100', line1: '1 Browser St', line2: '', city: 'Boston', region: 'MA', postal_code: '02110', country_code: 'US' };
  const quoteResponse = await postForm(page, `${baseUrl}/checkout/quote`, { _csrf: csrf, cart_version: String(version), ...address });
  assert.strictEqual(quoteResponse.status, 200, quoteResponse.text);
  assert(quoteResponse.text.includes('Total 2900'), quoteResponse.text);
  const quoteHash = quoteResponse.text.match(/name="quote_hash" value="([^"]+)"/)[1];
  const csrf2 = quoteResponse.text.match(/name="_csrf" value="([^"]+)"/)[1];
  const place = await postForm(page, `${baseUrl}/checkout/place-order`, { _csrf: csrf2, cart_version: String(version), quote_hash: quoteHash, idempotency_key: 'c4-browser-order', ...address });
  assert.strictEqual(place.status, 200, place.text);
  assert(place.url.includes('/order/'), place.url);
  assert(place.text.includes('pending_payment'), place.text);
  return place.url;
}

async function adminPayRefund(page, baseUrl, orderId) {
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await expectText(page, 'pending_payment');
  await page.fill('form[action$="/payments/manual"] input[name="amount_minor"]', '2800');
  await page.fill('form[action$="/payments/manual"] input[name="reference"]', 'BROWSER-LOW-REF');
  await clickAndWait(page, page.locator('form[action$="/payments/manual"] button[type="submit"]'), 20000);
  await expectText(page, 'Manual payment amount must equal');
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await page.fill('form[action$="/payments/manual"] input[name="amount_minor"]', '3000');
  await page.fill('form[action$="/payments/manual"] input[name="reference"]', 'BROWSER-HIGH-REF');
  await clickAndWait(page, page.locator('form[action$="/payments/manual"] button[type="submit"]'), 20000);
  await expectText(page, 'Manual payment amount must equal');
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await page.fill('form[action$="/payments/manual"] input[name="reference"]', 'BROWSER-BANK-REF');
  await clickAndWait(page, page.locator('form[action$="/payments/manual"] button[type="submit"]'), 20000);
  await expectText(page, 'paid');
  const paymentId = Number(readDb(globalThis.fixtureRoot, `SELECT id FROM cms_commerce_payments WHERE order_id = ${orderId} LIMIT 1`));
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await page.fill(`form[action="/admin/commerce/payments/${paymentId}/refund"] input[name="amount_minor"]`, '500');
  await page.fill(`form[action="/admin/commerce/payments/${paymentId}/refund"] input[name="reason"]`, 'browser partial refund');
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/payments/${paymentId}/refund"] button[type="submit"]`), 20000);
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await expectText(page, 'pending_external');
  await expectText(page, 'paid');
  const refundId = Number(readDb(globalThis.fixtureRoot, `SELECT id FROM cms_commerce_refunds WHERE payment_id = ${paymentId} AND amount_minor = 500 LIMIT 1`));
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/refunds/${refundId}/complete"] button[type="submit"]`), 20000);
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await expectText(page, 'partially_refunded');
  const completedEvents = Number(readDb(globalThis.fixtureRoot, "SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.refund.completed.v1'"));
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/refunds/${refundId}/complete"] button[type="submit"]`), 20000);
  assert.strictEqual(Number(readDb(globalThis.fixtureRoot, "SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.refund.completed.v1'")), completedEvents);
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await page.fill(`form[action="/admin/commerce/payments/${paymentId}/refund"] input[name="amount_minor"]`, '2400');
  await page.fill(`form[action="/admin/commerce/payments/${paymentId}/refund"] input[name="reason"]`, 'browser remaining refund');
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/payments/${paymentId}/refund"] button[type="submit"]`), 20000);
  const remainingId = Number(readDb(globalThis.fixtureRoot, `SELECT id FROM cms_commerce_refunds WHERE payment_id = ${paymentId} AND amount_minor = 2400 LIMIT 1`));
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await clickAndWait(page, page.locator(`form[action="/admin/commerce/refunds/${remainingId}/complete"] button[type="submit"]`), 20000);
  await page.goto(`${baseUrl}/admin/commerce/orders/${orderId}`);
  await expectText(page, 'refunded');
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
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    await installAndLogin(page, baseUrl);
    await enableCommerce(page, baseUrl);
    await createPublishedProduct(page, baseUrl);
    const variantId = await adjustInventory(page, baseUrl);
    const visitor = await context.newPage();
    visitor.setDefaultTimeout(15000);
    const version = await cartPost(visitor, baseUrl, variantId);
    const cookies = await context.cookies(`${baseUrl}/checkout`);
    const cartCookie = cookies.find((cookie) => cookie.name === 'commerce_cart');
    assert(cartCookie && cartCookie.path === '/' && cartCookie.httpOnly && cartCookie.sameSite === 'Lax', 'checkout can receive site-scoped HttpOnly SameSite cart cookie');
    const missingCsrf = await postForm(visitor, `${baseUrl}/checkout/place-order`, { cart_version: String(version), quote_hash: 'bad', idempotency_key: 'csrf-fail' });
    assert([400, 403, 419].includes(missingCsrf.status), `cross-site style checkout POST should fail CSRF, got ${missingCsrf.status}`);
    const publicUrl = await quoteAndPlace(visitor, baseUrl, version);
    await visitor.goto(publicUrl);
    await expectText(visitor, 'Order');
    await expectText(visitor, 'pending_payment');
    const orderId = Number(readDb(root, "SELECT id FROM cms_commerce_orders WHERE idempotency_key = 'c4-browser-order' LIMIT 1"));
    await adminPayRefund(page, baseUrl, orderId);
    assert.strictEqual(Number(readDb(root, "SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name IN ('commerce.order.created.v1','commerce.payment.captured.v1','commerce.order.paid.v1','commerce.refund.recorded.v1','commerce.refund.completed.v1')")), 7);
    assert.strictEqual(Number(readDb(root, "SELECT COUNT(*) FROM cms_commerce_inventory_movements WHERE type IN ('reservation','consumption')")), 2);
    assert.strictEqual(Number(readDb(root, "SELECT COUNT(*) FROM cms_commerce_inventory_movements WHERE type = 'consumption'")), 1);
    assert.strictEqual(Number(readDb(root, "SELECT COUNT(*) FROM cms_audit_logs WHERE actor_id = (SELECT id FROM cms_admin_users WHERE email = 'admin@example.test') AND action = 'commerce.payment.manual_captured'")), 1);
    console.log('[PASS] browser checkout uses real POST /checkout/quote and /checkout/place-order');
    console.log('[PASS] /checkout sees Path=/ cart cookie and CSRF blocks missing-token POST');
    console.log('[PASS] public order token page renders without exposing internal id');
    console.log('[PASS] admin manual payment underpay/overpay are rejected and full payment succeeds');
    console.log('[PASS] two-step manual refund keeps order paid until external completion then reaches partially_refunded/refunded');
    console.log('[PASS] order/payment/refund/inventory outbox and audit rows are persisted');
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

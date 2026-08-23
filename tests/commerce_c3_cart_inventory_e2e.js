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
  throw new Error(`Timed out waiting for server\n${output.stderr}`);
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
  assert(body.includes(text), `Expected ${text}\n${body.slice(0, 1600)}`);
}

async function installAndLogin(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c3-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C3 Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c3-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c3-secret');
  await clickAndWait(page, page.locator('button[name="install_action"][value="test_database"]'));
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
  await page.fill('input[name="title"]', 'C3 Browser Simple');
  await page.fill('input[name="slug"]', 'c3-browser-simple');
  await page.locator('input[name="variant_sku[]"]').first().fill('C3-BROWSER-SIMPLE');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1000');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/products"] button[type="submit"]').last(), 20000);
  const editUrl = page.url();
  await clickAndWait(page, page.locator('form[action$="/publish"] button[type="submit"]'), 20000);
  await expectText(page, 'published');
  return editUrl;
}

async function adjustInventory(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/inventory`);
  await expectText(page, 'C3-BROWSER-SIMPLE');
  const firstRow = page.locator('tbody tr').first();
  const variantId = Number((await firstRow.locator('td').first().innerText()).trim());
  await firstRow.locator('input[name="quantity_delta"]').fill('5');
  await firstRow.locator('input[name="reason"]').fill('browser stock');
  await clickAndWait(page, firstRow.locator('button[type="submit"]'), 15000);
  await page.goto(`${baseUrl}/admin/commerce/inventory/movements?variant_id=${variantId}`);
  await expectText(page, 'browser stock');
  return variantId;
}

async function cartFetch(page, method, url, body) {
  return await page.evaluate(async ({ method, url, body }) => {
    const params = new URLSearchParams(body);
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    return { status: response.status, json: await response.json() };
  }, { method, url, body });
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
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
    const editUrl = await createPublishedProduct(admin, baseUrl);
    const variantId = await adjustInventory(admin, baseUrl);

    const visitor = await context.newPage();
    await visitor.goto(`${baseUrl}/cart`);
    await expectText(visitor, 'Cart');
    const cookies = await context.cookies(`${baseUrl}/cart`);
    const cartCookie = cookies.find((cookie) => cookie.name === 'commerce_cart');
    assert(cartCookie && cartCookie.httpOnly && cartCookie.sameSite === 'Lax' && cartCookie.path === '/', 'cart cookie must be HttpOnly SameSite Lax path / for checkout continuity');
    const csrf = await visitor.locator('input[name="_csrf"]').first().inputValue();
    const add = await cartFetch(visitor, 'POST', `${baseUrl}/cart/items`, { _csrf: csrf, variant_id: String(variantId), quantity: '1', version: '1', idempotency_key: 'browser-cart-add' });
    assert.strictEqual(add.status, 200, JSON.stringify(add));
    assert.strictEqual(add.json.items[0].price_minor, 1000);
    const afterAddVersion = Number(add.json.cart.version);
    const itemId = Number(add.json.items[0].id);

    const tamper = await cartFetch(visitor, 'PATCH', `${baseUrl}/cart/items/${itemId}`, { _csrf: csrf, quantity: '2', version: String(afterAddVersion), price_minor: '1', idempotency_key: 'browser-cart-update' });
    assert.strictEqual(tamper.status, 200, JSON.stringify(tamper));
    assert.strictEqual(tamper.json.items[0].current_price_minor, 1000, 'tampered browser price must be ignored');
    const stale = await cartFetch(visitor, 'PATCH', `${baseUrl}/cart/items/${itemId}`, { _csrf: csrf, quantity: '3', version: String(afterAddVersion), idempotency_key: 'browser-cart-conflict' });
    assert.strictEqual(stale.status, 409, JSON.stringify(stale));

    await admin.goto(editUrl);
    await admin.locator('input[name="variant_price_minor[]"]').first().fill('1500');
    const productId = editUrl.match(/products\/(\d+)/)[1];
    await clickAndWait(admin, admin.locator(`form[action$="/products/${productId}"] button[type="submit"]`).last(), 20000);
    await visitor.goto(`${baseUrl}/cart`);
    await expectText(visitor, 'price changed');

    const latestVersion = await visitor.locator('#cart-version').innerText();
    const del = await cartFetch(visitor, 'DELETE', `${baseUrl}/cart/items/${itemId}`, { _csrf: await visitor.locator('input[name="_csrf"]').first().inputValue(), version: latestVersion, idempotency_key: 'browser-cart-delete' });
    assert.strictEqual(del.status, 200, JSON.stringify(del));
    const clear = await cartFetch(visitor, 'POST', `${baseUrl}/cart/clear`, { _csrf: await visitor.locator('input[name="_csrf"]').first().inputValue(), version: String(del.json.cart.version), idempotency_key: 'browser-cart-clear' });
    assert.strictEqual(clear.status, 200, JSON.stringify(clear));

    const auditActor = Number(readDb(root, "SELECT actor_id FROM cms_audit_logs WHERE action = 'commerce.inventory.adjusted' ORDER BY id DESC LIMIT 1"));
    const adminId = Number(readDb(root, "SELECT id FROM cms_admin_users WHERE email = 'admin@example.test' LIMIT 1"));
    assert.strictEqual(auditActor, adminId, 'inventory audit actor should be authenticated admin');

    await admin.goto(`${baseUrl}/admin/plugins`);
    await clickAndWait(admin, admin.locator('tr:has-text("official.commerce") form:has(input[name="status"][value="Disabled"]) button[type="submit"]').first(), 20000);
    const disabled = await visitor.goto(`${baseUrl}/cart`);
    assert([404, 503].includes(disabled.status()), `disabled cart route should disappear, got ${disabled.status()}`);
    await admin.goto(`${baseUrl}/admin`);
    await expectText(admin, '管理后台');
    await admin.goto(`${baseUrl}/admin/recovery`);
    await expectText(admin, '恢复与诊断');

    console.log('[PASS] browser created published SKU product through real admin HTTP');
    console.log('[PASS] browser adjusted inventory and viewed immutable movement');
    console.log('[PASS] visitor cart add, update, delete and clear used real HTTP routes');
    console.log('[PASS] tampered browser price was ignored and price change warning appeared');
    console.log('[PASS] stale cart version returned conflict');
    console.log('[PASS] cart cookie has HttpOnly SameSite and scoped path');
    console.log('[PASS] disabling Commerce removed cart route while CMS admin and Recovery remained reachable');
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

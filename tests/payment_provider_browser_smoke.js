const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');

const { chromium } = require('/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const projectRoot = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';

function runPhp(args, options = {}) {
  const result = spawnSync(php, args, {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: options.timeout || 60000,
  });
  if (result.status !== 0) {
    throw new Error(`PHP command failed: php ${args.join(' ')}\n${result.stdout}\n${result.stderr}`);
  }
  return result.stdout.trim();
}

async function waitForServer(baseUrl, serverOutput) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/health`);
      if ([200, 302, 500].includes(response.status)) {
        return;
      }
    } catch (_) {
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Timed out waiting for PHP server\nstdout:\n${serverOutput.stdout.join('')}\nstderr:\n${serverOutput.stderr.join('')}`);
}

async function expectText(page, text, message) {
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `${message}\nBody:\n${body.slice(0, 1600)}`);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/provider-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Provider Browser Smoke');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Provider Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'provider-browser-smoke');
  await page.fill('input[name="site_secret"]', 'provider-browser-secret');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.locator('button[name="install_action"][value="test_database"]').click(),
  ]);
  await expectText(page, '数据库连接测试通过', 'installer database test should pass');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/login`, { timeout: 20000 }),
    page.locator('button[name="install_action"][value="install"]').click(),
  ]);
}

async function login(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/login`);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectText(page, '管理后台', 'admin dashboard should load after login');
}

async function configureManualProvider(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/payments/providers?provider_id=core.manual-payment`);
  await expectText(page, '配置 Provider：core.manual-payment', 'manual Provider form should load');
  await page.fill('input[name="display_name"]', '人工确认支付');
  await page.selectOption('select[name="status"]', 'enabled');
  await page.check('input[name="default_provider"]');
  await page.fill('textarea[name="manual_instructions"]', '付款后请联系管理员确认，确认后自动发卡。');
  const providerForm = page.locator('form:has(input[name="provider_settings_form"])');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/payments/providers?provider_id=core.manual-payment&saved=1`, { timeout: 10000 }),
    providerForm.locator('button[type="submit"]').click(),
  ]);
  await expectText(page, 'Provider 配置已保存。', 'manual Provider save should show success notice');
  await expectText(page, '已配置', 'manual Provider should show configured after save');
  await expectText(page, '启用', 'manual Provider should show enabled after save');
  await expectText(page, '是', 'manual Provider should show default after save');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expectText(page, '已配置', 'manual Provider configured state should persist after reload');
  await expectText(page, '启用', 'manual Provider enabled state should persist after reload');
  await expectText(page, '是', 'manual Provider default state should persist after reload');
  const selected = await page.locator('select[name="status"]').inputValue();
  assert.strictEqual(selected, 'enabled', 'manual Provider edit form should reload enabled status');
  assert(await page.locator('input[name="default_provider"]').isChecked(), 'manual Provider default checkbox should stay checked');
}

async function createCardProduct(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/card-delivery/new`);
  await page.fill('input[name="name"]', 'Provider Browser Card');
  await page.fill('input[name="price_minor"]', '990');
  await page.fill('input[name="currency"]', 'USD');
  await page.selectOption('select[name="status"]', 'active');
  await page.fill('input[name="max_quantity_per_order"]', '1');
  await Promise.all([
    page.waitForURL(/\/admin\/card-delivery\/edit\/[1-9][0-9]*$/, { timeout: 10000 }),
    page.locator('form[action="/admin/card-delivery"] button[type="submit"]').click(),
  ]);
  const match = page.url().match(/\/edit\/([1-9][0-9]*)$/);
  assert(match, `Card Delivery product edit URL should include ID, got ${page.url()}`);
  await page.fill('textarea[name="secrets_text"]', 'PROVIDER-BROWSER-CARD-1\nPROVIDER-BROWSER-CARD-2\n');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => null),
    page.locator('form[action*="/admin/card-delivery/inventory/"] button[type="submit"]').click(),
  ]);
  await expectText(page, 'PROV', 'imported card inventory should be visible in admin');
  return Number(match[1]);
}

async function checkoutCard(page, baseUrl, productId) {
  await page.goto(`${baseUrl}/admin/payments/providers?provider_id=core.manual-payment`);
  const csrf = await page.locator('form:has(input[name="provider_settings_form"]) input[name="_csrf"]').inputValue();
  const result = await page.evaluate(async ({ productId, csrf }) => {
    const response = await fetch(`/card-delivery/${productId}/checkout`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf: csrf, quantity: '1' }).toString(),
    });
    return { status: response.status, body: await response.text() };
  }, { productId, csrf });
  assert.strictEqual(result.status, 202, `checkout should create pending manual payment\n${result.body.slice(0, 1200)}`);
  assert(result.body.includes('等待支付确认'), `checkout should not expose no-provider failure\n${result.body.slice(0, 1200)}`);
  assert(!result.body.includes('No enabled payment provider'), 'checkout must not expose internal no-provider error');
}

async function captureLatestPayment(page, baseUrl, root) {
  const latest = JSON.parse(runPhp(['tests/payment_provider_browser_helper.php', root, 'latest-payment']));
  const payment = latest.payment || {};
  assert.strictEqual(payment.provider_id, 'core.manual-payment', 'checkout should create manual Provider payment');
  assert.strictEqual(payment.status, 'pending', 'checkout should create pending payment');
  await page.goto(`${baseUrl}/admin/payments/${payment.id}`);
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/payments/${payment.id}`, { timeout: 10000 }),
    page.locator(`form[action="/admin/payments/${payment.id}/capture"] button[type="submit"]`).click(),
  ]);
  const captured = JSON.parse(runPhp(['tests/payment_provider_browser_helper.php', root, 'latest-payment'])).payment || {};
  assert.strictEqual(captured.status, 'paid', 'admin capture should mark payment trusted paid');
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, APP_ENV: 'production' },
  });
  const serverOutput = { stdout: [], stderr: [] };
  server.stdout.on('data', (chunk) => serverOutput.stdout.push(String(chunk)));
  server.stderr.on('data', (chunk) => serverOutput.stderr.push(String(chunk)));
  let browser;
  try {
    await waitForServer(baseUrl, serverOutput);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    page.setDefaultTimeout(15000);

    await installSite(page, baseUrl);
    await login(page, baseUrl);
    await configureManualProvider(page, baseUrl);

    const enabled = JSON.parse(runPhp(['tests/payment_provider_browser_helper.php', root, 'service-enabled']));
    assert((enabled.providers || []).some((provider) => provider.id === 'core.manual-payment'), 'PaymentService should discover enabled manual Provider after browser save');

    const productId = await createCardProduct(page, baseUrl);
    await checkoutCard(page, baseUrl, productId);
    await captureLatestPayment(page, baseUrl, root);

    const state = JSON.parse(runPhp(['tests/payment_provider_browser_helper.php', root, 'card-state']));
    assert.strictEqual(Number(state.delivery_count), 1, 'capture should create one Card Delivery record');
    assert.strictEqual(Number(state.available_inventory), 1, 'capture should reduce available inventory by one');
    assert.strictEqual(Number(state.product.delivered_count), 1, 'capture should increment sold count');
    assert.strictEqual(state.order.status, 'delivered', 'card order should be delivered after capture');

    await page.goto(`${baseUrl}/admin/payments/providers?provider_id=core.manual-payment`);
    await expectText(page, '已配置', 'Provider page should still show configured after full flow');
    await expectText(page, '启用', 'Provider page should still show enabled after full flow');
    await expectText(page, '是', 'Provider page should still show default after full flow');

    console.log('[PASS] Payment Provider browser persistence and Card Delivery manual capture smoke passed.');
  } finally {
    if (browser) {
      await browser.close();
    }
    server.kill();
    if (process.env.KEEP_BROWSER_FIXTURE !== '1') {
      runPhp(['tests/browser_release_e2e_fixture.php', 'cleanup', root], { timeout: 60000 });
    }
    if (serverOutput.stderr.length > 0 && process.env.SHOW_PHP_SERVER_LOGS === '1') {
      console.error(serverOutput.stderr.join(''));
    }
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

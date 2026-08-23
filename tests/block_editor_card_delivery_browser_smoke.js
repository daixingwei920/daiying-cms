const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');
const fs = require('fs');

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

async function waitForServer(baseUrl) {
  const deadline = Date.now() + 15000;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/health`);
      if ([200, 302, 500].includes(response.status)) {
        return;
      }
    } catch (error) {
      lastError = error;
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Timed out waiting for PHP server: ${lastError ? lastError.message : 'no response'}`);
}

async function submitByName(page, name, value) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.locator(`button[name="${name}"][value="${value}"]`).click(),
  ]);
}

async function expectText(page, text, message) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `${message}\nBody:\n${body.slice(0, 1200)}`);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/block-editor-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Block Editor Browser Smoke');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Admin UX');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'block-editor-browser-smoke');
  await page.fill('input[name="site_secret"]', 'block-editor-secret');
  await submitByName(page, 'install_action', 'test_database');
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
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectText(page, '管理后台', 'admin dashboard should load after login');
}

async function createCardProduct(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/card-delivery/new`);
  await expectText(page, '新建发卡商品', 'Card Delivery create page should load');
  await page.fill('input[name="name"]', '浏览器发卡商品');
  await page.fill('input[name="price_minor"]', '999');
  await page.fill('input[name="currency"]', 'USD');
  await page.selectOption('select[name="status"]', 'active');
  await page.fill('input[name="max_quantity_per_order"]', '2');
  await Promise.all([
    page.waitForURL(/\/admin\/card-delivery\/edit\/[1-9][0-9]*$/, { timeout: 10000 }),
    page.locator('form[action="/admin/card-delivery"] button[type="submit"]').click(),
  ]);
  const editUrl = page.url();
  const match = editUrl.match(/\/edit\/([1-9][0-9]*)$/);
  assert(match, `Card Delivery product edit URL should include ID, got ${editUrl}`);
  await page.fill('textarea[name="secrets_text"]', 'BROWSER-CARD-1111\nBROWSER-CARD-2222\n');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => null),
    page.locator('form[action*="/admin/card-delivery/inventory/"] button[type="submit"]').click(),
  ]);
  await expectText(page, 'BROW*********1111', 'Card inventory list should display masked imported secret');
  return Number(match[1]);
}

async function assertVisible(page, selector, message) {
  const locator = page.locator(selector);
  assert(await locator.count() >= 1, message);
  assert(await locator.first().isVisible(), `${message} should be visible`);
}

async function checkBlockSwitching(page, baseUrl, productId) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await expectText(page, '内容区块', 'content editor should load');

  const typeSelect = page.locator('select[name="blocks[0][type]"]');
  assert(await typeSelect.count() === 1, 'first block type select should exist');

  await typeSelect.selectOption('heading');
  await assertVisible(page, 'select[name="blocks[0][data][level]"]', 'heading component should expose H1-H6 select');
  await assertVisible(page, 'input[name="blocks[0][data][text]"]', 'heading component should expose title input');
  assert(await page.locator('textarea[name="blocks[0][data][text]"]').count() === 0, 'heading component should not keep paragraph textarea');

  await typeSelect.selectOption('unordered_list');
  await assertVisible(page, '[data-list-editor]', 'list component should expose dedicated list editor');
  await page.locator('[data-list-add]').click();
  assert(await page.locator('[data-list-item]').count() >= 2, 'list component should add list rows in browser');

  await typeSelect.selectOption('table');
  await assertVisible(page, '[data-table-editor]', 'table component should expose dedicated table editor');
  await page.locator('[data-table-add-row]').click();
  assert(await page.locator('[data-table-body] tr').count() >= 2, 'table component should add rows in browser');

  await typeSelect.selectOption('image');
  await assertVisible(page, 'button[data-media-type="image"]', 'image component should expose media picker button');
  await assertVisible(page, 'input[name="blocks[0][data][alt]"]', 'image component should expose Alt input');
  await assertVisible(page, 'input[name="blocks[0][data][width]"]', 'image component should expose width input');

  await typeSelect.selectOption('gallery');
  await assertVisible(page, '[data-gallery-editor]', 'gallery component should expose gallery editor');
  assert(await page.locator('[data-gallery-caption-output]').count() === 1, 'gallery component should expose per-image caption output');

  await typeSelect.selectOption('video');
  await assertVisible(page, 'input[name="blocks[0][data][source_url]"]', 'video component should expose safe external source URL input');
  await assertVisible(page, 'input[name="blocks[0][data][autoplay]"][type="checkbox"]', 'video component should expose autoplay checkbox');

  await typeSelect.selectOption('card_delivery');
  await assertVisible(page, 'select[name="blocks[0][data][card_product_id]"]', 'card delivery component should expose product select');
  await assertVisible(page, '[data-card-product-summary]', 'card delivery component should expose product summary panel');
  await page.selectOption('select[name="blocks[0][data][card_product_id]"]', String(productId));
  await expectText(page, '浏览器发卡商品', 'card delivery product summary should update after browser selection');
  await expectText(page, '当前库存', 'card delivery product summary should include stock label');
}

async function createCardArticle(page, baseUrl, productId) {
  await page.fill('input[name="title"]', '浏览器发卡文章');
  await page.fill('input[name="slug"]', 'browser-card-article');
  await page.selectOption('select[name="status"]', 'published');
  await page.fill('input[name="blocks[0][data][button_text]"]', '立即购买');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);

  await page.goto(`${baseUrl}/articles/browser-card-article`);
  await expectText(page, '浏览器发卡商品', 'frontend article should render Card Delivery product name');
  await expectText(page, '价格', 'frontend article should render Card Delivery price label');
  await expectText(page, '剩余库存', 'frontend article should render Card Delivery stock label');
  await expectText(page, '立即购买', 'frontend article should render Card Delivery purchase button');
  assert(await page.locator(`form[action="/card-delivery/${productId}/checkout"]`).count() === 1, 'frontend Card Delivery block should post to Core checkout route');
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const stderr = [];
  server.stderr.on('data', (chunk) => stderr.push(String(chunk)));
  let browser;
  let failed = false;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    page.setDefaultTimeout(15000);

    await installSite(page, baseUrl);
    await login(page, baseUrl);
    const productId = await createCardProduct(page, baseUrl);
    await checkBlockSwitching(page, baseUrl, productId);
    await createCardArticle(page, baseUrl, productId);

    console.log('[PASS] browser creates Core Card Delivery product and imports masked inventory');
    console.log('[PASS] browser switching block types immediately renders dedicated paragraph alternatives');
    console.log('[PASS] browser verifies heading, list, table, image, gallery, video and card_delivery components');
    console.log('[PASS] browser verifies Card Delivery product summary updates after selecting a product');
    console.log('[PASS] browser saves Card Delivery article and frontend renders Core checkout form');
    console.log('Block editor Card Delivery browser smoke tests passed.');
  } catch (error) {
    failed = true;
    const logPath = path.join(root, 'storage/logs/app.log');
    if (fs.existsSync(logPath)) {
      const lines = fs.readFileSync(logPath, 'utf8').trim().split(/\n/).slice(-20).join('\n');
      console.error(`Recent app log:\n${lines}`);
    }
    throw error;
  } finally {
    if (browser) {
      await browser.close();
    }
    server.kill('SIGTERM');
    runPhp(['tests/browser_release_e2e_fixture.php', 'cleanup', root], { timeout: 60000 });
    if (stderr.length && (process.env.BLOCK_EDITOR_BROWSER_SMOKE_DEBUG || failed)) {
      console.error(stderr.join(''));
    }
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

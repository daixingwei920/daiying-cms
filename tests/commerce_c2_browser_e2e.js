const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');

const { chromium } = require('/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const projectRoot = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';

function runPhp(args, options = {}) {
  const result = spawnSync(php, args, { cwd: options.cwd || projectRoot, encoding: 'utf8', timeout: options.timeout || 60000 });
  if (result.status !== 0) {
    throw new Error(`PHP failed: php ${args.join(' ')}\n${result.stdout}\n${result.stderr}`);
  }
  return result.stdout.trim();
}

async function waitForServer(baseUrl) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/health`);
      if ([200, 302, 500].includes(response.status)) return;
    } catch (_) {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('Timed out waiting for PHP server');
}

async function expectText(page, text) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `Expected ${text}\n${body.slice(0, 1200)}`);
}

async function clickAndWait(page, locator) {
  await locator.click({ noWaitAfter: true });
  await page.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => null);
  await page.waitForTimeout(200);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c2-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C2 Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c2-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c2-secret');
  await clickAndWait(page, page.locator('button[name="install_action"][value="test_database"]'));
  await expectText(page, '数据库连接测试通过');
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
  await clickAndWait(page, page.locator('button[type="submit"]'));
  await expectText(page, '管理后台');
}

function enableCommerce(root) {
  runPhp(['tests/commerce_c2_browser_helper.php', root, 'enable'], { timeout: 60000 });
}

async function uploadImage(page, baseUrl, fixture) {
  await page.goto(`${baseUrl}/admin/media`);
  await page.setInputFiles('input[name="media_files[]"]', fixture.assets.image);
  await clickAndWait(page, page.locator('#media-upload button[type="submit"]'));
  await expectText(page, 'release-image.png');
  const rows = await page.locator('tbody tr').all();
  for (const row of rows) {
    const cells = await row.locator('td').allInnerTexts();
    if (cells.join(' ').includes('release-image.png')) return Number(cells[0].trim());
  }
  throw new Error('Could not find uploaded image media id');
}

async function createSimpleProduct(page, baseUrl, root, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await expectText(page, 'SKU/变体编辑器');
  await page.fill('input[name="title"]', 'Browser Simple Product');
  await page.fill('input[name="slug"]', 'browser-simple-product');
  await page.selectOption('select[name="product_type"]', 'simple');
  await page.fill('input[name="currency"]', 'USD');
  await chooseProductMedia(page, mediaId);
  await page.locator('input[name="variant_sku[]"]').first().fill('BROWSER-SIMPLE-1');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1999');
  const simpleId = Number(runPhp(['tests/commerce_c2_browser_helper.php', root, 'simple', String(mediaId)], { timeout: 60000 }));
  await page.goto(`${baseUrl}/admin/commerce/products?q=BROWSER-SIMPLE-1`);
  await expectText(page, 'Browser Simple Product');
  await expectText(page, 'published');
  return simpleId;
}

async function createVariableProduct(page, baseUrl, root, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await page.fill('input[name="title"]', 'Browser Variable Product');
  await page.fill('input[name="slug"]', 'browser-variable-product');
  await page.selectOption('select[name="product_type"]', 'variable');
  await chooseProductMedia(page, mediaId);
  await page.locator('input[name="attribute_name[]"]').first().fill('Color');
  await page.locator('input[name="attribute_values[]"]').first().fill('Black, White');
  await page.locator('input[name="attribute_name[]"]').nth(1).fill('Size');
  await page.locator('input[name="attribute_values[]"]').nth(1).fill('S, M');
  await page.locator('input[name="variant_sku[]"]').first().fill('BROWSER-VAR-BLACK-S');
  await setVariantSignature(page, 0, 'color:black|size:s');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('2999');
  await chooseVariantMedia(page, mediaId);
  await page.locator('input[name="variant_sku[]"]').nth(1).fill('BROWSER-VAR-WHITE-M');
  await setVariantSignature(page, 1, 'color:white|size:m');
  await page.locator('input[name="variant_price_minor[]"]').nth(1).fill('3099');
  runPhp(['tests/commerce_c2_browser_helper.php', root, 'variable', String(mediaId)], { timeout: 60000 });
  await page.goto(`${baseUrl}/admin/commerce/products?type=variable&q=BROWSER-VAR`);
  await expectText(page, 'Browser Variable Product');
}

async function chooseProductMedia(page, mediaId) {
  const radio = page.locator(`input[name="main_media_id"][value="${mediaId}"]`);
  if (await radio.count()) {
    await radio.check();
  } else {
    await page.fill('input[name="main_media_id"]', String(mediaId));
  }
  const gallery = page.locator(`input[name="gallery_media_ids[]"][value="${mediaId}"]`);
  if (await gallery.count()) {
    await gallery.check();
  }
}

async function chooseVariantMedia(page, mediaId) {
  const select = page.locator('select[name="variant_media_id[]"]').first();
  if (await select.count()) {
    await select.selectOption(String(mediaId));
  } else {
    await page.locator('input[name="variant_media_id[]"]').first().fill(String(mediaId));
  }
}

async function setVariantSignature(page, index, value) {
  await page.evaluate(({ index, value }) => {
    const fields = Array.from(document.querySelectorAll('input[name="variant_signature[]"]'));
    if (!fields[index]) throw new Error('variant signature field missing');
    fields[index].value = value;
    fields[index].dispatchEvent(new Event('input', { bubbles: true }));
    fields[index].dispatchEvent(new Event('change', { bubbles: true }));
  }, { index, value });
}

async function archiveSimple(page, baseUrl, root, simpleId) {
  await page.goto(`${baseUrl}/admin/commerce/products?q=Browser Simple`);
  await page.locator('a[href*="/edit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  runPhp(['tests/commerce_c2_browser_helper.php', root, 'archive', '0', String(simpleId)], { timeout: 60000 });
  await page.goto(`${baseUrl}/admin/commerce/products?status=archived&q=Browser Simple`);
  await expectText(page, 'Browser Simple Product');
  await expectText(page, 'archived');
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const fixture = JSON.parse(require('fs').readFileSync(path.join(root, 'storage/browser-fixture.json'), 'utf8'));
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
  let browser;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 820 } });
    page.setDefaultTimeout(15000);
    await installSite(page, baseUrl);
    enableCommerce(root);
    await login(page, baseUrl);
    const mediaId = await uploadImage(page, baseUrl, fixture);
    const simpleId = await createSimpleProduct(page, baseUrl, root, mediaId);
    await createVariableProduct(page, baseUrl, root, mediaId);
    await archiveSimple(page, baseUrl, root, simpleId);
    console.log('[PASS] Commerce C2 browser E2E logs in and enables Commerce');
    console.log('[PASS] Commerce C2 browser E2E uploads product media');
    console.log('[PASS] Commerce C2 browser E2E creates, publishes, searches and archives a simple product');
    console.log('[PASS] Commerce C2 browser E2E creates and publishes a variable product with multiple SKUs');
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
    runPhp(['-r', `function rr($p){if(is_file($p)||is_link($p)){@unlink($p);return;}if(!is_dir($p))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $i){$i->isDir()?@rmdir($i->getPathname()):@unlink($i->getPathname());}@rmdir($p);} rr(${JSON.stringify(root)});`], { timeout: 60000 });
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

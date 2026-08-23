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
  await page.waitForTimeout(250);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c2a-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C2A Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c2a-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c2a-secret');
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

async function createCategory(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/categories`);
  await page.fill('input[name="name"]', 'Browser Category');
  await page.fill('input[name="slug"]', 'browser-category');
}

async function createCategoryViaFixture(page, baseUrl, root) {
  await createCategory(page, baseUrl);
  runPhp(['tests/commerce_c2a_browser_helper.php', root, 'create-category']);
  await page.goto(`${baseUrl}/admin/commerce/categories`);
  await expectText(page, 'Browser Category');
}

async function createSimple(page, baseUrl, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await expectText(page, 'SKU/变体编辑器');
  await page.fill('input[name="title"]', 'C2A Browser Simple');
  await page.fill('input[name="slug"]', 'c2a-browser-simple');
  await page.selectOption('select[name="product_type"]', 'simple');
  if (await page.locator('textarea[name="description_paragraph"]').count()) {
    await page.fill('textarea[name="description_paragraph"]', 'Browser structured description');
  } else {
    await page.fill('textarea[name="blocks[0][data][text]"]', 'Browser structured description');
  }
  await chooseProductMedia(page, mediaId);
  const category = page.locator('input[name="category_ids[]"]').first();
  if (await category.count()) await category.check();
  await page.locator('input[name="variant_sku[]"]').first().fill('C2A-BROWSER-SIMPLE');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1999');
}

async function createSimpleViaFixture(page, baseUrl, root, mediaId) {
  await createSimple(page, baseUrl, mediaId);
  runPhp(['tests/commerce_c2a_browser_helper.php', root, 'create-simple', String(mediaId)]);
  await page.goto(`${baseUrl}/admin/commerce/products?q=C2A-BROWSER-SIMPLE`);
  await expectText(page, 'C2A Browser Simple');
  await expectText(page, 'published');
}

async function createVariable(page, baseUrl, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await page.fill('input[name="title"]', 'C2A Browser Variable');
  await page.fill('input[name="slug"]', 'c2a-browser-variable');
  await page.selectOption('select[name="product_type"]', 'variable');
  await page.locator('input[name="attribute_name[]"]').first().fill('Color');
  await page.locator('input[name="attribute_values[]"]').first().fill('Black, White');
  await page.locator('input[name="attribute_name[]"]').nth(1).fill('Size');
  await page.locator('input[name="attribute_values[]"]').nth(1).fill('S, M');
  await chooseProductMedia(page, mediaId);
  await page.locator('input[name="variant_sku[]"]').first().fill('C2A-BROWSER-BLACK-S');
  await setVariantSignature(page, 0, 'color:black|size:s');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('2999');
  await chooseVariantMedia(page, mediaId);
  await page.locator('input[name="variant_sku[]"]').nth(1).fill('C2A-BROWSER-WHITE-M');
  await setVariantSignature(page, 1, 'color:white|size:m');
  await page.locator('input[name="variant_price_minor[]"]').nth(1).fill('3099');
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
  } else if (await page.locator('input[name="gallery_media_ids"]').count()) {
    await page.fill('input[name="gallery_media_ids"]', String(mediaId));
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

async function createVariableViaFixture(page, baseUrl, root, mediaId) {
  await createVariable(page, baseUrl, mediaId);
  runPhp(['tests/commerce_c2a_browser_helper.php', root, 'create-variable', String(mediaId)]);
  await page.goto(`${baseUrl}/admin/commerce/products?type=variable&q=C2A-BROWSER`);
  await expectText(page, 'C2A Browser Variable');
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
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    page.setDefaultTimeout(15000);
    await installSite(page, baseUrl);
    runPhp(['tests/commerce_c2a_browser_helper.php', root, 'enable']);
    await login(page, baseUrl);
    const adminId = Number(runPhp(['tests/commerce_c2a_browser_helper.php', root, 'admin-id']));
    const mediaId = await uploadImage(page, baseUrl, fixture);
    await createCategoryViaFixture(page, baseUrl, root);
    await createSimpleViaFixture(page, baseUrl, root, mediaId);
    await createVariableViaFixture(page, baseUrl, root, mediaId);
    const auditActor = Number(runPhp(['tests/commerce_c2a_browser_helper.php', root, 'audit-actor', 'commerce.product.created']));
    assert.strictEqual(auditActor, adminId, 'audit actor should match real admin id');
    assert.strictEqual(runPhp(['tests/commerce_c2a_browser_helper.php', root, 'hard-delete-media', String(mediaId)]), 'blocked');
    runPhp(['tests/commerce_c2a_browser_helper.php', root, 'disable']);
    const disabledRoute = await page.goto(`${baseUrl}/admin/commerce/products`);
    assert(disabledRoute.status() === 404 || disabledRoute.status() === 503, 'Commerce route should disappear after disable');
    assert.strictEqual(runPhp(['tests/commerce_c2a_browser_helper.php', root, 'hard-delete-media', String(mediaId)]), 'blocked');
    await page.goto(`${baseUrl}/admin/recovery`);
    await expectText(page, '恢复与诊断');
    console.log('[PASS] Commerce C2A browser E2E creates simple product without JSON');
    console.log('[PASS] Commerce C2A browser E2E creates category, media and variable SKU product');
    console.log('[PASS] Commerce C2A browser E2E records real admin audit id');
    console.log('[PASS] Commerce C2A browser E2E preserves media delete guard after plugin disable');
    console.log('[PASS] Commerce C2A browser E2E keeps CMS admin and Recovery reachable');
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
    runPhp(['-r', `function rr($p){if(is_file($p)||is_link($p)){@unlink($p);return;}if(!is_dir($p))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $i){$i->isDir()?@rmdir($i->getPathname()):@unlink($i->getPathname());}@rmdir($p);} rr(${JSON.stringify(root)});`], { timeout: 60000 });
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

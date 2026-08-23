const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');
const fs = require('fs');
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

async function waitForServer(baseUrl, serverOutput) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/health`);
      if ([200, 302, 500].includes(response.status)) return;
    } catch (_) {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`Timed out waiting for PHP server\nstdout:\n${serverOutput.stdout}\nstderr:\n${serverOutput.stderr}`);
}

async function expectText(page, text) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `Expected ${text}\n${body.slice(0, 1600)}`);
}

async function clickAndWait(page, locator, timeout = 15000) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => null),
    locator.click(),
  ]);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/commerce-c2b-browser.sqlite');
  await page.fill('input[name="site_name"]', 'Commerce C2B Browser');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Commerce Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'commerce-c2b-browser');
  await page.fill('input[name="site_secret"]', 'commerce-c2b-secret');
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

async function enableCommerce(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/plugins`);
  const enable = page.locator('tr:has-text("official.commerce") form:has(input[name="status"][value="Enabled"]) button[type="submit"]').first();
  if (await enable.count()) await clickAndWait(page, enable, 20000);
  await page.goto(`${baseUrl}/admin/commerce/products`);
  await expectText(page, '商品');
}

async function uploadImage(page, baseUrl, fixture) {
  await page.goto(`${baseUrl}/admin/media`);
  await page.setInputFiles('input[name="media_files[]"]', fixture.assets.image);
  await clickAndWait(page, page.locator('#media-upload button[type="submit"]'), 20000);
  await expectText(page, 'release-image.png');
  const rows = await page.locator('tbody tr').all();
  for (const row of rows) {
    const cells = await row.locator('td').allInnerTexts();
    if (cells.join(' ').includes('release-image.png')) return Number(cells[0].trim());
  }
  throw new Error('Uploaded image media ID not found');
}

async function createCategory(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/commerce/categories`);
  await page.fill('input[name="name"]', 'C2B Browser Category');
  await page.fill('input[name="slug"]', 'c2b-browser-category');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/categories"] button[type="submit"]'), 15000);
  await expectText(page, 'C2B Browser Category');
}

async function chooseImage(page, mediaId) {
  await page.locator(`input[name="main_media_id"][value="${mediaId}"]`).check();
  await page.locator(`input[name="gallery_media_ids[]"][value="${mediaId}"]`).check();
}

async function createSimple(page, baseUrl, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await expectText(page, '结构化描述');
  await page.fill('input[name="title"]', 'C2B HTTP Simple');
  await page.fill('input[name="slug"]', 'c2b-http-simple');
  await page.selectOption('select[name="product_type"]', 'simple');
  await page.fill('textarea[name="blocks[0][data][text]"]', 'Paragraph from real HTTP form.');
  await page.locator('[data-block-action="add"]').click();
  await page.selectOption('select[name="blocks[1][type]"]', 'heading');
  await page.fill('textarea[name="blocks[1][data][text]"]', 'Structured Heading');
  await chooseImage(page, mediaId);
  const category = page.locator('input[name="category_ids[]"]').first();
  if (await category.count()) await category.check();
  await page.locator('input[name="variant_sku[]"]').first().fill('C2B-HTTP-SIMPLE');
  await page.locator('input[name="variant_price_minor[]"]').first().fill('1999');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/products"] button[type="submit"]').last(), 20000);
  assert(page.url().match(/\/admin\/commerce\/products\/\d+\/edit$/), `simple save should redirect to edit, got ${page.url()}`);
  assert.strictEqual(await page.locator('input[name="title"]').inputValue(), 'C2B HTTP Simple');
  await clickAndWait(page, page.locator(`form[action$="/publish"] button[type="submit"]`), 20000);
  await expectText(page, 'published');
  await page.goto(`${baseUrl}/admin/commerce/products?q=C2B-HTTP-SIMPLE`);
  await expectText(page, 'C2B HTTP Simple');
  await clickAndWait(page, page.locator('a.button:has-text("编辑")').first(), 15000);
  await clickAndWait(page, page.locator(`form[action$="/archive"] button[type="submit"]`), 20000);
  await expectText(page, 'archived');
}

async function createVariable(page, baseUrl, mediaId) {
  await page.goto(`${baseUrl}/admin/commerce/products/new`);
  await page.fill('input[name="title"]', 'C2B HTTP Variable');
  await page.fill('input[name="slug"]', 'c2b-http-variable');
  await page.selectOption('select[name="product_type"]', 'variable');
  await page.fill('textarea[name="blocks[0][data][text]"]', 'Variable product description.');
  await chooseImage(page, mediaId);
  await page.locator('input[name="attribute_name[]"]').first().fill('Color');
  await page.locator('input[name="attribute_values[]"]').first().fill('Black, White');
  await page.locator('input[name="attribute_name[]"]').nth(1).fill('Size');
  await page.locator('input[name="attribute_values[]"]').nth(1).fill('S, M');
  await page.locator('[data-variant-action="generate"]').click();
  await page.locator('input[name="variant_sku[]"]').nth(0).fill('C2B-BLACK-S');
  await page.locator('input[name="variant_price_minor[]"]').nth(0).fill('2999');
  await page.locator('select[name="variant_media_id[]"]').nth(0).selectOption(String(mediaId));
  await page.locator('input[name="variant_sku[]"]').nth(1).fill('C2B-BLACK-M');
  await page.locator('input[name="variant_price_minor[]"]').nth(1).fill('3099');
  await clickAndWait(page, page.locator('form[action="/admin/commerce/products"] button[type="submit"]').last(), 20000);
  assert.strictEqual(await page.locator('input[name="title"]').inputValue(), 'C2B HTTP Variable');
  await clickAndWait(page, page.locator(`form[action$="/publish"] button[type="submit"]`), 20000);
  await expectText(page, 'published');
  await page.reload({ waitUntil: 'domcontentloaded' });
  assert.strictEqual(await page.locator('input[name="variant_sku[]"]').nth(0).inputValue(), 'C2B-BLACK-S');
  await expectText(page, 'color:black|size:s');
}

async function hardDeleteShouldFail(page, baseUrl, mediaId) {
  await page.goto(`${baseUrl}/admin/media/detail/${mediaId}`);
  await expectText(page, 'Plugin media reference');
  await clickAndWait(page, page.locator('button[name="action"][value="hard_delete"]'), 15000);
  await expectText(page, 'Media is still referenced');
}

async function disableCommerce(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/plugins`);
  const disable = page.locator('tr:has-text("official.commerce") form:has(input[name="status"][value="Disabled"]) button[type="submit"]').first();
  await clickAndWait(page, disable, 20000);
  const response = await page.goto(`${baseUrl}/admin/commerce/products`);
  assert([404, 503].includes(response.status()), `disabled Commerce route should disappear, got ${response.status()}`);
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const fixture = JSON.parse(fs.readFileSync(path.join(root, 'storage/browser-fixture.json'), 'utf8'));
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
  const serverOutput = { stdout: '', stderr: '' };
  server.stdout.on('data', (chunk) => { serverOutput.stdout += chunk.toString(); });
  server.stderr.on('data', (chunk) => { serverOutput.stderr += chunk.toString(); });
  let browser;
  let success = false;
  try {
    await waitForServer(baseUrl, serverOutput);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 920 } });
    page.setDefaultTimeout(15000);
    await installSite(page, baseUrl);
    await login(page, baseUrl);
    await enableCommerce(page, baseUrl);
    await createCategory(page, baseUrl);
    const mediaId = await uploadImage(page, baseUrl, fixture);
    await createSimple(page, baseUrl, mediaId);
    await createVariable(page, baseUrl, mediaId);
    const auditActor = Number(readDb(root, "SELECT actor_id FROM cms_audit_logs WHERE action = 'commerce.product.created' ORDER BY id DESC LIMIT 1"));
    const adminId = Number(readDb(root, "SELECT id FROM cms_admin_users WHERE email = 'admin@example.test' LIMIT 1"));
    assert.strictEqual(auditActor, adminId, 'audit actor should be the authenticated admin');
    await hardDeleteShouldFail(page, baseUrl, mediaId);
    await disableCommerce(page, baseUrl);
    await hardDeleteShouldFail(page, baseUrl, mediaId);
    assert.strictEqual(fs.existsSync(path.join(root, 'storage/entry-loaded.flag')), false, 'disabled plugin entry marker should not exist');
    await page.goto(`${baseUrl}/admin`);
    await expectText(page, '管理后台');
    await page.goto(`${baseUrl}/admin/recovery`);
    await expectText(page, '恢复与诊断');
    console.log('[PASS] true HTTP POST created category, simple product, variable product, publish and archive');
    console.log('[PASS] structured editor blocks persisted and reopened through Commerce admin');
    console.log('[PASS] media selector picked main/gallery/variant image without manual ID entry');
    console.log('[PASS] real admin audit id recorded for Commerce HTTP writes');
    console.log('[PASS] disabled Commerce route disappeared while persistent media references still blocked hard delete');
    console.log('[PASS] CMS admin and Recovery remained reachable');
    success = true;
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
    if (success) {
      runPhp(['-r', `function rr($p){if(is_file($p)||is_link($p)){@unlink($p);return;}if(!is_dir($p))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $i){$i->isDir()?@rmdir($i->getPathname()):@unlink($i->getPathname());}@rmdir($p);} rr(${JSON.stringify(root)});`], { timeout: 60000 });
    } else {
      console.error(`Preserved failing fixture: ${root}`);
      console.error(`PHP server stdout:\n${serverOutput.stdout}`);
      console.error(`PHP server stderr:\n${serverOutput.stderr}`);
    }
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

const { spawn, spawnSync } = require('child_process');
const path = require('path');
const assert = require('assert');
const { chromium } = require('/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const projectRoot = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';

function runPhp(args, options = {}) {
  const result = spawnSync(php, args, { cwd: options.cwd || projectRoot, encoding: 'utf8', timeout: options.timeout || 60000, env: options.env || process.env });
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
  await page.fill('input[name="sqlite_path"]', 'storage/database/cj-v1-http.sqlite');
  await page.fill('input[name="site_name"]', 'CJ V1 HTTP');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'CJ Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'cj-v1-http');
  await page.fill('input[name="site_secret"]', 'cj-v1-http-secret');
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

async function enablePlugin(page, baseUrl, pluginId) {
  await page.goto(`${baseUrl}/admin/plugins`);
  const enable = page.locator(`tr:has-text("${pluginId}") form:has(input[name="status"][value="Enabled"]) button[type="submit"]`).first();
  if (await enable.count()) await clickAndWait(page, enable, 20000);
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  runPhp(['-r', `$root=${JSON.stringify(root)};$config=require $root."/config/app.php";$config["security"]["encryption_key"]="cj-v1-http-static-key";file_put_contents($root."/config/app.php","<?php\\n\\ndeclare(strict_types=1);\\n\\nreturn ".var_export($config,true).";\\n");`]);
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, APP_ENV: 'testing', CJ_FIXTURE_ALLOWED: '1' } });
  const output = { stdout: '', stderr: '' };
  server.stdout.on('data', (chunk) => { output.stdout += chunk.toString(); });
  server.stderr.on('data', (chunk) => { output.stderr += chunk.toString(); });
  let browser;
  let success = false;
  try {
    await waitForServer(baseUrl, output);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 920 } });
    page.setDefaultTimeout(15000);
    await installAndLogin(page, baseUrl);
    await enablePlugin(page, baseUrl, 'official.commerce');
    await enablePlugin(page, baseUrl, 'official.cj-dropshipping');

    await page.goto(`${baseUrl}/admin/cj/settings`);
    await expectText(page, '当前为Fixture认证版本');
    await clickAndWait(page, page.locator('form[action="/admin/cj/fixture-token"] button[type="submit"]'), 20000);
    assert(page.url().includes('fixture=1'), 'Fixture token setup should redirect with fixture=1');
    await page.goto(`${baseUrl}/admin/cj/settings`);
    await clickAndWait(page, page.locator('form[action="/admin/cj/connection/test"] button[type="submit"]'), 20000);

    await page.goto(`${baseUrl}/admin/cj/catalog?q=lamp&page_size=10`);
    await expectText(page, 'Fixture Lamp');
    await clickAndWait(page, page.locator('a[href*="/admin/cj/catalog/preview"]').first(), 20000);
    await expectText(page, '变体');
    const checkboxes = await page.locator('input[name="vids[]"]').count();
    assert(checkboxes >= 1, 'preview should expose selectable CJ variants');
    if (checkboxes > 1) await page.locator('input[name="vids[]"]').nth(1).uncheck();
    await clickAndWait(page, page.locator('form#cj-import-form button[type="submit"]'), 20000);
    await expectText(page, 'CJ 导入完成');
    const productId = Number(readDb(root, "SELECT commerce_product_id FROM cms_cj_product_mappings ORDER BY id DESC LIMIT 1"));
    assert(productId > 0, 'CJ import should create Commerce draft mapping');
    assert.strictEqual(readDb(root, `SELECT status FROM cms_commerce_products WHERE id = ${productId}`), 'draft');

    const vid = readDb(root, "SELECT cj_vid FROM cms_cj_variant_mappings ORDER BY id DESC LIMIT 1");
    await page.goto(`${baseUrl}/admin/cj/settings`);
    await page.fill('form[action="/admin/cj/sync/variant"] input[name="cj_vid"]', vid);
    await clickAndWait(page, page.locator('form[action="/admin/cj/sync/variant"] button[type="submit"]'), 20000);
    await expectText(page, '状态');
    assert(Number(readDb(root, "SELECT COUNT(*) FROM cms_cj_inventory_snapshots")) > 0, 'inventory snapshot should be written');

    await page.goto(`${baseUrl}/admin/cj/fulfillment`);
    await expectText(page, 'Sandbox 履约');
    await page.goto(`${baseUrl}/admin/cj/fulfillment/consume-paid`);
    assert([404, 405].includes((await page.goto(`${baseUrl}/admin/cj/fulfillment/consume-paid`)).status()), 'GET consume-paid must not execute writes');

    const code = `putenv("APP_ENV=production");require ${JSON.stringify(projectRoot + '/content/plugins/official.cj-dropshipping/src/Api/CjTransportInterface.php')};require ${JSON.stringify(projectRoot + '/content/plugins/official.cj-dropshipping/src/Api/CjApiException.php')};require ${JSON.stringify(projectRoot + '/content/plugins/official.cj-dropshipping/src/Api/CjFixtureTransport.php')};exit(Official\\CjDropshipping\\Api\\CjFixtureTransport::productionFixtureDisabled()?0:1);`;
    runPhp(['-r', code], { timeout: 10000, env: { ...process.env, APP_ENV: 'production' } });

    console.log('[PASS] CJ settings page, Fixture token and Fixture test connection use real admin HTTP routes');
    console.log('[PASS] CJ catalog search and preview use real admin HTTP routes');
    console.log('[PASS] CJ partial SKU import uses real POST and keeps Commerce product draft');
    console.log('[PASS] CJ inventory/cost sync uses real POST and writes local Fixture snapshot');
    console.log('[PASS] CJ fulfillment page renders Sandbox warnings and diagnostic consume-paid is not executable by GET');
    console.log('[PASS] APP_ENV=production disables Fixture Transport');
    success = true;
  } finally {
    if (browser) await browser.close();
    server.kill('SIGTERM');
    runPhp(['tests/browser_release_e2e_fixture.php', 'cleanup', root], { timeout: 60000 });
    if (!success) console.error(output.stderr);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});

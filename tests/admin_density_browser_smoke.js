const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const assert = require('assert');

const { chromium } = require('/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const projectRoot = path.resolve(__dirname, '..');
const outputsDir = path.resolve(projectRoot, '..', '..', 'outputs', 'admin-density-screenshots');
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
  throw new Error('Timed out waiting for PHP server');
}

async function expectText(page, text, message) {
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `${message}\nBody:\n${body.slice(0, 1200)}`);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/admin-density.sqlite');
  await page.fill('input[name="site_name"]', 'Admin Density Smoke');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Admin Density');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'admin-density-smoke');
  await page.fill('input[name="site_secret"]', 'admin-density-secret');
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
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => null),
    providerForm.locator('button[type="submit"]').click(),
  ]);
  const savedUrl = new URL(page.url());
  if (savedUrl.origin !== baseUrl || savedUrl.pathname !== '/admin/payments/providers' || savedUrl.searchParams.get('provider_id') !== 'core.manual-payment' || savedUrl.searchParams.get('saved') !== '1') {
    const body = await page.locator('body').innerText();
    throw new Error(`manual Provider save did not redirect. URL=${page.url()}\n${body.slice(0, 1200)}`);
  }
  await expectText(page, 'Provider 配置已保存。', 'manual Provider save should show a success notice');
  await expectText(page, '已配置', 'manual Provider should show configured after save');
  await expectText(page, '启用', 'manual Provider should show enabled after save');
  await expectText(page, '是', 'manual Provider should remain default after save');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expectText(page, '已配置', 'manual Provider configured state should persist after reload');
  await expectText(page, '启用', 'manual Provider enabled state should persist after reload');
  await expectText(page, '是', 'manual Provider default state should persist after reload');
}

async function createContent(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await page.fill('input[name="title"]', '密度测试文章');
  await page.fill('input[name="slug"]', 'admin-density-article');
  await page.selectOption('select[name="status"]', 'published');
  await page.fill('textarea[name="blocks[0][data][text]"]', '后台密度测试正文。');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);
  await expectText(page, '删除', 'content list should include delete action');
}

async function createCardProduct(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/card-delivery/new`);
  await page.fill('input[name="name"]', '密度测试卡密');
  await page.fill('input[name="price_minor"]', '990');
  await page.fill('input[name="currency"]', 'USD');
  await page.selectOption('select[name="status"]', 'active');
  await Promise.all([
    page.waitForURL(/\/admin\/card-delivery\/edit\/[1-9][0-9]*$/, { timeout: 10000 }),
    page.locator('form[action="/admin/card-delivery"] button[type="submit"]').click(),
  ]);
}

async function densityMetrics(page) {
  return await page.evaluate(() => {
    const px = (value) => Number.parseFloat(String(value || '0'));
    const firstNav = document.querySelector('.admin-nav a');
    const h1 = document.querySelector('.admin-main h1');
    const td = document.querySelector('.admin-main td');
    const th = document.querySelector('.admin-main th');
    const input = document.querySelector('.admin-main input:not([type="hidden"])');
    const button = document.querySelector('.admin-main button, .admin-main a.button');
    const providerCell = [...document.querySelectorAll('.admin-main td')].find((cell) => cell.textContent.includes('core.manual-payment'));
    const formTitle = document.querySelector('#provider-form');
    const style = (node) => node ? getComputedStyle(node) : null;
    const rect = (node) => node ? node.getBoundingClientRect() : { height: 0, top: 9999, width: 0 };
    return {
      h1Font: px(style(h1)?.fontSize),
      navFont: px(style(firstNav)?.fontSize),
      navHeight: rect(firstNav).height,
      tdFont: px(style(td)?.fontSize),
      tdPaddingTop: px(style(td)?.paddingTop),
      thFont: px(style(th)?.fontSize),
      inputHeight: rect(input).height,
      buttonHeight: rect(button).height,
      providerWhiteSpace: style(providerCell)?.whiteSpace || '',
      providerHeight: rect(providerCell).height,
      providerWidth: rect(providerCell).width,
      formTitleTop: rect(formTitle).top,
      viewportHeight: window.innerHeight,
    };
  });
}

async function assertCompact(page, name) {
  const metrics = await densityMetrics(page);
  assert(metrics.h1Font >= 22 && metrics.h1Font <= 28, `${name}: h1 font should be compact and readable: ${JSON.stringify(metrics)}`);
  assert(metrics.navFont <= 14 && metrics.navHeight <= 34, `${name}: sidebar nav should be compact: ${JSON.stringify(metrics)}`);
  if (metrics.tdFont > 0) {
    assert(metrics.tdFont <= 14 && metrics.tdPaddingTop <= 8, `${name}: table cells should be compact: ${JSON.stringify(metrics)}`);
  }
  if (metrics.thFont > 0) {
    assert(metrics.thFont <= 13, `${name}: table headers should be compact: ${JSON.stringify(metrics)}`);
  }
  if (metrics.inputHeight > 0) {
    assert(metrics.inputHeight <= 38, `${name}: inputs should be compact: ${JSON.stringify(metrics)}`);
  }
  if (metrics.buttonHeight > 0) {
    assert(metrics.buttonHeight <= 34, `${name}: buttons should be compact: ${JSON.stringify(metrics)}`);
  }
  if (name === 'providers') {
    assert(metrics.providerWhiteSpace === 'nowrap' && metrics.providerWidth >= 130, `${name}: Provider ID should not break ugly: ${JSON.stringify(metrics)}`);
    assert(metrics.formTitleTop < metrics.viewportHeight, `${name}: Provider form should start in first viewport: ${JSON.stringify(metrics)}`);
  }
  return metrics;
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  fs.mkdirSync(outputsDir, { recursive: true });
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, APP_ENV: 'testing' },
  });
  const stderr = [];
  server.stderr.on('data', (chunk) => stderr.push(String(chunk)));
  let browser;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    page.setDefaultTimeout(15000);

    await installSite(page, baseUrl);
    await login(page, baseUrl);
    await configureManualProvider(page, baseUrl);
    await createContent(page, baseUrl);
    await createCardProduct(page, baseUrl);

    const pages = [
      ['content', '/admin/content'],
      ['content-new', '/admin/content/new'],
      ['card-delivery', '/admin/card-delivery'],
      ['payments', '/admin/payments'],
      ['providers', '/admin/payments/providers?provider_id=core.manual-payment'],
    ];
    for (const [name, url] of pages) {
      await page.goto(`${baseUrl}${url}`);
      await page.screenshot({ path: path.join(outputsDir, `${name}.png`), fullPage: false });
      const metrics = await assertCompact(page, name);
      console.log(`[PASS] ${name} compact metrics ${JSON.stringify(metrics)}`);
    }
    console.log(`[RESULT] Admin density browser smoke passed. Screenshots: ${outputsDir}`);
  } catch (error) {
    const logPath = path.join(root, 'storage/logs/app.log');
    if (fs.existsSync(logPath)) {
      console.error(fs.readFileSync(logPath, 'utf8').trim().split(/\n/).slice(-20).join('\n'));
    }
    console.error(stderr.join(''));
    throw error;
  } finally {
    if (browser) {
      await browser.close();
    }
    server.kill('SIGTERM');
    runPhp(['tests/browser_release_e2e_fixture.php', 'cleanup', root], { timeout: 60000 });
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

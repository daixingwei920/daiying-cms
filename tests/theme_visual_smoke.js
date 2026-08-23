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

async function expectBodyText(page, text, message) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `${message}\nBody:\n${body.slice(0, 1000)}`);
}

async function submitByName(page, name, value) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.locator(`button[name="${name}"][value="${value}"]`).click(),
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
  await expectBodyText(page, '管理后台', 'admin dashboard should load');
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/visual-smoke.sqlite');
  await page.fill('input[name="site_name"]', 'Theme Visual Smoke');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Visual Admin');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'theme-visual-smoke');
  await page.fill('input[name="site_secret"]', 'theme-visual-smoke-secret');
  await submitByName(page, 'install_action', 'test_database');
  await expectBodyText(page, '数据库连接测试通过', 'database test should pass');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/login`, { timeout: 20000 }),
    page.locator('button[name="install_action"][value="install"]').click(),
  ]);
}

async function createVisualArticle(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await page.selectOption('select[name="content_type"]', 'article');
  await page.fill('input[name="title"]', 'Visual Smoke Article');
  await page.fill('input[name="slug"]', 'visual-smoke-article');
  await page.selectOption('select[name="status"]', 'published');
  await page.fill('textarea[name="blocks[0][data][text]"]', 'Visual smoke paragraph.');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);
}

async function screenshotAndAssert(page, url, outputPath, expectedText) {
  await page.goto(url);
  await expectBodyText(page, expectedText, `${url} should contain expected theme text`);
  const metrics = await page.evaluate(() => {
    const body = document.body;
    const rect = body.getBoundingClientRect();
    const styles = window.getComputedStyle(body);
    return {
      width: Math.round(rect.width),
      height: Math.round(rect.height),
      scrollWidth: Math.round(document.documentElement.scrollWidth),
      clientWidth: Math.round(document.documentElement.clientWidth),
      textLength: body.innerText.trim().length,
      background: styles.backgroundColor,
      color: styles.color,
    };
  });
  assert(metrics.width >= 360, `${url} should have a visible body width`);
  assert(metrics.height >= 80, `${url} should have a visible body height`);
  assert(metrics.textLength >= expectedText.length, `${url} should not be visually empty`);
  await page.screenshot({ path: outputPath, fullPage: true });
  const size = fs.statSync(outputPath).size;
  assert(size > 2000, `${outputPath} should contain a non-trivial screenshot`);
  return { ...metrics, size };
}

async function screenshotMobileAndAssert(page, url, outputPath, expectedText) {
  await page.setViewportSize({ width: 390, height: 844 });
  const metrics = await screenshotAndAssert(page, url, outputPath, expectedText);
  assert(metrics.clientWidth >= 360, `${url} should render in a mobile viewport`);
  assert(metrics.scrollWidth <= metrics.clientWidth + 2, `${url} should not create horizontal overflow in mobile viewport`);
  return metrics;
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const screenshotDir = path.join(root, 'storage/tmp/visual-smoke');
  fs.mkdirSync(screenshotDir, { recursive: true });
  const server = spawn(php, ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', 'public'], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  let browser;
  const stderr = [];
  server.stderr.on('data', (chunk) => stderr.push(String(chunk)));
  let failed = false;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    page.setDefaultTimeout(15000);

    await installSite(page, baseUrl);
    await login(page, baseUrl);
    await createVisualArticle(page, baseUrl);

    const defaultHome = await screenshotAndAssert(page, `${baseUrl}/`, path.join(screenshotDir, 'default-home.png'), 'Theme Visual Smoke');
    const defaultArticle = await screenshotAndAssert(page, `${baseUrl}/articles/visual-smoke-article`, path.join(screenshotDir, 'default-article.png'), 'Visual Smoke Article');
    const defaultMobileHome = await screenshotMobileAndAssert(page, `${baseUrl}/`, path.join(screenshotDir, 'default-home-mobile.png'), 'Theme Visual Smoke');
    const defaultMobileArticle = await screenshotMobileAndAssert(page, `${baseUrl}/articles/visual-smoke-article`, path.join(screenshotDir, 'default-article-mobile.png'), 'Visual Smoke Article');
    await page.setViewportSize({ width: 1280, height: 800 });

    await page.goto(`${baseUrl}/admin/themes`);
    const safeActivate = page.locator('form[action="/admin/themes/activate"] input[name="theme_id"][value="safe"]').locator('..').locator('button');
    await Promise.all([
      page.waitForURL(`${baseUrl}/admin/themes`, { timeout: 10000 }),
      safeActivate.click(),
    ]);

    const safeHome = await screenshotAndAssert(page, `${baseUrl}/`, path.join(screenshotDir, 'safe-home.png'), '安全主题正在运行');
    const safeMobileHome = await screenshotMobileAndAssert(page, `${baseUrl}/`, path.join(screenshotDir, 'safe-home-mobile.png'), '安全主题正在运行');

    assert(defaultHome.size !== safeHome.size, 'default and safe theme screenshots should not be identical in size');
    assert(defaultArticle.height >= 120, 'article theme screenshot should have content height');
    assert(defaultMobileHome.size > 2000 && defaultMobileArticle.size > 2000 && safeMobileHome.size > 2000, 'mobile screenshots should be non-trivial');

    console.log('[PASS] browser visual smoke installs temporary CMS site');
    console.log('[PASS] browser visual smoke screenshots default theme homepage');
    console.log('[PASS] browser visual smoke screenshots default theme article page');
    console.log('[PASS] browser visual smoke screenshots safe theme fallback homepage');
    console.log('[PASS] browser visual smoke validates non-empty theme render dimensions');
    console.log('[PASS] browser visual smoke screenshots default theme mobile homepage');
    console.log('[PASS] browser visual smoke screenshots default theme mobile article page');
    console.log('[PASS] browser visual smoke screenshots safe theme mobile homepage');
    console.log('[PASS] browser visual smoke validates mobile pages avoid horizontal overflow');
    console.log('Theme visual smoke tests passed.');
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
    if (stderr.length && (process.env.THEME_VISUAL_SMOKE_DEBUG || failed)) {
      console.error(stderr.join(''));
    }
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

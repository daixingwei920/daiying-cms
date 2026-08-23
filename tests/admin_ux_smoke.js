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

async function expectText(page, text, message) {
  await page.waitForLoadState('domcontentloaded');
  const body = await page.locator('body').innerText();
  assert(body.includes(text), `${message}\nBody:\n${body.slice(0, 1200)}`);
}

async function expectNoSensitiveText(page, message) {
  const body = await page.locator('body').innerText();
  assert(!/browser-e2e-secret|theme-visual-smoke-secret|password=|token=|\/Users\/xingweidai/i.test(body), message);
}

async function submitByName(page, name, value) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.locator(`button[name="${name}"][value="${value}"]`).click(),
  ]);
}

async function installSite(page, baseUrl) {
  await page.goto(`${baseUrl}/install`);
  await page.selectOption('select[name="db_driver"]', 'sqlite');
  await page.fill('input[name="sqlite_path"]', 'storage/database/admin-ux.sqlite');
  await page.fill('input[name="site_name"]', 'Admin UX Smoke');
  await page.fill('input[name="site_url"]', baseUrl);
  await page.fill('input[name="email"]', 'admin@example.test');
  await page.fill('input[name="display_name"]', 'Admin UX');
  await page.fill('input[name="password"]', 'browser-e2e-secret');
  await page.fill('input[name="site_id"]', 'admin-ux-smoke');
  await page.fill('input[name="site_secret"]', 'admin-ux-secret');
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

async function checkDashboard(page, baseUrl) {
  await page.goto(`${baseUrl}/admin`);
  await expectText(page, 'CMS 内容、媒体、主题、插件、更新和恢复主链路已启用', 'dashboard should summarize CMS main chain');
  await expectText(page, '导航菜单', 'dashboard should expose navigation menu shortcut');
  for (const href of ['/admin/content/new', '/admin/content', '/admin/media', '/admin/navigation', '/admin/themes', '/admin/plugins', '/admin/update', '/admin/recovery']) {
    assert(await page.locator(`a[href="${href}"]`).count() >= 1, `dashboard should link to ${href}`);
  }
  await expectNoSensitiveText(page, 'dashboard should not expose sensitive values');
}

async function checkContentEditorNavigation(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await expectText(page, '新建内容', 'new content page should load');
  await expectText(page, '返回内容管理', 'new content page should provide an in-app return link');
  await expectText(page, '内容区块', 'new content page should expose Chinese structured block label');
  const body = await page.locator('body').innerText();
  assert(!body.includes('Block 1'), 'new content page should not expose developer block labels');
  await expectNoSensitiveText(page, 'new content page should not expose sensitive values');
}

async function checkNavigationMenu(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/navigation`);
  await expectText(page, '导航菜单', 'navigation menu page should load');
  await expectText(page, '快速添加', 'navigation menu page should expose quick add actions');
  await expectText(page, '自定义链接', 'navigation menu page should allow custom links');
  await expectNoSensitiveText(page, 'navigation menu page should not expose sensitive values');
}

async function checkMedia(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/media`);
  await expectText(page, '暂无媒体', 'media library should show empty state');
  assert(await page.locator('#media-upload input[type="file"][multiple]').count() === 1, 'media library should expose multi-file upload control');
  assert(await page.locator('progress#media-progress').count() === 1, 'media library should expose upload progress indicator');
  await expectNoSensitiveText(page, 'media page should not expose sensitive values');
}

async function checkThemes(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/themes`);
  await expectText(page, '主题只负责 UI', 'themes page should explain theme scope');
  await expectText(page, '上传主题 ZIP 安装', 'themes page should expose local theme ZIP upload');
  await expectText(page, '当前', 'themes page should identify current theme');
  assert(await page.locator('form[action="/admin/themes/local-install"] input[type="file"][name="theme_zip"]').count() === 1, 'themes page should require theme ZIP file chooser');
  assert(await page.locator('form[action="/admin/themes/settings"]').count() >= 1, 'themes page should expose isolated settings form');
  await expectNoSensitiveText(page, 'themes page should not expose sensitive values');
}

async function checkPlugins(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/plugins`);
  await expectText(page, '上传 ZIP 安装', 'plugins page should expose local ZIP upload');
  assert(await page.locator('form[action="/admin/plugins/local-preview"] input[type="file"][name="plugin_zip"]').count() === 1, 'plugins page should require ZIP file chooser');
  await expectText(page, '本地插件由管理员自行承担信任责任', 'plugins page should explain local plugin trust risk');
  await expectNoSensitiveText(page, 'plugins page should not expose sensitive values');
}

async function checkUpdate(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/update`);
  await expectText(page, 'Core 更新', 'update page should load');
  assert(await page.locator('form[action="/admin/update/verify"] input[name="package_path"]').count() === 1, 'update page should expose dry-run package verification');
  const confirmation = page.locator('form[action="/admin/update/execute"] input[name="confirmation"]');
  assert(await confirmation.count() === 1 && (await confirmation.getAttribute('placeholder')) === 'UPDATE CORE', 'update page should expose explicit execution confirmation');
  await expectNoSensitiveText(page, 'update page should not expose sensitive values');
}

async function checkRecoveryAndDiagnostics(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/recovery`);
  await expectText(page, '恢复与诊断', 'admin recovery page should load');
  await expectText(page, '创建恢复点', 'admin recovery page should expose restore point creation');
  await expectText(page, '清缓存', 'admin recovery page should expose low-risk recovery action');
  await expectNoSensitiveText(page, 'admin recovery page should not expose sensitive values');

  await page.goto(`${baseUrl}/admin/diagnostics`);
  await expectText(page, '诊断', 'admin diagnostics page should load');
  await expectText(page, 'core_integrity', 'admin diagnostics page should show core integrity summary');
  await expectText(page, 'database', 'admin diagnostics page should show database status');
  await expectNoSensitiveText(page, 'admin diagnostics page should not expose sensitive values');
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
    await checkDashboard(page, baseUrl);
    await checkContentEditorNavigation(page, baseUrl);
    await checkNavigationMenu(page, baseUrl);
    await checkMedia(page, baseUrl);
    await checkThemes(page, baseUrl);
    await checkPlugins(page, baseUrl);
    await checkUpdate(page, baseUrl);
    await checkRecoveryAndDiagnostics(page, baseUrl);

    console.log('[PASS] admin UX smoke installs and logs into temporary CMS site');
    console.log('[PASS] admin UX smoke validates dashboard main-chain navigation');
    console.log('[PASS] admin UX smoke validates content editor in-app navigation');
    console.log('[PASS] admin UX smoke validates navigation menu management UI');
    console.log('[PASS] admin UX smoke validates media library empty state and upload controls');
    console.log('[PASS] admin UX smoke validates theme page current state and settings form');
    console.log('[PASS] admin UX smoke validates plugin ZIP upload and trust warning');
    console.log('[PASS] admin UX smoke validates Core update verification and confirmation controls');
    console.log('[PASS] admin UX smoke validates recovery actions and diagnostics summaries');
    console.log('[PASS] admin UX smoke verifies sensitive values are not exposed on checked admin pages');
    console.log('Admin UX smoke tests passed.');
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
    if (stderr.length && (process.env.ADMIN_UX_SMOKE_DEBUG || failed)) {
      console.error(stderr.join(''));
    }
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

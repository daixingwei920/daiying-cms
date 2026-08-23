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
  assert(body.includes(text), `${message || `Expected page body to contain ${text}`}\nBody:\n${body.slice(0, 1200)}`);
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
  assert(page.url() === `${baseUrl}/admin`, `login should redirect to admin dashboard, got ${page.url()}\nBody:\n${(await page.locator('body').innerText()).slice(0, 1200)}`);
  await expectText(page, '管理后台', 'admin dashboard should load after login');
}

async function createArticleWithBlocks(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await page.selectOption('select[name="content_type"]', 'article');
  await page.fill('input[name="title"]', 'Browser Article');
  await page.fill('input[name="slug"]', 'browser-article');
  await page.selectOption('select[name="status"]', 'published');
  await page.fill('input[name="categories"]', 'Release');
  await page.fill('input[name="tags"]', 'browser, e2e');

  await submitByName(page, 'block_action', 'add');
  await page.selectOption('select[name="blocks[1][type]"]', 'heading');
  await submitByName(page, 'block_action', 'add');

  await page.fill('textarea[name="blocks[0][data][text]"]', 'Browser paragraph before block operations.');
  await page.fill('input[name="blocks[1][data][text]"]', 'Browser Heading');
  await page.fill('textarea[name="blocks[2][data][text]"]', 'Block that survives ordering.');

  await submitByName(page, 'block_action', 'copy:1');
  await submitByName(page, 'block_action', 'down:1');
  await submitByName(page, 'block_action', 'up:2');
  await submitByName(page, 'block_action', 'delete:3');

  await page.selectOption('select[name="status"]', 'published');
  await page.fill('input[name="seo_title"]', 'Browser Article SEO');
  await page.fill('textarea[name="seo_description"]', 'Browser article SEO description.');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);

  await page.goto(`${baseUrl}/articles/browser-article`);
  await expectText(page, 'Browser Article', 'published article should render on frontend');
  await expectText(page, 'Browser paragraph before block operations.', 'paragraph block should render on frontend');
  await expectText(page, 'Browser Heading', 'heading block should render on frontend');
}

async function createPage(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await page.selectOption('select[name="content_type"]', 'page');
  await page.fill('input[name="title"]', 'Browser Page');
  await page.fill('input[name="slug"]', 'browser-page');
  await page.selectOption('select[name="status"]', 'published');
  await page.fill('textarea[name="blocks[0][data][text]"]', 'Browser page body.');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);
  await page.goto(`${baseUrl}/browser-page`);
  await expectText(page, 'Browser Page', 'published page should render on clean URL');
}

async function uploadMedia(page, baseUrl, fixture) {
  await page.goto(`${baseUrl}/admin/media`);
  await page.setInputFiles('input[name="media_files[]"]', [
    fixture.assets.image,
    fixture.assets.audio,
    fixture.assets.video,
    fixture.assets.pdf,
  ]);
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/media`, { timeout: 15000 }),
    page.locator('#media-upload button[type="submit"]').click(),
  ]);
  await expectText(page, 'release-image.png', 'image should appear in media library');
  await expectText(page, 'release-audio.mp3', 'audio should appear in media library');
  await expectText(page, 'release-video.mp4', 'video should appear in media library');
  await expectText(page, 'release-file.pdf', 'PDF should appear in media library');

  const media = {};
  const rows = await page.locator('tbody tr').all();
  for (const row of rows) {
    const cells = await row.locator('td').allInnerTexts();
    if (cells.length < 3) {
      continue;
    }
    const id = Number(cells[0].trim());
    const filename = cells[2].trim();
    if (filename.includes('release-image.png')) media.image = id;
    if (filename.includes('release-audio.mp3')) media.audio = id;
    if (filename.includes('release-video.mp4')) media.video = id;
    if (filename.includes('release-file.pdf')) media.pdf = id;
  }
  assert(media.image && media.audio && media.video && media.pdf, 'all uploaded media IDs should be visible');
  return media;
}

async function createMediaArticle(page, baseUrl, media) {
  await page.goto(`${baseUrl}/admin/content/new`);
  await page.fill('input[name="title"]', 'Browser Media Article');
  await page.fill('input[name="slug"]', 'browser-media-article');
  await page.selectOption('select[name="status"]', 'published');

  await page.selectOption('select[name="blocks[0][type]"]', 'image');
  await submitByName(page, 'block_action', 'add');
  await page.selectOption('select[name="blocks[1][type]"]', 'audio');
  await submitByName(page, 'block_action', 'add');
  await page.selectOption('select[name="blocks[2][type]"]', 'video');
  await submitByName(page, 'block_action', 'add');
  await page.selectOption('select[name="blocks[3][type]"]', 'attachment');
  await submitByName(page, 'block_action', 'add');

  await setHiddenInput(page, 'input[name="blocks[0][data][media_id]"]', media.image);
  await page.fill('input[name="blocks[0][data][alt]"]', 'Browser image alt');
  await page.fill('input[name="blocks[0][data][caption]"]', 'Browser image caption');
  await setHiddenInput(page, 'input[name="blocks[1][data][media_id]"]', media.audio);
  await page.fill('input[name="blocks[1][data][title]"]', 'Browser audio title');
  await setHiddenInput(page, 'input[name="blocks[2][data][media_id]"]', media.video);
  await setHiddenInput(page, 'input[name="blocks[2][data][poster_media_id]"]', media.image);
  await setHiddenInput(page, 'input[name="blocks[3][data][media_id]"]', media.pdf);
  await page.fill('input[name="blocks[3][data][display_name]"]', 'Download release PDF');
  await page.fill('textarea[name="blocks[4][data][text]"]', 'Media article trailing text.');

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/content`, { timeout: 10000 }),
    page.locator('button[name="content_action"][value="save"]').click(),
  ]);

  await page.goto(`${baseUrl}/articles/browser-media-article`);
  await expectText(page, 'Browser Media Article', 'media article should render');
  await expectText(page, 'Browser image caption', 'image caption should render');
  await expectText(page, 'Browser audio title', 'audio title should render');
  await expectText(page, 'Download release PDF', 'attachment link should render');
  assert(await page.locator('img[alt="Browser image alt"]').count() >= 1, 'image block should render img with alt');
  assert(await page.locator('audio[controls]').count() >= 1, 'audio block should render HTML5 audio controls');
  assert(await page.locator('video[controls]').count() >= 1, 'video block should render HTML5 video controls');
}

async function setHiddenInput(page, selector, value) {
  await page.locator(selector).evaluate((input, nextValue) => {
    input.value = String(nextValue);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

async function switchThemes(page, baseUrl) {
  await page.goto(`${baseUrl}/admin/themes`);
  await expectText(page, '当前', 'themes page should show current theme');
  const safeActivate = page.locator('form[action="/admin/themes/activate"] input[name="theme_id"][value="safe"]').locator('..').locator('button');
  if (await safeActivate.count()) {
    await Promise.all([
      page.waitForURL(`${baseUrl}/admin/themes`, { timeout: 10000 }),
      safeActivate.click(),
    ]);
  }
  await page.goto(`${baseUrl}/`);
  await expectText(page, 'Browser Release E2E', 'frontend should render after theme activation');

  await page.goto(`${baseUrl}/admin/themes`);
  const defaultActivate = page.locator('form[action="/admin/themes/activate"] input[name="theme_id"][value="default"]').locator('..').locator('button');
  if (await defaultActivate.count()) {
    await Promise.all([
      page.waitForURL(`${baseUrl}/admin/themes`, { timeout: 10000 }),
      defaultActivate.click(),
    ]);
  }
}

async function pluginLifecycle(page, baseUrl, fixture) {
  await page.goto(`${baseUrl}/admin/plugins`);
  await page.setInputFiles('input[name="plugin_zip"]', fixture.plugin_zip);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('form[action="/admin/plugins/local-preview"] button[type="submit"]').click(),
  ]);
  await expectText(page, '本地插件安装预检', 'local plugin preview should render');
  await expectText(page, 'browser_demo', 'plugin preview should show plugin ID');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/plugins`, { timeout: 10000 }),
    page.locator('form[action="/admin/plugins/local-install"] button[type="submit"]').click(),
  ]);
  await page.goto(`${baseUrl}/admin/modules`);
  await expectText(page, 'Browser Demo', 'installed module should appear in list');

  const enableButton = page.locator('form[action="/admin/plugins/status"] input[name="plugin_id"][value="browser_demo"]').locator('..').locator('button');
  if (await enableButton.count()) {
    await Promise.all([
      page.waitForURL(`${baseUrl}/admin/modules`, { timeout: 10000 }),
      enableButton.click(),
    ]);
  }
  await expectText(page, '已启用', 'module should be enabled from browser UI');

  const disableButton = page.locator('form[action="/admin/plugins/status"] input[name="plugin_id"][value="browser_demo"]').locator('..').locator('button');
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/modules`, { timeout: 10000 }),
    disableButton.click(),
  ]);
  await expectText(page, '已停用', 'module should be disabled from browser UI');

  await page.goto(`${baseUrl}/admin/plugins/detail?id=browser_demo&from=modules`);
  await expectText(page, '插件 ID', 'module detail should show technical details before uninstall');
  const uninstallButton = page.locator('form[action="/admin/plugins/uninstall"] input[name="plugin_id"][value="browser_demo"]').locator('..').locator('button');
  page.once('dialog', async (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(`${baseUrl}/admin/modules`, { timeout: 10000 }),
    uninstallButton.click(),
  ]);
  await expectText(page, '数据保留中', 'module code uninstall should retain dormant module record');
}

async function coreUpdateAndRecovery(page, baseUrl, root) {
  const prepared = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'prepare-update', root]));
  await page.goto(`${baseUrl}/admin/update`);
  await page.fill('form[action="/admin/update/verify"] input[name="package_path"]', prepared.package);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    page.locator('form[action="/admin/update/verify"] button[type="submit"]').click(),
  ]);
  await expectText(page, '更新包验证通过', 'update dry run should pass in browser');

  await page.goto(`${baseUrl}/admin/update`);
  await page.fill('form[action="/admin/update/execute"] input[name="package_path"]', prepared.package);
  await page.fill('form[action="/admin/update/execute"] input[name="confirmation"]', 'UPDATE CORE');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }),
    page.locator('form[action="/admin/update/execute"] button[type="submit"]').click({ timeout: 60000 }),
  ]);
  await expectText(page, 'Core 更新完成', 'Core update should execute from browser UI');

  await page.goto(`${baseUrl}/health`);
  await expectText(page, 'ok', 'health should remain ok after browser-driven update');
  await page.goto(`${baseUrl}/diagnostics`);
  await expectText(page, '诊断', 'diagnostics should render after update');
  await page.goto(`${baseUrl}/recovery`);
  await expectText(page, '恢复', 'recovery entry should remain available');
}

(async () => {
  const created = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'create']));
  const root = created.root;
  const port = created.port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const fixture = JSON.parse(runPhp(['tests/browser_release_e2e_fixture.php', 'info', root]));
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
    const page = await browser.newPage();
    page.setDefaultTimeout(15000);

    await page.goto(`${baseUrl}/install`);
    await page.selectOption('select[name="db_driver"]', 'sqlite');
    await page.fill('input[name="sqlite_path"]', 'storage/database/browser.sqlite');
    await page.fill('input[name="site_name"]', 'Browser Release E2E');
    await page.fill('input[name="site_url"]', baseUrl);
    await page.fill('input[name="email"]', 'admin@example.test');
    await page.fill('input[name="display_name"]', 'Browser Admin');
    await page.fill('input[name="password"]', 'browser-e2e-secret');
    await page.fill('input[name="site_id"]', 'browser-release-e2e');
    await page.fill('input[name="site_secret"]', 'browser-release-secret');
    await submitByName(page, 'install_action', 'test_database');
    await expectText(page, '数据库连接测试通过', 'database test should pass in installer');
    await page.fill('input[name="password"]', 'browser-e2e-secret');
    await Promise.all([
      page.waitForURL(`${baseUrl}/admin/login`, { timeout: 20000 }),
      page.locator('button[name="install_action"][value="install"]').click(),
    ]);

    await login(page, baseUrl);
    await createArticleWithBlocks(page, baseUrl);
    await createPage(page, baseUrl);
    const media = await uploadMedia(page, baseUrl, fixture);
    await createMediaArticle(page, baseUrl, media);
    await switchThemes(page, baseUrl);
    await pluginLifecycle(page, baseUrl, fixture);
    await coreUpdateAndRecovery(page, baseUrl, root);

    console.log('[PASS] browser install wizard E2E');
    console.log('[PASS] browser login E2E');
    console.log('[PASS] browser content block operations and publish E2E');
    console.log('[PASS] browser page clean URL E2E');
    console.log('[PASS] browser media upload and frontend playback/download E2E');
    console.log('[PASS] browser theme switching E2E');
    console.log('[PASS] browser plugin ZIP install/enable/disable/uninstall E2E');
    console.log('[PASS] browser Core update and recovery/diagnostics E2E');
    console.log('Browser release E2E passed.');
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
    if (stderr.length && (process.env.BROWSER_E2E_DEBUG || failed)) {
      console.error(stderr.join(''));
    }
  }
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});

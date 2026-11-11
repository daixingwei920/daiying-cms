import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../content/themes/daiying-restaurant-storefront/assets/storefront.js', import.meta.url), 'utf8');
let assertions = 0;

async function scenario(savedLocale, defaultLocale, {legacy = false, unavailable = false} = {}) {
  const storage = new Map(savedLocale === undefined ? [] : [['restaurant_locale', savedLocale]]);
  const listeners = {};
  const requests = [];
  const app = {dataset: {storefrontApi: '/storefront', menuApi: '/menu'}, style: {setProperty() {}}};
  const document = {
    documentElement: {lang: ''},
    querySelector: (selector) => selector === '[data-restaurant-app]' ? app : null,
    querySelectorAll: () => [],
    getElementById: () => null,
    addEventListener: (event, handler) => {listeners[event] = handler;},
  };
  const sandbox = {
    document, console,
    localStorage: {getItem: (key) => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value)},
    fetch: async (url) => {
      requests.push(url);
      if (unavailable) throw new Error('Isolated unavailable-plugin fixture');
      const locale = new URL(url, 'https://fixture.invalid').searchParams.get('locale') || (legacy ? 'zh-CN' : defaultLocale);
      const payload = url.startsWith('/storefront')
        ? {locale, ...(legacy ? {} : {default_locale: defaultLocale}), restaurant: {}}
        : {locale, items: [], categories: []};
      return {ok: true, json: async () => payload};
    },
  };
  const instrumented = source.replace('  function renderFallback(error) {', '  function renderFallback(error) { globalThis.languageFixtureFailure = error;');
  vm.runInNewContext(instrumented, sandbox);
  await new Promise(setImmediate);
  if (!unavailable) {
    assert.equal(sandbox.languageFixtureFailure, undefined);
    assertions++;
  }
  return {document, storage, requests, listeners};
}

for (const locale of ['en-US', 'zh-CN', 'es-US']) {
  const result = await scenario(undefined, locale);
  assert.equal(result.document.documentElement.lang, locale);
  assert.deepEqual(result.requests, ['/storefront', '/menu']);
  assert.equal(result.storage.has('restaurant_locale'), false);
  assertions += 3;
}
for (const saved of ['en-US', 'zh-CN', 'es-US']) {
  const result = await scenario(saved, 'es-US');
  assert.equal(result.document.documentElement.lang, saved);
  assert.ok(result.requests.every((url) => url.endsWith('?locale=' + saved)));
  assert.equal(result.storage.get('restaurant_locale'), saved);
  assertions += 3;
}
const invalid = await scenario('unsupported', 'es-US');
assert.equal(invalid.document.documentElement.lang, 'es-US');
assert.equal(invalid.requests.length, 2);
assertions += 2;
const legacy = await scenario(undefined, undefined, {legacy: true});
assert.equal(legacy.document.documentElement.lang, 'en-US');
assert.ok(legacy.requests.includes('/menu?locale=en-US'));
assertions += 2;
const unavailable = await scenario(undefined, undefined, {unavailable: true});
assert.equal(unavailable.document.documentElement.lang, 'en-US');
assertions++;
const selected = await scenario(undefined, 'es-US');
await selected.listeners.click({target: {closest: (selector) => selector === '[data-lang]' ? {dataset: {lang: 'zh-CN'}} : null}});
await new Promise(setImmediate);
assert.equal(selected.document.documentElement.lang, 'zh-CN');
assert.equal(selected.storage.get('restaurant_locale'), 'zh-CN');
assertions += 2;
console.log('Preferred language JS unit fixtures: ' + assertions + ' assertions passed; not browser E2E.');

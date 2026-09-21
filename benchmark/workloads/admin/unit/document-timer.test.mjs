import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DocumentTimer, isServerWork } from '../lib/document-timer.mjs';
import { adapterFor } from '../lib/adapters/index.mjs';
import { WinterAdapter } from '../lib/adapters/winter.mjs';

const request = (resourceType, headers = {}, ms = 10) => ({
  resourceType: () => resourceType,
  headers: () => headers,
  method: () => 'POST',
  url: () => 'http://stack/',
  timing: () => ({ requestStart: 0, responseEnd: ms }),
});

test('isServerWork counts documents and Winter AJAX handler requests, not assets', () => {
  assert.equal(isServerWork(request('document')), true);
  assert.equal(isServerWork(request('xhr', { 'x-winter-request-handler': 'onSave' })), true);
  assert.equal(isServerWork(request('xhr', { 'x-requested-with': 'XMLHttpRequest' })), false);
  assert.equal(isServerWork(request('script')), false);
  assert.equal(isServerWork(request('stylesheet')), false);
});

test('isServerWork counts Evolution manager action XHRs, not the manager assets or theme helpers', () => {
  const at = (resourceType, url) => ({ ...request(resourceType, { 'x-requested-with': 'XMLHttpRequest' }), url: () => url });
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/manager/index.php?a=5')), true);
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/manager/index.php?id=10104&a=5')), true);
  // the top-level form post itself is a document request and counted as such
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/manager/index.php')), false);
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/manager/index.php?a=lexicon')), false);
  assert.equal(isServerWork(at('fetch', 'http://nginx-parser/manager/index.php?a=27&id=10104')), true);
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/manager/media/style/default/ajax.php')), false);
  assert.equal(isServerWork(at('script', 'http://nginx-parser/manager/media/style/default/js/evo.js')), false);
  assert.equal(isServerWork(at('xhr', 'http://nginx-parser/index.php?id=1')), false);
});

test('DocumentTimer sums server time of the counted requests between marks', async () => {
  const listeners = {};
  const page = {
    on: (event, fn) => { listeners[event] = fn; },
    waitForTimeout: async () => {},
  };
  const timer = new DocumentTimer(page);
  timer.mark();
  listeners.requestfinished(request('document', {}, 40));
  listeners.requestfinished(request('xhr', { 'x-winter-request-handler': 'onSave' }, 25.5));
  listeners.requestfinished(request('image', {}, 100));
  assert.deepEqual(await timer.collect(), { serverMs: 65.5, requests: 2 });
  assert.deepEqual(await timer.collect(), { serverMs: 0, requests: 0 });
});

test('the winter stack resolves to the Winter adapter with the seeded page ids', () => {
  const config = { pages: 5, adminRootId: 10103, baseUrl: 'http://nginx-winter:80' };
  const adapter = adapterFor('winter', {}, config, {});
  assert.ok(adapter instanceof WinterAdapter);
  assert.deepEqual(adapter.pageIds(), [10104, 10105, 10106, 10107, 10108]);
  assert.equal(adapter.backendUrl('phramark/benchmark/pages/create'), 'http://nginx-winter:80/backend/phramark/benchmark/pages/create');
});

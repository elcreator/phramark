import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isServerWork } from '../lib/document-timer.mjs';
import { adapterFor } from '../lib/adapters/index.mjs';
import { ModxAdapter, postedAction } from '../lib/adapters/modx.mjs';

const request = (resourceType, url, headers = {}) => ({
  resourceType: () => resourceType,
  headers: () => headers,
  method: () => 'POST',
  url: () => url,
  timing: () => ({ requestStart: 0, responseEnd: 10 }),
});

test('isServerWork counts MODX connector XHRs, not the manager assets', () => {
  assert.equal(isServerWork(request('xhr', 'http://nginx-modx/connectors/index.php')), true);
  assert.equal(isServerWork(request('xhr', 'http://nginx-modx/connectors/index.php?action=Resource/GetNodes&id=root')), true);
  assert.equal(isServerWork(request('script', 'http://nginx-modx/manager/assets/modext/modx.jsgrps-min.js')), false);
  assert.equal(isServerWork(request('xhr', 'http://nginx-modx/manager/index.php?a=lexicon')), false);
});

test('postedAction reads the processor from multipart and urlencoded bodies', () => {
  const multipart = '------WebKitFormBoundaryX\r\nContent-Disposition: form-data; name="pagetitle"\r\n\r\nAdmin page 1 EDITED\r\n------WebKitFormBoundaryX\r\nContent-Disposition: form-data; name="action"\r\n\r\nResource/Update\r\n------WebKitFormBoundaryX--\r\n';
  assert.equal(postedAction(multipart), 'Resource/Update');
  assert.equal(postedAction('sortBy=menuindex&action=Resource%2FGetNodes&node=root&HTTP_MODAUTH=x'), 'Resource/GetNodes');
  assert.equal(postedAction('id=1&action=System%2FContentType%2FGetList'), 'System/ContentType/GetList');
  assert.equal(postedAction('interaction=1'), null);
  assert.equal(postedAction(null), null);
});

test('the modx stack resolves to the MODX adapter with the seeded page ids', () => {
  const config = { pages: 5, adminRootId: 10103, baseUrl: 'http://nginx-modx:80' };
  const adapter = adapterFor('modx', {}, config, {});
  assert.ok(adapter instanceof ModxAdapter);
  assert.deepEqual(adapter.pageIds(), [10104, 10105, 10106, 10107, 10108]);
  assert.equal(adapter.managerUrl(), 'http://nginx-modx:80/manager/');
  assert.equal(adapter.managerUrl('a=resource/update&id=10104'), 'http://nginx-modx:80/manager/?a=resource/update&id=10104');
});

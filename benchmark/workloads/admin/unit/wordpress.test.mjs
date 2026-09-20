import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adapterFor } from '../lib/adapters/index.mjs';
import { WordPressAdapter, editedPostId } from '../lib/adapters/wordpress.mjs';

test('editedPostId reads the page id from the editor URL WordPress redirects to', () => {
  assert.equal(editedPostId('http://nginx-wordpress-gantry/wp-admin/post.php?post=10109&action=edit&message=6'), 10109);
  assert.equal(editedPostId('http://nginx-wordpress-gantry/wp-admin/post.php?post=10104&action=edit&message=1'), 10104);
  assert.ok(Number.isNaN(editedPostId('http://nginx-wordpress-gantry/wp-admin/post-new.php?post_type=page')));
});

test('the wordpress-gantry stack resolves to the WordPress adapter with the seeded page ids', () => {
  const config = { pages: 5, adminRootId: 10103, baseUrl: 'http://nginx-wordpress-gantry:80' };
  const adapter = adapterFor('wordpress-gantry', {}, config, {});
  assert.ok(adapter instanceof WordPressAdapter);
  assert.deepEqual(adapter.pageIds(), [10104, 10105, 10106, 10107, 10108]);
  assert.equal(adapter.adminUrl(), 'http://nginx-wordpress-gantry:80/wp-admin/');
  assert.equal(adapter.adminUrl('post.php?post=10104&action=edit'), 'http://nginx-wordpress-gantry:80/wp-admin/post.php?post=10104&action=edit');
});

test('a plain WordPress stack without Gantry is not a supported stack', () => {
  assert.throws(() => adapterFor('wordpress', {}, {}, {}), /No admin workload adapter/);
});

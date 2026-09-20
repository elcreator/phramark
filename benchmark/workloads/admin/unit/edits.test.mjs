import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createdContent, createdTitle, editedContent, editedTitle, stripMarker } from '../lib/edits.mjs';

test('editedTitle appends the marker once', () => {
  assert.equal(editedTitle('Admin page 1'), 'Admin page 1 EDITED');
  assert.equal(editedTitle('Admin page 1 EDITED'), 'Admin page 1 EDITED');
  assert.equal(editedTitle('Admin page 1 EDITED EDITED  '), 'Admin page 1 EDITED');
});

test('editedContent keeps the marker inside the closing paragraph', () => {
  assert.equal(editedContent('<p>Body.</p>'), '<p>Body. EDITED</p>');
  assert.equal(editedContent('<p>Body. EDITED</p>'), '<p>Body. EDITED</p>');
  assert.equal(editedContent('<p>Body. EDITED EDITED</p>\n'), '<p>Body. EDITED</p>');
  assert.equal(editedContent('Plain body'), 'Plain body EDITED');
});

test('stripMarker leaves unrelated text alone', () => {
  assert.equal(stripMarker('EDITED at the front'), 'EDITED at the front');
  assert.equal(stripMarker('Unedited page'), 'Unedited page');
});

test('created pages are named per round and index', () => {
  assert.equal(createdTitle(2, 3), 'Playwright page r2-3');
  assert.match(createdContent(2, 3), /round 2, page 3/);
});

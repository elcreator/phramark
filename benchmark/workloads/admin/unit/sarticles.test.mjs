import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { adapterFor } from '../lib/adapters/index.mjs';
import { SArticlesAdapter } from '../lib/adapters/sarticles.mjs';
import { EvolutionAdapter } from '../lib/adapters/evolution.mjs';
import { articleIds } from '../lib/config.mjs';

const config = { baseUrl: 'http://nginx-sarticles:80', pages: 5, sarticlesFirstId: 100001 };
const source = readFileSync(new URL('../lib/adapters/sarticles.mjs', import.meta.url), 'utf8');

test('evo-sarticles gets the module adapter, not the document one', () => {
  // EvolutionAdapter.supports() matches every evo-* stack, so the order of
  // the registry decides; a regression here would silently measure the
  // document editor on a stack that has no documents to edit.
  assert.ok(SArticlesAdapter.supports('evo-sarticles'));
  assert.ok(EvolutionAdapter.supports('evo-sarticles'), 'the Evolution adapter would take it too');
  assert.ok(adapterFor('evo-sarticles', {}, config, {}) instanceof SArticlesAdapter);
  assert.ok(!(adapterFor('evo-parser', {}, config, {}) instanceof SArticlesAdapter));
});

test('the round edits the seeded articles of the module fixture', () => {
  assert.deepEqual(articleIds(5, 100001), [100001, 100002, 100003, 100004, 100005]);
  assert.deepEqual(adapterFor('evo-sarticles', {}, config, {}).pageIds(), [100001, 100002, 100003, 100004, 100005]);
});

test('both generations are driven, and each waits for the state the editor sees', () => {
  // 1.x posts a form, 2.x answers a Livewire request; a missing branch would
  // make one of the two versions unmeasurable.
  assert.match(source, /get=content&type=article&i=\$\{id\}&lang=base/, '1.x opens the content tab of the article');
  assert.match(source, /get=contentSave/, '1.x waits for the form post');
  assert.match(source, /openEditModal\(\$\{id\}\)/, '2.x opens the row modal');
  assert.match(source, /"saveModal"/, '2.x waits for the save request, not for any Livewire traffic');
  assert.match(source, /action=update/, 'evo-ui proxies Livewire through the manager');
});

test('the manager CSRF workaround is marked as one', () => {
  // sArticles 1.x posts without the manager token and Evolution 3.5.8 answers
  // 403. The harness adds it so the save can be timed; the comment says why,
  // so it is removed once the module posts it itself.
  assert.match(source, /addManagerToken/);
  assert.match(source, /403/, 'the workaround explains what happens without it');
});

test('the saved article is looked up after the step is timed', () => {
  const save = source.slice(source.indexOf('  async save({'));
  const collect = save.indexOf('this.timer.collect()');
  const lookup = save.indexOf('newestId()');
  assert.ok(collect > 0 && lookup > collect, 'finding the created article must not land inside the measured time');
});

test('opening an article clears the state of an unfinished create', () => {
  // The warmup opens the create form and never saves it; the next edit must
  // write into the article's own fields, not into that leftover buffer.
  const openEditor = source.slice(source.indexOf('  async openEditor('), source.indexOf('  // The empty form for a new article.'));
  assert.match(openEditor, /this\.pending = null/);
});

test('only a create looks for a new article; an edit keeps the one it opened', () => {
  // Reading the newest row back after an edit would compare the wrong
  // article and pass or fail for the wrong reason.
  assert.match(source, /async save\(\{ create = false \} = \{\}\)/);
  assert.match(source, /if \(create\) \{[^}]*this\.lastSavedId = await this\.newestId\(\);/s);
});

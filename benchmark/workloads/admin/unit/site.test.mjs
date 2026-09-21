import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  ACTIONS, ACTION_DESCRIPTIONS, GUEST_COLUMNS, actionsNote, adminRows, compareValues, componentsText, errorRows, formatValue, guestRows, nextSort, sortRows, versionRows,
} from '../../../../docs/site.js';
import { versionSuffix } from '../lib/report.mjs';

const set = {
  generatedAt: '2026-09-20T13:00:00Z',
  stacks: { modx: { label: 'MODX Revolution', port: 8087 }, typo3: { label: 'TYPO3', port: 8084 } },
  versions: {
    recordedAt: '2026-09-20T13:21:02Z',
    runtime: { php: '8.4.25' },
    stacks: { modx: [{ name: 'MODX Revolution', version: '3.2.4-pl', source: 'https://github.com/modxcms/revolution' }, { name: 'xpdo/xpdo', version: 'v3.1.7' }] },
  },
  guest: [
    { stack: 'modx', jit: 'off', rate: 30, rps: 30.1, p50Ms: 120.5, p99Ms: 300, errors: 0, containerPeakMb: 80 },
    { stack: 'typo3', jit: 'off', rate: 30, rps: 30.9, p50Ms: 45.85, p99Ms: 82.3, errors: 0, containerPeakMb: 81.5 },
    { stack: 'typo3', jit: 'tracing', rate: 30, rps: null, p50Ms: null, p99Ms: null, errors: 0, containerPeakMb: null },
  ],
  admin: [
    { stack: 'modx', jit: 'off', totalWallMs: 9000, containerPeakMb: 90, actions: { login: { ms: 700, serverMs: 300 }, logout: { ms: 200, serverMs: 80 } } },
    { stack: 'typo3', jit: 'off', totalWallMs: 17971, containerPeakMb: 100, actions: { login: { ms: 1662.1, serverMs: 1241.17 }, logout: { ms: 183.32, serverMs: 110.4 } } },
  ],
};

test('sortRows sorts numerically, keeps missing values last in both directions and is stable', () => {
  const rows = guestRows(set);
  assert.deepEqual(sortRows(rows, 'p50Ms', 'asc', true).map((row) => [row.stack, row.jit]), [['typo3', 'off'], ['modx', 'off'], ['typo3', 'tracing']]);
  assert.deepEqual(sortRows(rows, 'p50Ms', 'desc', true).map((row) => [row.stack, row.jit]), [['modx', 'off'], ['typo3', 'off'], ['typo3', 'tracing']]);
  assert.deepEqual(sortRows(rows, 'stack', 'asc').map((row) => [row.stack, row.jit]), [['modx', 'off'], ['typo3', 'off'], ['typo3', 'tracing']]);
  assert.deepEqual(sortRows(rows, 'stack', 'desc').map((row) => [row.stack, row.jit]), [['typo3', 'off'], ['typo3', 'tracing'], ['modx', 'off']]);
  assert.deepEqual(rows.map((row) => row.stack), ['modx', 'typo3', 'typo3'], 'sorting must not mutate the input');
});

test('rows carry the version label and the recorded components of a comparison run', () => {
  const compared = {
    stacks: { 'evo-latte': { label: 'Evolution + Latte' } },
    guest: [
      { stack: 'evo-latte', version: 'evo@3.5.8+latte@0.4.0', jit: 'off', rate: 30, p50Ms: 300, repeats: { n: 1, metrics: {} }, components: [{ name: 'PHP', version: '8.4.25' }, { name: 'Evolution CMS', version: '3.5.8 (tag 3.5.8@abc1234, 2026-08-01)' }] },
      { stack: 'evo-latte', version: '', jit: 'off', rate: 30, p50Ms: 280 },
    ],
    admin: [
      { stack: 'evo-latte', version: 'evo@3.5.8+latte@0.4.0', jit: 'off', totalWallMs: 5000, actions: { login: { ms: 500 } }, repeats: { n: 1, metrics: {} } },
      { stack: 'evo-latte', version: '', jit: 'off', totalWallMs: 4000, actions: { login: { ms: 400 } } },
    ],
  };
  assert.ok(GUEST_COLUMNS.some((column) => column.key === 'version'), 'the guest table needs a version column');
  const guest = guestRows(compared);
  assert.deepEqual(guest.map((row) => [row.label, row.version]), [['Evolution + Latte · evo@3.5.8+latte@0.4.0', 'evo@3.5.8+latte@0.4.0'], ['Evolution + Latte', '']]);
  assert.equal(guest[0].componentsText, 'PHP 8.4.25, Evolution CMS 3.5.8 (tag 3.5.8@abc1234, 2026-08-01)');
  assert.equal(componentsText(guest[1]), '');
  assert.deepEqual(adminRows(compared, 'ms').map((row) => [row.version, row.login]), [['evo@3.5.8+latte@0.4.0', 500], ['', 400]]);
  // The error table joins guest and admin per build, not per stack.
  assert.deepEqual(errorRows(compared).map((row) => [row.version, row.adminRuns]), [['evo@3.5.8+latte@0.4.0', 1], ['', 1]]);
});

test('versionSuffix names result files after the stack with the label, as Phramark\\VersionSpec::fileTag does', () => {
  assert.equal(versionSuffix(''), '');
  assert.equal(versionSuffix('evo@3.5.x+latte@0.4.0'), '~evo@3.5.x+latte@0.4.0');
  assert.equal(versionSuffix('evo@feature/x y'), '~evo@feature_x_y');
});

test('compareValues treats numbers as numbers, not as text', () => {
  assert.ok(compareValues(9, 10, true) < 0);
  assert.ok(compareValues('9', '10', false) > 0);
  assert.equal(compareValues(null, undefined), 0);
  assert.ok(compareValues(null, 1, true) > 0);
});

test('formatValue applies the column unit and decimals and marks missing values', () => {
  const p50 = GUEST_COLUMNS.find((column) => column.key === 'p50Ms');
  assert.equal(formatValue(45.85, p50), '45.85 ms');
  assert.equal(formatValue(30, { unit: 'MiB', decimals: 1 }), '30.0 MiB');
  assert.equal(formatValue(30), '30');
  assert.equal(formatValue(null, p50), '–');
  assert.equal(formatValue('tracing'), 'tracing');
});

test('nextSort toggles direction on the same column and resets on a new one', () => {
  assert.deepEqual(nextSort({ key: 'stack', direction: 'asc' }, 'stack'), { key: 'stack', direction: 'desc' });
  assert.deepEqual(nextSort({ key: 'stack', direction: 'desc' }, 'stack'), { key: 'stack', direction: 'asc' });
  assert.deepEqual(nextSort({ key: 'stack', direction: 'desc' }, 'p50Ms'), { key: 'p50Ms', direction: 'asc' });
});

test('adminRows flattens one metric per action so any action column can be sorted', () => {
  const wall = adminRows(set, 'ms');
  assert.deepEqual(Object.keys(wall[0]).filter((key) => ACTIONS.includes(key)), ACTIONS);
  assert.equal(wall[1].login, 1662.1);
  assert.equal(wall[1]['open-edit'], null);
  assert.equal(wall[1].totalWallMs, 17971);
  assert.equal(wall[0].label, 'MODX Revolution');
  const server = adminRows(set, 'serverMs');
  assert.equal(server[1].login, 1241.17);
  assert.equal(server[1].totalWallMs, null, 'the total wall time belongs to the wall-time table only');
  assert.deepEqual(sortRows(server, 'logout', 'desc', true).map((row) => row.stack), ['typo3', 'modx']);
});

test('guestRows carries the stack label for the tooltip', () => {
  assert.equal(guestRows(set)[1].label, 'TYPO3');
  assert.deepEqual(guestRows({}), []);
});

test('versionRows lists the exact component versions per stack', () => {
  const rows = versionRows(set.versions);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].id, 'modx');
  assert.equal(rows[0].components, 'MODX Revolution 3.2.4-pl, xpdo/xpdo v3.1.7');
  assert.deepEqual(versionRows(null), []);
});

test('sortRows without a key keeps the incoming order', () => {
  assert.deepEqual(sortRows(guestRows(set), null).map((row) => [row.stack, row.jit]), [['modx', 'off'], ['typo3', 'off'], ['typo3', 'tracing']]);
});

test('every admin action is explained on the results page', () => {
  for (const action of ACTIONS) {
    assert.ok(ACTION_DESCRIPTIONS[action]?.length > 40, `${action} has a description`);
    assert.ok(actionsNote().includes(`${action} — `), `${action} is in the note under the admin table`);
  }
  assert.ok(actionsNote().startsWith('Wall is what the browser experienced'), 'the note says what wall and server mean');
});

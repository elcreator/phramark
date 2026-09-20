import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  barGroups, describeSpread, describeTrend, linePath, linearScale, niceTicks, panelRange, rangeTicks, trendOf,
} from '../../../../docs/charts.js';
import { adminActionRows, errorRows, seriesPanels } from '../../../../docs/site.js';

test('linearScale maps the domain onto the range', () => {
  const x = linearScale([0, 100], [10, 210]);
  assert.equal(x(0), 10);
  assert.equal(x(50), 110);
  assert.equal(x(100), 210);
  assert.equal(linearScale([5, 5], [0, 1])(5), 0, 'a degenerate domain does not divide by zero');
});

test('niceTicks covers 0..max with a round step', () => {
  assert.deepEqual(niceTicks(738), [0, 200, 400, 600, 800]);
  assert.deepEqual(niceTicks(4.9, 4), [0, 2, 4, 6]);
  assert.deepEqual(niceTicks(0), [0]);
});

test('panelRange pads the values and refuses to zoom into noise', () => {
  const [low, high] = panelRange([1.77, 1.77, 1.78]);
  assert.ok(low < 1.77 && high > 1.78);
  assert.ok(high - low >= 1, 'a flat series at 1.77 MiB spans at least one unit');
  const [wideLow, wideHigh] = panelRange([100, 140]);
  assert.ok(wideLow < 100 && wideLow > 90 && wideHigh > 140 && wideHigh < 150);
  assert.deepEqual(panelRange([]), [0, 1]);
});

test('rangeTicks stays inside the range on a round step', () => {
  const ticks = rangeTicks(94, 146);
  assert.ok(ticks.every((tick) => tick >= 94 && tick <= 146));
  assert.ok(ticks.length >= 3 && ticks.length <= 6);
  assert.deepEqual(rangeTicks(5, 5), [5]);
});

test('linePath emits a move then lines', () => {
  const path = linePath([{ x: 0, y: 1 }, { x: 10, y: 2 }], (p) => p.x, (p) => p.y * 10);
  assert.equal(path, 'M0.0,10.0 L10.0,20.0');
});

test('barGroups groups by stack, orders JIT off first and sorts by the JIT-off value', () => {
  const rows = [
    { stack: 'b', jit: 'tracing', v: 5 },
    { stack: 'b', jit: 'off', v: 9 },
    { stack: 'a', jit: 'off', v: 20 },
    { stack: 'c', jit: 'tracing', v: 1 },
  ];
  const groups = barGroups(rows, 'v', { a: { label: 'A' } });
  assert.deepEqual(groups.map((group) => group.stack), ['c', 'b', 'a']);
  assert.deepEqual(groups[1].bars.map((bar) => bar.jit), ['off', 'tracing']);
  assert.equal(groups[2].label, 'A');
  assert.equal(groups[0].label, 'c', 'an unknown stack keeps its id as label');
});

test('trendOf reports slope and last-over-first-quarter growth', () => {
  const flat = trendOf([1, 2, 3, 4, 5, 6, 7, 8].map((x) => ({ x, y: 10 })));
  assert.deepEqual(flat, { slopeMbPerMin: 0, firstQuarterMb: 10, lastQuarterMb: 10, growth: 0 });
  const growing = trendOf([1, 2, 3, 4, 5, 6, 7, 8].map((x) => ({ x, y: x })));
  assert.equal(growing.slopeMbPerMin, 1);
  assert.equal(growing.firstQuarterMb, 2);
  assert.equal(growing.lastQuarterMb, 8);
  assert.equal(growing.growth, 3);
  assert.equal(trendOf([{ x: 1, y: 1 }]), null);
  assert.equal(trendOf([{ x: 1, y: null }, { x: 2, y: 3 }]), null, 'nulls are skipped');
});

test('describeTrend and describeSpread read as plain sentences', () => {
  assert.equal(describeTrend({ growth: 0.01, firstQuarterMb: 4.9, lastQuarterMb: 4.95, slopeMbPerMin: 0.001 }), 'flat (4.9 → 4.95 MiB)');
  assert.equal(describeTrend({ growth: 0.25, firstQuarterMb: 4, lastQuarterMb: 5, slopeMbPerMin: 0.5 }), 'grows +25 % (4 → 5 MiB, +0.5 MiB/min)');
  assert.equal(describeTrend(null), 'no series');
  assert.equal(describeTrend({ growth: 0.25, firstQuarterMb: 4, lastQuarterMb: 5, slopeMbPerMin: null }), 'grows +25 % (4 → 5 MiB)', 'no slope for untimed samples');
  assert.equal(describeTrend({ growth: 1.5, firstQuarterMb: 4000, lastQuarterMb: 10000, slopeMbPerMin: 300 }, 'nodes', 'nodes/step'), 'grows +150 % (4000 → 10000 nodes, +300 nodes/step)');
  assert.equal(describeSpread({ n: 1, metrics: { p50Ms: { median: 1, min: 1, max: 1, cv: null } } }, 'p50Ms'), 'n=1');
  assert.equal(describeSpread({ n: 3, metrics: { p50Ms: { median: 110, min: 100, max: 120, cv: 0.0915 } } }, 'p50Ms'), 'n=3, 100–120, CV 9.2 %');
  assert.equal(describeSpread(null, 'p50Ms'), 'n=1');
});

const set = {
  stacks: { modx: { label: 'MODX Revolution' } },
  guest: [
    { stack: 'modx', jit: 'off', p50Ms: 100, repeats: { n: 3, metrics: { p50Ms: { cv: 0.05 }, p99Ms: { cv: 0.1 }, rps: { cv: null } } }, series: { phpPeak: { points: [{ t: 1, medianMb: 1 }], trend: {} }, containerRss: null } },
    { stack: 'modx', jit: 'tracing', p50Ms: 90, repeats: { n: 1, metrics: {} }, series: null },
  ],
  admin: [
    { stack: 'modx', jit: 'off', actions: { login: { ms: 700, serverMs: 300 }, logout: { ms: 200, serverMs: 50 } }, repeats: { n: 2, metrics: { 'login.ms': { min: 690, max: 710, cv: 0.02 }, totalWallMs: { cv: 0.03 } } }, series: { steps: [{ index: 1, action: 'login', label: 'round 1', jsHeapUsedMb: 20 }, { index: 2, action: 'logout', label: 'round 1', jsHeapUsedMb: 22 }] } },
  ],
};

test('adminActionRows carries wall, server and the wall-time spread of one action', () => {
  const rows = adminActionRows(set, 'login');
  assert.deepEqual(rows[0], { stack: 'modx', jit: 'off', value: 700, server: 300, repeats: { n: 2, metrics: { value: { min: 690, max: 710, cv: 0.02 } } } });
  assert.equal(adminActionRows(set, 'open-edit')[0].value, null);
});

test('seriesPanels builds one panel per stack with JIT lines in a fixed order', () => {
  const panels = seriesPanels(set.guest, set.stacks, (row) => row.series?.phpPeak && { points: row.series.phpPeak.points });
  assert.equal(panels.length, 1);
  assert.equal(panels[0].label, 'MODX Revolution');
  assert.deepEqual(panels[0].lines.map((line) => line.jit), ['off'], 'a cell without a series has no line');
});

test('errorRows pairs guest and admin repetitions per cell with CV in percent', () => {
  const rows = errorRows(set);
  assert.equal(rows.length, 2);
  assert.deepEqual([rows[0].guestRuns, rows[0].p50Cv, rows[0].p99Cv, rows[0].rpsCv, rows[0].adminRuns, rows[0].loginCv, rows[0].totalCv], [3, 5, 10, null, 2, 2, 3]);
  assert.deepEqual([rows[1].guestRuns, rows[1].p50Cv, rows[1].adminRuns, rows[1].loginCv], [1, null, null, null]);
});

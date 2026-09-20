import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Timeline, formatReport, percentile, summarize } from '../lib/timeline.mjs';
import { buildReport, mergePhpMemory } from '../lib/report.mjs';
import { adminPageIds } from '../lib/config.mjs';

test('Timeline.measure records wall time and merges step details', async () => {
  let clock = 0;
  const timeline = new Timeline(() => clock);
  const step = await timeline.measure('save-edit', 'page 1', async () => {
    clock += 125.456;
    return { serverMs: 80, id: 42 };
  });
  assert.deepEqual(step, { action: 'save-edit', label: 'page 1', ms: 125.46, serverMs: 80, id: 42 });
  assert.equal(timeline.steps.length, 1);
});

test('percentile uses nearest-rank on a sorted list', () => {
  assert.equal(percentile([], 0.5), 0);
  assert.equal(percentile([10], 0.95), 10);
  assert.equal(percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.5), 5);
  assert.equal(percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.95), 10);
});

test('summarize groups by action and ignores steps without the key', () => {
  const steps = [
    { action: 'save-edit', ms: 300, serverMs: 200 },
    { action: 'save-edit', ms: 100, serverMs: 50 },
    { action: 'login', ms: 1000 },
  ];
  const wall = summarize(steps, 'ms');
  assert.deepEqual(wall['save-edit'], { count: 2, total: 400, mean: 200, median: 100, p95: 300, min: 100, max: 300 });
  assert.deepEqual(Object.keys(summarize(steps, 'serverMs')), ['save-edit']);
});

test('formatReport lists every action with its server median', () => {
  const text = formatReport({
    stack: 'evo-parser', jit: 'off', baseUrl: 'http://x', rounds: 1, pages: 5,
    steps: [{ action: 'login', ms: 12, serverMs: 8 }, { action: 'logout', ms: 4 }],
  });
  assert.match(text, /stack=evo-parser jit=off/);
  assert.match(text, /server p50/);
  assert.match(text, /login\s+1\s+12 ms.*8 ms/);
  assert.match(text, /logout\s+1\s+4 ms.*-\s*$/m);
  assert.doesNotMatch(text, /php peak/, 'columns without data stay out of the table');
});

test('formatReport adds memory columns when steps carry them', () => {
  const text = formatReport({
    stack: 's', jit: 'off', baseUrl: 'http://x', rounds: 1, pages: 5, containerPeakMb: 321.5,
    steps: [{ action: 'save-edit', ms: 10, serverMs: 5, phpPeakMb: 12.5, jsHeapUsedMb: 30.25, domNodes: 1200 }],
  });
  assert.match(text, /container peak RSS during the run: 321.5 MiB/);
  assert.match(text, /php peak p50\s+js heap p50\s+dom nodes p50/);
  assert.match(text, /save-edit.*12.5 MiB\s+30.25 MiB\s+1200/);
});

test('mergePhpMemory attributes per-step PHP peaks by the X-Phramark-Step key', () => {
  const report = buildReport({ stack: 's', jit: 'off', baseUrl: 'http://x', rounds: 1, pages: 5, resultsDir: '.' }, [
    { action: 'save-edit', label: 'page 1', ms: 10 },
    { action: 'logout', label: 'round 1', ms: 4 },
  ]);
  const merged = mergePhpMemory(report, {
    requests: 3,
    steps: { 'save-edit:page 1': { requests: 2, peak_real: { max: 2 * 1048576 }, peak: { max: 1048576 } } },
  }, 256);
  assert.equal(merged.steps[0].phpPeakMb, 1);
  assert.equal(merged.steps[0].phpPeakRealMb, 2);
  assert.equal(merged.steps[0].phpRequests, 2);
  assert.equal(merged.steps[1].phpPeakMb, undefined);
  assert.equal(merged.containerPeakMb, 256);
  assert.equal(merged.summary.phpPeakMb['save-edit'].max, 1);
  assert.equal(merged.phpMemory.requests, 3);
});

test('buildReport carries the run dimensions needed to compare JIT modes', () => {
  const report = buildReport({ stack: 'evo-latte', jit: 'tracing', baseUrl: 'http://x', rounds: 2, pages: 5, resultsDir: '.' }, [{ action: 'login', ms: 1, serverMs: 1 }], { createdIds: [7] });
  assert.equal(report.workload, 'admin');
  assert.equal(report.jit, 'tracing');
  assert.deepEqual(report.createdIds, [7]);
  assert.equal(report.summary.ms.login.count, 1);
  assert.equal(report.summary.serverMs.login.count, 1);
});

test('adminPageIds mirrors FixturePlan::adminPageId', () => {
  assert.deepEqual(adminPageIds(5, 10103), [10104, 10105, 10106, 10107, 10108]);
});

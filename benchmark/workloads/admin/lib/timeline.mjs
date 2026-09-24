// Records timed steps of one admin session and summarises them per action.
// Pure data: nothing here touches Playwright, so it is unit-tested directly.

export class Timeline {
  constructor(now = () => performance.now()) {
    this.now = now;
    this.steps = [];
  }

  async measure(action, label, fn) {
    const started = this.now();
    const detail = (await fn()) ?? {};
    const ms = this.now() - started;
    const step = { action, label, ms: round(ms), ...detail };
    this.steps.push(step);
    return step;
  }
}

export function percentile(sorted, fraction) {
  if (sorted.length === 0) return 0;
  const rank = Math.ceil(fraction * sorted.length) - 1;
  return sorted[Math.min(Math.max(rank, 0), sorted.length - 1)];
}

export function summarize(steps, key = 'ms') {
  const groups = new Map();
  for (const step of steps) {
    if (typeof step[key] !== 'number') continue;
    if (!groups.has(step.action)) groups.set(step.action, []);
    groups.get(step.action).push(step[key]);
  }
  const summary = {};
  for (const [action, values] of groups) {
    const sorted = [...values].sort((a, b) => a - b);
    const total = sorted.reduce((sum, value) => sum + value, 0);
    summary[action] = {
      count: sorted.length,
      total: round(total),
      mean: round(total / sorted.length),
      median: round(percentile(sorted, 0.5)),
      p95: round(percentile(sorted, 0.95)),
      min: round(sorted[0]),
      max: round(sorted[sorted.length - 1]),
    };
  }
  return summary;
}

// Every metric a step may carry, with its column label and unit.
export const METRICS = [
  { key: 'ms', label: 'wall', unit: 'ms' },
  { key: 'serverMs', label: 'server', unit: 'ms' },
  // Password hashing, already taken out of wall and server (report.mjs excludeHashing).
  { key: 'hashMs', label: 'hashing', unit: 'ms' },
  { key: 'phpPeakMb', label: 'php peak', unit: 'MiB' },
  { key: 'jsHeapUsedMb', label: 'js heap', unit: 'MiB' },
  { key: 'domNodes', label: 'dom nodes', unit: '' },
];

export function formatReport({ stack, jit, baseUrl, rounds, pages, steps, containerPeakMb }) {
  const wall = summarize(steps, 'ms');
  const total = steps.reduce((sum, step) => sum + step.ms, 0);
  const present = METRICS.filter((metric) => steps.some((step) => typeof step[metric.key] === 'number'));
  const lines = [
    `Phramark admin workload: stack=${stack} jit=${jit} base=${baseUrl} rounds=${rounds} pages=${pages}`,
    `Total wall time: ${round(total)} ms over ${steps.length} steps`,
  ];
  if (typeof containerPeakMb === 'number') {
    lines.push(`PHP-FPM container peak RSS during the run: ${containerPeakMb} MiB`);
  }
  lines.push('', pad('action', 14) + pad('n', 5) + pad('wall p50', 12) + pad('wall p95', 12) + pad('wall max', 12)
    + present.filter((metric) => metric.key !== 'ms').map((metric) => pad(`${metric.label} p50`, 15)).join(''));
  for (const [action, stats] of Object.entries(wall)) {
    let line = pad(action, 14) + pad(String(stats.count), 5) + pad(`${stats.median} ms`, 12) + pad(`${stats.p95} ms`, 12) + pad(`${stats.max} ms`, 12);
    for (const metric of present) {
      if (metric.key === 'ms') continue;
      const stats = summarize(steps, metric.key)[action];
      line += pad(stats ? `${stats.median} ${metric.unit}`.trim() : '-', 15);
    }
    lines.push(line);
  }
  return lines.join('\n') + '\n';
}

function pad(value, width) {
  return String(value).padEnd(width);
}

function round(value) {
  return Math.round(value * 100) / 100;
}

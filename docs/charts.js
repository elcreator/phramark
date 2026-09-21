// SVG charts for the results site: pure layout helpers (tested in
// benchmark/workloads/admin/unit/charts.test.mjs) and the renderers that use
// them. Two categorical series only (JIT off / JIT tracing); stacks are the
// categories on the axis, so no colour is spent on identity beyond that.

export const SERIES = {
  off: { label: 'JIT off', className: 'series-off' },
  tracing: { label: 'JIT tracing', className: 'series-tracing' },
};

export function linearScale([d0, d1], [r0, r1]) {
  const span = d1 - d0 || 1;
  return (value) => r0 + ((value - d0) / span) * (r1 - r0);
}

// "Nice" tick values covering 0..max: 1, 2, 2.5, 5 × 10^k steps, 4–7 ticks.
export function niceTicks(max, count = 5) {
  if (!(max > 0)) return [0];
  const rough = max / count;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= rough) ?? magnitude * 10;
  const ticks = [];
  for (let value = 0; value <= max + 1e-9; value += step) ticks.push(Number(value.toFixed(10)));
  if (ticks[ticks.length - 1] < max) ticks.push(Number((ticks[ticks.length - 1] + step).toFixed(10)));
  return ticks;
}

// A padded [low, high] range around the values of one small-multiple panel,
// never narrower than 10 % of the level (or 1 unit) so a flat line does not
// turn measurement noise into a mountain range.
export function panelRange(values) {
  const finite = values.filter((v) => Number.isFinite(v));
  if (finite.length === 0) return [0, 1];
  const min = Math.min(...finite);
  const max = Math.max(...finite);
  const floor = Math.max(Math.abs(max) * 0.1, 1);
  let low = min;
  let high = max;
  if (high - low < floor) {
    const mid = (low + high) / 2;
    low = mid - floor / 2;
    high = mid + floor / 2;
  }
  const pad = (high - low) * 0.15;
  return [Math.max(0, low - pad), high + pad];
}

// Four to five tick values between low and high on a nice step.
export function rangeTicks(low, high, count = 4) {
  const rough = (high - low) / count;
  if (!(rough > 0)) return [low];
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= rough) ?? magnitude * 10;
  const ticks = [];
  for (let value = Math.ceil(low / step) * step; value <= high + 1e-9; value += step) ticks.push(Number(value.toFixed(10)));
  return ticks;
}

export function linePath(points, x, y) {
  return points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(point).toFixed(1)},${y(point).toFixed(1)}`).join(' ');
}

// A row's cell identity and label: the stack, and the version label of a
// comparison run when the stack was measured in several builds.
export function cellId(row) {
  return row.version ? `${row.stack}~${row.version}` : row.stack;
}

export function cellLabel(row, stacks) {
  const label = stacks?.[row.stack]?.label ?? row.stack;
  return row.version ? `${label} · ${row.version}` : label;
}

// Rows for a grouped horizontal bar chart: one group per stack (and
// version), one bar per JIT mode present, sorted by the JIT-off value
// (falls back to tracing).
// The left gutter of a bar chart, wide enough for the longest row label
// ("Evolution + Latte · evo@../evolution" with a version): about 6 px per
// character at the 11 px label font, never narrower than the plain-stack
// layout and capped so the bars keep most of the width.
export function labelGutter(labels, { min = 150, max = 320, perChar = 6.2, padding = 16 } = {}) {
  const longest = Math.max(0, ...labels.map((label) => String(label ?? '').length));
  return Math.min(max, Math.max(min, Math.ceil(longest * perChar + padding)));
}

export function barGroups(rows, key, stacks) {
  const groups = new Map();
  for (const row of rows) {
    const id = cellId(row);
    if (!groups.has(id)) groups.set(id, { stack: row.stack, version: row.version ?? '', label: cellLabel(row, stacks), bars: [] });
    groups.get(id).bars.push({ jit: row.jit, value: row[key] ?? null, row });
  }
  const sortValue = (group) => group.bars.find((bar) => bar.jit === 'off')?.value ?? group.bars[0]?.value ?? Infinity;
  return [...groups.values()]
    .map((group) => ({ ...group, bars: [...group.bars].sort((a, b) => (a.jit === 'off' ? -1 : 1) - (b.jit === 'off' ? -1 : 1)) }))
    .sort((a, b) => (sortValue(a) ?? Infinity) - (sortValue(b) ?? Infinity));
}

// Human summary of a memory trend: growth of the last quarter over the first.
export function describeTrend(trend, unit = 'MiB', slopeUnit = 'MiB/min') {
  if (!trend) return 'no series';
  const percent = Math.round(trend.growth * 100);
  if (Math.abs(percent) < 3) return `flat (${trend.firstQuarterMb} → ${trend.lastQuarterMb} ${unit})`;
  const slope = trend.slopeMbPerMin === null || trend.slopeMbPerMin === undefined ? '' : `, ${trend.slopeMbPerMin > 0 ? '+' : ''}${trend.slopeMbPerMin} ${slopeUnit}`;
  return `${percent > 0 ? 'grows' : 'shrinks'} ${percent > 0 ? '+' : ''}${percent} % (${trend.firstQuarterMb} → ${trend.lastQuarterMb} ${unit}${slope})`;
}

// The measuring error of a cell as text: spread across repetitions.
export function describeSpread(repeats, metric) {
  const stats = repeats?.metrics?.[metric];
  if (!repeats || !stats) return 'n=1';
  if (repeats.n < 2 || stats.cv === null) return `n=${repeats.n}`;
  return `n=${repeats.n}, ${stats.min}–${stats.max}, CV ${(stats.cv * 100).toFixed(1)} %`;
}

const NS = 'http://www.w3.org/2000/svg';

function svg(tag, attributes = {}, text = null) {
  const node = document.createElementNS(NS, tag);
  for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
  if (text !== null) node.textContent = text;
  return node;
}

function html(tag, attributes = {}, text = null) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
  if (text !== null) node.textContent = text;
  return node;
}

function tooltip(figure) {
  const tip = html('div', { class: 'tooltip', hidden: '' });
  figure.append(tip);
  return {
    show(event, lines) {
      tip.replaceChildren(...lines.map((line) => html('div', {}, line)));
      tip.hidden = false;
      const box = figure.getBoundingClientRect();
      tip.style.left = `${Math.min(event.clientX - box.left + 12, box.width - tip.offsetWidth - 8)}px`;
      tip.style.top = `${event.clientY - box.top + 12}px`;
    },
    hide() { tip.hidden = true; },
  };
}

function legend(series) {
  const list = html('ul', { class: 'legend' });
  for (const jit of series) {
    const item = html('li');
    item.append(html('span', { class: `swatch ${SERIES[jit].className}` }), SERIES[jit].label);
    list.append(item);
  }
  return list;
}

// Grouped horizontal bars (stack groups × JIT) with an optional range mark
// (e.g. p99 to the right of p50, or the server share inside the wall bar)
// and a min–max whisker across repetitions.
export function renderBars(container, { title, note, rows, key, stacks, unit, decimals = 0, range, spreadMetric, inner }) {
  const groups = barGroups(rows, key, stacks);
  if (groups.length === 0) return;
  const figure = html('figure', { class: 'chart' });
  figure.append(html('figcaption', {}, title));
  if (note) figure.append(html('p', { class: 'note' }, note));
  const series = [...new Set(rows.map((row) => row.jit))].sort();
  figure.append(legend(series));

  const barHeight = 12;
  const gap = 2;
  const groupGap = 10;
  // The gutter grows with the labels and the chart with it, so the bars
  // keep their width whatever the version labels add.
  const left = labelGutter(groups.map((group) => group.label));
  const right = 24;
  const top = 8;
  const width = 760 + (left - 150);
  const groupHeight = series.length * (barHeight + gap) - gap + groupGap;
  const height = top + groups.length * groupHeight + 28;
  const maxValue = Math.max(...rows.flatMap((row) => [row[key] ?? 0, range ? row[range] ?? 0 : 0, row.repeats?.metrics?.[spreadMetric]?.max ?? 0]));
  const ticks = niceTicks(maxValue);
  const x = linearScale([0, ticks[ticks.length - 1]], [left, width - right]);
  const root = svg('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': title });
  const tip = tooltip(figure);

  for (const tick of ticks) {
    root.append(svg('line', { class: 'grid', x1: x(tick), x2: x(tick), y1: top, y2: height - 24 }));
    root.append(svg('text', { class: 'axis', x: x(tick), y: height - 8, 'text-anchor': 'middle' }, `${tick}${unit ? ` ${unit}` : ''}`));
  }
  groups.forEach((group, groupIndex) => {
    const y0 = top + groupIndex * groupHeight;
    root.append(svg('text', { class: 'label', x: left - 8, y: y0 + (groupHeight - groupGap) / 2 + 4, 'text-anchor': 'end' }, group.label));
    group.bars.forEach((bar, barIndex) => {
      const y = y0 + barIndex * (barHeight + gap);
      if (bar.value === null) {
        root.append(svg('text', { class: 'axis', x: left + 4, y: y + barHeight - 2 }, 'no run'));
        return;
      }
      const hit = svg('g', { class: 'hit' });
      hit.append(svg('rect', { class: `bar ${SERIES[bar.jit].className}`, x: left, y, width: Math.max(x(bar.value) - left, 1), height: barHeight, rx: 2 }));
      if (inner && bar.row[inner] !== null && bar.row[inner] !== undefined) {
        hit.append(svg('rect', { class: `bar inner ${SERIES[bar.jit].className}`, x: left, y: y + 3, width: Math.max(x(bar.row[inner]) - left, 1), height: barHeight - 6, rx: 1 }));
      }
      if (range && bar.row[range] !== null && bar.row[range] !== undefined) {
        hit.append(svg('line', { class: `range ${SERIES[bar.jit].className}`, x1: x(bar.value), x2: x(bar.row[range]), y1: y + barHeight / 2, y2: y + barHeight / 2 }));
        hit.append(svg('line', { class: `range ${SERIES[bar.jit].className}`, x1: x(bar.row[range]), x2: x(bar.row[range]), y1: y + 2, y2: y + barHeight - 2 }));
      }
      const spread = bar.row.repeats?.metrics?.[spreadMetric];
      if (spread && bar.row.repeats.n > 1) {
        hit.append(svg('line', { class: 'whisker', x1: x(spread.min), x2: x(spread.max), y1: y + barHeight / 2, y2: y + barHeight / 2 }));
        hit.append(svg('line', { class: 'whisker', x1: x(spread.min), x2: x(spread.min), y1: y + 1, y2: y + barHeight - 1 }));
        hit.append(svg('line', { class: 'whisker', x1: x(spread.max), x2: x(spread.max), y1: y + 1, y2: y + barHeight - 1 }));
      }
      hit.append(svg('rect', { class: 'hit-area', x: left, y: y - 1, width: width - left - right, height: barHeight + 2 }));
      const lines = [`${group.label} · ${SERIES[bar.jit].label}`, `${bar.value.toFixed(decimals)} ${unit}`];
      if (inner && bar.row[inner] != null) lines.push(`server ${bar.row[inner].toFixed(decimals)} ${unit}`);
      if (range && bar.row[range] != null) lines.push(`p99 ${bar.row[range].toFixed(decimals)} ${unit}`);
      lines.push(`repetitions: ${describeSpread(bar.row.repeats, spreadMetric)}`);
      hit.addEventListener('mousemove', (event) => tip.show(event, lines));
      hit.addEventListener('mouseleave', () => tip.hide());
      root.append(hit);
    });
  });
  figure.append(root);
  container.append(figure);
  return figure;
}

// Small multiples of lines over time: one panel per stack, one line per JIT
// mode, a shared y scale so panels compare, and the trend under each title.
export function renderSmallMultiples(container, { title, note, panels, unit, xLabel, decimals = 1, slopeUnit = `${unit}/min` }) {
  const usable = panels.filter((panel) => panel.lines.some((line) => line.points.length > 1));
  if (usable.length === 0) return;
  const figure = html('figure', { class: 'chart multiples' });
  figure.append(html('figcaption', {}, title));
  if (note) figure.append(html('p', { class: 'note' }, note));
  const series = [...new Set(usable.flatMap((panel) => panel.lines.map((line) => line.jit)))].sort();
  figure.append(legend(series));
  const grid = html('div', { class: 'grid' });
  const tip = tooltip(figure);

  for (const panel of usable) {
    const width = 320;
    const height = 150;
    const left = 44;
    const bottom = 22;
    const xMax = Math.max(...panel.lines.flatMap((line) => line.points.map((point) => point.x)));
    const x = linearScale([0, xMax || 1], [left, width - 8]);
    // The y range is the panel's own (not zero-based): the question is whether
    // the line drifts, and a shared zero-based scale would flatten a drift of a
    // few percent into a straight line. The tick labels say where it sits.
    const [yLow, yHigh] = panelRange(panel.lines.flatMap((line) => line.points.map((point) => point.y)));
    const ticks = rangeTicks(yLow, yHigh);
    const y = linearScale([yLow, yHigh], [height - bottom, 8]);
    const box = html('div', { class: 'panel' });
    box.append(html('h3', {}, `${panel.label} (${unit})`));
    for (const line of panel.lines) {
      box.append(html('p', { class: 'trend' }, `${SERIES[line.jit].label}: ${describeTrend(line.trend, unit, slopeUnit)}`));
    }
    const root = svg('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': `${title}: ${panel.label}` });
    for (const tick of ticks) {
      root.append(svg('line', { class: 'grid', x1: left, x2: width - 8, y1: y(tick), y2: y(tick) }));
      root.append(svg('text', { class: 'axis', x: left - 4, y: y(tick) + 3, 'text-anchor': 'end' }, String(tick)));
    }
    root.append(svg('text', { class: 'axis', x: width - 8, y: height - 6, 'text-anchor': 'end' }, `${xLabel} 0–${xMax}`));
    for (const line of panel.lines) {
      if (line.points.length < 2) continue;
      root.append(svg('path', { class: `line ${SERIES[line.jit].className}`, d: linePath(line.points, (p) => x(p.x), (p) => y(p.y)) }));
    }
    const cursor = svg('line', { class: 'cursor', y1: 8, y2: height - bottom, visibility: 'hidden' });
    root.append(cursor);
    root.addEventListener('mousemove', (event) => {
      const rect = root.getBoundingClientRect();
      const px = ((event.clientX - rect.left) / rect.width) * width;
      const value = ((px - left) / (width - 8 - left)) * (xMax || 1);
      const lines = [panel.label];
      for (const line of panel.lines) {
        if (line.points.length === 0) continue;
        const nearest = line.points.reduce((best, point) => (Math.abs(point.x - value) < Math.abs(best.x - value) ? point : best));
        lines.push(`${SERIES[line.jit].label}: ${nearest.y.toFixed(decimals)} ${unit} at ${xLabel} ${nearest.x}${nearest.note ? ` (${nearest.note})` : ''}`);
      }
      cursor.setAttribute('x1', Math.max(left, Math.min(px, width - 8)));
      cursor.setAttribute('x2', Math.max(left, Math.min(px, width - 8)));
      cursor.setAttribute('visibility', 'visible');
      tip.show(event, lines);
    });
    root.addEventListener('mouseleave', () => { cursor.setAttribute('visibility', 'hidden'); tip.hide(); });
    box.append(root);
    grid.append(box);
  }
  figure.append(grid);
  container.append(figure);
  return figure;
}

// Trend of an ordered series of {x, y} points (e.g. the JS heap after each
// admin step): least-squares slope per x unit and the ratio of the last to
// the first quarter, the same shape ResultSet::trend() produces server-side.
export function trendOf(points) {
  const values = points.filter((point) => point.y !== null && point.y !== undefined);
  if (values.length < 2) return null;
  const n = values.length;
  const x0 = values[0].x;
  let sumX = 0; let sumY = 0; let sumXX = 0; let sumXY = 0;
  for (const { x, y } of values) {
    const t = x - x0;
    sumX += t; sumY += y; sumXX += t * t; sumXY += t * y;
  }
  const denominator = n * sumXX - sumX * sumX;
  const slope = denominator === 0 ? 0 : (n * sumXY - sumX * sumY) / denominator;
  const quarter = Math.max(1, Math.floor(n / 4));
  const median = (part) => { const sorted = part.map((p) => p.y).sort((a, b) => a - b); return sorted[Math.floor(sorted.length / 2)]; };
  const first = median(values.slice(0, quarter));
  const last = median(values.slice(-quarter));
  return {
    slopeMbPerMin: Number(slope.toFixed(4)),
    firstQuarterMb: Number(first.toFixed(2)),
    lastQuarterMb: Number(last.toFixed(2)),
    growth: first === 0 ? 0 : Number((last / first - 1).toFixed(4)),
  };
}

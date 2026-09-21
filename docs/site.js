// Phramark results site: renders docs/results.json (written by
// `php benchmark/scripts/summary.php --json`, the same data as the README's
// Markdown tables) as sortable tables. The helpers are pure so
// benchmark/workloads/admin/unit/site.test.mjs can cover them without a DOM.

import { cellId, cellLabel, renderBars, renderSmallMultiples, trendOf } from './charts.js';

export const ACTIONS = ['login', 'open-edit', 'save-edit', 'open-create', 'save-create', 'logout'];

// What each admin action is: one Playwright session per cell, a round of five
// seeded pages; every number is the median over those five (login and logout
// happen once per round). Shown under the first admin table and as the
// tooltip of each action column.
export const ACTION_DESCRIPTIONS = {
  login: 'Open the CMS login page, submit the credentials and wait until the admin shell has fully loaded (menus, document tree). Once per round.',
  'open-edit': 'Open the editor of an existing seeded page and wait until its form is ready: the read path of the admin, PHP bootstrap plus the form and the JS/CSS it pulls in.',
  'save-edit': 'Change the content of that open page, submit, and wait until the CMS has confirmed the save: the write path, validation, database writes, cache invalidation.',
  'open-create': 'Open the "new page" form and wait until it is ready, like open-edit but without loading an existing document.',
  'save-create': 'Fill in title and content, submit, and wait for the confirmation: the write path plus creating the row, alias/URL and tree placement. The created pages are removed afterwards, so every run starts from the same state.',
  logout: 'End the session and return to the login page.',
};

export function actionsNote() {
  return 'Wall is what the browser experienced end to end (network, rendering, JS); server is the PHP time of the main request(s) of the step, the number to compare between versions. Actions: '
    + ACTIONS.map((action) => `${action} — ${ACTION_DESCRIPTIONS[action]}`).join(' ');
}

const ms = { unit: 'ms', decimals: 2 };
const mib = { unit: 'MiB', decimals: 1 };

export const GUEST_COLUMNS = [
  { key: 'stack', label: 'Stack' },
  { key: 'version', label: 'Version' },
  { key: 'jit', label: 'JIT' },
  { key: 'rate', label: 'Offered rps', numeric: true },
  { key: 'rps', label: 'Achieved rps', numeric: true, decimals: 2 },
  { key: 'p50Ms', label: 'p50', numeric: true, ...ms },
  { key: 'p99Ms', label: 'p99', numeric: true, ...ms },
  { key: 'errors', label: 'Non-2xx', numeric: true },
  { key: 'phpScriptMedianMb', label: 'PHP peak/request (script median)', numeric: true, ...mib },
  { key: 'phpAllocP95Mb', label: 'PHP alloc peak p95', numeric: true, ...mib },
  { key: 'containerPeakMb', label: 'FPM container peak RSS', numeric: true, ...mib },
];

export const ADMIN_COLUMNS = [
  { key: 'stack', label: 'Stack' },
  { key: 'version', label: 'Version' },
  { key: 'jit', label: 'JIT' },
  ...ACTIONS.map((action) => ({ key: action, label: action, numeric: true, ...ms })),
  { key: 'totalWallMs', label: 'Total wall', numeric: true, unit: 'ms', decimals: 0 },
];

// One admin table per metric: wall time, server time and the memory sides.
export const ADMIN_METRICS = [
  { key: 'ms', title: 'Admin workload: wall time per action (median)', unit: 'ms', decimals: 2 },
  { key: 'serverMs', title: 'Admin workload: server time per action (median)', unit: 'ms', decimals: 2 },
  { key: 'phpPeakMb', title: 'Admin workload: PHP peak per action (script, median)', unit: 'MiB', decimals: 2 },
  { key: 'jsHeapUsedMb', title: 'Admin workload: frontend JS heap after the action (median)', unit: 'MiB', decimals: 2 },
  { key: 'domNodes', title: 'Admin workload: DOM nodes after the action (median)', unit: '', decimals: 0 },
];

// The exact components a row's container reported (versions.php --attach),
// shown as the Version cell's tooltip; "the default build" when a result
// predates the recording.
export function componentsText(row) {
  if (!Array.isArray(row.components) || row.components.length === 0) return '';
  return row.components.map((component) => `${component.name} ${component.version}`).join(', ');
}

export function guestRows(set) {
  return (set.guest ?? []).map((row) => ({ ...row, version: row.version ?? '', label: cellLabel(row, set.stacks), componentsText: componentsText(row) }));
}

// Flattens the per-action medians of one metric into columns so a table
// can sort by any action.
export function adminRows(set, metric) {
  return (set.admin ?? []).map((row) => {
    const flat = {
      stack: row.stack,
      version: row.version ?? '',
      label: cellLabel(row, set.stacks),
      componentsText: componentsText(row),
      jit: row.jit,
      recordedAt: row.recordedAt,
      totalWallMs: metric === 'ms' ? row.totalWallMs : null,
      containerPeakMb: row.containerPeakMb,
    };
    for (const action of ACTIONS) {
      flat[action] = row.actions?.[action]?.[metric] ?? null;
    }
    return flat;
  });
}

// Missing values sort last in either direction; numbers compare as numbers,
// everything else as text.
export function compareValues(a, b, numeric = false) {
  const missing = (value) => value === null || value === undefined || value === '';
  if (missing(a) && missing(b)) return 0;
  if (missing(a)) return 1;
  if (missing(b)) return -1;
  if (numeric) return Number(a) - Number(b);
  return String(a).localeCompare(String(b), 'en');
}

export function sortRows(rows, key, direction = 'asc', numeric = false) {
  if (!key) return [...rows];
  const sign = direction === 'desc' ? -1 : 1;
  return [...rows]
    .map((row, index) => ({ row, index }))
    .sort((a, b) => {
      const order = compareValues(a.row[key], b.row[key], numeric);
      // A missing value stays last regardless of direction.
      if (order !== 0 && (a.row[key] == null || b.row[key] == null)) return order;
      return order !== 0 ? sign * order : a.index - b.index;
    })
    .map((entry) => entry.row);
}

export function formatValue(value, column = {}) {
  if (value === null || value === undefined || value === '') return '–';
  if (typeof value === 'number') {
    const text = column.decimals === undefined ? String(value) : value.toFixed(column.decimals);
    return column.unit ? `${text} ${column.unit}` : text;
  }
  return String(value);
}

// The next sort state after a click on a column header.
export function nextSort(current, key) {
  if (current.key !== key) return { key, direction: 'asc' };
  return { key, direction: current.direction === 'asc' ? 'desc' : 'asc' };
}

function element(tag, attributes = {}, children = []) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (name === 'text') node.textContent = value;
    else node.setAttribute(name, value);
  }
  for (const child of children) node.append(child);
  return node;
}

export function renderTable(root, { title, columns, rows, note }) {
  const section = element('section', { class: 'table-section' });
  section.append(element('h2', { text: title }));
  if (note) section.append(element('p', { class: 'note', text: note }));
  const wrapper = element('div', { class: 'table-wrapper' });
  const table = element('table');
  const head = element('thead');
  const body = element('tbody');
  // Unsorted (the order the data came in: stack, then JIT) until a click.
  let sort = { key: null, direction: 'asc' };

  const draw = () => {
    head.replaceChildren();
    const headRow = element('tr');
    for (const column of columns) {
      const active = sort.key === column.key;
      const button = element('button', {
        type: 'button',
        class: `sort${active ? ` ${sort.direction}` : ''}`,
        'aria-sort': active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none',
        text: column.label,
        ...(ACTION_DESCRIPTIONS[column.key] ? { title: ACTION_DESCRIPTIONS[column.key] } : {}),
      });
      button.addEventListener('click', () => {
        sort = nextSort(sort, column.key);
        draw();
      });
      headRow.append(element('th', { scope: 'col', class: column.numeric ? 'numeric' : '' }, [button]));
    }
    head.append(headRow);
    body.replaceChildren();
    const column = columns.find((candidate) => candidate.key === sort.key);
    for (const row of sortRows(rows, sort.key, sort.direction, Boolean(column?.numeric))) {
      const tr = element('tr');
      for (const cell of columns) {
        const attributes = { class: cell.numeric ? 'numeric' : '' };
        if (cell.key === 'stack') attributes.title = row.label ?? '';
        if (cell.key === 'version') attributes.title = row.componentsText ?? '';
        tr.append(element('td', { ...attributes, text: formatValue(row[cell.key], cell) }));
      }
      body.append(tr);
    }
  };

  draw();
  table.append(head, body);
  wrapper.append(table);
  section.append(wrapper);
  root.append(section);
  return section;
}

export function renderStacks(root, stacks) {
  const rows = Object.entries(stacks ?? {}).map(([id, stack]) => ({
    id,
    label: stack.label,
    port: stack.port,
    framework: stack.framework,
    components: (stack.components ?? []).join(', '),
  }));
  renderTable(root, {
    title: 'Stacks',
    note: 'The framework column is what a warm category request actually executes, recorded by benchmark/scripts/trace-request and asserted against Phramark\\RuntimeProfile.',
    columns: [
      { key: 'id', label: 'ID' },
      { key: 'label', label: 'Label' },
      { key: 'port', label: 'Port', numeric: true },
      { key: 'framework', label: 'Request path (traced)' },
      { key: 'components', label: 'Components' },
    ],
    rows,
  });
}

// One row per stack: the exact component versions the containers reported
// (benchmark/scripts/versions.php), each linked to its source repository.
export function versionRows(versions) {
  return Object.entries(versions?.stacks ?? {}).map(([id, components]) => ({
    id,
    components: components.map((component) => `${component.name} ${component.version}`).join(', '),
    links: components,
  }));
}

export function renderVersions(root, versions) {
  if (!versions?.stacks) return;
  const runtime = versions.runtime ?? {};
  const section = renderTable(root, {
    title: 'Versions tested',
    note: `Recorded from the running containers on ${versions.recordedAt ?? '?'}: PHP ${runtime.php ?? '?'}, MySQL ${runtime.mysql ?? '?'}, ${runtime.nginx ?? '?'}, OPcache ${runtime.opcache ?? '?'}.`,
    columns: [
      { key: 'id', label: 'Stack' },
      { key: 'components', label: 'Components (exact versions)' },
    ],
    rows: versionRows(versions),
  });
  // Replace the plain component text with links to the source repositories.
  for (const row of section.querySelectorAll('tbody tr')) {
    const id = row.firstElementChild.textContent;
    const cell = row.lastElementChild;
    cell.replaceChildren();
    cell.style.whiteSpace = 'normal';
    (versions.stacks[id] ?? []).forEach((component, index) => {
      if (index > 0) cell.append(', ');
      const label = `${component.name} ${component.version}`;
      cell.append(component.source ? element('a', { href: component.source, text: label }) : label);
    });
  }
}

// Rows for one admin action chart: wall time with the server share inside,
// and the spread of the wall time across repetitions.
export function adminActionRows(set, action) {
  return (set.admin ?? []).map((row) => ({
    stack: row.stack,
    version: row.version ?? '',
    jit: row.jit,
    value: row.actions?.[action]?.ms ?? null,
    server: row.actions?.[action]?.serverMs ?? null,
    repeats: row.repeats ? { n: row.repeats.n, metrics: { value: row.repeats.metrics?.[`${action}.ms`] ?? null } } : null,
  }));
}

// One panel per stack (and version) with one line per JIT mode from a
// series picker.
export function seriesPanels(rows, stacks, pick) {
  const panels = new Map();
  for (const row of rows) {
    const line = pick(row);
    if (!line) continue;
    const id = cellId(row);
    if (!panels.has(id)) panels.set(id, { stack: row.stack, version: row.version ?? '', label: cellLabel(row, stacks), lines: [] });
    panels.get(id).lines.push({ jit: row.jit, ...line });
  }
  return [...panels.values()].map((panel) => ({ ...panel, lines: panel.lines.sort((a, b) => a.jit.localeCompare(b.jit)) }));
}

// The measuring error per cell: repetitions and coefficient of variation of
// the headline metrics, so a difference between stacks can be judged against
// the noise of this host.
export function errorRows(set) {
  const cv = (repeats, metric) => {
    const stats = repeats?.metrics?.[metric];
    return stats && stats.cv !== null && stats.cv !== undefined ? Math.round(stats.cv * 1000) / 10 : null;
  };
  const admin = new Map((set.admin ?? []).map((row) => [`${cellId(row)}|${row.jit}`, row]));
  return (set.guest ?? []).map((row) => {
    const adminRow = admin.get(`${cellId(row)}|${row.jit}`);
    return {
      stack: row.stack,
      version: row.version ?? '',
      label: cellLabel(row, set.stacks),
      jit: row.jit,
      guestRuns: row.repeats?.n ?? 1,
      p50Cv: cv(row.repeats, 'p50Ms'),
      p99Cv: cv(row.repeats, 'p99Ms'),
      rpsCv: cv(row.repeats, 'rps'),
      adminRuns: adminRow ? adminRow.repeats?.n ?? 1 : null,
      loginCv: cv(adminRow?.repeats, 'login.ms'),
      saveEditCv: cv(adminRow?.repeats, 'save-edit.ms'),
      saveCreateCv: cv(adminRow?.repeats, 'save-create.ms'),
      totalCv: cv(adminRow?.repeats, 'totalWallMs'),
    };
  });
}

export function renderCharts(root, set) {
  const section = element('section', { class: 'charts' });
  section.append(element('h2', { text: 'Charts' }));
  renderBars(section, {
    title: 'Guest latency at the offered rate: p50 (bar) and p99 (tick), lower is better',
    note: 'Whiskers are the min–max of p50 across repetitions of the cell where more than one run exists; without them the cell was run once and its error is unknown.',
    rows: guestRows(set), key: 'p50Ms', range: 'p99Ms', spreadMetric: 'p50Ms', stacks: set.stacks, unit: 'ms',
  });
  renderBars(section, {
    title: 'Guest PHP-FPM container peak RSS during the run',
    rows: guestRows(set), key: 'containerPeakMb', spreadMetric: 'containerPeakMb', stacks: set.stacks, unit: 'MiB', decimals: 1,
  });
  for (const action of ACTIONS) {
    renderBars(section, {
      title: `Admin ${action}: wall time (bar) and server time (inner bar), median per session`,
      rows: adminActionRows(set, action), key: 'value', inner: 'server', spreadMetric: 'value', stacks: set.stacks, unit: 'ms',
    });
  }
  root.append(section);
}

export function renderDynamics(root, set) {
  const section = element('section', { class: 'charts' });
  section.append(element('h2', { text: 'Memory over time: does it grow?' }));
  section.append(element('p', { class: 'note', text: 'Each line is the latest run of the cell. Guest: the PHP peak per request (median of each of 40 time slices) and the container RSS sampled every second, over the wrk2 run. Admin: the browser JS heap, DOM nodes and PHP peak after each step of the session, in order (login, 5 × open-edit/save-edit, 5 × open-create/save-create, logout). The trend compares the last quarter with the first; "flat" is within ±3 %.' }));
  const guest = guestRows(set);
  renderSmallMultiples(section, {
    title: 'Guest: PHP peak memory per request over the run',
    panels: seriesPanels(guest, set.stacks, (row) => row.series?.phpPeak && { points: row.series.phpPeak.points.map((p) => ({ x: p.t, y: p.medianMb, note: `${p.requests} requests, max ${p.maxMb}` })), trend: row.series.phpPeak.trend }),
    unit: 'MiB', xLabel: 's',
  });
  renderSmallMultiples(section, {
    title: 'Guest: PHP-FPM container RSS over the run',
    panels: seriesPanels(guest, set.stacks, (row) => row.series?.containerRss && { points: row.series.containerRss.points.map((p) => ({ x: p.t, y: p.mb })), trend: row.series.containerRss.trend }),
    unit: 'MiB', xLabel: 's',
  });
  const admin = set.admin ?? [];
  const stepPoints = (row, key) => (row.series?.steps ?? []).map((step) => ({ x: step.index, y: step[key], note: `${step.action} ${step.label}` })).filter((p) => p.y !== null && p.y !== undefined);
  for (const [key, title, unit, decimals] of [['jsHeapUsedMb', 'Admin: browser JS heap after each step', 'MiB', 1], ['domNodes', 'Admin: DOM nodes after each step', 'nodes', 0], ['phpPeakMb', 'Admin: PHP peak of the largest request in each step', 'MiB', 2]]) {
    renderSmallMultiples(section, {
      title,
      panels: seriesPanels(admin, set.stacks, (row) => { const points = stepPoints(row, key); return points.length > 1 ? { points, trend: trendOf(points) } : null; }),
      unit, xLabel: 'step', decimals, slopeUnit: `${unit}/step`,
    });
  }
  renderSmallMultiples(section, {
    title: 'Admin: PHP peak memory per request over the session',
    panels: seriesPanels(admin, set.stacks, (row) => row.series?.phpPeak && { points: row.series.phpPeak.points.map((p) => ({ x: p.t, y: p.medianMb, note: `${p.requests} requests, max ${p.maxMb}` })), trend: row.series.phpPeak.trend }),
    unit: 'MiB', xLabel: 's', decimals: 2,
  });
  root.append(section);
}

export function renderErrors(root, set) {
  renderTable(root, {
    title: 'Measuring error: spread across repetitions',
    note: "CV is the coefficient of variation (standard deviation / mean, in %) of the cell's metric across its repetitions; it needs at least two runs (REPEATS=N benchmark/scripts/matrix). A difference between two stacks smaller than about twice their CV is noise on this host. Within one run, wrk2 gives the full latency histogram and the admin report the p95/min/max of each action's five repetitions.",
    columns: [
      { key: 'stack', label: 'Stack' },
      { key: 'version', label: 'Version' },
      { key: 'jit', label: 'JIT' },
      { key: 'guestRuns', label: 'Guest runs', numeric: true },
      { key: 'p50Cv', label: 'p50 CV %', numeric: true, decimals: 1 },
      { key: 'p99Cv', label: 'p99 CV %', numeric: true, decimals: 1 },
      { key: 'rpsCv', label: 'rps CV %', numeric: true, decimals: 1 },
      { key: 'adminRuns', label: 'Admin runs', numeric: true },
      { key: 'loginCv', label: 'login CV %', numeric: true, decimals: 1 },
      { key: 'saveEditCv', label: 'save-edit CV %', numeric: true, decimals: 1 },
      { key: 'saveCreateCv', label: 'save-create CV %', numeric: true, decimals: 1 },
      { key: 'totalCv', label: 'total wall CV %', numeric: true, decimals: 1 },
    ],
    rows: errorRows(set),
  });
}

export function render(set, root) {
  root.replaceChildren();
  const generated = document.querySelector('[data-generated]');
  if (generated) generated.textContent = set.generatedAt ?? '';
  renderCharts(root, set);
  renderDynamics(root, set);
  renderErrors(root, set);
  renderVersions(root, set.versions);
  renderStacks(root, set.stacks);
  renderTable(root, {
    title: 'Guest workload (wrk2, constant offered rate)',
    note: 'Latest run per stack, version, JIT mode and offered rate. Version is what the stack was built from, read from the components its container reported: the CMS release (3.5.8, 11.4.7), a working copy as directory@commit (3.5.9 ../evolution@3f9ea9220), a branch as branch@commit, and the extensions the harness adds (aLatteX, aPhalcon, Gantry); a run pinned with benchmark/scripts/matrix --versions=evo@3.5.8 and a default build of the same release are one cell. Hover the version for the exact components. wrk2 reports coordinated-omission-corrected latency, so an offered rate above capacity shows queueing time. Memory: PHP script peak per request (median), allocator peak (p95), FPM container peak RSS.',
    columns: GUEST_COLUMNS,
    rows: guestRows(set),
  });
  for (const metric of ADMIN_METRICS) {
    const columns = ADMIN_COLUMNS
      .filter((column) => metric.key === 'ms' || column.key !== 'totalWallMs')
      .map((column) => (ACTIONS.includes(column.key) ? { ...column, unit: metric.unit, decimals: metric.decimals } : column));
    if (metric.key === 'phpPeakMb') columns.push({ key: 'containerPeakMb', label: 'FPM container peak', numeric: true, ...mib });
    renderTable(root, {
      title: metric.title,
      columns,
      rows: adminRows(set, metric.key),
      note: metric.key === 'ms' ? actionsNote() : undefined,
    });
  }
}

export async function boot() {
  const root = document.querySelector('#results');
  try {
    const response = await fetch('results.json', { cache: 'no-cache' });
    if (!response.ok) throw new Error(`results.json answered HTTP ${response.status}`);
    render(await response.json(), root);
  } catch (error) {
    root.replaceChildren(element('p', { class: 'error', text: `Could not load results.json: ${error.message}` }));
  }
}

if (typeof document !== 'undefined' && document.querySelector('#results')) {
  boot();
}

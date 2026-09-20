// Workload configuration from the environment. Every knob has a default that
// targets the evo-parser stack published by benchmark/compose.yaml.

const env = process.env;

function integer(name, fallback) {
  const value = Number.parseInt(env[name] ?? '', 10);
  return Number.isFinite(value) && value > 0 ? value : fallback;
}

export const config = Object.freeze({
  // 127.0.0.1 rather than localhost: Chromium's dual-stack connect to Docker
  // Desktop's port proxy stalls for 30 s per connection on some hosts.
  baseUrl: (env.PHRAMARK_BASE_URL ?? 'http://127.0.0.1:8080').replace(/\/$/, ''),
  stack: env.PHRAMARK_STACK ?? 'evo-parser',
  jit: env.PHRAMARK_JIT ?? 'unknown',
  rounds: integer('PHRAMARK_ROUNDS', 1),
  // An untimed pass over every code path first, so a freshly restarted
  // PHP-FPM (cold OPcache) does not land in round 1.
  warmup: (env.PHRAMARK_WARMUP ?? '1') !== '0',
  pages: integer('PHRAMARK_PAGES', 5),
  resultsDir: env.PHRAMARK_RESULTS_DIR ?? new URL('../../../results/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'),
  username: env.PHRAMARK_ADMIN_USER ?? 'benchmark',
  // Same admin user on every stack; TYPO3 enforces a password policy, so its
  // setup uses a different default (TYPO3_ADMIN_PASSWORD in compose.yaml).
  password: env.PHRAMARK_ADMIN_PASSWORD ?? ((env.PHRAMARK_STACK ?? 'evo-parser') === 'typo3' ? 'Benchmark1!' : 'benchmark-admin'),
  // Mirrors Phramark\FixturePlan::ADMIN_ROOT_ID and adminPageId().
  adminRootId: integer('PHRAMARK_ADMIN_ROOT_ID', 10103),
});

export function adminPageIds(count = config.pages, rootId = config.adminRootId) {
  return Array.from({ length: count }, (_, index) => rootId + index + 1);
}

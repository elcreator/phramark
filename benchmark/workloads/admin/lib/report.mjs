import fs from 'node:fs';
import path from 'node:path';
import { METRICS, formatReport, summarize } from './timeline.mjs';

export function buildReport(config, steps, extra = {}) {
  const summary = {};
  for (const metric of METRICS) {
    const stats = summarize(steps, metric.key);
    if (Object.keys(stats).length > 0) summary[metric.key] = stats;
  }
  return {
    workload: 'admin',
    stack: config.stack,
    version: config.version ?? '',
    jit: config.jit,
    baseUrl: config.baseUrl,
    rounds: config.rounds,
    pages: config.pages,
    recordedAt: new Date().toISOString(),
    ...extra,
    summary,
    steps,
  };
}

// A version label as a file name segment after the stack id (the rule of
// Phramark\VersionSpec::fileTag and benchmark/scripts/lib.sh version_suffix).
export function versionSuffix(version) {
  return version ? `~${version.replace(/[^A-Za-z0-9@.+_-]/g, '_')}` : '';
}

export function reportBase(config) {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  return path.join(config.resultsDir, `admin-${config.stack}${versionSuffix(config.version)}-jit-${config.jit}-${stamp}`);
}

export function writeReport(config, steps, extra = {}) {
  fs.mkdirSync(config.resultsDir, { recursive: true });
  const base = reportBase(config);
  return writeReportFiles(base, buildReport(config, steps, extra));
}

export function writeReportFiles(base, report) {
  fs.writeFileSync(`${base}.json`, JSON.stringify(report, null, 2) + '\n');
  const text = formatReport(report);
  fs.writeFileSync(`${base}.txt`, text);
  return { json: `${base}.json`, txt: `${base}.txt`, text };
}

// Attributes PHP-side peak memory (memory-summary.php --json, keyed by the
// X-Phramark-Step header "action:label") to the recorded steps.
export function mergePhpMemory(report, memorySummary, containerPeakMb) {
  const steps = report.steps.map((step) => {
    const stat = memorySummary.steps?.[`${step.action}:${step.label}`];
    // phpPeakMb: exact script peak (memory_get_peak_usage(false)); phpPeakRealMb:
    // what the allocator took from the OS (2 MiB granularity).
    return stat ? { ...step, phpPeakMb: mib(stat.peak.max), phpPeakRealMb: mib(stat.peak_real.max), phpRequests: stat.requests } : step;
  });
  const merged = { ...report, steps, phpMemory: memorySummary };
  if (typeof containerPeakMb === 'number') merged.containerPeakMb = containerPeakMb;
  const summary = {};
  for (const metric of METRICS) {
    const stats = summarize(steps, metric.key);
    if (Object.keys(stats).length > 0) summary[metric.key] = stats;
  }
  merged.summary = summary;
  return merged;
}

function mib(bytes) {
  return Math.round((bytes / 1048576) * 100) / 100;
}

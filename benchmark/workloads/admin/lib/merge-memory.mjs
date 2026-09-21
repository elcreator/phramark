// Merges the PHP-side memory summary of a run into its admin report and
// rewrites the .json/.txt pair in place.
//
//   node lib/merge-memory.mjs REPORT.json MEMORY-SUMMARY.json [CONTAINER_PEAK_MIB]

import fs from 'node:fs';
import { mergePhpMemory, writeReportFiles } from './report.mjs';

const [reportPath, summaryPath, containerPeak, containerFootprint] = process.argv.slice(2);
if (!reportPath || !summaryPath) {
  console.error('Usage: merge-memory.mjs REPORT.json MEMORY-SUMMARY.json [CONTAINER_PEAK_MIB]');
  process.exit(2);
}
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const summary = JSON.parse(fs.readFileSync(summaryPath, 'utf8'));
const merged = mergePhpMemory(report, summary, containerPeak ? Number.parseFloat(containerPeak) : undefined, containerFootprint ? Number.parseFloat(containerFootprint) : undefined);
const { text } = writeReportFiles(reportPath.replace(/\.json$/, ''), merged);
console.log(text);

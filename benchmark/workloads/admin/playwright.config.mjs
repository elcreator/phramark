import { defineConfig } from '@playwright/test';

// One sequential admin session at a time: the workload measures latency of a
// single editor, not concurrent throughput (that is the wrk2 guest workload).
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 10 * 60 * 1000,
  reporter: [['list']],
  use: {
    headless: true,
    locale: 'en-US',
    trace: 'off',
    video: 'off',
    screenshot: 'only-on-failure',
  },
});

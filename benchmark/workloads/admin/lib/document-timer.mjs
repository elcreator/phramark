// Accumulates server time of top-level document requests (HTML navigations
// and form posts, including redirects) between two marks. Static assets are
// excluded so the "serverMs" of a step is what the CMS spent answering, not
// what the browser spent fetching CSS and scripts.

export class DocumentTimer {
  constructor(page) {
    this.page = page;
    this.entries = [];
    page.on('requestfinished', (request) => {
      if (request.resourceType() !== 'document') return;
      const timing = request.timing();
      if (!timing || timing.responseEnd < 0 || timing.requestStart < 0) return;
      this.entries.push({
        method: request.method(),
        url: request.url(),
        ms: timing.responseEnd - timing.requestStart,
      });
    });
  }

  mark() {
    this.entries = [];
  }

  // Lets the requestfinished events of the step drain before reading them.
  async collect() {
    await this.page.waitForTimeout(0);
    const entries = this.entries;
    this.entries = [];
    return {
      serverMs: Math.round(entries.reduce((sum, entry) => sum + entry.ms, 0) * 100) / 100,
      requests: entries.length,
    };
  }
}

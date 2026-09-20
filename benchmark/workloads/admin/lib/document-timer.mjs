// Accumulates server time of top-level document requests (HTML navigations
// and form posts, including redirects) between two marks. Static assets are
// excluded so the "serverMs" of a step is what the CMS spent answering, not
// what the browser spent fetching CSS and scripts.
//
// A CMS whose editor saves through its own AJAX framework instead of a form
// post (Winter: every handler request carries X-Winter-Request-Handler;
// MODX: every manager processor call goes to /connectors/index.php) does
// the same server work in an XHR; those requests count as well, so a save
// step is measured alike whether the CMS posts a form or an XHR.

const AJAX_HANDLER_HEADERS = ['x-winter-request-handler'];
const AJAX_ENDPOINTS = [/\/connectors\/index\.php(\?|$)/];

export function isServerWork(request) {
  if (request.resourceType() === 'document') return true;
  const headers = request.headers();
  if (AJAX_HANDLER_HEADERS.some((name) => name in headers)) return true;
  return AJAX_ENDPOINTS.some((pattern) => pattern.test(request.url()));
}

export class DocumentTimer {
  constructor(page) {
    this.page = page;
    this.entries = [];
    page.on('requestfinished', (request) => {
      if (!isServerWork(request)) return;
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

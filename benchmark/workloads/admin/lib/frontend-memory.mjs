// Frontend-side memory of the admin session: the renderer's JS heap and DOM
// size after each step, read through the Chrome DevTools Protocol. This is
// what the CMS manager UI costs the browser, as opposed to what PHP spends
// answering (see memory-prepend.php on the server side).

export class FrontendMemory {
  constructor(page) {
    this.page = page;
    this.session = null;
  }

  async enable() {
    try {
      this.session = await this.page.context().newCDPSession(this.page);
      await this.session.send('Performance.enable');
    } catch {
      this.session = null; // not Chromium: no frontend memory metrics
    }
  }

  async snapshot() {
    if (!this.session) return {};
    const { metrics } = await this.session.send('Performance.getMetrics');
    const value = (name) => metrics.find((metric) => metric.name === name)?.value ?? 0;
    return {
      jsHeapUsedMb: round(value('JSHeapUsedSize') / 1048576),
      jsHeapTotalMb: round(value('JSHeapTotalSize') / 1048576),
      domNodes: value('Nodes'),
      jsListeners: value('JSEventListeners'),
    };
  }
}

function round(value) {
  return Math.round(value * 100) / 100;
}

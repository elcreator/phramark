// Evolution CMS 3.x manager adapter. The manager is a shell page with an
// iframe named "mainframe"; document forms live in the iframe and post to
// index.php?a=5, which redirects back to the editor (stay = continue editing).

import { adminPageIds } from '../config.mjs';

const EDITOR_URL = /[?&]a=27\b/;

export class EvolutionAdapter {
  static supports(stack) {
    return /^evo-/.test(stack);
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
  }

  url(query) {
    return `${this.config.baseUrl}/manager/index.php?${query}`;
  }

  pageIds() {
    return adminPageIds(this.config.pages, this.config.adminRootId);
  }

  frame() {
    const frame = this.page.frame({ name: 'mainframe' });
    if (!frame) throw new Error('The manager mainframe is not loaded; is the session logged in?');
    return frame;
  }

  async login() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/manager/`);
    await this.page.fill('#username', this.config.username);
    await this.page.fill('#password', this.config.password);
    await this.page.click('#submitButton');
    await this.page.waitForURL(/\/manager\/(#.*)?$/);
    const element = await this.page.waitForSelector('iframe#mainframe');
    const mainframe = await element.contentFrame();
    await mainframe.waitForLoadState('load');
    await this.page.waitForSelector('a[href="index.php?a=8"]', { state: 'attached' });
    return this.timer.collect();
  }

  async openEditor(id) {
    this.timer.mark();
    await this.frame().goto(this.url(`a=27&id=${id}`));
    await this.frame().waitForSelector('input[name="pagetitle"]');
    return this.timer.collect();
  }

  async openCreator() {
    this.timer.mark();
    await this.frame().goto(this.url(`a=4&pid=${this.config.adminRootId}`));
    await this.frame().waitForSelector('input[name="pagetitle"]');
    return this.timer.collect();
  }

  async readTitle() {
    return this.frame().inputValue('input[name="pagetitle"]');
  }

  async readContent() {
    return this.frame().inputValue('textarea#ta');
  }

  async fillTitle(title) {
    await this.frame().fill('input[name="pagetitle"]', title);
  }

  async fillContent(content) {
    await this.frame().fill('textarea#ta', content);
  }

  // After a save the editor of the saved document is shown again.
  async savedTitle() {
    return this.readTitle();
  }

  async savedContent() {
    return this.readContent();
  }

  // Save and continue editing: the POST redirects to the editor of the saved
  // document, so the id of a created document is read back from its URL.
  async save() {
    this.timer.mark();
    const frame = this.frame();
    // The "stay" select is hidden behind a styled dropdown; set it directly.
    await frame.evaluate(() => { document.querySelector('select[name="stay"]').value = '2'; });
    const posted = this.page.waitForResponse((response) => response.request().method() === 'POST' && /\/manager\//.test(response.url()));
    // The editor URL may already match after an edit, so wait for the actual
    // navigation that the POST's redirect triggers, not just for the URL.
    const navigated = frame.waitForNavigation({ url: EDITOR_URL, waitUntil: 'load' });
    await frame.click('#Button1');
    const response = await posted;
    if (response.status() >= 400) throw new Error(`Save failed with HTTP ${response.status()}`);
    await navigated;
    await frame.waitForSelector('input[name="pagetitle"]');
    const id = Number.parseInt(new URL(frame.url()).searchParams.get('id') ?? '', 10);
    return { ...(await this.timer.collect()), id };
  }

  async logout() {
    this.timer.mark();
    await this.page.goto(this.url('a=8'));
    await this.page.waitForSelector('#username');
    return this.timer.collect();
  }
}

// Winter CMS 1.2 backend adapter. Frame-less pages: /backend/backend/auth/signin
// (a plain form post), /backend/phramark/benchmark/pages/update/{id} and
// /create (the Form behavior of benchmark/implementations/winter). Saving is
// Winter's AJAX framework: the toolbar's Save button posts the form to the
// onSave handler with the X-Winter-Request-Handler header. On update the
// form stays open and shows a success flash; on create the handler answers
// with a redirect to the editor of the new record, which the framework
// follows as a document navigation, so the id is read from the URL.

import { adminPageIds } from '../config.mjs';

const TITLE = 'input[name="Page[title]"]';
const CONTENT = 'textarea[name="Page[content]"]';
const SAVE = '.form-buttons button.wn-icon-save';

export class WinterAdapter {
  static supports(stack) {
    return stack === 'winter';
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
  }

  pageIds() {
    return adminPageIds(this.config.pages, this.config.adminRootId);
  }

  backendUrl(path) {
    return `${this.config.baseUrl}/backend/${path}`;
  }

  async login() {
    this.timer.mark();
    await this.page.goto(this.backendUrl('backend/auth/signin'));
    await this.page.fill('input[name="login"]', this.config.username);
    await this.page.fill('input[name="password"]', this.config.password);
    await Promise.all([
      this.page.waitForURL((url) => !url.pathname.includes('/auth/signin')),
      this.page.click('button.login-button'),
    ]);
    await this.page.waitForLoadState('load');
    return this.timer.collect();
  }

  async openEditor(id) {
    this.timer.mark();
    await this.page.goto(this.backendUrl(`phramark/benchmark/pages/update/${id}`));
    await this.page.waitForSelector(TITLE);
    return this.timer.collect();
  }

  async openCreator() {
    this.timer.mark();
    await this.page.goto(this.backendUrl('phramark/benchmark/pages/create'));
    await this.page.waitForSelector(TITLE);
    return this.timer.collect();
  }

  async readTitle() {
    return this.page.inputValue(TITLE);
  }

  async readContent() {
    return this.page.inputValue(CONTENT);
  }

  async fillTitle(title) {
    await this.page.fill(TITLE, title);
  }

  async fillContent(content) {
    await this.page.fill(CONTENT, content);
  }

  async save({ create = false } = {}) {
    this.timer.mark();
    this.verified = create;
    if (create) {
      await Promise.all([
        this.page.waitForURL(/\/pages\/update\/\d+$/),
        this.page.click(SAVE),
      ]);
      await this.page.waitForLoadState('load');
      await this.page.waitForSelector(TITLE);
      const id = Number.parseInt(this.page.url().match(/\/update\/(\d+)$/)?.[1] ?? '', 10);
      return { ...(await this.timer.collect()), id };
    }
    // A previous flash may still be fading out; count only a new one.
    await this.page.evaluate(() => document.querySelectorAll('.flash-message').forEach((el) => el.remove()));
    await this.page.click(SAVE);
    await this.page.waitForSelector('.flash-message.success');
    return this.timer.collect();
  }

  // An update save leaves the form as the workload filled it, so the saved
  // values are re-read from the server once (outside the timed step and,
  // via its own step header, outside the step's memory attribution). After
  // a create the editor of the new record has already been loaded.
  async savedTitle() {
    if (!this.verified) {
      await this.page.setExtraHTTPHeaders({ 'X-Phramark-Step': 'verify' });
      await this.page.reload();
      await this.page.waitForSelector(TITLE);
      this.verified = true;
    }
    return this.readTitle();
  }

  async savedContent() {
    await this.savedTitle();
    return this.readContent();
  }

  async logout() {
    this.timer.mark();
    await this.page.goto(this.backendUrl('backend/auth/signout'));
    await this.page.waitForSelector('input[name="login"]');
    return this.timer.collect();
  }
}

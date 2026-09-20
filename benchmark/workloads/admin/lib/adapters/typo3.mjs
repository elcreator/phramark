// TYPO3 14 backend adapter. The backend is a shell with a content iframe
// ("list_frame"); every module route carries a per-session token that is
// read from the module menu after login. Pages and their content are separate
// records: a seeded page (uid = admin page id) has one text element with the
// same uid (see benchmark/fixtures/cms/typo3-admin-seed.php), and FormEngine
// edits both in one form. Creating a page therefore means two saves: the page
// record, then a text element on it; both are counted in the save-create step.

import { adminPageIds } from '../config.mjs';

export class Typo3Adapter {
  static supports(stack) {
    return stack === 'typo3';
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
    this.editToken = null;
    this.recordsUrl = null;
    this.logoutUrl = null;
  }

  pageIds() {
    return adminPageIds(this.config.pages, this.config.adminRootId);
  }

  frame() {
    const frame = this.page.frame({ name: 'list_frame' });
    if (!frame) throw new Error('The TYPO3 content frame is not loaded; is the session logged in?');
    return frame;
  }

  editUrl(query) {
    return `${this.config.baseUrl}/typo3/record/edit?token=${this.editToken}&${query}`;
  }

  // Login, then read the module and record-edit tokens the session needs:
  // the records module link from the module menu, and the edit token from
  // any edit link the records module renders for the admin folder.
  async login() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/typo3/`);
    await this.page.fill('#t3-username', this.config.username);
    await this.page.fill('#t3-password', this.config.password);
    await this.page.click('#t3-login-submit');
    await this.page.waitForURL(/\/typo3\/module\//);
    const records = await this.page.getAttribute('a[data-modulemenu-identifier="records"]', 'href');
    this.recordsUrl = `${this.config.baseUrl}${records}`;
    this.logoutUrl = `${this.config.baseUrl}${await this.page.getAttribute('a[href*="/typo3/logout"]', 'href')}`;
    await this.page.waitForSelector('iframe[name="list_frame"]');
    await this.frame().goto(`${this.recordsUrl}&id=${this.config.adminRootId}`);
    const edit = await this.frame().getAttribute('a[href*="record/edit"]', 'href');
    this.editToken = new URL(edit, this.config.baseUrl).searchParams.get('token');
    return this.timer.collect();
  }

  async openEditor(id) {
    this.timer.mark();
    await this.frame().goto(this.editUrl(`edit[pages][${id}]=edit&edit[tt_content][${id}]=edit`));
    await this.frame().waitForSelector(`[data-formengine-input-name="data[pages][${id}][title]"]`);
    await this.frame().waitForSelector('.ck-editor__editable');
    this.current = { page: id, content: id };
    return this.timer.collect();
  }

  async openCreator() {
    this.timer.mark();
    await this.frame().goto(this.editUrl(`edit[pages][${this.config.adminRootId}]=new`));
    await this.frame().waitForSelector('[data-formengine-input-name$="[title]"]');
    this.current = { page: null, content: null };
    return this.timer.collect();
  }

  async readTitle() {
    return this.frame().inputValue('[data-formengine-input-name$="[title]"]');
  }

  async readContent() {
    return this.frame().inputValue('textarea[name$="[bodytext]"]');
  }

  async fillTitle(title) {
    await this.frame().fill('[data-formengine-input-name$="[title]"]', title);
  }

  // The RTE (CKEditor 5) mirrors its content into the hidden bodytext textarea.
  // A new page has no content element yet; its body is written in save().
  async fillContent(content) {
    if (this.current.page === null) {
      this.pendingContent = content;
      return;
    }
    await this.frame().fill('.ck-editor__editable', content.replace(/<[^>]+>/g, ''));
  }

  async submitForm() {
    const frame = this.frame();
    const navigated = frame.waitForNavigation({ waitUntil: 'load' });
    await frame.click('button[name="_savedok"]');
    await navigated;
  }

  async save({ create = false, content } = {}) {
    this.timer.mark();
    await this.submitForm();
    const frame = this.frame();
    if (!create) {
      await frame.waitForSelector('[data-formengine-input-name$="[title]"]');
      return this.timer.collect();
    }
    // The page now exists; FormEngine reloads its editor with the real uid.
    const id = Number.parseInt(decodeURIComponent(frame.url()).match(/edit\[pages\]\[(\d+)\]=edit/)?.[1] ?? '', 10);
    if (!Number.isFinite(id)) throw new Error(`Could not read the created page uid from ${frame.url()}`);
    await frame.goto(this.editUrl(`edit[tt_content][${id}]=new&defVals[tt_content][CType]=text`));
    await frame.waitForSelector('.ck-editor__editable');
    await frame.fill('[data-formengine-input-name$="[header]"]', `Body of page ${id}`);
    await frame.fill('.ck-editor__editable', (content ?? this.pendingContent ?? '').replace(/<[^>]+>/g, ''));
    await this.submitForm();
    await frame.goto(this.editUrl(`edit[pages][${id}]=edit`));
    await frame.waitForSelector(`[data-formengine-input-name="data[pages][${id}][title]"]`);
    this.current = { page: id, content: null };
    return { ...(await this.timer.collect()), id };
  }

  // After a save FormEngine shows the form again with the saved values.
  async savedTitle() {
    return this.readTitle();
  }

  async savedContent() {
    return this.readContent();
  }

  async logout() {
    this.timer.mark();
    await this.page.goto(this.logoutUrl);
    await this.page.waitForSelector('#t3-username');
    return this.timer.collect();
  }
}

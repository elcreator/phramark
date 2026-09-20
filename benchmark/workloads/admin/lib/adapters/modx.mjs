// MODX Revolution 3 manager adapter. The manager is an ExtJS application:
// /manager/ (plain login form post), /manager/?a=resource/update&id=N and
// ?a=resource/create&parent=N render the resource form with its record
// inline; the resource tree and every save are XHRs to
// /connectors/index.php (action=Resource/Update or Resource/Create). An
// update save leaves the form open and echoes the saved record back into
// it; a create save answers with the new id and the manager then loads the
// editor of the new resource as a document navigation, so the id is read
// from that URL.

import { adminPageIds } from '../config.mjs';

const TITLE = 'input[name="pagetitle"]';
const CONTENT = 'textarea[name="ta"]';
const SAVE = '#modx-abtn-save';
const CONNECTOR = /\/connectors\/index\.php/;

// The processor a connector request asks for: the resource form posts
// multipart (file uploads are possible), other manager calls urlencoded.
export function postedAction(postData) {
  const body = postData ?? '';
  const multipart = /name="action"\r?\n\r?\n([^\r\n]+)/.exec(body);
  if (multipart) return multipart[1];
  const encoded = /(?:^|&)action=([^&]+)/.exec(body);
  return encoded ? decodeURIComponent(encoded[1]) : null;
}

export class ModxAdapter {
  static supports(stack) {
    return stack === 'modx';
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
  }

  pageIds() {
    return adminPageIds(this.config.pages, this.config.adminRootId);
  }

  managerUrl(query = '') {
    return `${this.config.baseUrl}/manager/${query ? `?${query}` : ''}`;
  }

  // A connector response for one processor action, checked for success so a
  // rejected save (validation error, expired token) fails the run instead
  // of being timed as if it had worked.
  async connectorResponse(action, trigger) {
    const responded = this.page.waitForResponse((response) => {
      const request = response.request();
      return CONNECTOR.test(response.url()) && request.method() === 'POST' && postedAction(request.postData()) === action;
    });
    await trigger();
    const response = await responded;
    const body = await response.json();
    if (!body.success) throw new Error(`${action} failed: ${body.message || JSON.stringify(body)}`);
    return body;
  }

  async login() {
    this.timer.mark();
    await this.page.goto(this.managerUrl());
    await this.page.fill('#modx-login-username', this.config.username);
    await this.page.fill('#modx-login-password', this.config.password);
    await Promise.all([
      this.page.waitForURL(/\/manager\/(\?.*)?$/),
      this.page.click('#modx-login-btn'),
    ]);
    // The shell is usable once the resource tree (an XHR) has rendered.
    await this.page.waitForSelector('#modx-resource-tree .x-tree-node');
    return this.timer.collect();
  }

  async openEditor(id) {
    this.timer.mark();
    await this.page.goto(this.managerUrl(`a=resource/update&id=${id}`));
    await this.page.waitForSelector(SAVE);
    await this.page.waitForFunction((selector) => document.querySelector(selector)?.value !== '', TITLE);
    return this.timer.collect();
  }

  async openCreator() {
    this.timer.mark();
    await this.page.goto(this.managerUrl(`a=resource/create&context_key=web&parent=${this.config.adminRootId}`));
    await this.page.waitForSelector(SAVE);
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
      await this.connectorResponse('Resource/Create', () => this.page.click(SAVE));
      await this.page.waitForURL(/[?&]a=resource\/update\b/);
      await this.page.waitForLoadState('load');
      await this.page.waitForSelector(SAVE);
      const id = Number.parseInt(new URL(this.page.url()).searchParams.get('id') ?? '', 10);
      return { ...(await this.timer.collect()), id };
    }
    await this.connectorResponse('Resource/Update', () => this.page.click(SAVE));
    return this.timer.collect();
  }

  // An update save echoes the record into the open form; the saved values
  // are nevertheless re-read from the server once, outside the timed step
  // and (through its own step header) outside the step's memory
  // attribution. After a create the new resource's editor is already open.
  async savedTitle() {
    if (!this.verified) {
      await this.page.setExtraHTTPHeaders({ 'X-Phramark-Step': 'verify' });
      await this.page.reload();
      await this.page.waitForSelector(SAVE);
      await this.page.waitForFunction((selector) => document.querySelector(selector)?.value !== '', TITLE);
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
    await this.page.goto(this.managerUrl('a=security/logout'));
    await this.page.waitForSelector('#modx-login-username');
    return this.timer.collect();
  }
}

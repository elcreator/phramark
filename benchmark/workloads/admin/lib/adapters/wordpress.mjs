// WordPress (wp-admin) adapter for the wordpress-gantry stack. Plain,
// frame-less forms: /wp-login.php, /wp-admin/post.php?post=N&action=edit and
// /wp-admin/post-new.php?post_type=page with the Classic Editor in its Text
// tab (benchmark/implementations/wordpress-gantry sets wp_default_editor to
// html), so the title and body are an input and a textarea like the Drupal,
// Evolution and Winter fixtures. Saving posts the form to post.php, which
// redirects back to the editor of the (new) page with a message; the id is
// read from that URL. Logout is the nonce-protected admin-bar link.

import { adminPageIds } from '../config.mjs';

const TITLE = '#title';
const CONTENT = '#content';
const SAVE = '#publish';

// The page id of an editor URL (post.php?post=N&action=edit), or NaN.
export function editedPostId(url) {
  return Number.parseInt(new URL(url).searchParams.get('post') ?? '', 10);
}

export class WordPressAdapter {
  static supports(stack) {
    return stack === 'wordpress-gantry';
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
  }

  pageIds() {
    return adminPageIds(this.config.pages, this.config.adminRootId);
  }

  adminUrl(path = '') {
    return `${this.config.baseUrl}/wp-admin/${path}`;
  }

  async login() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/wp-login.php`);
    await this.page.fill('#user_login', this.config.username);
    await this.page.fill('#user_pass', this.config.password);
    await Promise.all([
      this.page.waitForURL(/\/wp-admin\/(index\.php)?(\?.*)?$/),
      this.page.click('#wp-submit'),
    ]);
    await this.page.waitForLoadState('load');
    await this.page.waitForSelector('#wpadminbar');
    return this.timer.collect();
  }

  async editorReady() {
    await this.page.waitForSelector(SAVE);
    await this.page.waitForSelector(CONTENT);
  }

  async openEditor(id) {
    this.timer.mark();
    await this.page.goto(this.adminUrl(`post.php?post=${id}&action=edit`));
    await this.editorReady();
    return this.timer.collect();
  }

  // post-new.php creates an auto-draft; the seeded container becomes the
  // parent through the Page Attributes box so the reset can find the page.
  async openCreator() {
    this.timer.mark();
    await this.page.goto(this.adminUrl('post-new.php?post_type=page'));
    await this.editorReady();
    await this.page.selectOption('#parent_id', String(this.config.adminRootId));
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
    await Promise.all([
      this.page.waitForURL(/[?&]action=edit\b.*[?&]message=\d+/),
      this.page.click(SAVE),
    ]);
    await this.page.waitForLoadState('load');
    await this.editorReady();
    const result = await this.timer.collect();
    return create ? { ...result, id: editedPostId(this.page.url()) } : result;
  }

  // After a save WordPress reloads the editor with the saved record.
  async savedTitle() {
    return this.readTitle();
  }

  async savedContent() {
    return this.readContent();
  }

  async logout() {
    this.timer.mark();
    const href = await this.page.getAttribute('#wp-admin-bar-logout a', 'href');
    await this.page.goto(href);
    await this.page.waitForSelector('#user_login');
    return this.timer.collect();
  }
}

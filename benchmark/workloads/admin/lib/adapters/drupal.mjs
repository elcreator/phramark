// Drupal 11 adapter. Plain, frame-less forms: /user/login, /node/{nid}/edit,
// /node/add/page (the "page" type and its plain-text body come from
// benchmark/fixtures/cms/drupal-admin-seed.php). Saving redirects to the
// node's view page, so the saved values are read from that page. Logout is
// a confirmation form (Drupal 10.3+ protects /user/logout against CSRF).

export class DrupalAdapter {
  static supports(stack) {
    return stack === 'drupal-11';
  }

  constructor(page, config, timer) {
    this.page = page;
    this.config = config;
    this.timer = timer;
  }

  // The seed creates the pages on a fresh install, so they are nodes 1..N;
  // PHRAMARK_DRUPAL_NIDS overrides that when an install differs.
  pageIds() {
    const override = process.env.PHRAMARK_DRUPAL_NIDS;
    if (override) return override.split(',').filter(Boolean).map((id) => Number.parseInt(id, 10));
    return Array.from({ length: this.config.pages }, (_, index) => index + 1);
  }

  async login() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/user/login`);
    await this.page.fill('#edit-name', this.config.username);
    await this.page.fill('#edit-pass', this.config.password);
    await this.page.click('#edit-submit');
    await this.page.waitForURL(/\/user\/\d+/);
    return this.timer.collect();
  }

  async openEditor(id) {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/node/${id}/edit`);
    await this.page.waitForSelector('#edit-title-0-value');
    return this.timer.collect();
  }

  async openCreator() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/node/add/page`);
    await this.page.waitForSelector('#edit-title-0-value');
    return this.timer.collect();
  }

  async readTitle() {
    return this.page.inputValue('#edit-title-0-value');
  }

  async readContent() {
    return this.page.inputValue('#edit-body-0-value');
  }

  async fillTitle(title) {
    await this.page.fill('#edit-title-0-value', title);
  }

  async fillContent(content) {
    await this.page.fill('#edit-body-0-value', content);
  }

  async save() {
    this.timer.mark();
    await Promise.all([
      this.page.waitForURL(/\/node\/\d+$/),
      this.page.click('#edit-submit'),
    ]);
    await this.page.waitForLoadState('load');
    const id = Number.parseInt(this.page.url().match(/\/node\/(\d+)$/)?.[1] ?? '', 10);
    return { ...(await this.timer.collect()), id };
  }

  // After a save Drupal shows the node; the page title and body are there.
  async savedTitle() {
    return (await this.page.textContent('h1, .page-title, .node__title')).trim();
  }

  async savedContent() {
    return this.page.textContent('.node__content, article, main, body');
  }

  async logout() {
    this.timer.mark();
    await this.page.goto(`${this.config.baseUrl}/user/logout`);
    await this.page.waitForSelector('#edit-submit');
    await this.page.click('#edit-submit');
    await this.page.waitForLoadState('load');
    await this.page.goto(`${this.config.baseUrl}/user/login`);
    await this.page.waitForSelector('#edit-name');
    return this.timer.collect();
  }
}

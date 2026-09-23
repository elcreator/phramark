// sArticles module adapter (evo-sarticles): the editorial round on articles
// that live in the module's own tables rather than in the document tree.
//
// The stack exists to compare what an editor does in sArticles 1.x against
// 2.x, where the manager UI was rebuilt on evo-ui and Livewire. The two
// generations reach the same six actions through different surfaces:
//
//   1.x  manager tabs: the content tab is a plain form
//        (?get=content&i=<id>&lang=base) posted to ?get=contentSave, with the
//        title and the summary on one page
//   2.x  evo-ui: a Livewire modal opened from the row action
//        openEditModal(<id>), with the summary behind a "Content" tab, saved
//        without leaving the list
//
// Every timed step is the same user intent in both, waited to the state where
// the editor can act again: the form is ready, or the save is confirmed. What
// differs inside a step is the product's own doing and is part of the result.
//
// The manager shell, login and logout are Evolution's, so this adapter is the
// Evolution one with the module's editor in place of the document editor. It
// must be registered before EvolutionAdapter, whose supports() matches every
// evo-* stack.

import { EvolutionAdapter } from './evolution.mjs';
import { articleIds } from '../config.mjs';

// evo-ui proxies Livewire through the manager: /manager/evo-ui?action=update.
const LIVEWIRE_UPDATE = /action=update/;

export class SArticlesAdapter extends EvolutionAdapter {
  static supports(stack) {
    return stack === 'evo-sarticles';
  }

  constructor(page, config, timer) {
    super(page, config, timer);
    this.moduleUrl = null;
    // "evo-ui" (2.x) or "tabs" (1.x), detected from the rendered list.
    this.generation = null;
    this.lastSavedId = null;
    // Title and summary of an article 1.x has not created yet (see
    // openCreator); null whenever an article is being edited.
    this.pending = null;
  }

  pageIds() {
    return articleIds(this.config.pages, this.config.sarticlesFirstId);
  }

  // The module as the editor reaches it: the entry the package adds to the
  // manager menu. Its id is a hash of the module title, so it is read from
  // the shell rather than computed.
  async openModule() {
    // Read the entry again every time: the manager renders it with the
    // session's current CSRF token, and an older one is rejected.
    const href = await this.page.$$eval('a', (links) => links
      .map((link) => link.getAttribute('href') ?? '')
      .find((candidate) => /a=112/.test(candidate) && /id=[0-9a-f]{32}/.test(candidate)) ?? '');
    if (!href && !this.moduleUrl) {
      throw new Error('The sArticles module is not in the manager menu; is the package installed?');
    }
    if (href) {
      this.moduleUrl = new URL(href, `${this.config.baseUrl}/manager/`).toString();
    }
    await this.frame().goto(this.moduleUrl);
    await this.frame().waitForLoadState('load');
    if (!this.generation) {
      this.generation = await this.frame().$('[wire\\:click="openCreateModal"]') ? 'evo-ui' : 'tabs';
    }
    return this.generation;
  }

  async login() {
    const timed = await super.login();
    await this.openModule();
    return timed;
  }

  get evoUi() {
    return this.generation === 'evo-ui';
  }

  // Evolution rotates the manager's CSRF token, so a module URL kept from an
  // earlier page is stale: every navigation is built with the token of the
  // page the frame is on right now, and only falls back to the one the menu
  // link carried.
  moduleFrom(current) {
    const url = new URL(this.moduleUrl);
    const fresh = (() => {
      try {
        return new URL(current).searchParams.get('_token');
      } catch {
        return null;
      }
    })();
    if (fresh) {
      url.searchParams.set('_token', fresh);
    }
    return url.toString();
  }

  // The URL of one of the module's views, with a current token.
  viewUrl(query) {
    return `${this.moduleFrom(this.frame().url())}${query}`;
  }

  modal() {
    return this.frame().locator('.evo-ui-modal--form');
  }

  // The modal is ready when its fields have arrived with the Livewire payload
  // and the summary editor has been built: evo-ui syncs the rich editors on
  // submit, and a modal whose editors were never initialised answers a click
  // on Save with nothing at all.
  async waitForModal() {
    await this.modal().waitFor({ state: 'visible', timeout: 60_000 });
    await this.modal().locator('[id$="-pagetitle"]').waitFor({ state: 'visible', timeout: 60_000 });
    // The editor is on the Content tab and is built when that tab is first
    // shown. Opening it here is not politeness: evo-ui syncs the rich
    // editors on submit, and a modal whose editors were never built answers
    // a click on Save with nothing at all.
    await this.openTab('content');
    await this.openTab('main');
  }

  // The module answers a request it does not accept (a rotated CSRF token,
  // for one) with another page instead of the form, and waiting for a field
  // that will never come says nothing. Name what was expected and show what
  // arrived.
  async waitForForm(what) {
    try {
      await this.frame().waitForSelector('input[name="pagetitle"]', { timeout: 60_000 });
    } catch (error) {
      const text = (await this.frame().innerText('body').catch(() => '')).replace(/\s+/g, ' ').slice(0, 200);
      throw new Error(`${what} did not render (${this.frame().url().replace(/_token=[^&]+/, '_token=…')}): ${text}`);
    }
  }

  // Switching a tab of the modal is an Alpine toggle: the fields of the tab
  // are in the page all along and become visible with it, so the switch is
  // waited for rather than fired and assumed.
  async openTab(tab) {
    const control = this.modal().locator(`[x-on\\:click="selectedModalTab = '${tab}'"]`).first();
    if (!await control.count()) {
      return;
    }
    if (tab !== 'content') {
      await control.click();
      return;
    }
    // The modal arrives before Alpine has bound it, and a click that lands in
    // between changes nothing at all. The tab is open once the summary
    // editor exists — TinyMCE keeps its own shell hidden from the page, so
    // visibility says nothing, but its instance does.
    const built = () => this.frame().waitForFunction(
      () => (window.tinymce?.get() ?? []).some((editor) => editor.id.endsWith('-introtext')),
      null,
      { timeout: 15_000 },
    ).then(() => true, () => false);
    for (let attempt = 1; attempt <= 5; attempt++) {
      await control.click();
      if (await built()) {
        return;
      }
    }
    throw new Error('The summary editor was never built after switching to the Content tab.');
  }

  // The editor of one article, from the list, as the row action opens it.
  async openEditor(id) {
    this.timer.mark();
    // An article that exists has its own fields; nothing is held back.
    this.pending = null;
    if (this.evoUi) {
      await this.openModule();
      await this.frame().locator(`[wire\\:click\\.stop="openEditModal(${id})"]`).click();
      await this.waitForModal();
    } else {
      await this.frame().goto(this.viewUrl(`&get=content&type=article&i=${id}&lang=base`));
      await this.waitForForm(`the editor of article ${id}`);
    }
    this.lastSavedId = id;
    return this.timer.collect();
  }

  // The empty form for a new article.
  async openCreator() {
    this.timer.mark();
    if (this.evoUi) {
      await this.openModule();
      await this.frame().locator('[wire\\:click="openCreateModal"]').first().click();
      await this.waitForModal();
    } else {
      // "Add Article" opens the article form. In 1.x that form has no title
      // and no summary: the article row is created there, and its text is
      // written on the content tab afterwards. The workload fills both, so
      // the values are held until there is an article to put them on.
      this.pending = { title: '', content: '' };
      await this.frame().goto(this.viewUrl('&get=article&type=article&i=0'));
      await this.frame().waitForSelector('input[name="alias"]');
    }
    this.lastSavedId = null;
    return this.timer.collect();
  }

  titleField() {
    return this.evoUi
      ? this.modal().locator('[id$="-pagetitle"]')
      : this.frame().locator('input[name="pagetitle"]');
  }

  // 1.x edits the summary in a plain textarea; 2.x puts the same field in a
  // TinyMCE instance behind the Content tab. TinyMCE keeps its own frame
  // hidden from the outside, so the text goes in through the editor's API,
  // which is where a keystroke would land as well — and it is what evo-ui
  // reads back from on submit (EvoUI.syncRichEditors). The widget is the
  // product's choice; the action ("write the summary") is the same.
  async richText(text = null) {
    return this.frame().evaluate(({ value }) => {
      const editor = window.tinymce?.get().find((candidate) => candidate.id.endsWith('-introtext'));
      if (!editor) {
        throw new Error('The summary editor is not initialised.');
      }
      if (value !== null) {
        editor.setContent(`<p>${value}</p>`);
        editor.fire('change');
        editor.save();
      }

      return editor.getContent({ format: 'text' }).trim();
    }, { value: text });
  }

  async readTitle() {
    return this.titleField().inputValue();
  }

  async readContent() {
    if (this.evoUi) {
      await this.openTab('content');
      const text = await this.richText();
      await this.openTab('main');
      return text;
    }
    return this.frame().inputValue('textarea[name="introtext"]');
  }

  async fillTitle(title) {
    if (this.pending) {
      this.pending.title = title;
      // A new article needs an alias of its own; the editor types one.
      await this.frame().fill('input[name="alias"]', title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
      return;
    }
    await this.titleField().fill(title);
  }

  async fillContent(content) {
    if (this.pending) {
      this.pending.content = content;
      return;
    }
    if (this.evoUi) {
      await this.openTab('content');
      await this.richText(content.slice(0, 400));
      await this.openTab('main');
      return;
    }
    await this.frame().fill('textarea[name="introtext"]', content);
  }

  // After a save 1.x renders the editor again; 2.x closes the modal and
  // leaves the editor in the list, so the saved values are read back from the
  // article's own modal (outside the timed step).
  async savedTitle() {
    if (this.evoUi) {
      await this.reopenSaved();
    }
    return this.readTitle();
  }

  async savedContent() {
    if (this.evoUi) {
      await this.reopenSaved();
    }
    return this.readContent();
  }

  async reopenSaved() {
    if (await this.modal().isVisible().catch(() => false)) {
      return;
    }
    if (!this.lastSavedId) {
      throw new Error('No article was saved, so there is nothing to read back.');
    }
    await this.frame().locator(`[wire\\:click\\.stop="openEditModal(${this.lastSavedId})"]`).click();
    await this.waitForModal();
  }

  async closeModal() {
    const close = this.modal().locator('[wire\\:click="closeModal"]').first();
    if (await close.count()) {
      await close.click();
      await this.modal().waitFor({ state: 'hidden', timeout: 30_000 }).catch(() => null);
    }
  }

  // Save and wait until the editor could act again.
  // The workload says which save it is (`save({ create: true })`), and the
  // two are not the same afterwards: an edit stays on its own article, a
  // create has to find the one the module just made.
  async save({ create = false } = {}) {
    this.timer.mark();
    const frame = this.frame();
    if (this.evoUi) {
      const answered = this.page.waitForResponse((response) => response.request().method() === 'POST'
        && LIVEWIRE_UPDATE.test(response.url())
        && (response.request().postData() ?? '').includes('"saveModal"'), { timeout: 120_000 });
      await this.modal().locator('button[type="submit"]').first().click();
      const response = await answered;
      if (response.status() >= 400) throw new Error(`Save failed with HTTP ${response.status()}`);
      const body = await response.text();
      if (!body.trimStart().startsWith('{')) {
        // Livewire expects JSON; PHP output before it means the save died.
        throw new Error(`Save answered with ${body.replace(/\s+/g, ' ').slice(0, 160)}`);
      }
      await this.modal().waitFor({ state: 'hidden', timeout: 60_000 });
      const timed = await this.timer.collect();
      // Finding the created article is bookkeeping, not part of the step; an
      // edited one is the article the editor already had open.
      if (create) {
        this.lastSavedId = await this.newestId();
      }
      return { ...timed, id: this.lastSavedId };
    }
    if (this.pending) {
      const { title, content } = this.pending;
      this.pending = null;
      // Creating an article in 1.x is two forms: the article row first, its
      // title and summary second. Both are inside the step, because an
      // article without them is not what the editor set out to create. The
      // module saves the row and returns to the empty form without naming
      // what it created, so the editor finds it in the list, and that
      // round-trip is part of the cost too.
      await this.submit(/get=articleSave/, 'input[name="alias"]');
      const id = await this.newestId();
      if (!id) throw new Error('sArticles 1.x created no article: the list shows none newer than the fixture.');
      await frame.goto(this.viewUrl(`&get=content&type=article&i=${id}&lang=base`));
      await this.waitForForm(`the editor of the created article ${id}`);
      await this.titleField().fill(title);
      await frame.fill('textarea[name="introtext"]', content);
      await this.submit(/get=contentSave/, 'input[name="pagetitle"]');
      this.lastSavedId = id;
      return { ...(await this.timer.collect()), id };
    }
    const id = await this.submit(/get=contentSave/, 'input[name="pagetitle"]');
    this.lastSavedId = id || this.lastSavedId;
    return { ...(await this.timer.collect()), id: this.lastSavedId };
  }

  // One form of the manager tabs: submitted with the token the module omits,
  // waited for the answer and for the form the module renders next. Returns
  // the article the manager lands on.
  async submit(action, field) {
    const frame = this.frame();
    await this.addManagerToken();
    const posted = this.page.waitForResponse((response) => response.request().method() === 'POST'
      && action.test(response.request().url()), { timeout: 120_000 });
    await frame.click('#Button1');
    const response = await posted;
    if (response.status() >= 400) throw new Error(`Save failed with HTTP ${response.status()} (${response.url().replace(/_token=[^&]+/, '_token=…')})`);
    await frame.waitForSelector(field, { timeout: 60_000 });

    return Number.parseInt(new URL(frame.url()).searchParams.get('i') ?? '', 10) || 0;
  }

  // sArticles 1.x builds the form action from the module URL without the
  // manager's CSRF token (sArticlesController::moduleUrl()), and Evolution
  // 3.5.8 answers such a POST with 403: the save never reaches the module.
  // The token the manager put in the current URL is added here so the save
  // can be measured at all. Remove this once the module posts it itself.
  async addManagerToken() {
    const token = new URL(this.moduleFrom(this.frame().url())).searchParams.get('_token');
    if (!token) {
      return;
    }
    await this.frame().$eval('#form', (form, value) => {
      const action = form.getAttribute('action') ?? '';
      if (!action.includes('_token=')) {
        form.setAttribute('action', `${action}&_token=${value}`);
      }
    }, token);
  }

  // The id of the newest article: the highest one the list shows, read from
  // the row ids of evo-ui or from the edit links of the manager tabs.
  async newestId() {
    if (!this.evoUi) {
      await this.frame().goto(this.viewUrl('&get=articles&type=article'));
      await this.frame().waitForLoadState('load');
    }
    const ids = this.evoUi
      ? await this.frame().locator('tr .evo-ui-id').evaluateAll((nodes) => nodes
        .map((node) => Number.parseInt((node.textContent ?? '').replace(/\D/g, ''), 10)))
      : await this.frame().$$eval('a[href*="get=article"][href*="i="]', (links) => links
        .map((link) => Number.parseInt(new URLSearchParams((link.getAttribute('href') ?? '').split('?')[1] ?? '').get('i') ?? '', 10)));
    const usable = ids.filter((value) => Number.isFinite(value) && value > 0);
    return usable.length ? Math.max(...usable) : 0;
  }
}

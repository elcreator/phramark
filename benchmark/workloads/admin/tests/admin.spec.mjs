import { test, expect } from '@playwright/test';
import { config } from '../lib/config.mjs';
import { adapterFor } from '../lib/adapters/index.mjs';
import { DocumentTimer } from '../lib/document-timer.mjs';
import { FrontendMemory } from '../lib/frontend-memory.mjs';
import { Timeline } from '../lib/timeline.mjs';
import { createdContent, createdTitle, editedContent, editedTitle, MARKER } from '../lib/edits.mjs';
import { writeReport } from '../lib/report.mjs';

// The admin workload: log in, edit N seeded pages (append EDITED to title and
// body, save), create N pages (save), log out. Every step is timed; the
// benchmark/scripts/admin wrapper resets the fixture around the run.
//
// Each step sends an X-Phramark-Step header so the PHP-side memory log can
// attribute its requests to the step, and records the renderer's JS heap and
// DOM size afterwards (frontend side).
test(`admin workload on ${config.stack}`, async ({ page }) => {
  const timeline = new Timeline();
  const timer = new DocumentTimer(page);
  const frontend = new FrontendMemory(page);
  await frontend.enable();
  const admin = adapterFor(config.stack, page, config, timer);
  const created = [];

  const step = async (action, label, fn) => {
    await page.setExtraHTTPHeaders({ 'X-Phramark-Step': `${action}:${label}` });
    const recorded = await timeline.measure(action, label, fn);
    Object.assign(recorded, await frontend.snapshot());
    return recorded;
  };

  if (config.warmup) {
    await page.setExtraHTTPHeaders({ 'X-Phramark-Step': 'warmup' });
    await admin.login();
    await admin.openEditor(admin.pageIds()[0]);
    await admin.save();
    await admin.openCreator();
    await admin.logout();
  }

  for (let round = 1; round <= config.rounds; round++) {
    await step('login', `round ${round}`, () => admin.login());

    for (const id of admin.pageIds()) {
      await step('open-edit', `page ${id}`, () => admin.openEditor(id));
      const title = editedTitle(await admin.readTitle());
      const content = editedContent(await admin.readContent());
      await admin.fillTitle(title);
      await admin.fillContent(content);
      await step('save-edit', `page ${id}`, () => admin.save());
      expect(await admin.savedTitle()).toBe(title);
      expect(await admin.savedContent()).toContain(MARKER);
    }

    for (let index = 1; index <= config.pages; index++) {
      await step('open-create', `page ${index}`, () => admin.openCreator());
      await admin.fillTitle(createdTitle(round, index));
      const body = createdContent(round, index);
      // Stacks whose page body is a separate record (TYPO3) add it inside save().
      await admin.fillContent(body);
      const saved = await step('save-create', `page ${index}`, () => admin.save({ create: true, content: body }));
      expect(saved.id).toBeGreaterThan(0);
      expect(await admin.savedTitle()).toBe(createdTitle(round, index));
      created.push(saved.id);
    }

    await step('logout', `round ${round}`, () => admin.logout());
  }

  const report = writeReport(config, timeline.steps, { createdIds: created, warmup: config.warmup });
  console.log(report.text);
  console.log(`Results: ${report.json}`);
});

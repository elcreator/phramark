import { DrupalAdapter } from './drupal.mjs';
import { EvolutionAdapter } from './evolution.mjs';
import { Typo3Adapter } from './typo3.mjs';
import { WinterAdapter } from './winter.mjs';
import { ModxAdapter } from './modx.mjs';
import { WordPressAdapter } from './wordpress.mjs';

// Every adapter implements: pageIds(), login(), openEditor(id), openCreator(),
// readTitle(), readContent(), fillTitle(), fillContent(), save({create,
// content}) -> {id?, ...timer}, savedTitle(), savedContent(), logout().
const adapters = [EvolutionAdapter, DrupalAdapter, Typo3Adapter, WinterAdapter, ModxAdapter, WordPressAdapter];

export function adapterFor(stack, page, config, timer) {
  const Adapter = adapters.find((candidate) => candidate.supports(stack));
  if (!Adapter) {
    throw new Error(`No admin workload adapter for stack "${stack}". Available: evo-* (Evolution CMS), drupal-11, typo3, winter, modx, wordpress-gantry.`);
  }
  return new Adapter(page, config, timer);
}

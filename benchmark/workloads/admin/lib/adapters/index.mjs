import { DrupalAdapter } from './drupal.mjs';
import { EvolutionAdapter } from './evolution.mjs';
import { Typo3Adapter } from './typo3.mjs';

// Every adapter implements: pageIds(), login(), openEditor(id), openCreator(),
// readTitle(), readContent(), fillTitle(), fillContent(), save({create,
// content}) -> {id?, ...timer}, savedTitle(), savedContent(), logout().
const adapters = [EvolutionAdapter, DrupalAdapter, Typo3Adapter];

export function adapterFor(stack, page, config, timer) {
  const Adapter = adapters.find((candidate) => candidate.supports(stack));
  if (!Adapter) {
    throw new Error(`No admin workload adapter for stack "${stack}". Available: evo-* (Evolution CMS), drupal-11, typo3.`);
  }
  return new Adapter(page, config, timer);
}

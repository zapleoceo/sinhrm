/**
 * Page-side extraction entry point.
 *
 * Injection approach: esbuild bundles this module (with all extractors) into a single
 * self-contained IIFE `build/extract.js` assigned to `var __sinhrmClipper`, plus a footer
 * statement `__sinhrmClipper.run();`. The popup injects it with
 * `chrome.scripting.executeScript({ target: { tabId }, files: ['extract.js'] })`.
 * For file injection Chrome returns the completion value of the script, i.e. the value
 * of the last evaluated expression statement - here the return value of run().
 * This avoids the `func:` pitfall where func.toString() loses imported helpers/closures.
 *
 * run() reads ONLY the current document; it never fetches, crawls or navigates.
 */
import { detectSite } from './detect';
import { extract as linkedin } from './extractors/linkedin';
import { extract as workua } from './extractors/workua';
import { extract as djinni } from './extractors/djinni';
import { extract as dou } from './extractors/dou';
import { extract as robotaua } from './extractors/robotaua';
import type { ExtractResult, Profile, SourceSite } from './types';

const extractors: Record<SourceSite, (doc: Document, url: string) => Profile> = {
  linkedin,
  work_ua: workua,
  djinni,
  dou,
  robota_ua: robotaua,
};

export function extractFromDocument(doc: Document, url: string): ExtractResult {
  const site = detectSite(url);
  if (!site) return { ok: false, error: 'unsupported_site' };
  return { ok: true, profile: extractors[site](doc, url) };
}

export function run(): ExtractResult {
  return extractFromDocument(document, location.href);
}

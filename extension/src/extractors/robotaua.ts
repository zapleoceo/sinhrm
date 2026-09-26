import type { Profile } from '../types';
import { extractCommon, type SiteSelectors } from './common';

/**
 * Robota.ua employer-side CV pages (Angular SPA). DOM selectors are best-effort guesses, NOT calibrated
 * against live logged-in pages (the site returns 403 to non-browser clients); JSON-LD and og: are tried first,
 * and several alternatives per field keep it tolerant. Missing fields stay empty.
 */
export const selectors: SiteSelectors = {
  name: ['[data-id="cv-name"]', '.santa-typo-h1', '.cv-name', 'h1'],
  headline: ['[data-id="cv-position"]', '.cv-position', '.santa-typo-h2', 'h2'],
  location: ['[data-id="cv-city"]', '.cv-city', '.cv-location'],
  summary: ['[data-id="cv-experience"]', '.cv-experience', '.cv-about', '[data-id="cv-about"]'],
};

export function extract(doc: Document, url: string): Profile {
  return extractCommon(doc, url, 'robota_ua', selectors);
}

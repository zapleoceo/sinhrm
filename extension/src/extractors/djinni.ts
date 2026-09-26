import type { Profile } from '../types';
import { extractCommon, type SiteSelectors } from './common';

/** DOM selectors are best-effort (not validated against live pages); JSON-LD and og: are tried first. */
export const selectors: SiteSelectors = {
  name: ['h1'],
  headline: ['.candidate-headline', '.page-header h2'],
  location: ['.candidate-location', '.location-text'],
  summary: ['.candidate-summary', '.profile-details-text', '#candidate_description'],
};

export function extract(doc: Document, url: string): Profile {
  return extractCommon(doc, url, 'djinni', selectors);
}

import type { Profile } from '../types';
import { extractCommon, type SiteSelectors } from './common';

/** DOM selectors are best-effort (not validated against live pages); JSON-LD and og: are tried first. */
export const selectors: SiteSelectors = {
  name: ['h1', '.text-heading-xlarge'],
  headline: ['.text-body-medium.break-words', '.pv-text-details__left-panel .text-body-medium'],
  location: ['.text-body-small.inline.t-black--light.break-words', '.pv-text-details__left-panel .text-body-small'],
  summary: ['#about ~ div .inline-show-more-text', '.pv-about__summary-text', 'section.summary'],
};

export function extract(doc: Document, url: string): Profile {
  return extractCommon(doc, url, 'linkedin', selectors);
}

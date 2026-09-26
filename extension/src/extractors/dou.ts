import type { Profile } from '../types';
import { extractCommon, type SiteSelectors } from './common';

/** DOM selectors are best-effort (not validated against live pages); JSON-LD and og: are tried first. */
export const selectors: SiteSelectors = {
  name: ['h1', '.user-name'],
  headline: ['.user-info .position', '.position'],
  location: ['.user-info .location', '.location'],
  summary: ['.user-descr', '.profile-descr'],
};

export function extract(doc: Document, url: string): Profile {
  return extractCommon(doc, url, 'dou', selectors);
}

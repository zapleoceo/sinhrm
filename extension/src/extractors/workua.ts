import type { Profile } from '../types';
import { extractCommon, type SiteSelectors } from './common';

/** DOM selectors are best-effort (not validated against live pages); JSON-LD and og: are tried first. */
export const selectors: SiteSelectors = {
  name: ['h1'],
  headline: ['.resume-header h2', 'h2'],
  location: ['.resume-location', 'dl.dl-horizontal dd'],
  summary: ['#add_info', '.resume-summary'],
};

export function extract(doc: Document, url: string): Profile {
  return extractCommon(doc, url, 'work_ua', selectors);
}

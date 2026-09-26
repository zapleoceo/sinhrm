import type { SourceSite } from './types';

/**
 * Supported URL patterns (profile pages only, https only):
 *  - linkedin: https://www.linkedin.com/in/<slug>/
 *  - work_ua:  https://www.work.ua/resumes/<id>/  (also /ru/ and /en/ language prefixes)
 *  - djinni:   https://djinni.co/q/<id>/...
 *  - dou:      https://dou.ua/users/<slug>/  (also www.dou.ua)
 */
export function detectSite(rawUrl: string): SourceSite | null {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:') return null;
  const host = url.hostname.toLowerCase();
  const path = url.pathname;
  if (host === 'www.linkedin.com' && /^\/in\/[^/]+\/?$/.test(path)) return 'linkedin';
  if (host === 'www.work.ua' && /^\/(?:(?:ru|en)\/)?resumes\/\d+\/?$/.test(path)) return 'work_ua';
  if (host === 'djinni.co' && /^\/q\/[^/]+/.test(path)) return 'djinni';
  if ((host === 'dou.ua' || host === 'www.dou.ua') && /^\/users\/[^/]+\/?$/.test(path)) return 'dou';
  return null;
}

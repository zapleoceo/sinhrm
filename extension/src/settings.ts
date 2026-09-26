export const DEFAULT_BASE_URL = 'https://sinhrm.vercel.app';

export interface Settings {
  baseUrl: string;
  token: string;
  lang?: string;
}

/** Returns normalized https origin+path without trailing slash, or null if invalid / not https. */
export function normalizeBaseUrl(raw: string): string | null {
  const value = raw.trim() || DEFAULT_BASE_URL;
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:' || url.username || url.password) return null;
  return (url.origin + url.pathname).replace(/\/+$/, '');
}

export async function loadSettings(): Promise<Settings> {
  const s = await chrome.storage.local.get(['baseUrl', 'token', 'lang']);
  return {
    baseUrl: typeof s.baseUrl === 'string' && s.baseUrl ? s.baseUrl : DEFAULT_BASE_URL,
    token: typeof s.token === 'string' ? s.token : '',
    lang: typeof s.lang === 'string' ? s.lang : undefined,
  };
}

export async function saveSettings(patch: Partial<Settings>): Promise<void> {
  await chrome.storage.local.set(patch);
}

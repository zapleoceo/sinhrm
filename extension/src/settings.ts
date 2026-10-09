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

/**
 * The link from an API answer is opened only when it points to the configured SinHRM origin (https), so a wrong
 * base URL or a tampered answer cannot make the extension open an arbitrary page (javascript:, data:, phishing).
 */
export function trustedLink(link: string | undefined, baseUrl: string): string | null {
  if (!link) return null;
  try {
    const url = new URL(link, baseUrl);
    return url.protocol === 'https:' && url.origin === new URL(baseUrl).origin ? url.href : null;
  } catch {
    return null;
  }
}

/** Keep the token out of content scripts (extract.js runs inside third-party pages): trusted extension pages only. */
async function restrictStorage(): Promise<void> {
  try {
    await chrome.storage.local.setAccessLevel?.({ accessLevel: 'TRUSTED_CONTEXTS' });
  } catch {
    /* older Chrome: content scripts of this extension never read storage anyway */
  }
}

export async function loadSettings(): Promise<Settings> {
  await restrictStorage();
  const s = await chrome.storage.local.get(['baseUrl', 'token', 'lang']);
  return {
    baseUrl: typeof s.baseUrl === 'string' && s.baseUrl ? s.baseUrl : DEFAULT_BASE_URL,
    token: typeof s.token === 'string' ? s.token : '',
    lang: typeof s.lang === 'string' ? s.lang : undefined,
  };
}

export async function saveSettings(patch: Partial<Settings>): Promise<void> {
  await restrictStorage();
  await chrome.storage.local.set(patch);
}

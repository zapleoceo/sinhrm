// Fixture helpers shared by the recorder and the mock API: keys, list trimming, sanitizing to synthetic data.

/** Stable key of a request: "<METHOD> <path>?<sorted query>". @param {string} method @param {URL} url */
export function fixtureKey(method, url) {
  const params = [...url.searchParams.entries()].sort(([a, av], [b, bv]) => a.localeCompare(b) || av.localeCompare(bv));
  const query = new URLSearchParams(params).toString();
  return `${method} ${url.pathname}${query ? '?' + query : ''}`;
}

/** Fixture file of a key: first path segment after /api/ ("GET /api/people/1" → "people"). @param {string} key */
export function areaOf(key) {
  const path = key.split(' ')[1].split('?')[0];
  const seg = path.split('/').filter(Boolean);
  return seg[0] === 'api' ? (seg[1] ?? 'root').replace(/[^a-z0-9-]/gi, '_') : seg[0];
}

const MAX_ROWS = 30;

/** Lists longer than 30 rows (top-level `data` or the body itself) are cut to 30 — fixtures stay small. */
export function trim(body) {
  if (Array.isArray(body)) return body.slice(0, MAX_ROWS);
  if (body && typeof body === 'object' && Array.isArray(body.data)) return { ...body, data: body.data.slice(0, MAX_ROWS) };
  return body;
}

const SECRET_KEY = /(token|secret|password|api_key|apikey|private_key|client_secret)$/i;
const EMAIL = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi;

/** Everything not synthetic is replaced: e-mails outside @sinhrm.test, local hosts, avatars, file paths, secrets. */
export function sanitize(value, key = '') {
  if (typeof value === 'string') {
    if (SECRET_KEY.test(key) && value !== '') return '[ТЕСТ]';
    return value
      .replace(EMAIL, (m) => (m.toLowerCase().endsWith('@sinhrm.test') ? m : `user${hash(m)}@sinhrm.test`))
      .replace(/https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?/g, 'https://sinhrm.test')
      .replace(/https:\/\/[a-z0-9.-]*googleusercontent\.com\/\S*/gi, '')
      .replace(/[A-Z]:[\\/][^\s"']*/g, '/app/file');
  }
  if (Array.isArray(value)) return value.map((v) => sanitize(v, key));
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, sanitize(v, k)]));
  }
  return value;
}

function hash(s) {
  let h = 0;
  for (const c of s) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return h % 100000;
}

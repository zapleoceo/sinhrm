// Static file lookup for the built SPA with history-API fallback. Pure helpers, no I/O beyond fs.stat (tested in scripts/server-static.test.mjs).
import { stat } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';

export const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.ico': 'image/x-icon', '.webmanifest': 'application/manifest+json', '.woff2': 'font/woff2',
  '.woff': 'font/woff', '.txt': 'text/plain; charset=utf-8', '.map': 'application/json; charset=utf-8',
};

/** Resolves a URL pathname to a file under root, or null (traversal, directory, missing). */
export async function resolveFile(root, pathname) {
  let decoded;
  try { decoded = decodeURIComponent(pathname); } catch { return null; }
  if (decoded.includes('\0')) return null;
  const file = normalize(join(root, decoded));
  if (file !== root && !file.startsWith(root + sep)) return null;
  try { return (await stat(file)).isFile() ? file : null; } catch { return null; }
}

/** Hashed bundles (main-XXXX.js, styles-XXXX.css, media/*) are immutable; index.html and the rest must revalidate. */
export function cacheControl(file, pathname) {
  if (pathname === '/build.json') return 'no-store';
  if (/-[A-Z0-9]{8}\.(js|css|woff2?|png|svg)$/.test(file)) return 'public, max-age=31536000, immutable';
  if (extname(file) === '.html') return 'no-cache';
  return 'public, max-age=3600';
}

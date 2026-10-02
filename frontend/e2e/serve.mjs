// Static server for the production build (dist/frontend/browser) with the SPA fallback to index.html.
// Tests answer /api/** and /sanctum/** themselves (page.route), so by default the server answers them 503.
// The fixture recorder passes --proxy=http://127.0.0.1:8017 to forward them to a local backend instead.
// Usage: node e2e/serve.mjs [--port=4317] [--proxy=http://127.0.0.1:8017]
import { createServer, request as httpRequest } from 'node:http';
import { existsSync } from 'node:fs';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const port = Number(args.port ?? 4317);
const proxy = args.proxy ? new URL(args.proxy) : null;
const modules = resolve(fileURLToPath(new URL('../node_modules', import.meta.url)));
// Fonts: the production build inlines the Google Fonts CSS (fonts.gstatic.com URLs) into index.html. Without
// --proxy (tests) every such @font-face is swapped for the same family/weight from a devDependency —
// @fontsource/<family> (latin + cyrillic files) or material-symbols — served here under /__fonts/: the same glyphs,
// no network. A family whose package is not installed falls back to the next font and is reported on start-up.
const RANGES = {
  latin: 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
  cyrillic: 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
};
const missingFonts = new Set();
let warned = false;
function localFace(family, weight) {
  if (family === 'Material Symbols Outlined') {
    return `@font-face{font-family:'${family}';font-style:normal;font-weight:100 700;font-display:block;src:url(/__fonts/material-symbols/material-symbols-outlined.woff2) format('woff2')}`;
  }
  const pkg = family.toLowerCase().replace(/\s+/g, '-');
  return Object.entries(RANGES)
    .map(([subset, range]) => {
      const file = `@fontsource/${pkg}/files/${pkg}-${subset}-${weight}-normal.woff2`;
      if (!existsSync(join(modules, file))) {
        missingFonts.add(`@fontsource/${pkg} (${weight})`);
        return '';
      }
      return `@font-face{font-family:'${family}';font-style:normal;font-weight:${weight};font-display:block;src:url(/__fonts/${file}) format('woff2');unicode-range:${range}}`;
    })
    .join('');
}
function localFonts(html) {
  const faces = new Set();
  const out = html.replace(/@font-face\s*\{[^}]*fonts\.gstatic\.com[^}]*\}/g, (rule) => {
    const family = /font-family:\s*'([^']+)'/.exec(rule)?.[1];
    const weight = /font-weight:\s*(\d+)/.exec(rule)?.[1] ?? '400';
    if (family) faces.add(localFace(family, weight));
    return '';
  });
  if (missingFonts.size && !warned) {
    warned = true;
    console.warn(`ui-parity: no local font for ${[...missingFonts].join(', ')} — npm i -D the package`);
  }
  return out.replace('</head>', `<style id="ui-parity-fonts">${[...faces].join('\n')}</style></head>`);
}
const root = resolve(fileURLToPath(new URL('../dist/frontend/browser', import.meta.url)));
const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.md': 'text/markdown; charset=utf-8',
};

async function file(path) {
  const full = normalize(join(root, path));
  if (!full.startsWith(root)) return null;
  try {
    return (await stat(full)).isFile() ? full : null;
  } catch {
    return null;
  }
}

createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost');
  if (/^\/(api|sanctum)\//.test(url.pathname)) {
    if (!proxy) {
      res.writeHead(503).end('mock only');
      return;
    }
    const out = httpRequest(
      { host: proxy.hostname, port: proxy.port, path: req.url, method: req.method, headers: { ...req.headers, host: req.headers.host } },
      (up) => {
        res.writeHead(up.statusCode ?? 502, up.headers);
        up.pipe(res);
      },
    );
    out.on('error', () => res.writeHead(502).end());
    req.pipe(out);
    return;
  }
  // Local copies of the Google Fonts for the tests (e2e/harness.ts rewrites the font CSS to these).
  const font = /^\/__fonts\/((?:@fontsource\/[a-z0-9-]+\/files|material-symbols)\/[a-z0-9-]+\.woff2)$/.exec(url.pathname);
  if (font) {
    try {
      const body = await readFile(join(modules, font[1]));
      res.writeHead(200, { 'content-type': 'font/woff2', 'cache-control': 'public, max-age=86400' });
      res.end(body);
    } catch {
      res.writeHead(404).end();
    }
    return;
  }
  const path = (await file(decodeURIComponent(url.pathname))) ?? (await file('/index.html'));
  if (!path) {
    res.writeHead(404).end('build missing: run npm run build');
    return;
  }
  res.writeHead(200, { 'content-type': types[extname(path)] ?? 'application/octet-stream', 'cache-control': 'no-store' });
  const body = await readFile(path);
  res.end(!proxy && path.endsWith('index.html') ? localFonts(body.toString('utf8')) : body);
}).listen(port, '127.0.0.1', () => console.log(`ui-parity server on http://127.0.0.1:${port}${proxy ? ` → ${proxy.origin}` : ''}`));

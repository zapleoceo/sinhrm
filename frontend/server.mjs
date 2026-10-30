// Production server of the SPA (ITSTEP on-site container). Serves the Angular build from dist/frontend/browser and falls back
// to index.html for client-side routes. It does NOT proxy: on the shared domain nginx sends /api/* and /health to the backend,
// everything else lands here (a backend path that reaches this server is a routing mistake and gets a JSON 404).
//   • unix socket — APP_SOCKET set (behind nginx in the on-site container; production speaks HTTP over the socket only)
//   • TCP port    — otherwise (PORT, default 4200; local use only)
import { createServer } from 'node:http';
import { createReadStream, chmodSync, existsSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { SPA_HEADERS, isBackendPath } from './server/headers.mjs';
import { MIME, cacheControl, resolveFile } from './server/static.mjs';
import { extname } from 'node:path';

const root = resolve(fileURLToPath(new URL('./dist/frontend/browser', import.meta.url)));
const port = Number(process.env.PORT) || 4200;
const socketPath = process.env.APP_SOCKET || '';

if (!existsSync(resolve(root, 'index.html'))) {
  console.error(`> sinhrm-frontend: ${root}/index.html not found — run "npm run build" first`);
  process.exit(1);
}

async function serveStatic(req, res, pathname) {
  let file = await resolveFile(root, pathname);
  const spa = !file;
  // A missing asset (has an extension) is a real 404; a missing route is a client-side route → index.html.
  if (spa && extname(pathname)) { res.writeHead(404, { 'content-type': 'text/plain' }); return res.end('Not found'); }
  file ??= resolve(root, 'index.html');
  res.writeHead(200, { ...SPA_HEADERS, 'content-type': MIME[extname(file)] ?? 'application/octet-stream', 'cache-control': spa ? 'no-cache' : cacheControl(file, pathname) });
  if (req.method === 'HEAD') return res.end();
  createReadStream(file).pipe(res);
}

const server = createServer((req, res) => {
  const { pathname } = new URL(req.url ?? '/', 'http://localhost');
  if (isBackendPath(pathname)) {
    res.writeHead(404, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ message: 'Not routed to the backend' }));
  }
  if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405, { allow: 'GET, HEAD' }); return res.end(); }
  serveStatic(req, res, pathname).catch((err) => {
    console.error(`> static ${pathname}: ${err.message}`);
    if (!res.headersSent) res.writeHead(500);
    res.end();
  });
});

if (socketPath) {
  // A socket file can survive a crash that skipped cleanup; listen() refuses to rebind onto it.
  if (existsSync(socketPath)) unlinkSync(socketPath);
  server.listen(socketPath, () => {
    chmodSync(socketPath, 0o666); // let nginx (different user/group) reach it
    console.log(`> sinhrm-frontend listening on unix:${socketPath}`);
  });
} else {
  server.listen(port, () => console.log(`> sinhrm-frontend listening on http://localhost:${port}`));
}

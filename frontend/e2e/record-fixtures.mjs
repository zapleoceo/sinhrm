// Records the API fixtures of the UI parity harness (e2e/fixtures/*.json) from a LOCAL backend. Not run in CI.
//
// What it does: serves the production build (e2e/serve.mjs) with /api and /sanctum proxied to the local backend,
// opens every page and state of e2e/pages.mjs at 1440 and 390 px, stores every GET answer, trims lists to 30 rows,
// replaces anything that is not synthetic (see sanitize()), and writes e2e/fixtures/<area>.json + meta.json.
//
// Prerequisites (docs/guides/ui-parity.md, «Перезапис фікстур»):
//   1. backend on SQLite with migrations + all DemoDataService steps + a superadmin user, served by
//      `php artisan serve --port=8017`, SANCTUM_STATEFUL_DOMAINS=127.0.0.1:<E2E_PORT, default 4317>;
//   2. a session cookie of that superadmin, made by a local helper OUTSIDE the repo (never a login route),
//      saved as JSON {"name": "<session cookie name>", "value": "<encrypted cookie value>"};
//   3. `npm run build`.
// Run: SINHRM_SESSION=D:/Projects/_tmp/sinhrm/<dir>/session.json npm run e2e:record
// RECORD_ONLY=<page id>,<page id> re-records those pages only and merges the answers into the existing fixtures.
import { spawn } from 'node:child_process';
import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { PAGES } from './pages.mjs';
import { runSteps, settle, watchNetwork } from './steps.mjs';
import { fixtureKey, sanitize, trim, areaOf } from './fixture-lib.mjs';
import { E2E_ORIGIN, E2E_PORT } from './port.mjs';

const api = process.env.SINHRM_API ?? 'http://127.0.0.1:8017';
const sessionFile = process.env.SINHRM_SESSION;
if (!sessionFile) throw new Error('SINHRM_SESSION=<path to session.json> is required');
const session = JSON.parse(await readFile(sessionFile, 'utf8'));
const port = E2E_PORT;
const base = E2E_ORIGIN;
const outDir = fileURLToPath(new URL('./fixtures/', import.meta.url));
const only = process.env.RECORD_ONLY ? new Set(process.env.RECORD_ONLY.split(',')) : null;

const server = spawn(process.execPath, [fileURLToPath(new URL('./serve.mjs', import.meta.url)), `--port=${port}`, `--proxy=${api}`], { stdio: 'inherit' });
await new Promise((r) => setTimeout(r, 800));

/** @type {Record<'user' | 'guest', Map<string, { status: number, body: unknown }>>} */
const store = { user: new Map(), guest: new Map() };
const browser = await chromium.launch();
const recordedAt = new Date();
recordedAt.setSeconds(0, 0);
// RECORD_ONLY merges into fixtures recorded at another moment: keep that moment (dates in queries depend on it).
if (only) recordedAt.setTime(Date.parse(JSON.parse(await readFile(outDir + 'meta.json', 'utf8')).recordedAt));

async function open(scope, viewport) {
  const ctx = await browser.newContext({ viewport, locale: 'uk-UA', timezoneId: 'Europe/Kyiv', reducedMotion: 'reduce' });
  if (scope === 'user') await ctx.addCookies([{ name: session.name, value: session.value, url: base }]);
  await ctx.route(/fonts\.(googleapis|gstatic)\.com/, (r) => r.abort());
  ctx.on('response', async (res) => {
    const req = res.request();
    const url = new URL(req.url());
    if (req.method() !== 'GET' || !/^\/(api|sanctum)\//.test(url.pathname)) return;
    let body;
    try {
      body = await res.json();
    } catch {
      body = null;
    }
    store[scope].set(fixtureKey('GET', url), { status: res.status(), body });
  });
  return ctx;
}

// Synthetic own column on the personal board of vacancy 1 (the drag-into-personal-column test needs one).
async function ensurePersonalColumn(ctx) {
  const page = await ctx.newPage();
  watchNetwork(page);
  await page.goto(`${base}/`);
  await page.evaluate(async () => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf };
    const board = await (await fetch('/api/vacancies/1/personal-board', { headers })).json();
    if ((board.data?.columns ?? []).length === 0) {
      await fetch('/api/vacancies/1/personal-board/columns', { method: 'POST', headers, body: JSON.stringify({ title: 'Мої [ТЕСТ]' }) });
    }
  });
  await page.close();
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
  const vp = viewport.width > 800 ? 'desktop' : 'mobile';
  if (vp === 'desktop') {
    const setup = await open('user', viewport);
    await ensurePersonalColumn(setup);
    await setup.close();
  }
  for (const p of PAGES.filter((x) => !only || only.has(x.id))) {
    const runs = [{ id: '', steps: [] }, ...(p.states ?? []).filter((s) => !s.only || s.only === vp)];
    for (const s of runs) {
      // A fresh context per run, like the tests: no localStorage (view prefs, nav groups) leaks between pages.
      const ctx = await open(p.guest ? 'guest' : 'user', viewport);
      const page = await ctx.newPage();
      await page.clock.setSystemTime(recordedAt);
      try {
        watchNetwork(page);
        await page.goto(base + p.path);
        await settle(page);
        await runSteps(page, s.steps);
        console.log(`ok   ${String(store.user.size + store.guest.size).padStart(4)} ${vp} ${p.id}${s.id ? '--' + s.id : ''}`);
      } catch (e) {
        console.log(`FAIL ${vp} ${p.id}${s.id ? '--' + s.id : ''}: ${String(e).split('\n')[0]}`);
      }
      await ctx.close();
    }
  }
}
await browser.close();
server.kill();

/** @type {Record<string, Record<string, unknown>>} */
const files = {};
if (only) {
  for (const name of await readdir(outDir)) {
    if (name.endsWith('.json') && name !== 'meta.json') files[name.slice(0, -5)] = JSON.parse(await readFile(outDir + name, 'utf8'));
  }
} else {
  await rm(outDir, { recursive: true, force: true });
  await mkdir(outDir, { recursive: true });
}
for (const scope of /** @type {const} */ (['user', 'guest'])) {
  for (const [key, value] of [...store[scope].entries()].sort(([a], [b]) => a.localeCompare(b))) {
    const file = scope === 'guest' ? 'guest' : areaOf(key);
    (files[file] ??= {})[key] = { status: value.status, body: trim(sanitize(value.body)) };
  }
}
for (const [name, data] of Object.entries(files)) {
  await writeFile(`${outDir}${name}.json`, JSON.stringify(data, null, 1) + '\n');
}
await writeFile(`${outDir}meta.json`, JSON.stringify({ recordedAt: recordedAt.toISOString(), timezone: 'Europe/Kyiv' }, null, 2) + '\n');
console.log(`fixtures: ${Object.values(files).reduce((n, f) => n + Object.keys(f).length, 0)} answers in ${Object.keys(files).length} files`);

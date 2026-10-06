// Deterministic environment of the UI parity harness: mock API from committed fixtures, fixed clock.
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import type { BrowserContext, Page, Request } from '@playwright/test';
import { fixtureKey } from './fixture-lib.mjs';
import { E2E_HOST } from './port.mjs';

interface Fixture {
  status: number;
  body: unknown;
}

/** A mutation the page sent (the mock answers it with a plausible success and logs it here). */
export interface Mutation {
  method: string;
  path: string;
  body: unknown;
}

export interface Mock {
  mutations: Mutation[];
  /** GET requests without a recorded answer — re-record fixtures (docs/guides/ui-parity.md). */
  missing: string[];
  /** Requests deliberately made by named page states, for proving the selected fixture was exercised. */
  requests: string[];
}

const here = __dirname;
const fixturesDir = join(here, 'fixtures');
const fixtures: { user: Map<string, Fixture>; guest: Map<string, Fixture> } = { user: new Map(), guest: new Map() };
for (const f of readdirSync(fixturesDir)) {
  if (!f.endsWith('.json') || f === 'meta.json') continue;
  const data = JSON.parse(readFileSync(join(fixturesDir, f), 'utf8')) as Record<string, Fixture>;
  const target = f === 'guest.json' ? fixtures.guest : fixtures.user;
  for (const [k, v] of Object.entries(data)) target.set(k, v);
}

/** "Now" of every test = the moment the fixtures were recorded (e2e/fixtures/meta.json). */
export const RECORDED_AT = new Date((JSON.parse(readFileSync(join(fixturesDir, 'meta.json'), 'utf8')) as { recordedAt: string }).recordedAt);

function lookup(scope: Map<string, Fixture>, key: string): Fixture | undefined {
  const hit = scope.get(key);
  if (hit) return hit;
  // Same path, other query (e.g. a search term the recorder did not type): the first recorded answer of that path.
  const path = key.split('?')[0];
  for (const [k, v] of scope) if (k === path || k.startsWith(path + '?')) return v;
  return undefined;
}

function mutationAnswer(method: string, path: string, body: unknown): { status: number; body?: unknown } {
  if (path === '/sanctum/csrf-cookie') return { status: 204 };
  if (method === 'DELETE') return { status: 204 };
  if (path === '/api/auth/active-role') {
    const me = (fixtures.user.get('GET /api/auth/me')?.body ?? {}) as Record<string, unknown>;
    const role = (body as { role?: string | null } | null)?.role ?? null;
    return { status: 200, body: { ...me, active_role: role, effective_roles: role ? [role] : me['roles'] } };
  }
  const id = Number(/\/(\d+)(?:\/[a-z-]+)?$/.exec(path)?.[1] ?? 1);
  const data = body && typeof body === 'object' && !Array.isArray(body) ? body : {};
  return { status: method === 'POST' ? 201 : 200, body: { data: { id, ...data } } };
}

/** Answers /api/** and /sanctum/** from fixtures and logs mutations; every other host is cut off. */
export async function installMock(context: BrowserContext, opts: { guest?: boolean } = {}): Promise<Mock> {
  const mock: Mock = { mutations: [], missing: [], requests: [] };
  // Fonts are local (e2e/serve.mjs rewrites the inlined Google Fonts); any other host = network dependence.
  await context.route((url) => /^https?:$/.test(url.protocol) && url.host !== E2E_HOST, (route) => route.abort('blockedbyclient'));
  await context.route((url) => url.protocol === 'http:' && url.host === E2E_HOST && /^\/(api|sanctum)\//.test(url.pathname), async (route) => {
    const req: Request = route.request();
    const url = new URL(req.url());
    const method = req.method();
    if (method === 'GET') {
      const key = fixtureKey('GET', url);
      mock.requests.push(key);
      const hit = (opts.guest ? lookup(fixtures.guest, key) : undefined) ?? lookup(fixtures.user, key);
      if (!hit) {
        mock.missing.push(key);
        await route.fulfill({ status: 404, json: { message: 'ui-parity: no fixture' } });
        return;
      }
      await route.fulfill(hit.status === 204 ? { status: 204 } : { status: hit.status, json: hit.body });
      return;
    }
    let body: unknown;
    try {
      body = req.postDataJSON();
    } catch {
      body = req.postData();
    }
    mock.mutations.push({ method, path: url.pathname, body });
    const answer = mutationAnswer(method, url.pathname, body);
    const headers: Record<string, string> = url.pathname === '/sanctum/csrf-cookie' ? { 'set-cookie': 'XSRF-TOKEN=ui-parity; Path=/' } : {};
    await route.fulfill(answer.body === undefined ? { status: answer.status, headers } : { status: answer.status, headers, json: answer.body });
  });
  return mock;
}

/** Console errors, page errors, failed requests and unexpected HTTP errors seen by a page. */
export function watchErrors(page: Page, expectedStatus: (path: string, status: number) => boolean): string[] {
  const errors: string[] = [];
  page.on('console', (m) => {
    // "Failed to load resource" duplicates an HTTP status, which the response handler below judges.
    if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) errors.push(`console: ${m.text()}`);
  });
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('requestfailed', (r) => {
    const u = new URL(r.url());
    // ERR_ABORTED = the app cancelled the request (route change, switchMap) — normal, not a failure.
    if (u.host === E2E_HOST && r.failure()?.errorText !== 'net::ERR_ABORTED') errors.push(`requestfailed: ${r.method()} ${u.pathname} ${r.failure()?.errorText ?? ''}`);
  });
  page.on('response', (r) => {
    const u = new URL(r.url());
    if (u.host === E2E_HOST && r.status() >= 400 && !expectedStatus(u.pathname, r.status())) {
      errors.push(`http ${r.status()}: ${r.request().method()} ${u.pathname}${u.search}`);
    }
  });
  return errors;
}

/** Recorded non-2xx answers are part of the scenario (guest 401, «no employee profile» 404) — not errors. */
export function recordedStatus(path: string, status: number): boolean {
  for (const scope of [fixtures.user, fixtures.guest]) {
    for (const [k, v] of scope) if (v.status === status && k.split('?')[0] === `GET ${path}`) return true;
  }
  return false;
}

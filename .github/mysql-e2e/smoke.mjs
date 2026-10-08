// CI-only MySQL 8.4 e2e run (.github/workflows/mysql-e2e.yml). Copied into frontend/e2e/ before the run so the
// harness modules resolve. Real backend (php artisan serve) behind e2e/serve.mjs --proxy, real demo data, no mocks.
// Output: $OUT/report.json, $OUT/screens/*.png. Exit code 1 when any 5xx / page error was seen.
import { chromium } from '@playwright/test';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { PAGES } from './pages.mjs';
import { runSteps, settle, watchNetwork } from './steps.mjs';
import { E2E_ORIGIN as base } from './port.mjs';

const OUT = process.env.OUT ?? '.out/mysql';
const session = JSON.parse(await readFile(process.env.SINHRM_SESSION, 'utf8'));
const routes = JSON.parse(await readFile(process.env.ROUTES_JSON, 'utf8'));
await mkdir(`${OUT}/screens`, { recursive: true });

const EXTRA = [
  'admin/scripts', 'admin/perform/reviews', 'admin/pulse', 'admin/documents/templates', 'admin/hiring-requests',
  'admin/acquisition-channels', 'admin/time', 'safe-speak/inbox', 'admin/timeoff', 'admin/directory', 'admin/mail',
  'admin/sheets-import', 'reports/builder', 'status', 'settings/extension', 'hiring-requests/inbox', 'jobs',
  'pulse/waves/1/results', 'admin/workflows/1', 'admin/knowledge/1', 'admin/scripts/1',
].map((p) => ({ id: 'x-' + p.replace(/\//g, '-'), path: '/' + p }));

const report = { pages: [], scenarios: [], api: { total: 0, byStatus: {}, errors: [] } };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'uk-UA', timezoneId: 'Europe/Kyiv' });
await ctx.addCookies([{ name: session.name, value: session.value, url: base }]);
await ctx.route(/fonts\.(googleapis|gstatic)\.com/, (r) => r.abort());

function watch(page, sink) {
  page.on('console', (m) => { if (m.type() === 'error') sink.console.push(m.text().slice(0, 400)); });
  page.on('pageerror', (e) => sink.pageErrors.push(String(e).slice(0, 400)));
  page.on('response', async (res) => {
    const u = new URL(res.url());
    if (!/^\/(api|sanctum)\//.test(u.pathname) || res.status() < 400) return;
    let body = '';
    try { body = (await res.text()).slice(0, 600); } catch { /* aborted */ }
    sink.http.push({ method: res.request().method(), url: u.pathname + u.search, status: res.status(), body });
  });
}

// ---------------------------------------------------------------- 1. every page + state of the inventory
for (const p of [...PAGES.filter((x) => !x.guest), ...EXTRA]) {
  for (const s of [{ id: '', steps: [] }, ...(p.states ?? []).filter((x) => !x.only || x.only === 'desktop')]) {
    const id = p.id + (s.id ? '--' + s.id : '');
    const sink = { id, path: p.path, console: [], pageErrors: [], http: [], ok: true, error: null, finalUrl: '' };
    const page = await ctx.newPage();
    watch(page, sink);
    watchNetwork(page);
    try {
      await page.goto(base + p.path);
      await settle(page, 500);
      await runSteps(page, s.steps);
      sink.finalUrl = new URL(page.url()).pathname;
    } catch (e) {
      sink.ok = false;
      sink.error = String(e).split('\n')[0];
    }
    await page.screenshot({ path: `${OUT}/screens/${id}.png`, fullPage: true }).catch(() => {});
    report.pages.push(sink);
    console.log(`${sink.ok ? 'ok  ' : 'FAIL'} ${id} http>=400:${sink.http.length} console:${sink.console.length} pageerr:${sink.pageErrors.length}${sink.error ? ' ' + sink.error : ''}`);
    await page.close();
  }
}

// ---------------------------------------------------------------- 2. scenarios (API through the SPA origin + UI)
const page = await ctx.newPage();
await page.goto(base + '/');
await settle(page);
async function api(method, path, body) {
  return page.evaluate(async ({ method, path, body }) => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const r = await fetch(path, { method, credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: body ? JSON.stringify(body) : undefined });
    let json = null; const text = await r.text();
    try { json = JSON.parse(text); } catch { /* not json */ }
    return { status: r.status, json, text: json ? '' : text.slice(0, 400) };
  }, { method, path, body });
}
function scenario(name, data, pass) {
  report.scenarios.push({ name, pass, data });
  console.log(`${pass ? 'PASS' : 'FAIL'} scenario ${name}: ${JSON.stringify(data).slice(0, 500)}`);
}
const total = (r) => r.json?.meta?.total ?? r.json?.total ?? (Array.isArray(r.json?.data) ? r.json.data.length : null);
const names = (r) => (r.json?.data ?? []).map((x) => x.full_name ?? x.name ?? x.title ?? x.id);

try {
  // People: case / ё-е / ї-і insensitivity of the live search
  const q = {};
  for (const term of ['їжакевич', 'ЇЖАКЕВИЧ', 'іжакевич', 'семён', 'семен', 'СЕМЕН', 'ґудзь', 'гудзь', 'ірина ґудзь', 'ёлкін', 'елкін', 'коваленко', 'КОВАЛЕНКО', 'КоВаЛеНкО', '%', '_']) {
    const r = await api('GET', '/api/people?q=' + encodeURIComponent(term));
    q[term] = { status: r.status, total: total(r), first: names(r).slice(0, 3) };
  }
  scenario('people.search.case', { коваленко: q['коваленко'], КОВАЛЕНКО: q['КОВАЛЕНКО'], КоВаЛеНкО: q['КоВаЛеНкО'] }, q['коваленко'].total > 0 && q['коваленко'].total === q['КОВАЛЕНКО'].total && q['КОВАЛЕНКО'].total === q['КоВаЛеНкО'].total);
  scenario('people.search.cyrillic-specials', q, q['їжакевич'].total > 0 && q['ЇЖАКЕВИЧ'].total === q['їжакевич'].total && q['ґудзь'].total > 0);
  scenario('people.search.like-escape', { '%': q['%'], _: q['_'] }, q['%'].status === 200 && q['_'].status === 200);

  // People: every sort × direction, NULLs last both ways for manager/department/position/branch
  const sorts = {};
  for (const sort of ['name', 'position', 'department', 'branch', 'manager']) {
    for (const dir of ['asc', 'desc']) {
      const r = await api('GET', `/api/people?sort=${sort}&dir=${dir}&perPage=100`);
      const rows = r.json?.data ?? [];
      const key = { position: 'position', department: 'department', branch: 'branch', manager: 'manager' }[sort];
      const vals = key ? rows.map((x) => { const v = x[key] ?? x[key + '_name'] ?? null; return v && typeof v === 'object' ? (v.name ?? v.full_name ?? null) : v; }) : rows.map((x) => x.full_name);
      const firstNull = vals.indexOf(null);
      const nullsLast = firstNull === -1 || vals.slice(firstNull).every((v) => v === null);
      sorts[`${sort}.${dir}`] = { status: r.status, n: rows.length, nullsLast, head: vals.slice(0, 3), tail: vals.slice(-3) };
    }
  }
  scenario('people.sort', sorts, Object.values(sorts).every((s) => s.status === 200 && s.nullsLast));

  // Other lists: sorts of users / audit / dictionaries
  const other = {};
  for (const path of ['/api/users?sort=last_login&dir=asc', '/api/users?sort=last_login&dir=desc', '/api/users?sort=status', '/api/users?q=DEMO', '/api/users?q=демо',
    '/api/audit?sort=user&dir=asc', '/api/audit?sort=entity&dir=desc', '/api/audit?sort=action', '/api/candidates?sort=screening_score', '/api/candidates?q=КОВАЛ',
    '/api/vacancies?q=МЕНЕДЖЕР', '/api/people/search?q=КОВ', '/api/people/search?q=їжа', '/api/people?status=terminated', '/api/people?manager=ковал', '/api/people?contact=SINHRM.TEST']) {
    const r = await api('GET', path);
    other[path] = { status: r.status, total: total(r), body: r.status >= 400 ? (r.text || JSON.stringify(r.json).slice(0, 300)) : undefined };
  }
  scenario('lists.misc', other, Object.values(other).every((x) => x.status < 500));

  // Knowledge: long body + Cyrillic search (case, ё/е, ї/і)
  const body = ('Довгий текст статті з Ёлкою та Їжаком. ' + 'Ґанок, їжа, ЄВРО, ёмкость. ').repeat(1500).slice(0, 99_000);
  const created = await api('POST', '/api/knowledge/articles', { title: 'Ёлка і Їжак — перевірка MySQL [ТЕСТ]', body_md: body, tags: ['mysql-e2e', 'Їжак'], status: 'published' });
  const kb = {};
  for (const term of ['Ёлка', 'ёлка', 'ЁЛКА', 'елка', 'їжак', 'ЇЖАК', 'іжак', 'mysql']) {
    const r = await api('GET', '/api/knowledge/articles?q=' + encodeURIComponent(term));
    kb[term] = { status: r.status, total: total(r), first: names(r).slice(0, 2) };
  }
  const art = created.json?.data?.id ?? created.json?.id;
  const shown = art ? await api('GET', `/api/knowledge/articles/${art}`) : { status: 0, json: null };
  const len = (shown.json?.data?.body_md ?? shown.json?.body_md ?? '').length;
  scenario('knowledge.create-long', { status: created.status, err: created.status >= 400 ? JSON.stringify(created.json ?? created.text).slice(0, 400) : undefined, id: art, readBackLength: len, sent: body.length, sentTrimmed: body.trim().length }, created.status < 300 && len === body.trim().length);
  scenario('knowledge.search-cyrillic', kb, kb['ёлка'].total > 0 && kb['ЁЛКА'].total === kb['ёлка'].total && kb['їжак'].total > 0 && kb['ЇЖАК'].total === kb['їжак'].total);

  // People: dismissal and restore (Kyiv "today")
  const list = await api('GET', '/api/people?perPage=50&sort=name');
  const victim = (list.json?.data ?? []).find((x) => !String(x.full_name).includes('Їжакевич') && x.status !== 'terminated');
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date());
  if (victim) {
    const t = await api('POST', `/api/people/${victim.id}/terminate`, { fired_at: today, reason: 'Перевірка MySQL [ТЕСТ] — ґ є ї ё' });
    const after = await api('GET', `/api/people/${victim.id}`);
    const terminatedList = await api('GET', '/api/people?status=terminated&q=' + encodeURIComponent(String(victim.full_name).split(' ')[0].toUpperCase()));
    const restore = await api('POST', `/api/people/${victim.id}/restore`, {});
    const cancel = restore.status >= 400 ? await api('POST', `/api/people/${victim.id}/terminate/cancel`, {}) : null;
    const back = await api('GET', `/api/people/${victim.id}`);
    const st = (r) => r.json?.data?.status ?? r.json?.status;
    const fired = (r) => r.json?.data?.fired_at ?? r.json?.fired_at;
    scenario('people.terminate-restore', {
      id: victim.id, today, terminate: t.status, terminateErr: t.status >= 400 ? JSON.stringify(t.json).slice(0, 300) : undefined,
      statusAfter: st(after), firedAt: fired(after), inTerminatedList: total(terminatedList),
      restore: restore.status, restoreErr: restore.status >= 400 ? JSON.stringify(restore.json).slice(0, 300) : undefined,
      cancel: cancel?.status, statusBack: st(back), firedBack: fired(back),
    }, t.status < 300 && (restore.status < 300 || (cancel?.status ?? 500) < 300) && st(back) !== 'terminated');
  } else {
    scenario('people.terminate-restore', { error: 'no active employee in list', status: list.status }, false);
  }

  // UI: people live search + sort header + open card
  const ui = {};
  const p2 = await ctx.newPage();
  const uiSink = { console: [], pageErrors: [], http: [] };
  watch(p2, uiSink);
  watchNetwork(p2);
  await p2.goto(base + '/people');
  await settle(p2, 500);
  const box = p2.locator('input[type=search], input[type=text], input:not([type])').first();
  ui.searchBoxVisible = await box.isVisible();
  await box.fill('ЇЖАКЕВИЧ');
  await settle(p2, 800);
  ui.searchUpperFound = await p2.getByText('Їжакевич', { exact: false }).count();
  await box.fill('семен');
  await settle(p2, 800);
  ui.searchYoFound = await p2.getByText('Семён', { exact: false }).count();
  await box.fill('');
  await settle(p2, 800);
  await p2.screenshot({ path: `${OUT}/screens/ui-people-search.png`, fullPage: true });
  await p2.getByRole('radio').first().click(); // table view (the cards state above left "cards" in localStorage)
  await settle(p2, 800);
  ui.rowsAfterClear = await p2.locator('tbody tr').count();
  const header = p2.locator('th', { hasText: 'Керівник' }).getByRole('button').first();
  await header.click({ timeout: 5000 }).catch((e) => { ui.sortClickError = String(e).split('\n')[0]; });
  await settle(p2, 800);
  ui.sortUrl1 = new URL(p2.url()).search;
  ui.firstManagerAsc = (await p2.locator('tbody tr').first().locator('td').last().textContent())?.trim();
  await header.click({ timeout: 5000 }).catch(() => {});
  await settle(p2, 800);
  ui.sortUrl2 = new URL(p2.url()).search;
  ui.firstManagerDesc = (await p2.locator('tbody tr').first().locator('td').last().textContent())?.trim();
  await p2.screenshot({ path: `${OUT}/screens/ui-people-sorted.png`, fullPage: true });
  await p2.locator('tbody tr').first().getByRole('link').first().click({ timeout: 5000 }).catch((e) => { ui.cardClickError = String(e).split('\n')[0]; });
  await settle(p2, 800);
  ui.cardUrl = new URL(p2.url()).pathname;
  await p2.screenshot({ path: `${OUT}/screens/ui-people-card.png`, fullPage: true });
    ui.errors = { pageErrors: uiSink.pageErrors, http: uiSink.http.filter((h) => h.status !== 429) };
  scenario('ui.people', ui, ui.searchUpperFound > 0 && ui.searchYoFound > 0 && /sort=manager/.test(ui.sortUrl1 ?? '') && /dir=desc/.test(ui.sortUrl2 ?? '') && /^\/people\/\d+/.test(ui.cardUrl) && ui.errors.http.every((h) => h.status < 500) && uiSink.pageErrors.length === 0);

  // UI: knowledge search box with Cyrillic upper-case
  const p3 = await ctx.newPage();
  const kSink = { console: [], pageErrors: [], http: [] };
  watch(p3, kSink);
  watchNetwork(p3);
  await p3.goto(base + '/knowledge');
  await settle(p3, 500);
  await p3.getByRole('searchbox').or(p3.getByRole('textbox')).first().fill('ЁЛКА').catch(() => {});
  await p3.keyboard.press('Enter').catch(() => {});
  await settle(p3, 800);
  const kFound = await p3.getByText('Ёлка і Їжак', { exact: false }).count();
  await p3.screenshot({ path: `${OUT}/screens/ui-knowledge-search.png`, fullPage: true });
  scenario('ui.knowledge-search', { found: kFound, errors: kSink }, kFound > 0 && kSink.http.every((h) => h.status < 500));
} catch (e) {
  scenario('scenarios.crash', { error: String(e).split('\n').slice(0, 3).join(' ') }, false);
}

// ---------------------------------------------------------------- 3. every GET API route
const skip = /^api\/(auth\/google|ops\/|sanctum)/;
for (const r of routes) {
  const methods = String(r.method).split('|');
  if (!methods.includes('GET') || !r.uri.startsWith('api/') || skip.test(r.uri)) continue;
  const path = '/' + r.uri.replace(/\{[^}]+\?\}/g, '').replace(/\{[^}]+\}/g, '1').replace(/\/+$/, '');
  const res = await api('GET', path);
  report.api.total++;
  report.api.byStatus[res.status] = (report.api.byStatus[res.status] ?? 0) + 1;
  if (res.status >= 400 && res.status !== 404 || res.status === 0) {
    report.api.errors.push({ uri: r.uri, path, status: res.status, body: (res.text || JSON.stringify(res.json)).slice(0, 600) });
  }
}
console.log(`API GET sweep: ${report.api.total} routes, by status ${JSON.stringify(report.api.byStatus)}`);
for (const e of report.api.errors) console.log(`  ${e.status >= 500 ? '5xx' : '4xx'} ${e.status} ${e.path}: ${e.body.slice(0, 300)}`);

await browser.close();
await writeFile(`${OUT}/report.json`, JSON.stringify(report, null, 1));
const bad5xx = report.pages.flatMap((p) => p.http.filter((h) => h.status >= 500).map((h) => `${p.id}: ${h.status} ${h.method} ${h.url}`));
const badPage = report.pages.filter((p) => p.pageErrors.length || !p.ok).map((p) => `${p.id}: ${p.error ?? p.pageErrors[0]}`);
const all4xx = report.pages.flatMap((p) => p.http.filter((h) => h.status < 500 && h.status !== 429).map((h) => `${p.id}: ${h.status} ${h.method} ${h.url} ${h.body.slice(0, 160)}`));
console.log('\n==== SUMMARY ====');
console.log(`pages: ${report.pages.length}, with 5xx: ${bad5xx.length}, page errors/fails: ${badPage.length}, 4xx: ${all4xx.length}`);
for (const l of [...bad5xx, ...badPage]) console.log('  ! ' + l);
for (const l of all4xx) console.log('  4xx ' + l);
console.log(`scenarios: ${report.scenarios.filter((s) => s.pass).length}/${report.scenarios.length} pass; failed: ${report.scenarios.filter((s) => !s.pass).map((s) => s.name).join(', ') || '-'}`);
const api5xx = report.api.errors.filter((e) => e.status >= 500 || e.status === 0);
console.log(`429 (assistant quips throttle during the rapid walk): ${report.pages.reduce((n, p) => n + p.http.filter((h) => h.status === 429).length, 0)}`);
console.log(`api: ${report.api.total} GET routes, 5xx: ${api5xx.length}`);
process.exit(bad5xx.length || badPage.length || api5xx.length ? 1 : 0);

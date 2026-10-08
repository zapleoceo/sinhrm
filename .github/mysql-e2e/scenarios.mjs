// CI-only MySQL 8.4 e2e run, round 2 (.github/workflows/mysql-e2e.yml, branch test/mysql-e2e2). Copied into
// frontend/e2e/ before the run. Real backend (php artisan serve on MySQL 8.4 with the full demo data) behind
// e2e/serve.mjs --proxy; a second backend with a faked clock (Kyiv 00:30 of a Monday) behind :4318.
// Requests go through a page of the SPA origin of each role (cookie session + XSRF), i.e. exactly what the UI sends.
// Output: $OUT/scenarios.json (every scenario with its evidence) and a PASS/FAIL table on stdout.
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { crc32 } from 'node:zlib';

const OUT = process.env.OUT ?? '.out/mysql';
const S = JSON.parse(await readFile(process.env.SINHRM_SESSION, 'utf8'));
const BASE = 'http://127.0.0.1:4317';
const FAKE = 'http://127.0.0.1:4318';
await mkdir(OUT, { recursive: true });

const results = [];
function record(group, name, pass, data) {
  results.push({ group, name, pass, data });
  console.log(`${pass ? 'PASS' : 'FAIL'} [${group}] ${name}: ${JSON.stringify(data).slice(0, 700)}`);
}

// ---------------------------------------------------------------- sessions: one browser context per role
const browser = await chromium.launch();
const pages = {};
async function open(role, base, cookie) {
  const ctx = await browser.newContext({ locale: 'uk-UA', timezoneId: 'Europe/Kyiv' });
  await ctx.route(/fonts\.(googleapis|gstatic)\.com/, (r) => r.abort());
  if (cookie) await ctx.addCookies([{ name: cookie.name, value: cookie.value, url: base }]);
  const page = await ctx.newPage();
  await page.goto(base + '/');
  pages[role] = { page, ctx, base };
}
for (const role of ['admin', 'recruiter', 'manager', 'employee', 'hr']) await open(role, BASE, S[role]);
await open('guest', BASE, null);
await open('fakeAdmin', FAKE, S.fakeAdmin);
await open('fakeEmployee', FAKE, S.fakeEmployee);

/**
 * One request from the role's SPA page. body: object (JSON) | {multipart: {field: string | {name, type, b64}}}.
 * Returns status, parsed JSON (or a text head), the download headers and the first bytes.
 */
async function api(role, method, path, body) {
  const { page } = pages[role];
  return page.evaluate(async ({ method, path, body }) => {
    if (method !== 'GET') await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf };
    let payload;
    if (body && body.multipart) {
      payload = new FormData();
      for (const [k, v] of Object.entries(body.multipart)) {
        if (typeof v === 'string') payload.append(k, v);
        else payload.append(k, new Blob([Uint8Array.from(atob(v.b64), (c) => c.charCodeAt(0))], { type: v.type }), v.name);
      }
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const t = performance.now();
    const r = await fetch(path, { method, credentials: 'include', headers, body: payload });
    const buf = new Uint8Array(await r.arrayBuffer());
    const ms = Math.round(performance.now() - t);
    const text = new TextDecoder().decode(buf);
    let json = null;
    try { json = JSON.parse(text); } catch { /* not json */ }
    const h = (n) => r.headers.get(n);
    return {
      status: r.status, ms, json, text: json ? '' : text.slice(0, 600), bytes: buf.length, lineCount: json ? null : text.split(String.fromCharCode(10)).length,
      head: Array.from(buf.slice(0, 4)).map((b) => b.toString(16).padStart(2, '0')).join(''),
      headers: { type: h('content-type'), disposition: h('content-disposition'), nosniff: h('x-content-type-options'), cache: h('cache-control'), retryAfter: h('retry-after') },
    };
  }, { method, path, body });
}
const data = (r) => r.json?.data ?? r.json;
const err = (r) => (r.status >= 400 ? (r.json ? JSON.stringify(r.json).slice(0, 300) : r.text.slice(0, 300)) : undefined);
const brief = (r) => ({ status: r.status, err: err(r) });
const kyivToday = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date());
const addDays = (ymd, n) => { const d = new Date(ymd + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const nextWeekday = (ymd, wd) => { let d = ymd; for (let i = 0; i < 8; i++) { d = addDays(d, 1); if (new Date(d + 'T12:00:00Z').getUTCDay() === wd) return d; } return d; };
async function group(name, fn) {
  try { await fn(); } catch (e) { record(name, 'crash', false, { error: String(e).split('\n').slice(0, 3).join(' ') }); }
}

// ---------------------------------------------------------------- files
const b64 = (buf) => Buffer.from(buf).toString('base64');
const pdf = (pad = 0) => b64(Buffer.concat([Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n'), Buffer.alloc(pad, 0x20), Buffer.from('\ntrailer<<>>\n%%EOF\n')]));
function zipOne(name, content) { // a stored (uncompressed) zip with one entry: a minimal .docx container
  const n = Buffer.from(name); const c = Buffer.from(content); const crc = crc32(c);
  const local = Buffer.alloc(30); local.writeUInt32LE(0x04034b50, 0); local.writeUInt16LE(20, 4); local.writeUInt32LE(crc, 14); local.writeUInt32LE(c.length, 18); local.writeUInt32LE(c.length, 22); local.writeUInt16LE(n.length, 26);
  const central = Buffer.alloc(46); central.writeUInt32LE(0x02014b50, 0); central.writeUInt16LE(20, 4); central.writeUInt16LE(20, 6); central.writeUInt32LE(crc, 16); central.writeUInt32LE(c.length, 20); central.writeUInt32LE(c.length, 24); central.writeUInt16LE(n.length, 28);
  const end = Buffer.alloc(22); end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(1, 8); end.writeUInt16LE(1, 10); end.writeUInt32LE(46 + n.length, 12); end.writeUInt32LE(30 + n.length + c.length, 16);
  return Buffer.concat([local, n, c, central, n, end]);
}
const docx = () => b64(zipOne('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'));
const PDF = { name: 'Договір ґ [ТЕСТ].pdf', type: 'application/pdf', b64: pdf() };
const DOCX = { name: 'Наказ [ТЕСТ].docx', type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', b64: docx() };
const BIG = { name: 'big.pdf', type: 'application/pdf', b64: pdf(2 * 1024 * 1024 + 10) };
const EXE_AS_PDF = { name: 'invoice.pdf', type: 'application/pdf', b64: b64(Buffer.concat([Buffer.from('MZ\x90\x00'), Buffer.alloc(512, 1)])) };
const TXT = { name: 'note.txt', type: 'text/plain', b64: b64(Buffer.from('просто текст')) };
const HTML_AS_PNG = { name: 'pic.png', type: 'image/png', b64: b64(Buffer.from('<html><script>alert(1)</script></html>')) };

const today = kyivToday();
const ids = {};

// ================================================================= (1) uploads / downloads
await group('uploads', async () => {
  const empId = S.employee.employeeId;
  const doc = await api('admin', 'POST', '/api/documents', { employee_id: empId, title: 'Документ e2e ґїєё [ТЕСТ]', category: 'contract' });
  const docId = data(doc)?.id;
  ids.doc = docId;
  record('uploads', 'documents.create', doc.status === 201 && !!docId, brief(doc));
  const up = {};
  for (const [k, f] of Object.entries({ pdf: PDF, docx: DOCX, big: BIG, exeAsPdf: EXE_AS_PDF, txt: TXT, htmlAsPng: HTML_AS_PNG })) {
    const r = await api('admin', 'POST', `/api/documents/${docId}/file`, { multipart: { file: f } });
    up[k] = { status: r.status, code: r.json?.code ?? Object.keys(r.json?.errors ?? {}).join(','), file: data(r)?.file ?? null };
  }
  record('uploads', 'documents.upload pdf/docx ok, >2MB / wrong type refused', up.pdf.status === 200 && up.docx.status === 200 && up.big.status === 422 && up.exeAsPdf.status === 422 && up.txt.status === 422 && up.htmlAsPng.status === 422, up);
  // last accepted file is the DOCX: re-upload the PDF and download it
  await api('admin', 'POST', `/api/documents/${docId}/file`, { multipart: { file: PDF } });
  const dl = await api('admin', 'GET', `/api/documents/${docId}/file`);
  record('uploads', 'documents.download headers', dl.status === 200 && /^attachment/.test(dl.headers.disposition ?? '') && /^nosniff/.test(dl.headers.nosniff ?? '') && /application\/pdf/.test(dl.headers.type ?? '') && dl.head === '25504446', { status: dl.status, headers: dl.headers, head: dl.head, bytes: dl.bytes });
  // draft is invisible to the employee; after send — visible and downloadable; a stranger (recruiter) gets 404
  const beforeSend = await api('employee', 'GET', `/api/documents/${docId}/file`);
  const send = await api('admin', 'POST', `/api/documents/${docId}/send`);
  const mine = await api('employee', 'GET', '/api/me/documents');
  const empDl = await api('employee', 'GET', `/api/documents/${docId}/file`);
  const mgrDl = await api('manager', 'GET', `/api/documents/${docId}/file`);
  const recDl = await api('recruiter', 'GET', `/api/documents/${docId}/file`);
  const recUp = await api('recruiter', 'POST', `/api/documents/${docId}/file`, { multipart: { file: PDF } });
  record('uploads', 'documents.visibility by role', beforeSend.status === 404 && send.status < 300 && (data(mine) ?? []).some((d) => d.id === docId) && empDl.status === 200 && mgrDl.status === 200 && recDl.status === 404 && recUp.status === 403,
    { draftForEmployee: beforeSend.status, send: brief(send), inMine: (data(mine) ?? []).some((d) => d.id === docId), employee: empDl.status, manager: mgrDl.status, recruiter: recDl.status, recruiterUpload: recUp.status });
  // concurrency: 4 parallel uploads to one document (updateOrCreate on document_files)
  const doc2 = data(await api('admin', 'POST', '/api/documents', { employee_id: empId, title: 'Паралельно [ТЕСТ]' }));
  const par = await Promise.all([0, 1, 2, 3].map(() => api('admin', 'POST', `/api/documents/${doc2.id}/file`, { multipart: { file: PDF } })));
  record('uploads', 'documents.parallel uploads (no 5xx)', par.every((r) => r.status === 200), par.map(brief));

  // Desk: category (admin), case + attachments (employee), downloads by role
  const cat = await api('admin', 'POST', '/api/desk/categories', { name: 'E2E категорія [ТЕСТ]' });
  const kase = await api('employee', 'POST', '/api/desk/cases', { category_id: data(cat)?.id, subject: 'Довідка ґ [ТЕСТ]', body: 'Потрібна довідка з місця роботи. Ёлка, Їжак. ' + '😀'.repeat(2000) });
  const caseId = data(kase)?.id;
  const att = await api('employee', 'POST', `/api/desk/cases/${caseId}/attachments`, { multipart: { file: PDF } });
  const attBig = await api('employee', 'POST', `/api/desk/cases/${caseId}/attachments`, { multipart: { file: BIG } });
  const attBad = await api('employee', 'POST', `/api/desk/cases/${caseId}/attachments`, { multipart: { file: EXE_AS_PDF } });
  const attId = data(att)?.attachments?.[0]?.id;
  const own = await api('employee', 'GET', `/api/desk/cases/${caseId}/attachments/${attId}`);
  const hr = await api('admin', 'GET', `/api/desk/cases/${caseId}/attachments/${attId}`);
  const other = await api('recruiter', 'GET', `/api/desk/cases/${caseId}/attachments/${attId}`);
  record('uploads', 'desk.case + attachment + download by role', cat.status === 201 && kase.status === 201 && att.status === 201 && attBig.status === 422 && attBad.status === 422 && own.status === 200 && hr.status === 200 && other.status === 404 && /^attachment/.test(own.headers.disposition ?? '') && /^nosniff/.test(own.headers.nosniff ?? ''),
    { category: brief(cat), case: brief(kase), attach: brief(att), big: attBig.status, exeAsPdf: attBad.status, employee: own.status, admin: hr.status, recruiter: other.status, headers: own.headers });
  const many = [];
  for (let i = 0; i < 10; i++) many.push((await api('employee', 'POST', `/api/desk/cases/${caseId}/attachments`, { multipart: { file: PDF } })).status);
  record('uploads', 'desk.attachments limit 10 per case', many.filter((s) => s === 201).length === 9 && many.at(-1) === 422, { statuses: many });

  // CV: public career apply with a CV (published vacancy of the demo branch)
  const vac = await api('admin', 'GET', '/api/public/vacancies');
  let slug = (data(vac) ?? [])[0]?.slug;
  if (!slug) {
    const branches = await api('admin', 'GET', '/api/directory/branches');
    const v = await api('admin', 'POST', '/api/vacancies', { title: 'Публічна вакансія e2e [ТЕСТ]', branch_id: (data(branches) ?? [])[0]?.id, status: 'open', published: true });
    slug = data(await api('admin', 'GET', '/api/public/vacancies'))?.find((x) => x.id === data(v)?.id || x.title?.includes('e2e'))?.slug;
  }
  const email = `cv-e2e-${Date.now()}@sinhrm.test`;
  const apply = await api('guest', 'POST', `/api/public/vacancies/${slug}/apply`, { multipart: { name: 'Кандидат з CV ґ [ТЕСТ]', email, consent: '1', cv: PDF } });
  const applyDocx = await api('guest', 'POST', `/api/public/vacancies/${slug}/apply`, { multipart: { name: 'Кандидат DOCX [ТЕСТ]', email: 'docx-' + email, consent: '1', cv: DOCX } });
  const applyBig = await api('guest', 'POST', `/api/public/vacancies/${slug}/apply`, { multipart: { name: 'Кандидат великий [ТЕСТ]', email: 'big-' + email, consent: '1', cv: BIG } });
  const applyBad = await api('guest', 'POST', `/api/public/vacancies/${slug}/apply`, { multipart: { name: 'Кандидат exe [ТЕСТ]', email: 'exe-' + email, consent: '1', cv: EXE_AS_PDF } });
  const found = await api('admin', 'GET', '/api/candidates?q=' + encodeURIComponent(email));
  record('uploads', 'career.apply with CV (pdf/docx ok, >2MB / exe refused)', apply.status === 201 && applyDocx.status === 201 && applyBig.status === 422 && applyBad.status === 422,
    { slug, pdf: brief(apply), docx: brief(applyDocx), big: brief(applyBig), exe: brief(applyBad), candidateFound: (data(found) ?? []).length });
  const cand = (data(found) ?? [])[0];
  const detail = cand ? await api('recruiter', 'GET', `/api/candidates/${cand.id}`) : null;
  const cvLink = detail ? JSON.stringify(detail.json).match(/"[^"]*(cv|resume|attachment|file)[^"]*":\s*"[^"]+"/i)?.[0] ?? null : null;
  record('uploads', 'career.CV reachable by the recruiter', !!cvLink, { candidate: cand?.id ?? null, detail: detail?.status ?? null, cvLink, note: 'no download route for career_submissions.cv_content' });
});

// ================================================================= (2) import / export
await group('import-export', async () => {
  // People CSV export: 200 ids (the max), BOM, attachment, nosniff, formula guard; 201 ids refused; non-HR 403
  const list = await api('admin', 'GET', '/api/people?perPage=200&sort=name');
  const pids = (data(list) ?? []).map((x) => x.id);
  const csv = await api('admin', 'POST', '/api/people/bulk', { action: 'export', ids: pids });
  const lines = (csv.text || '').split('\n');
  const formula = await api('admin', 'POST', '/api/people/bulk', { action: 'export', ids: [S.formulaEmployeeId].filter(Boolean) });
  const over = await api('admin', 'POST', '/api/people/bulk', { action: 'export', ids: [...pids, ...Array.from({ length: 5 }, (_, i) => 900000 + i)] });
  const asRec = await api('recruiter', 'POST', '/api/people/bulk', { action: 'export', ids: pids.slice(0, 3) });
  const asMgr = await api('manager', 'POST', '/api/people/bulk', { action: 'export', ids: pids.slice(0, 3) });
  record('import-export', 'people.csv export', csv.status === 200 && csv.head.startsWith('efbbbf') && /^attachment/.test(csv.headers.disposition ?? '') && csv.headers.nosniff === 'nosniff' && /text\/csv/.test(csv.headers.type ?? '') && over.status === 422 && asRec.status === 403 && asMgr.status === 403,
    { status: csv.status, ms: csv.ms, rows: pids.length, csvLines: csv.lineCount, header: lines[0]?.slice(0, 120), headers: csv.headers, head: csv.head, over201: over.status, recruiter: asRec.status, manager: asMgr.status });
  record('import-export', 'people.csv formula guard', formula.status === 200 && /'=HYPERLINK/.test(formula.text) && !/,=HYPERLINK/.test(formula.text), { status: formula.status, line: (formula.text || '').split('\n')[1]?.slice(0, 160) });

  // Privacy export (json + html), headers; erase refused for a working employee; non-admin 403
  const pj = await api('admin', 'GET', `/api/privacy/employee/${S.employee.employeeId}/export`);
  const ph = await api('admin', 'GET', `/api/privacy/employee/${S.employee.employeeId}/export?format=html`);
  const pe = await api('admin', 'POST', `/api/privacy/employee/${S.employee.employeeId}/erase`, { reason: 'e2e', confirm: true });
  const pr = await api('admin', 'GET', `/api/privacy/employee/${S.employee.employeeId}/requests`);
  const pm = await api('manager', 'GET', `/api/privacy/employee/${S.employee.employeeId}/export`);
  const sections = Object.keys(pj.json?.sections ?? {});
  record('import-export', 'privacy.export json/html + erase guard + roles', pj.status === 200 && /^attachment/.test(pj.headers.disposition ?? '') && pj.headers.nosniff === 'nosniff' && /no-store/.test(pj.headers.cache ?? '') && ph.status === 200 && /text\/html/.test(ph.headers.type ?? '') && pe.status === 409 && pm.status === 403 && pr.status === 200,
    { json: { status: pj.status, headers: pj.headers, sections }, html: { status: ph.status, headers: ph.headers }, erase: brief(pe), requests: (data(pr) ?? []).length, manager: pm.status });
  record('import-export', 'privacy.export has documents + desk of the employee', sections.some((s) => /doc/i.test(s)) && sections.some((s) => /desk|case/i.test(s)), { sections });

  // Sheets import on the fixture client (no Google call): inspect, save+run, rerun (idempotent)
  const url = 'https://docs.google.com/spreadsheets/d/E2E_FIXTURE_SPREADSHEET_0123456789/edit';
  const ins = await api('admin', 'POST', '/api/google/sheets/inspect', { url, sheet: 'Анкети' });
  const mapping = { full_name: 0, phone: 1, email: 2, telegram: 3, utm_source: 4, vacancy: 5, created_at: 6 };
  const run1 = await api('admin', 'POST', '/api/google/sheets/imports', { url, sheet: 'Анкети', mapping, auto_sync: false });
  const impId = data(run1)?.id;
  const run2 = impId ? await api('admin', 'POST', `/api/google/sheets/imports/${impId}/run`) : { status: 0 };
  const cands = await api('admin', 'GET', '/api/candidates?perPage=200&q=' + encodeURIComponent('sheets-e2e'));
  const r1 = run1.json?.report ?? {}; const r2 = run2.json?.report ?? {};
  record('import-export', 'sheets.import fixture: inspect → run → rerun', ins.status === 200 && run1.status === 201 && (r1.created ?? 0) + (r1.matched ?? 0) >= 25 && run2.status === 200 && (r2.created ?? 0) === 0,
    { inspect: { status: ins.status, headers: data(ins)?.headers, suggested: data(ins)?.suggested, err: err(ins) }, run1: { status: run1.status, report: r1, err: err(run1) }, run2: { status: run2.status, report: r2 }, candidatesWithSheetsEmail: (data(cands) ?? []).length });
  record('import-export', 'sheets.import case-insensitive e-mail match (rows 10/20 = row 1)', (r1.matched ?? 0) >= 2, { report: r1 });
  const recIns = await api('recruiter', 'POST', '/api/google/sheets/inspect', { url, sheet: '' });
  record('import-export', 'sheets.import superadmin only', recIns.status === 403, { recruiter: recIns.status });

  // Mass candidate operations: tag / assign / move / reject 150 bulk candidates of a vacancy
  const vacs = await api('recruiter', 'GET', '/api/vacancies?perPage=50');
  const vacancy = (data(vacs) ?? []).find((v) => v.status === 'open') ?? (data(vacs) ?? [])[0];
  const vd = data(await api('recruiter', 'GET', `/api/vacancies/${vacancy.id}`));
  const stages = vd?.stages ?? [];
  const bulkC = data(await api('admin', 'GET', '/api/candidates?perPage=150&q=' + encodeURIComponent('Кандидат-масовий'))) ?? [];
  const cids = bulkC.map((c) => c.id);
  const applied = await Promise.all(cids.slice(0, 40).map((cid) => api('recruiter', 'POST', `/api/vacancies/${vacancy.id}/applications`, { candidate_id: cid })));
  const tag = await api('recruiter', 'POST', '/api/candidates/bulk', { action: 'tag', ids: cids, tag: 'Їжак-e2e' });
  const assign = await api('recruiter', 'POST', '/api/candidates/bulk', { action: 'assign', ids: cids.slice(0, 20), owner_id: S.recruiter.userId });
  const select = stages.find((s) => s.kind === 'select') ?? stages[2];
  const move = await api('recruiter', 'POST', '/api/candidates/bulk', { action: 'move', ids: cids.slice(0, 40), vacancy_id: vacancy.id, stage_id: select?.id });
  const reasons = data(await api('recruiter', 'GET', '/api/reject-reasons')) ?? [];
  const reject = await api('recruiter', 'POST', '/api/candidates/bulk', { action: 'reject', ids: cids.slice(0, 20), vacancy_id: vacancy.id, reject_reason_id: reasons[0]?.id, reason: 'Масова відмова [ТЕСТ] ґ' });
  const ok = (r) => (data(r) ?? []).filter((x) => x.ok).length;
  const tagged = await api('admin', 'GET', '/api/candidates?perPage=200&q=' + encodeURIComponent('Кандидат-масовий'));
  const tagCount = (data(tagged) ?? []).filter((c) => (c.tags ?? []).includes('Їжак-e2e')).length;
  const empBulk = await api('employee', 'POST', '/api/candidates/bulk', { action: 'tag', ids: cids.slice(0, 2), tag: 'x' });
  record('import-export', 'candidates.bulk tag/assign/move/reject', tag.status === 200 && ok(tag) === cids.length && ok(assign) === 20 && ok(move) === 40 && ok(reject) === 20 && tagCount >= cids.length - 10,
    { vacancy: vacancy?.id, candidates: cids.length, applied201: applied.filter((r) => r.status === 201).length, tag: { status: tag.status, ok: ok(tag), ms: tag.ms }, assign: ok(assign), move: { ok: ok(move), errors: (data(move) ?? []).filter((x) => !x.ok).slice(0, 3) }, reject: { ok: ok(reject), errors: (data(reject) ?? []).filter((x) => !x.ok).slice(0, 3) }, tagCountReadBack: tagCount, employee: { status: empBulk.status, ok: ok(empBulk) } });
  ids.vacancy = vacancy.id; ids.stages = stages; ids.reasons = reasons;
});

// ================================================================= (3) integrations in test mode + assistant
await group('integrations', async () => {
  const before = await api('admin', 'POST', '/api/integrations/ai_broker/check');
  const tg = await api('admin', 'POST', '/api/integrations/telegram_business/check');
  const binotel = await api('admin', 'POST', '/api/integrations/binotel/check');
  record('integrations', 'check without secrets: no network, explicit state', data(before)?.status === 'error' && /missing_secret/.test(data(before)?.last_error ?? '') && /missing_secret/.test(data(tg)?.last_error ?? '') && binotel.status === 422,
    { ai_broker: { status: before.status, state: data(before)?.status, last_error: data(before)?.last_error }, telegram: data(tg)?.last_error, binotel: brief(binotel) });
  const policy = await api('admin', 'PUT', '/api/integrations/ai-policy', { enabled: true });
  const save = await api('admin', 'PUT', '/api/integrations/ai_broker', { settings: { base_url: 'https://ai-broker.e2e.test' }, secrets: { project_key: 'e2e-stub-not-a-key' } });
  const st = await api('admin', 'POST', '/api/integrations/ai_broker/status', { status: 'demo' });
  const after = await api('admin', 'POST', '/api/integrations/ai_broker/check');
  const logs = await api('admin', 'GET', '/api/integrations/ai_broker/logs');
  const secretLeak = JSON.stringify([save.json, after.json, logs.json]).includes('e2e-stub-not-a-key');
  record('integrations', 'ai_broker check through the stub (https, SSRF guard on)', policy.status < 300 && save.status < 300 && st.status < 300 && data(after)?.status === 'connected' && !secretLeak,
    { policy: brief(policy), save: brief(save), status: brief(st), check: { status: after.status, state: data(after)?.status, last_error: data(after)?.last_error }, secretInResponses: secretLeak });
  const recCheck = await api('recruiter', 'POST', '/api/integrations/ai_broker/check');
  record('integrations', 'integrations superadmin only', recCheck.status === 403, { recruiter: recCheck.status });

  // Assistant: turn 1 → server tool find_endpoints + client call api_get; turn 2 with the tool result → final text
  const status = await api('employee', 'GET', '/api/assistant/status');
  const msgs = [{ role: 'user', content: 'Покажи відкриті вакансії' }];
  const t1 = await api('employee', 'POST', '/api/assistant/turn', { messages: msgs, page: { path: '/vacancies', title: 'Вакансії' } });
  const d1 = data(t1) ?? {};
  const calls = d1.client_calls ?? [];
  const followUp = [...msgs, d1.assistant, ...(d1.server_results ?? []), ...calls.map((c) => ({ role: 'tool', tool_call_id: c.id, content: JSON.stringify({ status: 200, data: [{ id: 1, title: 'Вакансія [ТЕСТ]' }] }) }))].filter(Boolean);
  const t2 = calls.length || (d1.server_results ?? []).length ? await api('employee', 'POST', '/api/assistant/turn', { messages: followUp, page: { path: '/vacancies', title: 'Вакансії' } }) : null;
  const d2 = t2 ? data(t2) ?? {} : {};
  const poll = d1.request_id ? await api('employee', 'GET', `/api/assistant/turns/${d1.request_id}`) : null;
  const pollOther = d1.request_id ? await api('manager', 'GET', `/api/assistant/turns/${d1.request_id}`) : null;
  record('integrations', 'assistant chat with tool calls on the broker stub', t1.status === 200 && d1.state === 'done' && (d1.server_results ?? []).length >= 1 && calls.some((c) => c.name === 'api_get') && d2.state === 'done' && /Готово/.test(d2.assistant?.content ?? '') && poll?.status === 200 && pollOther?.status === 404,
    { status: data(status), t1: { status: t1.status, ms: t1.ms, state: d1.state, error: d1.error, server: (d1.server_results ?? []).length, client: calls.map((c) => c.name) }, t2: t2 && { status: t2.status, ms: t2.ms, state: d2.state, error: d2.error, text: d2.assistant?.content?.slice(0, 80) }, poll: poll?.status, pollByOther: pollOther?.status });
});

// ================================================================= (4) lifecycles
await group('lifecycle', async () => {
  // ---- ATS: vacancy (recruiter) → candidate → offer (long template) → hired → Hire → terminate today (Kyiv) → restore
  const branches = data(await api('admin', 'GET', '/api/directory/branches')) ?? [];
  const recVacs = data(await api('recruiter', 'GET', '/api/vacancies?perPage=50')) ?? [];
  const branchId = recVacs[0]?.branch?.id ?? recVacs[0]?.branch_id ?? branches[0]?.id;
  const vac = await api('recruiter', 'POST', '/api/vacancies', { title: 'Повний цикл e2e ґїєё [ТЕСТ]', branch_id: branchId, status: 'open' });
  const vacancy = data(vac);
  const stages = vacancy?.stages ?? [];
  const st = (pred) => stages.find(pred);
  const offerStage = st((s) => s.kind === 'hire' && !s.is_terminal) ?? st((s) => /Офер/.test(s.name));
  const hiredStage = st((s) => s.is_hire && s.is_terminal) ?? st((s) => /Вийшов/.test(s.name));
  const rejectStage = st((s) => s.is_reject);
  const email = `hire-e2e-${Date.now()}@sinhrm.test`;
  const cand = await api('recruiter', 'POST', '/api/candidates', { full_name: 'Найманий Кандидат ґ [ТЕСТ]', email, vacancy_id: vacancy?.id, source: 'manual' });
  const candId = data(cand)?.id;
  const appId = (data(await api('recruiter', 'GET', `/api/candidates/${candId}`))?.applications ?? [])[0]?.id;
  record('lifecycle', 'ats.vacancy + candidate + application (recruiter)', vac.status === 201 && cand.status === 201 && !!appId, { vacancy: brief(vac), candidate: brief(cand), appId, stages: stages.map((s) => `${s.id}:${s.kind}${s.is_terminal ? '*' : ''}`) });
  const dup = await api('recruiter', 'POST', '/api/candidates', { full_name: 'Дубль', email: email.toUpperCase() });
  record('lifecycle', 'ats.duplicate by e-mail in another case → 409', dup.status === 409, brief(dup));
  // reject without a reason → 422, with a reason → rejected; then back
  const noReason = await api('recruiter', 'POST', `/api/applications/${appId}/move`, { stage_id: rejectStage?.id });
  const toOffer = await api('recruiter', 'POST', `/api/applications/${appId}/move`, { stage_id: offerStage?.id });
  record('lifecycle', 'ats.move: reject needs a reason; move to offer', noReason.status === 422 && toOffer.status === 200, { noReason: brief(noReason), toOffer: brief(toOffer) });
  // offer: template (admin) with a long body (~42k Cyrillic chars ≈ 84 KB > TEXT)
  const longBody = ('Шановний {ПІБ}! Пропонуємо посаду {Посада} із зарплатою {Зарплата}, дата виходу {Дата виходу}. Умови: {Умови}. Ґанок, їжа, ЄВРО. ').repeat(330);
  const tplShort = await api('admin', 'POST', '/api/documents/templates', { name: 'Офер e2e [ТЕСТ]', body: 'Шановний {ПІБ}! Посада {Посада}, зарплата {Зарплата}. [ТЕСТ]', category: 'offer' });
  const tplLong = await api('admin', 'POST', '/api/documents/templates', { name: 'Офер довгий e2e [ТЕСТ]', body: longBody.slice(0, 49000), category: 'offer' });
  const offerLong = await api('recruiter', 'POST', `/api/applications/${appId}/offer`, { template_id: data(tplLong)?.id, position: 'Викладач', salary: '42 000 грн', start_date: addDays(today, 7), conditions: 'Гібрид' });
  record('lifecycle', 'offer.create from a long template (> 64 KB rendered)', tplLong.status === 201 && offerLong.status === 201, { template: brief(tplLong), templateChars: 49000, offer: brief(offerLong) });
  const offer = offerLong.status === 201 ? offerLong : await api('recruiter', 'POST', `/api/applications/${appId}/offer`, { template_id: data(tplShort)?.id, position: 'Викладач', salary: '42 000 грн', start_date: addDays(today, 7), conditions: 'Гібрид' });
  const send = await api('recruiter', 'POST', `/api/applications/${appId}/offer/send`);
  // observer: the employee as interviewer sees the candidate but not the offer text
  const intv = await api('recruiter', 'PUT', `/api/applications/${appId}/interviewers`, { user_ids: [S.employee.userId] });
  const obsOffer = await api('employee', 'GET', `/api/applications/${appId}/offer`);
  const obsTl = await api('employee', 'GET', `/api/candidates/${candId}/timeline`);
  const recTl = await api('recruiter', 'GET', `/api/candidates/${candId}/timeline`);
  const offerTouch = (r) => (data(r) ?? []).map((e) => e.touchpoint).find((t) => t?.meta?.kind === 'offer');
  const ot = offerTouch(obsTl); const rt = offerTouch(recTl);
  record('lifecycle', 'offer: text hidden from the observer, visible to the recruiter', offer.status === 201 && send.status === 200 && intv.status < 300 && obsOffer.status === 403 && ot?.redacted === true && ot?.body == null && rt?.redacted === false && /Посада|Пропонуємо|Викладач/.test(rt?.body ?? ''),
    { offer: brief(offer), send: brief(send), interviewers: brief(intv), observerGetOffer: obsOffer.status, observerTimeline: { status: obsTl.status, touch: ot && { redacted: ot.redacted, body: ot.body?.slice?.(0, 40) ?? ot.body } }, recruiterTimeline: { status: recTl.status, touch: rt && { redacted: rt.redacted, bodyHead: rt.body?.slice(0, 60) } } });
  const decide = await api('recruiter', 'POST', `/api/applications/${appId}/offer/decision`, { status: 'accepted' });
  const notHired = await api('admin', 'POST', `/api/applications/${appId}/hire`, {});
  const toHired = await api('recruiter', 'POST', `/api/applications/${appId}/move`, { stage_id: hiredStage?.id });
  const hires = await Promise.all([0, 1, 2].map(() => api('admin', 'POST', `/api/applications/${appId}/hire`, { hired_at: today })));
  const empIds = [...new Set(hires.map((h) => data(h)?.id).filter(Boolean))];
  record('lifecycle', 'hire: only after the hired stage; 3 parallel hires → one employee', decide.status === 200 && notHired.status === 422 && toHired.status === 200 && hires.every((h) => h.status === 200 || h.status === 201) && empIds.length === 1,
    { decision: brief(decide), hireBeforeStage: brief(notHired), toHired: brief(toHired), parallel: hires.map(brief), employees: empIds });
  const newEmp = empIds[0];
  ids.hired = newEmp;
  // terminate today (Kyiv) → terminated at once; restore → working
  const term = await api('admin', 'POST', `/api/people/${newEmp}/terminate`, { fired_at: today, reason: 'Перевірка повного циклу ґ [ТЕСТ]' });
  const t2 = await api('admin', 'POST', `/api/people/${newEmp}/terminate`, { fired_at: today });
  const afterT = data(await api('admin', 'GET', `/api/people/${newEmp}`));
  const restore = await api('admin', 'POST', `/api/people/${newEmp}/restore`, {});
  const afterR = data(await api('admin', 'GET', `/api/people/${newEmp}`));
  record('lifecycle', 'people: terminate today (Kyiv) → restore', term.status === 200 && afterT?.status === 'terminated' && String(afterT?.fired_at ?? '').startsWith(today) && t2.status === 409 && restore.status === 200 && afterR?.status !== 'terminated' && !afterR?.fired_at,
    { today, terminate: brief(term), again: brief(t2), after: { status: afterT?.status, fired_at: afterT?.fired_at }, restore: brief(restore), back: { status: afterR?.status, fired_at: afterR?.fired_at } });
  // scheduled termination (future) + cancel; a manager cannot terminate himself; employee cannot terminate anyone
  const sched = await api('admin', 'POST', `/api/people/${newEmp}/terminate`, { fired_at: addDays(today, 10) });
  const schedState = data(await api('admin', 'GET', `/api/people/${newEmp}`));
  const cancel = await api('admin', 'POST', `/api/people/${newEmp}/terminate/cancel`);
  const selfT = await api('manager', 'POST', `/api/people/${S.manager.employeeId}/terminate`, { fired_at: addDays(today, 30) });
  const empT = await api('employee', 'POST', `/api/people/${S.manager.employeeId}/terminate`, { fired_at: addDays(today, 30) });
  record('lifecycle', 'people: scheduled termination + cancel; self / subordinate refused', sched.status === 200 && schedState?.status !== 'terminated' && cancel.status === 200 && selfT.status === 403 && [403, 404].includes(empT.status),
    { scheduled: brief(sched), state: { status: schedState?.status, fired_at: schedState?.fired_at }, cancel: brief(cancel), managerSelf: brief(selfT), employeeOnManager: brief(empT) });

  // ---- funnel with reject reasons: reject one more application with a reason, report shows it
  const c2 = data(await api('recruiter', 'POST', '/api/candidates', { full_name: 'Відмовлений Кандидат [ТЕСТ]', email: `rej-${Date.now()}@sinhrm.test`, vacancy_id: vacancy?.id }));
  const app2 = (data(await api('recruiter', 'GET', `/api/candidates/${c2?.id}`))?.applications ?? [])[0]?.id;
  const reasons = data(await api('recruiter', 'GET', '/api/reject-reasons')) ?? [];
  const rej = await api('recruiter', 'POST', `/api/applications/${app2}/move`, { stage_id: rejectStage?.id, reject_reason_id: reasons[1]?.id ?? reasons[0]?.id, reason: 'Не підходить графік [ТЕСТ]' });
  const funnel = await api('recruiter', 'GET', `/api/reports/funnel?vacancy_id=${vacancy?.id}&from=${addDays(today, -1)}&to=${addDays(today, 1)}`);
  const rr = await api('recruiter', 'GET', `/api/reports/reject-reasons?from=${addDays(today, -1)}&to=${addDays(today, 1)}`);
  const rrRow = (data(rr)?.rows ?? []).find((r) => r.reject_reason_id === (reasons[1]?.id ?? reasons[0]?.id));
  const empFunnel = await api('employee', 'GET', '/api/reports/funnel');
  record('lifecycle', 'ats.funnel + reject reasons report', rej.status === 200 && funnel.status === 200 && (data(funnel)?.totals?.total ?? 0) >= 2 && rr.status === 200 && (rrRow?.count ?? 0) >= 1,
    { reject: brief(rej), funnelTotal: data(funnel)?.totals, funnelRows: (data(funnel)?.rows ?? []).filter((r) => r.count > 0).map((r) => `${r.stage_name}:${r.count}`), rejectReasons: data(rr)?.rows?.slice(0, 6), employeeFunnel: empFunnel.status });

  // ---- time off: employee requests, manager approves; self-decision of an HR → 403; overlap; parallel creates
  const types = data(await api('employee', 'GET', '/api/timeoff/types')) ?? [];
  const dayOff = types.find((t) => t.code === 'day_off' || /day_off|відгул/i.test(`${t.code} ${t.name}`)) ?? types.find((t) => !t.tracks_balance) ?? types[0];
  const mon = nextWeekday(addDays(today, 14), 1);
  const req = await api('employee', 'POST', '/api/timeoff/requests', { leave_type_id: dayOff?.id, starts_on: mon, ends_on: mon, comment: 'Сімейні справи ґ [ТЕСТ]' });
  const reqId = data(req)?.id;
  const selfApprove = await api('employee', 'POST', `/api/timeoff/requests/${reqId}/approve`);
  const mgrList = await api('manager', 'GET', '/api/timeoff/approvals');
  const inList = (data(mgrList) ?? []).some((r) => r.id === reqId);
  const approvals = await Promise.all([0, 1, 2].map(() => api('manager', 'POST', `/api/timeoff/requests/${reqId}/approve`, { comment: 'Ок' })));
  const after = data(await api('employee', 'GET', `/api/timeoff/requests/${reqId}`));
  record('lifecycle', 'timeoff: employee → manager approves (3 parallel → exactly one)', req.status === 201 && [403, 404].includes(selfApprove.status) && inList && approvals.filter((a) => a.status === 200).length === 1 && approvals.filter((a) => a.status === 409).length === 2 && after?.status === 'approved',
    { create: brief(req), employeeSelfApprove: brief(selfApprove), inManagerApprovals: inList, parallel: approvals.map((a) => a.status), final: after?.status });
  const overlapPar = await Promise.all([0, 1, 2, 3].map((i) => api('employee', 'POST', '/api/timeoff/requests', { leave_type_id: dayOff?.id, starts_on: addDays(mon, 7), ends_on: addDays(mon, 7 + (i % 2)), comment: 'Паралельно ' + i })));
  record('lifecycle', 'timeoff: 4 parallel overlapping requests → exactly one created (lockForUpdate)', overlapPar.filter((r) => r.status === 201).length === 1 && overlapPar.every((r) => r.status < 500), { statuses: overlapPar.map(brief) });
  // HR (hr_manager with an employee card) cannot decide own leave; admin (no peer rule) handles it
  const hrReq = await api('hr', 'POST', '/api/timeoff/requests', { leave_type_id: dayOff?.id, starts_on: addDays(mon, 21), ends_on: addDays(mon, 21) });
  const hrSelf = await api('hr', 'POST', `/api/timeoff/requests/${data(hrReq)?.id}/approve`);
  const hrSelfReject = await api('hr', 'POST', `/api/timeoff/requests/${data(hrReq)?.id}/reject`);
  const hrApprovals = data(await api('hr', 'GET', '/api/timeoff/approvals')) ?? [];
  const adminOk = await api('admin', 'POST', `/api/timeoff/requests/${data(hrReq)?.id}/approve`);
  record('lifecycle', 'timeoff: self-decision forbidden for HR; another admin decides', hrReq.status === 201 && hrSelf.status === 403 && hrSelfReject.status === 403 && !hrApprovals.some((r) => r.id === data(hrReq)?.id) && adminOk.status === 200,
    { create: brief(hrReq), selfApprove: brief(hrSelf), selfReject: brief(hrSelfReject), ownInApprovals: hrApprovals.some((r) => r.id === data(hrReq)?.id), adminApprove: brief(adminOk) });
  // employee cancels own pending request; manager cannot cancel someone outside the subtree
  const req3 = data(await api('employee', 'POST', '/api/timeoff/requests', { leave_type_id: dayOff?.id, starts_on: addDays(mon, 28), ends_on: addDays(mon, 28) }));
  const cancel3 = await api('employee', 'POST', `/api/timeoff/requests/${req3?.id}/cancel`);
  record('lifecycle', 'timeoff: employee cancels own pending request', cancel3.status === 200 && data(cancel3)?.status === 'cancelled', brief(cancel3));

  // ---- timesheet: employee fills + submits, manager approves; employee cannot decide
  const week = nextWeekday(addDays(today, -14), 1);
  const entries = [0, 1, 2, 3, 4].map((i) => ({ date: addDays(week, i), hours: 8, project: 'Навчання ґ [ТЕСТ]', category: 'work', note: 'День ' + i }));
  const save = await api('employee', 'PUT', '/api/time/week', { week, entries });
  const overflow = await api('employee', 'PUT', '/api/time/week', { week, entries: [{ date: week, hours: 20 }, { date: week, hours: 10 }] });
  const outside = await api('employee', 'PUT', '/api/time/week', { week, entries: [{ date: addDays(week, 9), hours: 1 }] });
  const submit = await api('employee', 'POST', '/api/time/week/submit', { week });
  const tsId = data(submit)?.timesheet_id;
  const editAfter = await api('employee', 'PUT', '/api/time/week', { week, entries });
  const selfDecide = await api('employee', 'POST', `/api/time/timesheets/${tsId}/decision`, { decision: 'approve' });
  const mgrA = data(await api('manager', 'GET', '/api/time/approvals')) ?? [];
  const decide2 = await Promise.all([0, 1].map(() => api('manager', 'POST', `/api/time/timesheets/${tsId}/decision`, { decision: 'approve', comment: 'Ок' })));
  const wk = data(await api('employee', 'GET', `/api/time/week?week=${week}`));
  record('lifecycle', 'time: fill → submit → manager approves (2 parallel → one)', save.status === 200 && overflow.status === 422 && outside.status === 422 && submit.status === 200 && editAfter.status === 409 && [403, 404].includes(selfDecide.status) && mgrA.some((t) => t.id === tsId) && decide2.filter((d) => d.status === 200).length === 1 && wk?.status === 'approved' && wk?.totals?.worked === 40,
    { save: brief(save), overflow: brief(overflow), outside: brief(outside), submit: brief(submit), editAfterSubmit: editAfter.status, selfDecide: selfDecide.status, inManagerList: mgrA.some((t) => t.id === tsId), parallel: decide2.map((d) => d.status), final: { status: wk?.status, totals: wk?.totals } });

  // ---- Pulse: survey + anonymous wave to everyone; ≥ 5 answers (admin on behalf is not allowed → real users), close, results
  const survey = await api('admin', 'POST', '/api/pulse/surveys', { title: 'Пульс e2e ґ [ТЕСТ]', type: 'engagement', questions: [{ id: 'q1', type: 'scale5', text: 'Як справи?', required: true }, { id: 'q2', type: 'text', text: 'Коментар' }] });
  const wave = await api('admin', 'POST', `/api/pulse/surveys/${data(survey)?.id}/waves`, { starts_at: addDays(today, -1) + ' 00:00:00', ends_at: addDays(today, 10) + ' 00:00:00', schedule: 'once', audience: { branch_ids: [], department_ids: [] }, anonymous: true, min_group_size: 5 });
  const waveId = data(wave)?.id;
  const answers = [];
  for (const role of ['employee', 'manager', 'recruiter', 'hr']) answers.push(await api(role, 'POST', `/api/pulse/waves/${waveId}/responses`, { answers: { q1: 4, q2: `Відповідь ${role} ґ [ТЕСТ]` } }));
  const again = await Promise.all([0, 1, 2].map(() => api('employee', 'POST', `/api/pulse/waves/${waveId}/responses`, { answers: { q1: 5 } })));
  const liveAdmin = data(await api('admin', 'GET', `/api/pulse/waves/${waveId}/results`));
  const close = await api('admin', 'POST', `/api/pulse/waves/${waveId}/close`);
  const close2 = await api('admin', 'POST', `/api/pulse/waves/${waveId}/close`);
  const resAdmin = data(await api('admin', 'GET', `/api/pulse/waves/${waveId}/results?segment=department`));
  const resMgr = await api('manager', 'GET', `/api/pulse/waves/${waveId}/results?segment=department`);
  const resEmp = await api('employee', 'GET', `/api/pulse/waves/${waveId}/results`);
  const late = await api('admin', 'POST', `/api/pulse/waves/${waveId}/responses`, { answers: { q1: 1 } });
  record('lifecycle', 'pulse: answer → close → results with anonymity (4 < min 5 → suppressed)', survey.status === 201 && wave.status === 201 && answers.every((a) => a.status === 201) && again.every((a) => a.status === 409) && liveAdmin?.suppressed === true && close.status === 200 && close2.status === 409 && resAdmin?.suppressed === true && resAdmin?.responses == null && [403].includes(resEmp.status) && late.status === 409,
    { survey: brief(survey), wave: brief(wave), answers: answers.map((a) => a.status), duplicateParallel: again.map((a) => a.status), live: { suppressed: liveAdmin?.suppressed, participation: liveAdmin?.participation }, close: close.status, closeAgain: close2.status, admin: { suppressed: resAdmin?.suppressed, responses: resAdmin?.responses, segments: !!resAdmin?.segments }, manager: { status: resMgr.status, scope: data(resMgr)?.scope, segments: !!data(resMgr)?.segments, suppressed: data(resMgr)?.suppressed }, employee: resEmp.status, answerAfterClose: late.status });
  const moodMgr = await api('manager', 'GET', '/api/pulse/mood/team?weeks=8');
  const moodMgrF1 = await api('manager', 'GET', `/api/pulse/mood/team?weeks=8&branch_id=${branchId}`);
  const moodMgrF2 = await api('manager', 'GET', '/api/pulse/mood/team?weeks=8&department_id=1');
  const moodAdminF = await api('admin', 'GET', `/api/pulse/mood/team?weeks=8&branch_id=${branchId}`);
  const moodEmp = await api('employee', 'GET', '/api/pulse/mood/team?weeks=8');
  record('lifecycle', 'pulse.mood team: manager filters → 403, admin filters ok, employee 403', moodMgr.status === 200 && moodMgrF1.status === 403 && moodMgrF2.status === 403 && moodAdminF.status === 200 && moodEmp.status === 403,
    { manager: moodMgr.status, managerBranch: moodMgrF1.status, managerDept: moodMgrF2.status, adminBranch: moodAdminF.status, employee: moodEmp.status });

  // ---- SafeSpeak: anonymous report by code, handler replies, reporter reads by code and answers
  const sub = await api('guest', 'POST', '/api/safe-speak/public/reports', { category: 'ethics', subject: 'Звернення e2e ґ [ТЕСТ]', body: 'Опис ситуації. Ёлка, Їжак, ЄВРО. ' + 'Довгий текст. '.repeat(600) });
  const code = data(sub)?.code;
  const inbox = data(await api('admin', 'GET', '/api/safe-speak/reports')) ?? [];
  const rep = inbox.find((r) => /Звернення e2e/.test(r.subject));
  const reply = await api('admin', 'POST', `/api/safe-speak/reports/${rep?.id}/messages`, { body: 'Дякуємо, розглядаємо [ТЕСТ]' });
  const follow = await api('guest', 'POST', '/api/safe-speak/public/follow-up', { code: code?.toLowerCase() });
  const rr2 = await api('guest', 'POST', '/api/safe-speak/public/reply', { code, body: 'Додаю деталі [ТЕСТ]' });
  const wrong = await api('guest', 'POST', '/api/safe-speak/public/follow-up', { code: 'AAAA-BBBB-CCCC-DDDD' });
  const notHandler = await api('manager', 'GET', '/api/safe-speak/reports');
  const leak = JSON.stringify(data(follow) ?? {}).match(/"(id|user_id|employee_id|ip)"/);
  record('lifecycle', 'safespeak: submit → handler reply → reporter reads by code and answers', sub.status === 201 && !!code && !!rep && reply.status === 201 && follow.status === 200 && (data(follow)?.messages ?? []).length >= 2 && rr2.status === 201 && wrong.status === 404 && notHandler.status === 403 && !leak,
    { submit: brief(sub), codeShape: code?.replace(/[A-Z0-9]/g, 'x'), foundInInbox: !!rep, reply: brief(reply), followUpLowercase: { status: follow.status, messages: (data(follow)?.messages ?? []).length }, reporterReply: brief(rr2), wrongCode: wrong.status, managerInbox: notHandler.status, idLeak: leak?.[0] ?? null });
});

// ================================================================= (5) MySQL specifics
await group('mysql', async () => {
  // 1000+ rows: walk every page of people (perPage=200) for each sort; ids unique, count = total, NULLs last
  for (const sort of ['name', 'position', 'department', 'manager']) {
    for (const dir of ['asc', 'desc']) {
      const seen = new Set(); let total = null; let dup = 0; let page = 1; const vals = []; let ms = 0; let status = 200;
      for (; page <= 20; page++) {
        const r = await api('admin', 'GET', `/api/people?perPage=200&page=${page}&sort=${sort}&dir=${dir}`);
        status = r.status; ms += r.ms;
        if (r.status !== 200) break;
        total = r.json?.meta?.total ?? total;
        const rows = data(r) ?? [];
        for (const x of rows) { if (seen.has(x.id)) dup++; seen.add(x.id); const v = x[sort]; vals.push(sort === 'name' ? x.full_name : (v && typeof v === 'object' ? (v.name ?? v.full_name ?? null) : (v ?? null))); }
        if (rows.length < 200) break;
      }
      const firstNull = vals.indexOf(null);
      const nullsLast = firstNull === -1 || vals.slice(firstNull).every((v) => v === null);
      record('mysql', `pagination people sort=${sort} ${dir}`, status === 200 && total >= 1000 && seen.size === total && dup === 0 && nullsLast, { status, total, unique: seen.size, dup, pages: page, nullsLast, nulls: vals.filter((v) => v === null).length, ms });
    }
  }
  // candidates: default order + screening_score (NULLs last) across pages
  for (const q of ['', '&sort=screening_score']) {
    const seen = new Set(); let total = null; let dup = 0; let page = 1;
    for (; page <= 20; page++) {
      const r = await api('admin', 'GET', `/api/candidates?perPage=200&page=${page}${q}`);
      if (r.status !== 200) { total = `HTTP ${r.status}`; break; }
      total = r.json?.meta?.total ?? total;
      const rows = data(r) ?? [];
      for (const x of rows) { if (seen.has(x.id)) dup++; seen.add(x.id); }
      if (rows.length < 200) break;
    }
    record('mysql', `pagination candidates${q || ' default'}`, typeof total === 'number' && total >= 1000 && seen.size === total && dup === 0, { total, unique: seen.size, dup, pages: page });
  }
  // case- and accent-insensitive search (utf8mb4_0900_ai_ci)
  const s = {};
  for (const term of ['масовий є0003', 'МАСОВИЙ Є0003', 'масовий е0003', 'Масовий é0009', 'масовий e0009', 'кандидат-МАСОВИЙ 0001', 'ІМПОРТ', 'імпорт', "о'коннор"]) {
    const isCand = /кандидат|імпорт|коннор/i.test(term);
    const r = await api('admin', 'GET', `/api/${isCand ? 'candidates' : 'people'}?perPage=50&q=` + encodeURIComponent(term));
    s[term] = { status: r.status, total: r.json?.meta?.total ?? (data(r) ?? []).length };
  }
  record('mysql', 'search case/accent-insensitive', s['масовий є0003'].total > 0 && s['масовий є0003'].total === s['МАСОВИЙ Є0003'].total && s['Масовий é0009'].total > 0 && s['масовий e0009'].total === s['Масовий é0009'].total && s['ІМПОРТ'].total === s['імпорт'].total && Object.values(s).every((x) => x.status === 200), s);

  // JSON: custom_fields with Cyrillic keys/values, quotes, emoji; key order on read back
  const cf = { zeta: 'останнє', alpha: 'перше', 'Мова': 'українська "лапки" \\ ґ', emoji: '😀👍', n: '42', 'довгий ключ поля': 'x'.repeat(1000) };
  const patch = await api('admin', 'PATCH', `/api/people/${S.employee.employeeId}`, { custom_fields: cf });
  const back = data(await api('admin', 'GET', `/api/people/${S.employee.employeeId}`))?.custom_fields;
  const same = back && JSON.stringify(Object.entries(back).sort()) === JSON.stringify(Object.entries(cf).sort());
  record('mysql', 'json custom_fields round trip (values)', patch.status === 200 && same, { patch: brief(patch), back: back && Object.fromEntries(Object.entries(back).map(([k, v]) => [k, String(v).slice(0, 30)])) });
  record('mysql', 'json custom_fields key order preserved', !!back && JSON.stringify(Object.keys(back)) === JSON.stringify(Object.keys(cf)), { sent: Object.keys(cf), back: back && Object.keys(back) });
  // JSON meta: audit search by meta, touchpoint meta kind=offer survived (see offer scenario)
  const audit = await api('admin', 'GET', `/api/audit?entity_type=employee&entity_id=${S.employee.employeeId}&perPage=20`);
  record('mysql', 'json audit meta of the custom_fields change', audit.status === 200 && (data(audit) ?? []).length > 0, { status: audit.status, rows: (data(audit) ?? []).length, first: (data(audit) ?? [])[0] && { action: data(audit)[0].action, changes: JSON.stringify(data(audit)[0].changes ?? data(audit)[0].meta ?? {}).slice(0, 200) } });

  // Long texts: > 64 KB in bytes through the API (Cyrillic = 2 bytes, emoji = 4)
  const long99k = ('Довгий документ ґ. ' + '😀').repeat(5000).slice(0, 99000);
  const docLong = await api('admin', 'POST', '/api/documents', { employee_id: S.employee.employeeId, title: 'Довгий [ТЕСТ]', content_md: long99k });
  const docBack = docLong.status === 201 ? data(await api('admin', 'GET', `/api/documents/${data(docLong).id}`)) : null;
  record('mysql', 'long text: document content_md 99k chars (~250 KB)', docLong.status === 201 && (docBack?.content_md?.length ?? 0) === long99k.length, { create: brief(docLong), sentLen: long99k.length, readBackLen: docBack?.content_md?.length ?? null });
  const tplLong = await api('admin', 'POST', '/api/documents/templates', { name: 'Шаблон довгий [ТЕСТ]', body: ('Шаблон ґ {ПІБ}. ').repeat(4000).slice(0, 50000), category: 'contract' });
  record('mysql', 'long text: template body 50k chars (~100 KB)', tplLong.status === 201, brief(tplLong));
  const notes = await api('admin', 'POST', `/api/candidates/${(data(await api('admin', 'GET', '/api/candidates?perPage=1')) ?? [])[0]?.id}/touchpoints`, { channel: 'note', body: ('Нотатка 😀 ґ ').repeat(1000).slice(0, 10000) });
  record('mysql', 'long text: candidate note 10k chars with emoji (4-byte)', notes.status === 201, brief(notes));
  const desk4b = await api('employee', 'POST', '/api/desk/cases', { category_id: (data(await api('employee', 'GET', '/api/desk/categories')) ?? [])[0]?.id, subject: '😀'.repeat(200), body: '😀'.repeat(10000) });
  record('mysql', 'long text: desk case 10k emoji (40 KB)', desk4b.status === 201, brief(desk4b));

  // Parallel writes: mood check-ins of one employee for the same day (unique employee_id+day)
  const mood = await Promise.all([1, 2, 3, 4, 5].map((n) => api('employee', 'POST', '/api/pulse/mood', { score: n, comment: 'Паралельно ' + n })));
  const me = data(await api('employee', 'GET', '/api/pulse/mood/me?days=7')) ?? [];
  record('mysql', 'parallel mood check-ins (same day) → no 5xx, one row', mood.every((r) => r.status < 300) && me.filter((m) => String(m.day).startsWith(today)).length === 1, { statuses: mood.map(brief), rowsToday: me.filter((m) => String(m.day).startsWith(today)).length });
  // Parallel personal-board filing of one application
  const vac = ids.vacancy;
  if (vac) {
    const col = await api('recruiter', 'POST', `/api/vacancies/${vac}/personal-board/columns`, { name: 'Паралельна колонка [ТЕСТ]' });
    const board = data(await api('recruiter', 'GET', `/api/vacancies/${vac}/board`));
    const appId = (board?.applications ?? board?.cards ?? [])[0]?.id ?? (board?.columns ?? []).flatMap((c) => c.applications ?? c.cards ?? [])[0]?.id;
    const fil = await Promise.all([0, 1, 2, 3].map(() => api('recruiter', 'PUT', `/api/applications/${appId}/personal-column`, { column_id: data(col)?.id })));
    record('mysql', 'parallel personal-board filing → no 5xx', col.status === 201 && fil.every((r) => r.status < 500), { column: brief(col), appId, statuses: fil.map(brief) });
  }
});

// ================================================================= (5b) Kyiv midnight: backend clock = Monday 00:30 Europe/Kyiv (Sunday 21:30/22:30 UTC)
await group('kyiv-midnight', async () => {
  const clock = await api('fakeAdmin', 'GET', '/api/auth/me');
  const kyivDay = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date(Date.parse((process.env.FAKE_AT ?? '').replace(' ', 'T') + 'Z')));
  const week = data(await api('fakeEmployee', 'GET', '/api/time/week'));
  record('kyiv-midnight', 'time: default week at Monday 00:30 Kyiv is the new week', week?.week_start === kyivDay, { fakeAtUtc: process.env.FAKE_AT, kyivDay, week_start: week?.week_start, me: clock.status });
  const mood = await api('fakeEmployee', 'POST', '/api/pulse/mood', { score: 3, comment: 'Північ [ТЕСТ]' });
  const today2 = data(await api('fakeEmployee', 'GET', '/api/pulse/mood/today'));
  record('kyiv-midnight', 'pulse.mood: check-in at 00:30 Kyiv is stored for the Kyiv day', String(data(mood)?.day ?? today2?.today?.day ?? '').startsWith(kyivDay), { kyivDay, saved: data(mood)?.day ?? null, today: today2?.today?.day ?? null, ask: today2?.ask, err: err(mood) });
  const comp = await api('fakeAdmin', 'POST', `/api/people/${S.employee.employeeId}/compensation`, { amount: 33333, currency: 'UAH', period: 'month', effective_on: kyivDay, reason: 'Північ [ТЕСТ]' });
  const cview = data(await api('fakeAdmin', 'GET', `/api/people/${S.employee.employeeId}/compensation`));
  record('kyiv-midnight', 'compensation effective today (Kyiv) is current at 00:30', comp.status === 201 && cview?.current?.effective_on === kyivDay, { add: brief(comp), current: cview?.current?.effective_on ?? null, kyivDay });
  const types = data(await api('fakeAdmin', 'GET', '/api/timeoff/types')) ?? [];
  const sick = types.find((t) => !t.tracks_balance) ?? types[0];
  const leave = await api('fakeAdmin', 'POST', '/api/timeoff/requests', { employee_id: S.employee.employeeId, leave_type_id: sick?.id, starts_on: kyivDay, ends_on: kyivDay, override_balance: true });
  const appr = await api('fakeAdmin', 'POST', `/api/timeoff/requests/${data(leave)?.id}/approve`);
  const dash = data(await api('fakeAdmin', 'GET', '/api/dashboard'));
  const out = JSON.stringify(dash ?? {}).includes(`"employee_id":${S.employee.employeeId}`) || JSON.stringify(dash?.timeoff?.out_today ?? dash?.time_off?.out_today ?? []).includes(String(S.employee.employeeId));
  record('kyiv-midnight', 'dashboard «out today» at 00:30 Kyiv includes a leave starting today', leave.status === 201 && appr.status === 200 && out, { leave: brief(leave), approve: brief(appr), outToday: dash?.timeoff?.out_today ?? dash?.time_off?.out_today ?? Object.keys(dash ?? {}) });
  const term = await api('fakeAdmin', 'POST', `/api/people/${S.formulaEmployeeId}/terminate`, { fired_at: kyivDay });
  const st = data(await api('fakeAdmin', 'GET', `/api/people/${S.formulaEmployeeId}`));
  await api('fakeAdmin', 'POST', `/api/people/${S.formulaEmployeeId}/restore`, {});
  record('kyiv-midnight', 'terminate with the Kyiv date at 00:30 applies at once', term.status === 200 && st?.status === 'terminated', { terminate: brief(term), status: st?.status, fired_at: st?.fired_at });
});

// ================================================================= (4b) break-glass: the only superadmin decides own leave (last: blocks other admins)
await group('break-glass', async () => {
  const bg = JSON.parse(execFileSync('php', ['../.github/mysql-e2e/breakglass.php', 'on'], { cwd: '../backend', encoding: 'utf8' }));
  await open('bg', BASE, bg);
  const types = data(await api('bg', 'GET', '/api/timeoff/types')) ?? [];
  const t = types.find((x) => !x.tracks_balance) ?? types[0];
  const d = nextWeekday(addDays(today, 40), 2);
  const req = await api('bg', 'POST', '/api/timeoff/requests', { leave_type_id: t?.id, starts_on: d, ends_on: d, comment: 'Break-glass [ТЕСТ]' });
  const list = data(await api('bg', 'GET', '/api/timeoff/approvals')) ?? [];
  const ok = await api('bg', 'POST', `/api/timeoff/requests/${data(req)?.id}/approve`, { comment: 'Єдиний суперадмін' });
  const audit = data(await api('bg', 'GET', `/api/audit?entity_type=employee&entity_id=${bg.employeeId}&perPage=20`)) ?? [];
  const entry = audit.find((a) => JSON.stringify(a).includes('self_decision'));
  execFileSync('php', ['../.github/mysql-e2e/breakglass.php', 'peer'], { cwd: '../backend', encoding: 'utf8' });
  const req2 = await api('bg', 'POST', '/api/timeoff/requests', { leave_type_id: t?.id, starts_on: addDays(d, 7), ends_on: addDays(d, 7) });
  const denied = await api('bg', 'POST', `/api/timeoff/requests/${data(req2)?.id}/approve`);
  execFileSync('php', ['../.github/mysql-e2e/breakglass.php', 'off'], { cwd: '../backend', encoding: 'utf8' });
  record('break-glass', 'sole superadmin approves own leave (audited); with a peer → 403', req.status === 201 && list.some((r) => r.id === data(req)?.id) && ok.status === 200 && !!entry && denied.status === 403,
    { create: brief(req), ownInApprovals: list.some((r) => r.id === data(req)?.id), approve: brief(ok), audit: entry ? JSON.stringify(entry).slice(0, 300) : null, withPeer: brief(denied) });
});

await browser.close();
await writeFile(`${OUT}/scenarios.json`, JSON.stringify(results, null, 1));
console.log('\n==== SUMMARY ====');
const byGroup = {};
for (const r of results) (byGroup[r.group] ??= []).push(r);
for (const [g, rs] of Object.entries(byGroup)) console.log(`${g}: ${rs.filter((r) => r.pass).length}/${rs.length}`);
const failed = results.filter((r) => !r.pass);
console.log(`TOTAL ${results.length - failed.length}/${results.length} pass`);
for (const f of failed) console.log(`  FAIL [${f.group}] ${f.name}`);
process.exit(0);

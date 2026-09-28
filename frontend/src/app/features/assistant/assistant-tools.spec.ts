import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { AssistantToolExecutor, TOOL_CONTENT_LIMIT, guardApiPath, guardPagePath, toWriteRequest, truncateContent } from './assistant-tools';

describe('assistant tool guards', () => {
  it('accepts ordinary relative API paths and strips a leading /api/', () => {
    expect(guardApiPath('candidates')).toBe('candidates');
    expect(guardApiPath('candidates/12?stage=offer&page=2')).toBe('candidates/12?stage=offer&page=2');
    expect(guardApiPath('/api/people/3')).toBe('people/3');
    expect(guardApiPath('reports/catalog?q=a%20b')).toBe('reports/catalog?q=a%20b');
    expect(guardApiPath('opsfoo/1')).toBe('opsfoo/1');
  });

  it('rejects traversal, absolute and odd paths', () => {
    for (const bad of ['/candidates', '//evil.com', '../secret', 'people/../ops', 'people/%2e%2e/ops', 'a\\b', '', '-x', 'candidates/%41', 'auth//logout', 'me//extension-token', 'people//1', 42, null]) {
      expect(guardApiPath(bad)).toBeNull();
    }
  });

  it('rejects forbidden areas as a segment or prefix, any case, with a query', () => {
    for (const bad of ['ops', 'ops/migrate', 'OPS/x', 'mcp', 'assistant/turn', 'auth/logout', 'sanctum/csrf-cookie', 'clipper/x', 'public/x', 'me/extension-token', 'webhooks', 'webhooks/telegram?x=1', 'ops?x=1', '/api/mcp']) {
      expect(guardApiPath(bad)).toBeNull();
    }
    expect(guardApiPath('auth/me')).toBe('auth/me');
    expect(guardApiPath('me/documents')).toBe('me/documents');
  });

  it('page paths must be in-app', () => {
    expect(guardPagePath('/candidates/12')).toBe('/candidates/12');
    expect(guardPagePath('//evil.com')).toBeNull();
    expect(guardPagePath('/\\evil.com')).toBeNull();
    expect(guardPagePath('candidates')).toBeNull();
    expect(guardPagePath('https://evil.com')).toBeNull();
  });

  it('truncates long tool content', () => {
    const long = 'x'.repeat(TOOL_CONTENT_LIMIT + 50);
    const cut = truncateContent(long);
    expect(cut.length).toBe(TOOL_CONTENT_LIMIT + '…[truncated]'.length);
    expect(cut.endsWith('…[truncated]')).toBe(true);
    expect(truncateContent('short')).toBe('short');
  });

  it('validates api_write arguments', () => {
    expect(toWriteRequest({ method: 'post', path: 'candidates/3/notes', body: { text: 'hi' }, summary: 'Додати нотатку' })).toEqual({
      method: 'POST',
      path: 'candidates/3/notes',
      body: { text: 'hi' },
      summary: 'Додати нотатку',
    });
    expect(toWriteRequest({ method: 'GET', path: 'candidates' })).toBeNull();
    expect(toWriteRequest({ method: 'DELETE', path: 'ops/x', summary: 's' })).toBeNull();
    expect(toWriteRequest({ method: 'DELETE', path: 'candidates/1' })?.summary).toBe('DELETE /api/candidates/1');
  });
});

describe('AssistantToolExecutor', () => {
  let tools: AssistantToolExecutor;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    tools = TestBed.inject(AssistantToolExecutor);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  const flushMicro = (): Promise<void> => new Promise((r) => setTimeout(r));

  it('api_get calls /api/<path> with scalar params and returns {status, data}', async () => {
    const run = tools.run({ id: '1', name: 'api_get', arguments: { path: 'candidates', query: { stage: 'offer', page: 2, active: true, bad: { x: 1 } } } }, () => Promise.resolve(false));
    const req = http.expectOne((r) => r.url === '/api/candidates');
    expect(req.request.params.get('stage')).toBe('offer');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('active')).toBe('true');
    expect(req.request.params.has('bad')).toBe(false);
    req.flush({ data: [{ id: 1 }] });
    const res = await run;
    expect(JSON.parse(res.content)).toEqual({ status: 200, data: { data: [{ id: 1 }] } });
    expect(res.outcome).toBe('ok');
  });

  it('api_get reports HTTP errors with the server message', async () => {
    const run = tools.run({ id: '1', name: 'api_get', arguments: { path: 'people/9' } }, () => Promise.resolve(false));
    http.expectOne('/api/people/9').flush({ message: 'Not found' }, { status: 404, statusText: 'Not Found' });
    expect(JSON.parse((await run).content)).toEqual({ status: 404, error: 'Not found' });
  });

  it('api_get truncates big answers', async () => {
    const run = tools.run({ id: '1', name: 'api_get', arguments: { path: 'candidates' } }, () => Promise.resolve(false));
    http.expectOne('/api/candidates').flush({ data: 'y'.repeat(20_000) });
    const res = await run;
    expect(res.content.length).toBeLessThanOrEqual(TOOL_CONTENT_LIMIT + 20);
    expect(res.content.endsWith('…[truncated]')).toBe(true);
  });

  it('forbidden paths never reach the network', async () => {
    const res = await tools.run({ id: '1', name: 'api_get', arguments: { path: 'ops/migrate' } }, () => Promise.resolve(true));
    expect(JSON.parse(res.content)).toEqual({ error: 'forbidden_path' });
    http.expectNone(() => true);
  });

  it('api_write waits for confirmation, then executes', async () => {
    let resolveConfirm: (ok: boolean) => void = () => undefined;
    const confirm = vi.fn(() => new Promise<boolean>((r) => (resolveConfirm = r)));
    const run = tools.run({ id: 'w', name: 'api_write', arguments: { method: 'PATCH', path: 'candidates/3', body: { stage: 'offer' }, summary: 'Перевести на офер' } }, confirm);
    await flushMicro();
    expect(confirm).toHaveBeenCalledWith({ method: 'PATCH', path: 'candidates/3', body: { stage: 'offer' }, summary: 'Перевести на офер' });
    http.expectNone('/api/candidates/3');
    resolveConfirm(true);
    await flushMicro();
    const req = http.expectOne('/api/candidates/3');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ stage: 'offer' });
    req.flush({ data: { id: 3 } });
    const res = await run;
    expect(res.wrote).toBe(true);
    expect(JSON.parse(res.content)).toEqual({ status: 200, data: { data: { id: 3 } } });
  });

  it('api_write declined → no request', async () => {
    const res = await tools.run({ id: 'w', name: 'api_write', arguments: { method: 'DELETE', path: 'candidates/3', summary: 'Видалити' } }, () => Promise.resolve(false));
    expect(JSON.parse(res.content)).toEqual({ declined: true, note: 'user declined' });
    expect(res.outcome).toBe('declined');
    http.expectNone(() => true);
  });

  it('open_page navigates inside the app only', async () => {
    const router = TestBed.inject(Router);
    const nav = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const ok = await tools.run({ id: 'o', name: 'open_page', arguments: { path: '/candidates/12' } }, () => Promise.resolve(false));
    expect(nav).toHaveBeenCalledWith('/candidates/12');
    expect(JSON.parse(ok.content)).toEqual({ opened: true });
    const bad = await tools.run({ id: 'o', name: 'open_page', arguments: { path: '//evil.com' } }, () => Promise.resolve(false));
    expect(JSON.parse(bad.content)).toEqual({ error: 'forbidden_path' });
    expect(nav).toHaveBeenCalledTimes(1);
  });
});

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const staticDir = path.join(__dirname, '..', 'static');
const page = (name: string) =>
  /<body[^>]*>([\s\S]*)<\/body>/.exec(readFileSync(path.join(staticDir, name), 'utf8'))![1]!.replace(/<script[^>]*><\/script>/g, '');

const flush = async () => {
  for (let i = 0; i < 10; i++) await new Promise((r) => setTimeout(r, 0));
};
const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const el = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;

interface Env {
  store: Record<string, unknown>;
  tabs: { create: ReturnType<typeof vi.fn>; query: ReturnType<typeof vi.fn> };
  executeScript: ReturnType<typeof vi.fn>;
  fetchMock: ReturnType<typeof vi.fn>;
}

function setup(opts: {
  store?: Record<string, unknown>;
  tabUrl?: string;
  injection?: unknown;
  fetchImpl?: (url: string, init: RequestInit) => Response | Promise<Response>;
}): Env {
  const store: Record<string, unknown> = { token: 'tok-test-123', baseUrl: 'https://hrm.example.com', lang: 'en', ...opts.store };
  const tabs = {
    create: vi.fn(),
    query: vi.fn(async () => [{ id: 7, url: opts.tabUrl ?? 'https://www.linkedin.com/in/ivan-testenko/' }]),
  };
  const executeScript = vi.fn(async () => [{ result: opts.injection }]);
  const fetchMock = vi.fn(async (url: string, init: RequestInit) =>
    (opts.fetchImpl ?? (() => json(200, { data: {} })))(String(url), init),
  );
  vi.stubGlobal('chrome', {
    storage: {
      local: {
        setAccessLevel: vi.fn(async () => undefined),
        get: vi.fn(async (keys: string[]) => Object.fromEntries(keys.filter((k) => k in store).map((k) => [k, store[k]]))),
        set: vi.fn(async (patch: Record<string, unknown>) => void Object.assign(store, patch)),
      },
    },
    tabs,
    scripting: { executeScript },
    runtime: { openOptionsPage: vi.fn() },
    i18n: { getUILanguage: () => 'en' },
  });
  vi.stubGlobal('fetch', fetchMock);
  return { store, tabs, executeScript, fetchMock };
}

const profile = {
  full_name: 'Ivan Testenko',
  headline: 'QA',
  location: 'Testograd',
  profile_url: 'https://www.linkedin.com/in/ivan-testenko/',
  summary: 'x'.repeat(2500),
  source_site: 'linkedin',
};

const meOk = json(200, { data: { user: { id: 1, name: 'A', email: 'a@example.com' }, vacancies: [] } });
const isMe = (url: string) => url.endsWith('/api/clipper/me');

describe('popup', () => {
  beforeEach(() => {
    vi.resetModules();
    document.body.innerHTML = page('popup.html');
  });
  afterEach(() => vi.unstubAllGlobals());

  const load = async () => {
    await import('../src/popup');
    await flush();
  };
  const submit = async () => {
    el<HTMLFormElement>('form').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
  };

  it('without a token shows the settings prompt and touches neither tabs nor network', async () => {
    const env = setup({ store: { token: '' } });
    await load();
    expect(el('message').hidden).toBe(false);
    expect(el('open-options').hidden).toBe(false);
    expect(env.tabs.query).not.toHaveBeenCalled();
    expect(env.executeScript).not.toHaveBeenCalled();
    expect(env.fetchMock).not.toHaveBeenCalled();
  });

  it('on an unsupported page does not inject the extractor or call the API', async () => {
    const env = setup({ tabUrl: 'https://evil.example/in/someone/' });
    await load();
    expect(el('message-text').textContent).toContain('not a supported profile');
    expect(env.executeScript).not.toHaveBeenCalled();
    expect(env.fetchMock).not.toHaveBeenCalled();
  });

  it('injects extract.js into the active tab only and fills the form', async () => {
    const env = setup({ injection: { ok: true, profile }, fetchImpl: () => meOk.clone() });
    await load();
    expect(env.executeScript).toHaveBeenCalledTimes(1);
    expect(env.executeScript).toHaveBeenCalledWith({ target: { tabId: 7 }, files: ['extract.js'] });
    expect(el<HTMLInputElement>('f-full_name').value).toBe('Ivan Testenko');
  });

  it('reports extractFailed when injection returns nothing', async () => {
    setup({ injection: undefined });
    await load();
    expect(el('message-text').textContent).toContain('Could not read this page');
  });

  it('reports extractFailed when injection throws', async () => {
    const env = setup({ injection: undefined });
    env.executeScript.mockRejectedValueOnce(new Error('no access'));
    await load();
    expect(el('message-text').textContent).toContain('Could not read this page');
  });

  it('sends the edited form with the bearer token to the configured base URL and cuts the summary to 2000', async () => {
    const env = setup({
      injection: { ok: true, profile },
      fetchImpl: (url) =>
        isMe(url)
          ? json(200, { data: { user: { id: 1, name: 'A', email: 'a@example.com' }, vacancies: [{ id: 5, title: 'QA', branch: 'Kyiv' }] } })
          : json(201, { data: { candidate_id: 9, url: 'https://hrm.example.com/candidates/9', created: true } }),
    });
    await load();
    expect(el<HTMLSelectElement>('f-vacancy').options).toHaveLength(2);
    el<HTMLSelectElement>('f-vacancy').value = '5';
    el<HTMLInputElement>('f-full_name').value = 'Ivan T.';
    await submit();
    const call = env.fetchMock.mock.calls.find(([u]) => String(u).endsWith('/api/clipper/candidates'))!;
    expect(call[0]).toBe('https://hrm.example.com/api/clipper/candidates');
    const init = call[1] as RequestInit;
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok-test-123');
    expect(init.credentials).toBe('omit');
    const body = JSON.parse(init.body as string) as Record<string, unknown>;
    expect(body.full_name).toBe('Ivan T.');
    expect(body.vacancy_id).toBe(5);
    expect(String(body.summary)).toHaveLength(2000);
    expect(el('status').textContent).toContain('Candidate created');
  });

  it('blocks submit with an empty name', async () => {
    const env = setup({ injection: { ok: true, profile }, fetchImpl: () => meOk.clone() });
    await load();
    el<HTMLInputElement>('f-full_name').value = '   ';
    await submit();
    expect(el('status').textContent).toContain('Full name is required');
    expect(env.fetchMock.mock.calls.some(([u]) => String(u).endsWith('/candidates'))).toBe(false);
  });

  it('drops a result link that points to a foreign origin', async () => {
    setup({
      injection: { ok: true, profile },
      fetchImpl: (url) =>
        isMe(url) ? meOk.clone() : json(200, { data: { candidate_id: 1, url: 'https://evil.example/c/1', created: false } }),
    });
    await load();
    await submit();
    expect(el('status').textContent).toContain('Already in SinHRM');
    expect(el('status').querySelector('a')).toBeNull();
  });

  it('opens a trusted link through chrome.tabs.create', async () => {
    const env = setup({
      injection: { ok: true, profile },
      fetchImpl: (url) => (isMe(url) ? meOk.clone() : json(200, { data: { candidate_id: 1, url: '/candidates/1', created: true } })),
    });
    await load();
    await submit();
    el('status').querySelector('a')!.click();
    expect(env.tabs.create).toHaveBeenCalledWith({ url: 'https://hrm.example.com/candidates/1' });
  });

  it('shows validation details and the settings button on 401, never printing the token', async () => {
    setup({
      injection: { ok: true, profile },
      fetchImpl: (url) => (isMe(url) ? json(401, {}) : json(422, { errors: { email: ['bad email'] } })),
    });
    await load();
    expect(el('open-options-inline').hidden).toBe(false);
    await submit();
    expect(el('status').textContent).toContain('email: bad email');
    expect(document.body.innerHTML).not.toContain('tok-test-123');
  });
});

describe('options', () => {
  beforeEach(() => {
    vi.resetModules();
    document.body.innerHTML = page('options.html');
  });
  afterEach(() => vi.unstubAllGlobals());

  const load = async () => {
    await import('../src/options');
    await flush();
  };
  const submit = async () => {
    el<HTMLFormElement>('options-form').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
  };

  it('prefills stored settings and masks the token field', async () => {
    setup({});
    await load();
    expect(el<HTMLInputElement>('base-url').value).toBe('https://hrm.example.com');
    expect(el<HTMLInputElement>('token').value).toBe('tok-test-123');
    expect(el<HTMLInputElement>('token').type).toBe('password');
  });

  it.each(['http://hrm.example.com', 'javascript:alert(1)', 'https://u:p@hrm.example.com', 'garbage'])(
    'refuses to store a non-https or malformed base URL (%s)',
    async (bad) => {
      const env = setup({});
      await load();
      el<HTMLInputElement>('base-url').value = bad;
      el<HTMLInputElement>('token').value = 'tok-new';
      await submit();
      expect(env.store.baseUrl).toBe('https://hrm.example.com');
      expect(env.store.token).toBe('tok-test-123');
      expect(el('status').textContent).toContain('https://');
    },
  );

  it('stores a normalized URL and a trimmed token', async () => {
    const env = setup({});
    await load();
    el<HTMLInputElement>('base-url').value = ' https://hrm2.example.com/// ';
    el<HTMLInputElement>('token').value = '  tok-new  ';
    await submit();
    expect(env.store.baseUrl).toBe('https://hrm2.example.com');
    expect(env.store.token).toBe('tok-new');
    expect(el('status').textContent).toBe('Saved');
  });

  it('check connection reports the user name, then an auth error without showing the token', async () => {
    let ok = true;
    const env = setup({
      fetchImpl: () =>
        ok ? json(200, { data: { user: { id: 1, name: 'Test User', email: 'u@example.com' }, vacancies: [] } }) : json(401, {}),
    });
    await load();
    el('check').click();
    await flush();
    expect(el('status').textContent).toBe('Connected as: Test User');
    expect(String(env.fetchMock.mock.calls[0]![0])).toBe('https://hrm.example.com/api/clipper/me');
    ok = false;
    el('check').click();
    await flush();
    expect(el('status').textContent).toContain('Token invalid/expired');
    expect(el('status').textContent).not.toContain('tok-test-123');
  });

  it('persists the language change', async () => {
    const env = setup({});
    await load();
    el<HTMLSelectElement>('lang').value = 'uk';
    el('lang').dispatchEvent(new Event('change'));
    await flush();
    expect(env.store.lang).toBe('uk');
  });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DEFAULT_BASE_URL, loadSettings, saveSettings } from '../src/settings';

type Store = Record<string, unknown>;

function mockChrome(store: Store, local: Record<string, unknown> = {}) {
  const setAccessLevel = vi.fn(async () => undefined);
  const get = vi.fn(async (keys: string[]) => Object.fromEntries(keys.filter((k) => k in store).map((k) => [k, store[k]])));
  const set = vi.fn(async (patch: Store) => {
    Object.assign(store, patch);
  });
  vi.stubGlobal('chrome', { storage: { local: { setAccessLevel, get, set, ...local } } });
  return { setAccessLevel, get, set };
}

describe('token storage (chrome.storage.local)', () => {
  beforeEach(() => vi.unstubAllGlobals());
  afterEach(() => vi.unstubAllGlobals());

  it('returns defaults when nothing is stored', async () => {
    mockChrome({});
    expect(await loadSettings()).toEqual({ baseUrl: DEFAULT_BASE_URL, token: '', lang: undefined });
  });

  it('round-trips token, base URL and language', async () => {
    const store: Store = {};
    mockChrome(store);
    await saveSettings({ baseUrl: 'https://hrm.example.com', token: 'tok-test-123', lang: 'uk' });
    expect(store).toEqual({ baseUrl: 'https://hrm.example.com', token: 'tok-test-123', lang: 'uk' });
    expect(await loadSettings()).toEqual({ baseUrl: 'https://hrm.example.com', token: 'tok-test-123', lang: 'uk' });
  });

  it('saves only the given patch (a language change does not touch the token)', async () => {
    const store: Store = { token: 'tok-test-123' };
    const { set } = mockChrome(store);
    await saveSettings({ lang: 'ru' });
    expect(set).toHaveBeenCalledWith({ lang: 'ru' });
    expect(store.token).toBe('tok-test-123');
  });

  it('restricts storage to trusted contexts before every read and write', async () => {
    const { setAccessLevel, get, set } = mockChrome({});
    await loadSettings();
    await saveSettings({ token: 'x' });
    expect(setAccessLevel).toHaveBeenCalledTimes(2);
    expect(setAccessLevel).toHaveBeenCalledWith({ accessLevel: 'TRUSTED_CONTEXTS' });
    expect(setAccessLevel.mock.invocationCallOrder[0]).toBeLessThan(get.mock.invocationCallOrder[0]!);
    expect(setAccessLevel.mock.invocationCallOrder[1]).toBeLessThan(set.mock.invocationCallOrder[0]!);
  });

  it('still works when setAccessLevel is missing (older Chrome)', async () => {
    const store: Store = { token: 'tok-test-123' };
    mockChrome(store, { setAccessLevel: undefined });
    expect((await loadSettings()).token).toBe('tok-test-123');
  });

  it('still works when setAccessLevel throws', async () => {
    const store: Store = { token: 'tok-test-123' };
    mockChrome(store, {
      setAccessLevel: vi.fn(async () => {
        throw new Error('unsupported');
      }),
    });
    expect((await loadSettings()).token).toBe('tok-test-123');
    await expect(saveSettings({ token: 'new' })).resolves.toBeUndefined();
    expect(store.token).toBe('new');
  });

  it('ignores stored values of the wrong type', async () => {
    mockChrome({ baseUrl: 42, token: { a: 1 }, lang: ['uk'] });
    expect(await loadSettings()).toEqual({ baseUrl: DEFAULT_BASE_URL, token: '', lang: undefined });
  });

  it('falls back to the default URL for an empty stored base URL', async () => {
    mockChrome({ baseUrl: '', token: 't' });
    expect((await loadSettings()).baseUrl).toBe(DEFAULT_BASE_URL);
  });
});

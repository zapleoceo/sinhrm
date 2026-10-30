import { TestBed } from '@angular/core/testing';
import {
  APPEARANCE_STORAGE_KEY,
  DEFAULT_APPEARANCE,
  THEME_STORAGE_KEY,
  ThemeService,
} from './theme.service';

describe('ThemeService', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('style');
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-color-scheme');
    document.documentElement.removeAttribute('data-accent');
    document.documentElement.removeAttribute('data-interface-style');
    document.documentElement.removeAttribute('data-wallpaper');
    document.documentElement.removeAttribute('data-performance');
    document.documentElement.removeAttribute('data-motion');
    TestBed.configureTestingModule({});
  });

  afterEach(() => vi.restoreAllMocks());

  it('migrates the legacy theme value and keeps the toggle API compatible', () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'dark');
    const theme = TestBed.inject(ThemeService);
    expect(theme.theme()).toBe('dark');
    theme.init();
    theme.toggle();
    expect(theme.theme()).toBe('light');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('light');
    expect(JSON.parse(localStorage.getItem(APPEARANCE_STORAGE_KEY) ?? '{}')).toMatchObject({ themePreference: 'light' });
  });

  it('falls back safely for malformed or unrecognized persisted payloads', () => {
    localStorage.setItem(APPEARANCE_STORAGE_KEY, '{bad json');
    localStorage.setItem(THEME_STORAGE_KEY, 'unexpected');
    expect(TestBed.inject(ThemeService).preferences()).toEqual(DEFAULT_APPEARANCE);

    TestBed.resetTestingModule();
    localStorage.setItem(APPEARANCE_STORAGE_KEY, JSON.stringify({ ...DEFAULT_APPEARANCE, tracking: true }));
    TestBed.configureTestingModule({});
    expect(TestBed.inject(ThemeService).preferences()).toEqual(DEFAULT_APPEARANCE);
  });

  it('remains usable when browser storage reads and writes throw', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('storage blocked'); });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('storage blocked'); });
    const theme = TestBed.inject(ThemeService);
    expect(() => theme.init()).not.toThrow();
    expect(() => theme.updatePreferences({ accent: 'green', fastMode: true })).not.toThrow();
    expect(theme.preferences().accent).toBe('green');
    expect(document.documentElement.dataset['accent']).toBe('green');
  });

  it('clamps transparency and applies the shared root attribute contract', () => {
    const theme = TestBed.inject(ThemeService);
    theme.updatePreferences({
      themePreference: 'dark', colorScheme: 'custom', accent: 'violet', interfaceStyle: 'solid',
      wallpaper: 'dusk', transparency: 100, fastMode: true, reducedMotion: true,
    });
    theme.init();
    const root = document.documentElement;
    expect(root.dataset['theme']).toBe('dark');
    expect(root.dataset['colorScheme']).toBe('custom');
    expect(root.dataset['accent']).toBe('violet');
    expect(root.dataset['interfaceStyle']).toBe('solid');
    expect(root.dataset['wallpaper']).toBe('dusk');
    expect(root.dataset['performance']).toBe('fast');
    expect(root.dataset['motion']).toBe('reduced');
    expect(root.style.getPropertyValue('--os-glass-opacity')).toBe('60%');
    expect(theme.preferences().transparency).toBe(40);
  });

  it('uses the reference scheme as dark and leaves it when theme, accent, or wallpaper changes', () => {
    const theme = TestBed.inject(ThemeService);
    theme.updatePreferences({ colorScheme: 'sintegrum' });
    expect(theme.theme()).toBe('dark');
    theme.setAccent('green');
    expect(theme.preferences().colorScheme).toBe('custom');
    theme.updatePreferences({ colorScheme: 'sintegrum' });
    theme.setWallpaper('sand');
    expect(theme.preferences().colorScheme).toBe('custom');
    theme.updatePreferences({ colorScheme: 'sintegrum' });
    theme.setThemePreference('light');
    expect(theme.theme()).toBe('light');
    expect(theme.preferences().colorScheme).toBe('custom');
  });

  it('reset restores defaults and a safe value', () => {
    const theme = TestBed.inject(ThemeService);
    theme.updatePreferences({ accent: 'graphite', fastMode: true });
    theme.reset();
    expect(theme.preferences()).toEqual(DEFAULT_APPEARANCE);
    expect(JSON.parse(localStorage.getItem(APPEARANCE_STORAGE_KEY) ?? '{}')).toEqual(DEFAULT_APPEARANCE);
  });

  it('follows system color and motion changes and registers listeners once', () => {
    const listeners = new Map<string, (event: MediaQueryListEvent) => void>();
    const media = (query: string): MediaQueryList => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => undefined,
      removeListener: () => undefined,
      dispatchEvent: () => true,
      addEventListener: (_type: string, listener: EventListenerOrEventListenerObject | null) => {
        if (typeof listener === 'function') {
          listeners.set(query, listener as unknown as (event: MediaQueryListEvent) => void);
        }
      },
      removeEventListener: () => listeners.delete(query),
    } as unknown as MediaQueryList);
    Object.defineProperty(window, 'matchMedia', { configurable: true, value: vi.fn(media) });

    const theme = TestBed.inject(ThemeService);
    theme.init();
    theme.init();
    expect(window.matchMedia).toHaveBeenCalledTimes(4);
    listeners.get('(prefers-color-scheme: dark)')?.({ matches: true } as MediaQueryListEvent);
    expect(theme.theme()).toBe('dark');
    listeners.get('(prefers-reduced-motion: reduce)')?.({ matches: true } as MediaQueryListEvent);
    expect(document.documentElement.dataset['motion']).toBe('reduced');
  });
});

import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { safeStorage } from '../storage/safe-storage';

export type Theme = 'light' | 'dark';
export type ThemePreference = 'system' | Theme;
export type ColorScheme = 'custom' | 'sintegrum';
export type AccentColor = 'blue' | 'violet' | 'green' | 'graphite';
export type InterfaceStyle = 'glass' | 'solid';
export type Wallpaper = 'mist' | 'sand' | 'dusk';

export interface AppearancePreferences {
  readonly themePreference: ThemePreference;
  readonly colorScheme: ColorScheme;
  readonly accent: AccentColor;
  readonly interfaceStyle: InterfaceStyle;
  readonly wallpaper: Wallpaper;
  readonly transparency: number;
  readonly fastMode: boolean;
  readonly reducedMotion: boolean;
}

export type AppearancePreferencePatch = Partial<AppearancePreferences>;

export const THEME_STORAGE_KEY = 'sinhrm.theme';
export const APPEARANCE_STORAGE_KEY = 'sinhrm.appearance';

export const DEFAULT_APPEARANCE: AppearancePreferences = {
  themePreference: 'system',
  colorScheme: 'custom',
  accent: 'blue',
  interfaceStyle: 'glass',
  wallpaper: 'mist',
  transparency: 18,
  fastMode: false,
  reducedMotion: false,
};

const themes: readonly Theme[] = ['light', 'dark'];
const themePreferences: readonly ThemePreference[] = ['system', ...themes];
const colorSchemes: readonly ColorScheme[] = ['custom', 'sintegrum'];
const accents: readonly AccentColor[] = ['blue', 'violet', 'green', 'graphite'];
const interfaceStyles: readonly InterfaceStyle[] = ['glass', 'solid'];
const wallpapers: readonly Wallpaper[] = ['mist', 'sand', 'dusk'];
const validKeys = new Set<keyof AppearancePreferences>([
  'themePreference', 'colorScheme', 'accent', 'interfaceStyle', 'wallpaper', 'transparency', 'fastMode', 'reducedMotion',
]);

/** One local, privacy-safe authority for appearance preferences and their root CSS contract. */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private readonly preferencesState = signal<AppearancePreferences>(this.loadPreferences());
  private readonly systemDark = signal(this.matches('(prefers-color-scheme: dark)'));
  private readonly systemReducedMotion = signal(this.matches('(prefers-reduced-motion: reduce)'));
  private initialized = false;

  readonly preferences = this.preferencesState.asReadonly();
  readonly theme = computed<Theme>(() => {
    const preferences = this.preferencesState();
    if (preferences.colorScheme === 'sintegrum') return 'dark';
    return preferences.themePreference === 'system'
      ? (this.systemDark() ? 'dark' : 'light')
      : preferences.themePreference;
  });

  init(): void {
    if (!this.initialized) {
      this.initialized = true;
      this.watchMedia('(prefers-color-scheme: dark)', this.systemDark);
      this.watchMedia('(prefers-reduced-motion: reduce)', this.systemReducedMotion);
    }
    this.apply();
  }

  updatePreferences(patch: AppearancePreferencePatch): void {
    const current = this.preferencesState();
    const exitsReference = current.colorScheme === 'sintegrum'
      && (patch.themePreference !== undefined && patch.themePreference !== current.themePreference
        || patch.accent !== undefined && patch.accent !== current.accent
        || patch.wallpaper !== undefined && patch.wallpaper !== current.wallpaper);
    const next = this.sanitize({ ...current, ...patch, ...(exitsReference ? { colorScheme: 'custom' } : {}) });
    this.preferencesState.set(next);
    safeStorage.set(APPEARANCE_STORAGE_KEY, JSON.stringify(next));
    safeStorage.set(THEME_STORAGE_KEY, this.effectiveTheme(next));
    this.apply();
  }

  setThemePreference(themePreference: ThemePreference): void {
    if (!themePreferences.includes(themePreference)) return;
    this.updatePreferences({ themePreference, colorScheme: 'custom' });
  }

  setAccent(accent: AccentColor): void {
    if (!accents.includes(accent)) return;
    this.updatePreferences({ accent, colorScheme: 'custom' });
  }

  setWallpaper(wallpaper: Wallpaper): void {
    if (!wallpapers.includes(wallpaper)) return;
    this.updatePreferences({ wallpaper, colorScheme: 'custom' });
  }

  toggle(): void {
    this.setThemePreference(this.theme() === 'dark' ? 'light' : 'dark');
  }

  reset(): void {
    this.preferencesState.set({ ...DEFAULT_APPEARANCE });
    safeStorage.set(APPEARANCE_STORAGE_KEY, JSON.stringify(DEFAULT_APPEARANCE));
    safeStorage.set(THEME_STORAGE_KEY, this.effectiveTheme(DEFAULT_APPEARANCE));
    this.apply();
  }

  private loadPreferences(): AppearancePreferences {
    const raw = safeStorage.get(APPEARANCE_STORAGE_KEY);
    if (raw !== null) {
      try {
        const parsed: unknown = JSON.parse(raw);
        if (this.isRecord(parsed) && Object.keys(parsed).every((key) => validKeys.has(key as keyof AppearancePreferences))) {
          return this.sanitize(parsed);
        }
      } catch {
        // Ignore invalid persisted data and continue with the legacy theme or defaults.
      }
    }
    const legacy = safeStorage.get(THEME_STORAGE_KEY);
    if (legacy === 'light' || legacy === 'dark') return { ...DEFAULT_APPEARANCE, themePreference: legacy };
    return { ...DEFAULT_APPEARANCE };
  }

  private sanitize(value: unknown): AppearancePreferences {
    const source = this.isRecord(value) ? value : {};
    const transparency = source['transparency'];
    return {
      themePreference: this.member(source['themePreference'], themePreferences, DEFAULT_APPEARANCE.themePreference),
      colorScheme: this.member(source['colorScheme'], colorSchemes, DEFAULT_APPEARANCE.colorScheme),
      accent: this.member(source['accent'], accents, DEFAULT_APPEARANCE.accent),
      interfaceStyle: this.member(source['interfaceStyle'], interfaceStyles, DEFAULT_APPEARANCE.interfaceStyle),
      wallpaper: this.member(source['wallpaper'], wallpapers, DEFAULT_APPEARANCE.wallpaper),
      transparency: typeof transparency === 'number' && Number.isFinite(transparency)
        ? Math.max(0, Math.min(40, Math.round(transparency)))
        : DEFAULT_APPEARANCE.transparency,
      fastMode: typeof source['fastMode'] === 'boolean' ? source['fastMode'] : DEFAULT_APPEARANCE.fastMode,
      reducedMotion: typeof source['reducedMotion'] === 'boolean' ? source['reducedMotion'] : DEFAULT_APPEARANCE.reducedMotion,
    };
  }

  private member<T extends string>(value: unknown, allowed: readonly T[], fallback: T): T {
    return typeof value === 'string' && allowed.includes(value as T) ? value as T : fallback;
  }

  private isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }

  private effectiveTheme(preferences: AppearancePreferences): Theme {
    if (preferences.colorScheme === 'sintegrum') return 'dark';
    if (preferences.themePreference !== 'system') return preferences.themePreference;
    return this.systemDark() ? 'dark' : 'light';
  }

  private apply(): void {
    const root = this.document.documentElement;
    const preferences = this.preferencesState();
    const effectiveTheme = this.theme();
    root.dataset['theme'] = effectiveTheme;
    root.dataset['colorScheme'] = preferences.colorScheme;
    root.dataset['accent'] = preferences.accent;
    root.dataset['interfaceStyle'] = preferences.interfaceStyle;
    root.dataset['wallpaper'] = preferences.wallpaper;
    root.dataset['performance'] = preferences.fastMode ? 'fast' : 'standard';
    root.dataset['motion'] = preferences.reducedMotion || this.systemReducedMotion() ? 'reduced' : 'normal';
    root.style.setProperty('--os-glass-opacity', `${100 - preferences.transparency}%`);
  }

  private matches(query: string): boolean {
    return this.document.defaultView?.matchMedia?.(query).matches ?? false;
  }

  private watchMedia(query: string, target: ReturnType<typeof signal<boolean>>): void {
    const media = this.document.defaultView?.matchMedia?.(query);
    if (!media) return;
    const listener = (event: MediaQueryListEvent): void => {
      target.set(event.matches);
      this.apply();
    };
    media.addEventListener?.('change', listener);
    this.destroyRef.onDestroy(() => media.removeEventListener?.('change', listener));
  }
}

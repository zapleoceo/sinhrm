import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { safeStorage } from '../storage/safe-storage';

export type Theme = 'light' | 'dark';
export const THEME_STORAGE_KEY = 'sinhrm.theme';

/**
 * Light/dark theme. Default follows the OS (prefers-color-scheme); an explicit choice is kept
 * in localStorage and applied as <html data-theme="…"> (styles.scss switches color-scheme).
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly document = inject(DOCUMENT);
  private readonly mode = signal<Theme>(this.initial());

  readonly theme = this.mode.asReadonly();

  init(): void {
    this.apply(this.mode());
  }

  toggle(): void {
    const next: Theme = this.mode() === 'dark' ? 'light' : 'dark';
    this.mode.set(next);
    safeStorage.set(THEME_STORAGE_KEY, next);
    this.apply(next);
  }

  private initial(): Theme {
    const saved = safeStorage.get(THEME_STORAGE_KEY);
    if (saved === 'light' || saved === 'dark') {
      return saved;
    }
    const prefersDark = this.document.defaultView?.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
    return prefersDark ? 'dark' : 'light';
  }

  private apply(theme: Theme): void {
    this.document.documentElement.dataset['theme'] = theme;
  }
}

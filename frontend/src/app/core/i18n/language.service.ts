import { DOCUMENT } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { AppLang, DEFAULT_LANG, isAppLang } from '../auth/auth.model';
import { AuthService } from '../auth/auth.service';
import { safeStorage } from '../storage/safe-storage';

export const LANG_STORAGE_KEY = 'sinhrm.lang';

/**
 * UI language. Signed-in users: stored on the server (PATCH /api/auth/me/locale).
 * Guests: stored in localStorage of this browser.
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly transloco = inject(TranslocoService);
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);
  private readonly document = inject(DOCUMENT);
  private readonly lang = signal<AppLang>(DEFAULT_LANG);

  readonly current = this.lang.asReadonly();

  /** Picks the start language: user profile → saved guest choice → default (uk). */
  init(): void {
    const saved = safeStorage.get(LANG_STORAGE_KEY);
    const lang = this.auth.user()?.locale ?? (isAppLang(saved) ? saved : DEFAULT_LANG);
    this.apply(lang);
  }

  async use(lang: AppLang): Promise<void> {
    const previous = this.lang();
    this.apply(lang);
    if (!this.auth.isLoggedIn()) {
      safeStorage.set(LANG_STORAGE_KEY, lang);
      return;
    }
    try {
      await firstValueFrom(this.http.patch('/api/auth/me/locale', { locale: lang }));
      this.auth.setLocale(lang);
    } catch {
      this.apply(previous);
    }
  }

  private apply(lang: AppLang): void {
    this.lang.set(lang);
    this.transloco.setActiveLang(lang);
    this.document.documentElement.lang = lang;
  }
}

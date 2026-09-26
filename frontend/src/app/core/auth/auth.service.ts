import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AppLang, CurrentUser, UserRole } from './auth.model';

/** Session state of the SPA. Auth itself is a Sanctum session cookie (same origin, no tokens in JS). */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly state = signal<CurrentUser | null>(null);
  private readonly busy = signal(true);
  private loaded: Promise<void> | null = null;

  readonly user = this.state.asReadonly();
  readonly loading = this.busy.asReadonly();
  readonly isLoggedIn = computed(() => this.state() !== null);

  /** Loads GET /api/auth/me once; later callers get the same promise. 401 (or any error) → guest. */
  load(): Promise<void> {
    this.loaded ??= this.fetchMe();
    return this.loaded;
  }

  /** Forces a fresh GET /api/auth/me. */
  reload(): Promise<void> {
    this.loaded = this.fetchMe();
    return this.loaded;
  }

  hasRole(role: UserRole): boolean {
    return this.state()?.roles.includes(role) ?? false;
  }

  /** Module is switched on and allowed for the user's role (docs/modules/modules-access.md). */
  hasModule(key: string): boolean {
    const modules = this.state()?.modules;
    return modules === undefined || modules.includes(key);
  }

  setLocale(locale: AppLang): void {
    this.state.update((u) => (u ? { ...u, locale } : u));
  }

  async logout(): Promise<void> {
    try {
      await firstValueFrom(this.http.post<void>('/api/auth/logout', {}));
    } finally {
      this.state.set(null);
    }
  }

  private async fetchMe(): Promise<void> {
    this.busy.set(true);
    try {
      this.state.set(await firstValueFrom(this.http.get<CurrentUser>('/api/auth/me')));
    } catch (e: unknown) {
      if (!(e instanceof HttpErrorResponse)) {
        throw e;
      }
      this.state.set(null);
    } finally {
      this.busy.set(false);
    }
  }
}

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageSwitcher } from '../shell/language-switcher';
import { loginErrorKey } from './login-error';

/** Public sign-in page. The Google flow is a full-page redirect handled by the API. */
@Component({
  selector: 'app-login-page',
  imports: [MatButtonModule, MatIconModule, TranslocoPipe, LanguageSwitcher],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <section class="card" aria-labelledby="login-title">
        <p class="brand">{{ 'app.name' | transloco }}</p>
        <h1 id="login-title">{{ 'login.title' | transloco }}</h1>
        <p class="muted">{{ 'login.subtitle' | transloco }}</p>

        @if (errorKey(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }

        <a mat-flat-button class="google" [href]="googleUrl">
          <mat-icon>login</mat-icon>
          {{ 'login.google' | transloco }}
        </a>
        <p class="muted small">{{ 'login.hint' | transloco }}</p>
      </section>
      <app-language-switcher />
    </main>
  `,
  styles: `
    .login { min-height: 100vh; display: grid; place-content: center; gap: 1rem; justify-items: center; padding: 1rem; }
    .card {
      width: min(24rem, calc(100vw - 2rem)); box-sizing: border-box; padding: 2.5rem 2rem;
      border: 1px solid var(--app-border); border-radius: var(--app-radius); background: var(--mat-sys-surface-container-low);
    }
    .brand { font: var(--mat-sys-title-medium); color: var(--mat-sys-primary); margin: 0 0 1.5rem; }
    h1 { font: var(--mat-sys-headline-small); margin: 0 0 0.5rem; }
    .google { width: 100%; margin: 1.5rem 0 1rem; }
    .error { color: var(--app-danger); margin: 1rem 0 0; }
    .small { font: var(--mat-sys-body-small); margin: 0; }
  `,
})
export class LoginPage {
  /** ?error=<code> from the OAuth callback (router input binding). */
  readonly error = input<string>();

  protected readonly googleUrl = '/api/auth/google/redirect';
  protected readonly errorKey = computed(() => loginErrorKey(this.error()));
}

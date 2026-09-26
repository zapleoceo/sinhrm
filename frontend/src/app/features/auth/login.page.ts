import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Logo } from '../../core/ui/logo';
import { LanguageSwitcher } from '../shell/language-switcher';
import { loginErrorKey } from './login-error';

/**
 * Public sign-in page; also renders the ?error=<code> denial states (not invited, blocked…).
 * The Google flow is a full-page redirect handled by the API.
 * Background: pure-CSS drifting blobs (transform only), static under prefers-reduced-motion.
 */
@Component({
  selector: 'app-login-page',
  imports: [TranslocoPipe, Logo, LanguageSwitcher],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <div class="bg" aria-hidden="true">
        <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
        <span class="grid"></span>
      </div>

      <section class="card" aria-labelledby="login-title">
        <header class="top">
          <app-logo />
          <app-language-switcher class="lang" />
        </header>

        <h1 id="login-title">{{ 'login.title' | transloco }}</h1>
        <p class="subtitle">{{ 'login.subtitle' | transloco }}</p>

        @if (errorKey(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }

        <a class="google" [href]="googleUrl">
          <svg class="g" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
          </svg>
          <span>{{ 'login.google' | transloco }}</span>
        </a>

        <p class="hint">{{ 'login.hint' | transloco }}</p>
        <p class="tagline">{{ 'login.tagline' | transloco }}</p>
      </section>
    </main>
  `,
  styles: `
    :host { display: block; }
    .login {
      position: relative; isolation: isolate; overflow: hidden; min-height: 100dvh; box-sizing: border-box;
      display: grid; place-items: center; padding: 1rem; background: var(--mat-sys-surface);
    }
    .bg { position: absolute; inset: 0; z-index: -1; pointer-events: none; }
    .blob {
      position: absolute; width: 42vmax; height: 42vmax; border-radius: 50%;
      filter: blur(70px); opacity: 0.4; will-change: transform;
      animation: drift 28s ease-in-out infinite alternate;
    }
    .b1 { background: var(--mat-sys-primary); top: -18vmax; left: -12vmax; }
    .b2 { background: var(--mat-sys-tertiary); bottom: -20vmax; right: -14vmax; animation-duration: 34s; animation-direction: alternate-reverse; }
    .b3 { background: var(--mat-sys-secondary); top: 35%; left: 45%; width: 28vmax; height: 28vmax; opacity: 0.22; animation-duration: 40s; }
    .grid {
      position: absolute; inset: 0; opacity: 0.35;
      background-image:
        linear-gradient(var(--app-border) 1px, transparent 1px),
        linear-gradient(90deg, var(--app-border) 1px, transparent 1px);
      background-size: 32px 32px;
      mask-image: radial-gradient(ellipse at center, #000 20%, transparent 70%);
    }
    @keyframes drift {
      from { transform: translate3d(0, 0, 0) scale(1); }
      to { transform: translate3d(8vmax, 6vmax, 0) scale(1.15); }
    }

    .card {
      width: min(26rem, 100%); box-sizing: border-box; padding: 2rem 2rem 1.75rem;
      border: 1px solid var(--app-border); border-radius: calc(var(--app-radius) * 1.5);
      background: var(--mat-sys-surface-container-low);
      box-shadow: 0 1px 2px rgb(0 0 0 / 0.06), 0 12px 40px rgb(0 0 0 / 0.12);
    }
    @supports (backdrop-filter: blur(1px)) {
      .card {
        background: color-mix(in srgb, var(--mat-sys-surface-container-low) 80%, transparent);
        backdrop-filter: blur(18px) saturate(1.3);
      }
    }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 2rem; }
    .lang { flex: none; --mat-button-toggle-height: 30px; --mat-standard-button-toggle-height: 30px; font-size: 0.75rem; }

    h1 { font: var(--mat-sys-headline-medium); margin: 0 0 0.375rem; color: var(--mat-sys-on-surface); }
    .subtitle { margin: 0; color: var(--app-muted); }
    .error {
      margin: 1.25rem 0 0; padding: 0.75rem 0.875rem; border-radius: var(--app-radius);
      color: var(--app-danger); background: color-mix(in srgb, var(--app-danger) 10%, transparent);
      border: 1px solid color-mix(in srgb, var(--app-danger) 35%, transparent);
    }
    .google {
      display: flex; align-items: center; justify-content: center; gap: 0.75rem;
      width: 100%; box-sizing: border-box; min-height: 48px; margin: 1.5rem 0 1rem; padding: 0 1rem;
      border-radius: 999px; border: 1px solid var(--app-border);
      background: var(--mat-sys-surface-container-lowest); color: var(--mat-sys-on-surface);
      font: var(--mat-sys-label-large); font-size: 0.95rem; text-decoration: none;
      box-shadow: 0 1px 2px rgb(0 0 0 / 0.08);
      transition: box-shadow 150ms ease, transform 150ms ease;
    }
    .google:hover { box-shadow: 0 4px 14px rgb(0 0 0 / 0.14); transform: translateY(-1px); }
    .google:active { transform: none; }
    .google:focus-visible { outline: 3px solid var(--mat-sys-primary); outline-offset: 3px; }
    .g { width: 20px; height: 20px; flex: none; }
    .hint { margin: 0; text-align: center; font: var(--mat-sys-body-small); color: var(--app-muted); }
    .tagline {
      margin: 1.5rem 0 0; padding-top: 1rem; border-top: 1px solid var(--app-border);
      text-align: center; font: var(--mat-sys-label-medium); color: var(--app-muted);
    }
    @media (max-width: 480px) {
      .card { padding: 1.5rem 1.25rem 1.25rem; }
      .top { margin-bottom: 1.5rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .blob { animation: none; }
      .google { transition: none; }
      .google:hover { transform: none; }
    }
  `,
})
export class LoginPage {
  /** ?error=<code> from the OAuth callback (router input binding). */
  readonly error = input<string>();

  protected readonly googleUrl = '/api/auth/google/redirect';
  protected readonly errorKey = computed(() => loginErrorKey(this.error()));
}

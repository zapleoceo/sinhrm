import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Logo } from '../../core/ui/logo';
import { LanguageSwitcher } from '../shell/language-switcher';
import { loginErrorKey } from './login-error';

/** Stage kinds of the decorative route on the card, in funnel order (colours = global `--app-stage-*` tokens). */
export const LOGIN_ROUTE = ['new', 'screen', 'interview', 'offer', 'hire'] as const;

/**
 * Public sign-in page; also renders the ?error=<code> denial states (not invited, blocked…).
 * The Google flow is a full-page redirect handled by the API.
 * Look (restyle C «Маршрут»): a static soft glow + masked grid behind a «line» card; a five-station route above
 * the title (stage colours by kind) is the page's one «trace» animation, the card's entrance its one «pop» — both
 * transform/opacity only and off under prefers-reduced-motion.
 */
@Component({
  selector: 'app-login-page',
  imports: [TranslocoPipe, Logo, LanguageSwitcher],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <div class="bg" aria-hidden="true">
        <span class="grid"></span>
      </div>

      <section class="card" aria-labelledby="login-title">
        <header class="top">
          <app-logo />
          <app-language-switcher class="lang" />
        </header>

        <div class="route" aria-hidden="true" data-testid="login-route">
          <span class="rail"><span class="trace"></span></span>
          @for (kind of stations; track kind) {
            <span class="app-station" [attr.data-kind]="kind" [class.current]="$last"></span>
          }
        </div>

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
    /* Background (login only): a static brand/teal glow on the canvas and a masked grid. */
    .login {
      position: relative; isolation: isolate; overflow: hidden; min-height: 100dvh; box-sizing: border-box;
      display: grid; place-items: center; padding: 1rem;
      background:
        radial-gradient(48rem 34rem at 8% -6%, color-mix(in srgb, var(--mat-sys-primary) 16%, transparent), transparent 70%),
        radial-gradient(44rem 32rem at 104% 106%, color-mix(in srgb, var(--app-accent) 15%, transparent), transparent 70%),
        var(--app-canvas);
    }
    .bg { position: absolute; inset: 0; z-index: -1; pointer-events: none; }
    .grid {
      position: absolute; inset: 0; opacity: 0.6;
      background-image:
        linear-gradient(var(--app-track) 1px, transparent 1px),
        linear-gradient(90deg, var(--app-track) 1px, transparent 1px);
      background-size: 32px 32px;
      mask-image: radial-gradient(ellipse at center, black 15%, transparent 68%);
    }

    /* Card: «line» language — 1.5px frame, no shadow; frosted over the glow where supported. */
    .card {
      width: min(26rem, 100%); box-sizing: border-box; padding: 1.75rem 2rem 1.5rem;
      border: var(--app-border-w) solid var(--app-border); border-radius: calc(var(--app-radius) + 4px);
      background: var(--app-card);
      animation: pop 280ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
    }
    @supports (backdrop-filter: blur(1px)) {
      .card {
        background: color-mix(in srgb, var(--app-card) 90%, transparent);
        backdrop-filter: blur(16px) saturate(1.2);
      }
    }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 1.5rem; }
    .lang { flex: none; --mat-button-toggle-height: 32px; font-size: 0.75rem; }

    /* Route: five stations (new → screen → interview → offer → hire) on one rail; the rail fill «traces» once. */
    .route { position: relative; display: flex; justify-content: space-between; align-items: center; height: 0.75rem; margin: 0 0 1.25rem; }
    .rail { position: absolute; left: 0.375rem; right: 0.375rem; top: 50%; height: 4px; margin-top: -2px; border-radius: 2px; background: var(--app-track); overflow: hidden; }
    .trace {
      position: absolute; inset: 0; border-radius: inherit; transform-origin: left center;
      background: linear-gradient(90deg, var(--app-stage-new), var(--app-stage-screen), var(--app-stage-interview), var(--app-stage-offer), var(--app-stage-hire));
      animation: trace 600ms cubic-bezier(0.3, 0.7, 0.2, 1) 120ms both;
    }
    .route .app-station { position: relative; }
    .route .app-station.current { box-shadow: 0 0 0 4px color-mix(in srgb, var(--app-stage-hire) 22%, transparent); }

    h1 { font: var(--mat-sys-headline-medium); letter-spacing: var(--mat-sys-headline-medium-tracking); margin: 0 0 0.375rem; }
    .subtitle { margin: 0; color: var(--app-muted); }
    /* Denial: text + a square marker (meaning never by colour alone). */
    .error {
      display: flex; align-items: flex-start; gap: 0.6rem;
      margin: 1.25rem 0 0; padding: 0.75rem 0.875rem; border-radius: var(--app-radius-sm);
      color: var(--app-bad-text); background: var(--app-bad-bg);
      border: var(--app-border-w) solid color-mix(in srgb, var(--app-bad-text) 35%, transparent);
    }
    .error::before { content: ''; flex: none; width: 0.55rem; height: 0.55rem; margin-top: 0.42em; border-radius: 1px; background: currentColor; }
    .google {
      display: flex; align-items: center; justify-content: center; gap: 0.75rem;
      width: 100%; box-sizing: border-box; min-height: 48px; margin: 1.5rem 0 1rem; padding: 0 1rem;
      border-radius: var(--app-radius-pill); border: var(--app-border-w) solid var(--mat-sys-outline);
      background: var(--app-card); color: var(--mat-sys-on-surface);
      font: var(--mat-sys-label-large); font-size: 0.9375rem; text-decoration: none;
      transition: border-color var(--app-fast) ease, transform var(--app-fast) ease;
    }
    .google:hover { border-color: var(--app-ink); transform: translateY(-1px); }
    .g { width: 20px; height: 20px; flex: none; }
    .hint { margin: 0; text-align: center; font: var(--mat-sys-body-small); color: var(--app-muted); }
    .tagline {
      margin: 1.25rem 0 0; padding-top: 1rem; border-top: var(--app-border-w) dashed var(--app-border);
      text-align: center; font: var(--mat-sys-label-medium); color: var(--app-muted);
    }

    @keyframes pop {
      from { opacity: 0; transform: translateY(8px) scale(0.985); }
      to { opacity: 1; transform: none; }
    }
    @keyframes trace {
      from { transform: scaleX(0); }
      to { transform: scaleX(1); }
    }
    /* Touch targets: the language toggles reach 44px on phones (the compact 32px look is desktop only). */
    @media (max-width: 600px) {
      .lang { --mat-button-toggle-height: 44px; }
    }
    @media (max-width: 480px) {
      .card { padding: 1.25rem 1rem 1rem; }
      .top { margin-bottom: 1.25rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .card, .trace { animation: none; }
      .google { transition: none; }
      .google:hover { transform: none; }
    }
  `,
})
export class LoginPage {
  /** ?error=<code> from the OAuth callback (router input binding). */
  readonly error = input<string>();

  protected readonly googleUrl = '/api/auth/google/redirect';
  protected readonly stations = LOGIN_ROUTE;
  protected readonly errorKey = computed(() => loginErrorKey(this.error()));
}

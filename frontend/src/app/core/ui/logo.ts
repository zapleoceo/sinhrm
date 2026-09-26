import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * SinHRM logo, concept C (docs/architecture/design-direction.md §8): a rounded square with an "S" curve
 * cut out as negative space (mask, not a stroke), optional teal accent dot at the end of the S.
 * Square colour = currentColor (host default --app-brand); `mono` drops the dot.
 * Source of public/favicon.svg and the PNG/ICO set (scripts/gen-icons.mjs).
 */
@Component({
  selector: 'app-logo',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg class="mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
      <mask id="sinhrm-s-cut" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32">
        <rect width="32" height="32" fill="#fff" />
        <path d="M21 10.6C19.8 9.4 18.1 8.6 16 8.6c-2.9 0-4.9 1.5-4.9 3.6 0 4.9 9.8 2.9 9.8 7.8 0 2.1-2 3.5-4.9 3.5-2.1 0-3.9-.8-5.1-2.1" fill="none" stroke="#000" stroke-width="4" stroke-linecap="round" />
      </mask>
      <rect x="2" y="2" width="28" height="28" rx="8" fill="currentColor" mask="url(#sinhrm-s-cut)" />
      @if (!mono()) {
        <circle cx="10.9" cy="21.4" r="1.8" fill="#2EC4B6" />
      }
    </svg>
    @if (variant() === 'full') {
      <span class="word">Sin<span class="accent">HRM</span></span>
    }
  `,
  host: { role: 'img', 'aria-label': 'SinHRM' },
  styles: `
    :host { display: inline-flex; align-items: center; gap: 0.5rem; color: var(--app-brand); line-height: 1; }
    .mark { width: var(--app-logo-size, 32px); height: var(--app-logo-size, 32px); flex: none; }
    .word {
      font-family: Onest, Roboto, sans-serif; font-weight: 600; font-size: 1.25rem; letter-spacing: 0.01em;
      color: var(--mat-sys-on-surface);
    }
    .accent { color: var(--app-brand-text); }
  `,
})
export class Logo {
  /** `mark` — square only; `full` — square + wordmark. */
  readonly variant = input<'mark' | 'full'>('full');
  /** Single-colour mark without the accent dot. */
  readonly mono = input(false);
}

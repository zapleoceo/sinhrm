import { Injectable, inject } from '@angular/core';
import { MatSnackBar, MatSnackBarRef, TextOnlySnackBar } from '@angular/material/snack-bar';
import { TranslocoService } from '@jsverse/transloco';

/** How long a toast stays when the caller does not say otherwise (ms). */
export const NOTIFY_DURATION_MS = 4000;

export interface NotifyOptions {
  /** Transloco params of the message key. */
  readonly params?: Record<string, unknown>;
  /** Display time, ms (default NOTIFY_DURATION_MS). */
  readonly duration?: number;
}

/**
 * Short toast (Material snack bar) with a translated text — the one way features tell the user «saved», «failed», ….
 * Accessibility is the snack bar's defaults, as before: the text is announced politely by its live region (aria-live),
 * focus is not moved.
 */
@Injectable({ providedIn: 'root' })
export class NotifyService {
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  /** Shows the translated `key` for `options.duration` ms. */
  show(key: string, options: NotifyOptions = {}): MatSnackBarRef<TextOnlySnackBar> {
    return this.snack.open(this.i18n.translate(key, options.params), undefined, { duration: options.duration ?? NOTIFY_DURATION_MS });
  }
}

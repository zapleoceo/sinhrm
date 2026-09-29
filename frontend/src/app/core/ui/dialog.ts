import { Provider } from '@angular/core';
import { MAT_DIALOG_DEFAULT_OPTIONS, MatDialogConfig } from '@angular/material/dialog';

/** CSS class (styles.scss) that lifts Material's 560px surface cap and makes the dialog full-width on phones. */
export const WIDE_DIALOG_CLASS = 'app-dialog-wide';

/**
 * App-wide MatDialog defaults: never wider than the viewport. Material replaces (does not merge) its own defaults with
 * this object, so it must start from a full MatDialogConfig: a partial object silently drops `role="dialog"`.
 * Material already focuses the first tabbable element and restores focus, so those stay at their defaults.
 */
export function provideAppDialogDefaults(): Provider {
  return {
    provide: MAT_DIALOG_DEFAULT_OPTIONS,
    useValue: { ...new MatDialogConfig(), maxWidth: '95vw' } satisfies MatDialogConfig,
  };
}

/** Config for a form/document dialog wider than 560px: fixed width on desktop, 95vw cap, full-width ≤600px. */
export function wideDialog<D>(data: D, width = '720px'): MatDialogConfig<D> {
  return { data, width, maxWidth: '95vw', panelClass: WIDE_DIALOG_CLASS };
}

import { Provider } from '@angular/core';
import { MAT_DIALOG_DEFAULT_OPTIONS, MatDialogConfig } from '@angular/material/dialog';

/** CSS class (styles.scss) that lifts Material's 560px surface cap and makes the dialog full-width on phones. */
export const WIDE_DIALOG_CLASS = 'app-dialog-wide';

/**
 * App-wide MatDialog defaults: never wider than the viewport, focus the first field (or `cdkFocusInitial`),
 * return focus to the opener on close.
 */
export function provideAppDialogDefaults(): Provider {
  return {
    provide: MAT_DIALOG_DEFAULT_OPTIONS,
    useValue: { maxWidth: '95vw', autoFocus: 'first-tabbable', restoreFocus: true } satisfies MatDialogConfig,
  };
}

/** Config for a form/document dialog wider than 560px: fixed width on desktop, 95vw cap, full-width ≤600px. */
export function wideDialog<D>(data: D, width = '720px'): MatDialogConfig<D> {
  return { data, width, maxWidth: '95vw', panelClass: WIDE_DIALOG_CLASS };
}

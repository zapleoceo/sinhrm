import { Injectable, inject } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, map } from 'rxjs';
import { SUPERADMIN_ROLE, UserRole } from '../../core/auth/auth.model';
import { ConfirmDialog, ConfirmDialogData } from '../workflows/confirm.dialog';

export type SuperadminChange = 'grant' | 'revoke';

/** Whether a role change gives or takes superadmin (null = superadmin is untouched). */
export function superadminChange(current: readonly UserRole[], next: readonly UserRole[]): SuperadminChange | null {
  const had = current.includes(SUPERADMIN_ROLE);
  const has = next.includes(SUPERADMIN_ROLE);
  if (had === has) return null;
  return has ? 'grant' : 'revoke';
}

/**
 * Warning before giving or taking superadmin (HRM-84): full access to everything, users and integrations included.
 * The shared Material confirmation; emits true only when confirmed.
 */
@Injectable({ providedIn: 'root' })
export class SuperadminConfirm {
  private readonly dialog = inject(MatDialog);
  private readonly i18n = inject(TranslocoService);

  ask(change: SuperadminChange, name: string): Observable<boolean> {
    const t = (key: string): string => this.i18n.translate(`users.superadmin.${change}.${key}`, { name });
    return this.dialog
      .open<ConfirmDialog, ConfirmDialogData, { reason: string } | null>(ConfirmDialog, {
        data: { message: t('body'), confirm: t('confirm'), cancel: this.i18n.translate('common.cancel') },
        ariaLabel: t('title'),
      })
      .afterClosed()
      .pipe(map((ok) => !!ok));
  }
}

import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { EraseDialog, EraseDialogData } from './erase.dialog';
import { DataSubjectType, ExportFormat, PrivacyService, canManagePrivacy, privacyErrorKey } from './privacy.service';

/**
 * "Персональні дані" menu on a candidate card / employee profile (superadmin and admin only): export as JSON or a
 * readable HTML file, and erase (anonymize) after the confirmation dialog. Emits `erased` so the host reloads.
 */
@Component({
  selector: 'app-privacy-actions',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (allowed()) {
      <button mat-stroked-button type="button" [matMenuTriggerFor]="menu"><mat-icon>shield_person</mat-icon>{{ 'privacy.menu' | transloco }}</button>
      <mat-menu #menu="matMenu">
        <button mat-menu-item type="button" (click)="download('json')"><mat-icon>data_object</mat-icon>{{ 'privacy.exportJson' | transloco }}</button>
        <button mat-menu-item type="button" (click)="download('html')"><mat-icon>description</mat-icon>{{ 'privacy.exportHtml' | transloco }}</button>
        @if (erasable()) {
          <button mat-menu-item type="button" class="danger" (click)="erase()"><mat-icon>delete_forever</mat-icon>{{ 'privacy.erase.action' | transloco }}</button>
        }
      </mat-menu>
    }
  `,
})
export class PrivacyActions {
  readonly type = input.required<DataSubjectType>();
  readonly subjectId = input.required<number>();
  readonly name = input.required<string>();
  /** False hides "erase" (already anonymized, or an employee who still works here). */
  readonly erasable = input(true);
  readonly erased = output<void>();

  private readonly api = inject(PrivacyService);
  private readonly auth = inject(AuthService);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly allowed = computed(() => canManagePrivacy(this.auth.user()?.roles ?? []));

  protected download(format: ExportFormat): void {
    this.api.download(this.type(), this.subjectId(), format).subscribe({ error: (e: unknown) => this.toast(privacyErrorKey(e)) });
  }

  protected erase(): void {
    this.dialog
      .open<EraseDialog, EraseDialogData, string>(EraseDialog, { data: { name: this.name() } })
      .afterClosed()
      .subscribe((reason) => {
        if (!reason) {
          return;
        }
        this.api.erase(this.type(), this.subjectId(), reason).subscribe({
          next: () => {
            this.toast('privacy.erase.done');
            this.erased.emit();
          },
          error: (e: unknown) => this.toast(privacyErrorKey(e)),
        });
      });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}

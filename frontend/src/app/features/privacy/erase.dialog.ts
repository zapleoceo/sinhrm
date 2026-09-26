import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';

export interface EraseDialogData {
  name: string;
}

/**
 * "Видалити персональні дані": an irreversible-action warning, a required reason (goes to the journal) and an
 * explicit "I understand" checkbox. Closes with the reason, or undefined when cancelled.
 */
@Component({
  selector: 'app-erase-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatDialogModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'privacy.erase.title' | transloco: { name: data.name } }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="warning" role="alert">{{ 'privacy.erase.warning' | transloco }}</p>
        <p>{{ 'privacy.erase.kept' | transloco }}</p>
        <mat-form-field>
          <mat-label>{{ 'privacy.erase.reason' | transloco }}</mat-label>
          <textarea matInput formControlName="reason" rows="2" maxlength="500" required cdkFocusInitial></textarea>
          <mat-hint>{{ 'privacy.erase.reasonHint' | transloco }}</mat-hint>
          <mat-error>{{ 'privacy.erase.reasonRequired' | transloco }}</mat-error>
        </mat-form-field>
        <mat-checkbox formControlName="confirm">{{ 'privacy.erase.confirm' | transloco }}</mat-checkbox>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" class="danger" [disabled]="!form.controls.confirm.value">{{ 'privacy.erase.submit' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; gap: 0.5rem; min-width: min(28rem, 80vw); }
    .warning { color: var(--mat-sys-error); font-weight: 500; }
  `,
})
export class EraseDialog {
  protected readonly data = inject<EraseDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<EraseDialog, string>>(MatDialogRef);
  protected readonly form = inject(NonNullableFormBuilder).group({
    reason: ['', [Validators.required, Validators.minLength(3)]],
    confirm: [false, Validators.requiredTrue],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.ref.close(this.form.getRawValue().reason.trim());
  }
}

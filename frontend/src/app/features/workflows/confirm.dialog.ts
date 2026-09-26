import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';

/** Already translated texts. With `withReason` the dialog asks for an optional text. */
export interface ConfirmDialogData {
  message: string;
  confirm: string;
  cancel: string;
  withReason?: boolean;
  reasonLabel?: string;
}

/** Material confirmation (instead of native confirm/prompt). Closes with null on cancel, else {reason}. */
@Component({
  selector: 'app-workflows-confirm-dialog',
  imports: [MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, ReactiveFormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-dialog-content>
      <p>{{ data.message }}</p>
      @if (data.withReason) {
        <mat-form-field appearance="outline" class="full">
          <mat-label>{{ data.reasonLabel }}</mat-label>
          <input matInput [formControl]="reason" maxlength="200" />
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" [mat-dialog-close]="null">{{ data.cancel }}</button>
      <button mat-flat-button type="button" (click)="ok()">{{ data.confirm }}</button>
    </mat-dialog-actions>
  `,
  styles: `.full { width: 100%; }`,
})
export class ConfirmDialog {
  protected readonly data = inject<ConfirmDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<ConfirmDialog, { reason: string } | null>>(MatDialogRef);
  protected readonly reason = new FormControl('', { nonNullable: true });

  protected ok(): void {
    this.ref.close({ reason: this.reason.value.trim() });
  }
}

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { RejectReason } from '../recruiting.model';

export interface RejectDialogData {
  candidateName: string;
  reasons: RejectReason[];
}

export interface RejectDialogResult {
  reject_reason_id: number;
  reason?: string;
}

/** Asked before moving to the reject stage: a reason from the dictionary is required, a note is optional. */
@Component({
  selector: 'app-reject-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'recruiting.reject.title' | transloco: { name: data.candidateName } }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <mat-form-field>
          <mat-label>{{ 'recruiting.reject.reason' | transloco }}</mat-label>
          <mat-select formControlName="reject_reason_id" required cdkFocusInitial>
            @for (r of data.reasons; track r.id) {
              <mat-option [value]="r.id">{{ r.name }}</mat-option>
            }
          </mat-select>
          <mat-error>{{ 'recruiting.required' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'recruiting.reject.note' | transloco }}</mat-label>
          <textarea matInput formControlName="reason" rows="3" maxlength="2000"></textarea>
        </mat-form-field>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit">{{ 'recruiting.reject.submit' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `mat-dialog-content { display: flex; flex-direction: column; min-width: min(24rem, 80vw); }`,
})
export class RejectDialog {
  protected readonly data = inject<RejectDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<RejectDialog, RejectDialogResult>>(MatDialogRef);
  protected readonly form = inject(NonNullableFormBuilder).group({
    reject_reason_id: [null as number | null, Validators.required],
    reason: [''],
  });

  protected submit(): void {
    const v = this.form.getRawValue();
    if (this.form.invalid || v.reject_reason_id === null) {
      this.form.markAllAsTouched();
      return;
    }
    this.ref.close({ reject_reason_id: v.reject_reason_id, reason: v.reason.trim() || undefined });
  }
}

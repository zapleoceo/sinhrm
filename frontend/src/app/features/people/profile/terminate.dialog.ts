import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';
import { Employee } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';
import { toIsoDate, today } from '../../../core/date/iso-date';

/** True for a calendar day after today (local time, like the datepicker). */
export function isAfterToday(date: Date | null): boolean {
  return date !== null && toIsoDate(date) > toIsoDate(today());
}

/**
 * Terminate an employee (HR or a manager above): last working day (default today) and an optional reason. Nothing is
 * deleted. Today or earlier applies at once; a later date schedules the termination (the button says so).
 */
@Component({
  selector: 'app-terminate-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatDatepickerModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'people.terminate.title' | transloco: { name: employee.full_name } }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="muted">{{ 'people.terminate.hint' | transloco }}</p>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'people.fields.firedAt' | transloco }}</mat-label>
          <input matInput [matDatepicker]="dp1" formControlName="fired_at" required cdkFocusInitial /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
          <mat-hint>{{ 'people.terminate.dateHint' | transloco }}</mat-hint>
          <mat-error>{{ 'people.required' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.terminate.reason' | transloco }}</mat-label>
          <textarea matInput formControlName="reason" rows="3" maxlength="500"></textarea>
        </mat-form-field>
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" class="danger" [disabled]="saving()">{{ (scheduled() ? 'people.terminate.scheduledConfirm' : 'people.terminate.confirm') | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(26rem, 80vw); }
    .error { color: var(--app-bad-text); margin: 0; }
    .danger { background: var(--mat-sys-error); color: var(--mat-sys-on-error); }
  `,
})
export class TerminateDialog {
  protected readonly employee = inject<Employee>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<TerminateDialog, Employee>>(MatDialogRef);
  private readonly api = inject(PeopleService);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    fired_at: [today() as Date | null, Validators.required],
    reason: [''],
  });
  private readonly firedAt = toSignal(this.form.controls.fired_at.valueChanges, { initialValue: this.form.controls.fired_at.value });
  /** A date after today: the termination is scheduled, the person keeps working until then. */
  protected readonly scheduled = computed(() => isAfterToday(this.firedAt()));

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.api.terminate(this.employee.id, toIsoDate(v.fired_at), v.reason.trim() || null).subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }
}

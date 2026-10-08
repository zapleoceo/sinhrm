import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';
import { Employee } from '../people.model';
import { PeopleService } from '../people.service';
import { toIsoDate, today } from '../../../core/date/iso-date';
import { PersonPicker } from '../picker/person-picker';
import { DialogSave } from './dialog-save';

/** True for a calendar day after today (local time, like the datepicker). */
export function isAfterToday(date: Date | null): boolean {
  return date !== null && toIsoDate(date) > toIsoDate(today());
}

/**
 * Terminate an employee (HR or a manager above): last working day (default today) and an optional reason. Nothing is
 * deleted. Today or earlier applies at once; a later date schedules the termination (the button says so). Optional
 * "who takes over the work" (person picker of the caller; the colleague gets a task when the termination applies).
 */
@Component({
  selector: 'app-terminate-dialog',
  imports: [ReactiveFormsModule, PersonPicker, MatButtonModule, MatDialogModule, MatDatepickerModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
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
        <app-person-picker formControlName="handover_to_employee_id" label="people.terminate.handover" scope="employees" />
        <p class="muted hint">{{ 'people.terminate.handoverHint' | transloco }}</p>
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
    .hint { margin: 0.25rem 0 0.75rem; font-size: 0.875rem; }
    .danger { background: var(--mat-sys-error); color: var(--mat-sys-on-error); }
  `,
})
export class TerminateDialog {
  protected readonly employee = inject<Employee>(MAT_DIALOG_DATA);
  private readonly api = inject(PeopleService);
  private readonly dialog = new DialogSave<Employee>();
  protected readonly saving = this.dialog.saving;
  protected readonly error = this.dialog.error;
  protected readonly form = inject(NonNullableFormBuilder).group({
    fired_at: [today() as Date | null, Validators.required],
    reason: [''],
    handover_to_employee_id: [null as number | null],
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
    this.dialog.save(this.api.terminate(this.employee.id, toIsoDate(v.fired_at), v.reason.trim() || null, v.handover_to_employee_id));
  }
}

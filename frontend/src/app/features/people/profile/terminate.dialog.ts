import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';
import { Employee } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';

/** Terminate an employee (admin): last working day and an optional reason. Nothing is deleted. */
@Component({
  selector: 'app-terminate-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'people.terminate.title' | transloco: { name: employee.full_name } }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="muted">{{ 'people.terminate.hint' | transloco }}</p>
        <mat-form-field>
          <mat-label>{{ 'people.fields.firedAt' | transloco }}</mat-label>
          <input matInput type="date" formControlName="fired_at" required cdkFocusInitial />
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
        <button mat-flat-button type="submit" class="danger" [disabled]="saving()">{{ 'people.terminate.confirm' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(26rem, 80vw); }
    .error { color: var(--app-danger); margin: 0; }
    .danger { background: var(--app-danger); color: #fff; }
  `,
})
export class TerminateDialog {
  protected readonly employee = inject<Employee>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<TerminateDialog, Employee>>(MatDialogRef);
  private readonly api = inject(PeopleService);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    fired_at: [new Date().toISOString().slice(0, 10), Validators.required],
    reason: [''],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.api.terminate(this.employee.id, v.fired_at, v.reason.trim() || null).subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }
}

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';
import { ChangeRequest, Employee } from '../people.model';
import { PeopleService, diffChanges, peopleErrorKey } from '../people.service';

/**
 * Self-service: propose new personal contacts. Only changed fields are sent; an admin or a manager above approves,
 * and only then the profile changes.
 */
@Component({
  selector: 'app-change-request-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'people.changes.new' | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="muted">{{ 'people.changes.hint' | transloco }}</p>
        <mat-form-field>
          <mat-label>{{ 'people.fields.phone' | transloco }}</mat-label>
          <input matInput formControlName="phone" maxlength="32" cdkFocusInitial />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.fields.personalEmail' | transloco }}</mat-label>
          <input matInput type="email" formControlName="personal_email" maxlength="255" />
          <mat-error>{{ 'people.invalidEmail' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.fields.address' | transloco }}</mat-label>
          <input matInput formControlName="address" maxlength="1000" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.fields.emergencyContact' | transloco }}</mat-label>
          <input matInput formControlName="emergency_contact" maxlength="1000" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.changes.comment' | transloco }}</mat-label>
          <textarea matInput formControlName="comment" rows="2" maxlength="2000"></textarea>
        </mat-form-field>
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'people.changes.send' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(28rem, 80vw); }
    .error { color: var(--app-danger); margin: 0; }
  `,
})
export class ChangeRequestDialog {
  private readonly me = inject<Employee>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<ChangeRequestDialog, ChangeRequest>>(MatDialogRef);
  private readonly api = inject(PeopleService);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    phone: [this.me.phone ?? ''],
    personal_email: [this.me.personal_email ?? '', Validators.email],
    address: [this.me.address ?? ''],
    emergency_contact: [this.me.emergency_contact ?? ''],
    comment: [''],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const { comment, ...fields } = this.form.getRawValue();
    const changes = diffChanges(
      { phone: this.me.phone, personal_email: this.me.personal_email, address: this.me.address, emergency_contact: this.me.emergency_contact },
      fields,
    );
    if (Object.keys(changes).length === 0) {
      this.error.set('people.changes.nothing');
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.api.submitChange(changes, comment.trim() || null).subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }
}

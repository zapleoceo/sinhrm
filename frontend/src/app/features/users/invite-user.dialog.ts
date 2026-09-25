import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { INVITABLE_ROLES, UserRole } from '../../core/auth/auth.model';
import { AdminUser } from './users.model';
import { UsersService, userErrorKey } from './users.service';

/** Invite form; closes with the created user, stays open and shows the error otherwise. */
@Component({
  selector: 'app-invite-user-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'users.inviteDialog.title' | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="muted">{{ 'users.inviteDialog.hint' | transloco }}</p>
        <mat-form-field>
          <mat-label>{{ 'users.inviteDialog.email' | transloco }}</mat-label>
          <input matInput type="email" formControlName="email" autocomplete="off" required />
          @if (form.controls.email.hasError('email')) {
            <mat-error>{{ 'users.inviteDialog.invalidEmail' | transloco }}</mat-error>
          } @else {
            <mat-error>{{ 'users.inviteDialog.required' | transloco }}</mat-error>
          }
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'users.inviteDialog.name' | transloco }}</mat-label>
          <input matInput formControlName="name" autocomplete="off" required />
          <mat-error>{{ 'users.inviteDialog.required' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'users.inviteDialog.role' | transloco }}</mat-label>
          <mat-select formControlName="role">
            @for (role of roles; track role) {
              <mat-option [value]="role">{{ 'roles.' + role | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'users.inviteDialog.submit' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(24rem, 80vw); }
    .error { color: var(--app-danger); margin: 0; }
  `,
})
export class InviteUserDialog {
  private readonly users = inject(UsersService);
  private readonly ref = inject<MatDialogRef<InviteUserDialog, AdminUser>>(MatDialogRef);

  protected readonly roles = INVITABLE_ROLES;
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    name: ['', [Validators.required, Validators.minLength(2)]],
    role: ['recruiter' as UserRole, Validators.required],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.users.invite(this.form.getRawValue()).subscribe({
      next: (user) => this.ref.close(user),
      error: (e: unknown) => {
        this.error.set(userErrorKey(e));
        this.saving.set(false);
      },
    });
  }
}

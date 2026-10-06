import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { Employee, RestoreEmployee } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';
import { PersonPicker } from '../picker/person-picker';
import { toIsoDateOrNull } from '../../../core/date/iso-date';

type PlacementField = 'branch_id' | 'department_id' | 'position_id' | 'manager_id';
const PLACEMENT: readonly PlacementField[] = ['branch_id', 'department_id', 'position_id', 'manager_id'];

/**
 * Restore a terminated employee (HR): the previous position, department, branch and manager are preselected; only
 * changed fields are sent (an unchanged, since deactivated dictionary item must not fail validation). Optional new
 * hire date. The API unblocks the login only if the termination itself blocked it.
 */
@Component({
  selector: 'app-restore-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatDatepickerModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe, PersonPicker],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'people.restore.title' | transloco: { name: employee.full_name } }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <p class="muted">{{ 'people.restore.hint' | transloco }}</p>
        <div class="grid">
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.fields.position' | transloco }}</mat-label>
            <mat-select formControlName="position_id" cdkFocusInitial>
              <mat-option [value]="null">{{ 'people.restore.none' | transloco }}</mat-option>
              @for (p of positions(); track p.id) {
                <mat-option [value]="p.id">{{ p.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.fields.department' | transloco }}</mat-label>
            <mat-select formControlName="department_id">
              <mat-option [value]="null">{{ 'people.restore.none' | transloco }}</mat-option>
              @for (d of departments(); track d.id) {
                <mat-option [value]="d.id">{{ d.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
            <mat-select formControlName="branch_id">
              <mat-option [value]="null">{{ 'people.restore.none' | transloco }}</mat-option>
              @for (b of branches(); track b.id) {
                <mat-option [value]="b.id">{{ b.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <app-person-picker formControlName="manager_id" label="people.fields.manager" />
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.restore.hiredAt' | transloco }}</mat-label>
            <input matInput [matDatepicker]="dp" formControlName="hired_at" />
            <mat-datepicker-toggle matIconSuffix [for]="dp" /><mat-datepicker #dp />
            <mat-hint>{{ 'people.restore.hiredAtHint' | transloco }}</mat-hint>
          </mat-form-field>
        </div>
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'people.restore.confirm' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0.5rem 1rem; }
    .error { color: var(--app-bad-text); margin: 0; }
    @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
  `,
})
export class RestoreDialog implements OnInit {
  protected readonly employee = inject<Employee>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<RestoreDialog, Employee>>(MatDialogRef);
  private readonly api = inject(PeopleService);
  private readonly directory = inject(DirectoryService);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly departments = signal<DictionaryItem[]>([]);
  protected readonly positions = signal<DictionaryItem[]>([]);
  private readonly initial: Record<PlacementField, number | null> = {
    branch_id: this.employee.branch_id ?? this.employee.branch?.id ?? null,
    department_id: this.employee.department_id ?? this.employee.department?.id ?? null,
    position_id: this.employee.position_id ?? this.employee.position?.id ?? null,
    manager_id: this.employee.manager_id ?? this.employee.manager?.id ?? null,
  };
  protected readonly form = inject(NonNullableFormBuilder).group({
    branch_id: [this.initial.branch_id],
    department_id: [this.initial.department_id],
    position_id: [this.initial.position_id],
    manager_id: [this.initial.manager_id],
    hired_at: [null as Date | null],
  });

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l), error: () => undefined });
    this.directory.active('departments').subscribe({ next: (l) => this.departments.set(l), error: () => undefined });
    this.directory.active('positions').subscribe({ next: (l) => this.positions.set(l), error: () => undefined });
  }

  protected submit(): void {
    this.saving.set(true);
    this.error.set(null);
    this.api.restore(this.employee.id, this.body()).subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }

  private body(): RestoreEmployee {
    const v = this.form.getRawValue();
    const body: RestoreEmployee = {};
    for (const field of PLACEMENT) {
      if (v[field] !== this.initial[field]) {
        body[field] = v[field];
      }
    }
    const hiredAt = toIsoDateOrNull(v.hired_at);
    if (hiredAt !== null) {
      body.hired_at = hiredAt;
    }
    return body;
  }
}

import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { EMPLOYMENT_TYPES, Employee, EmploymentType, SaveEmployee } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';
import { fromIsoDate, toIsoDate, toIsoDateOrNull, today } from '../../../core/date/iso-date';

export interface EmployeeDialogData {
  employee: Employee | null;
}

type EditableStatus = 'active' | 'on_leave';

/** Create / edit an employee (admin). Dictionaries: active items; manager: any working employee except self. */
@Component({
  selector: 'app-employee-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatDatepickerModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ (data.employee ? 'people.edit.title' : 'people.directory.add') | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <div class="grid">
          <mat-form-field class="full">
            <mat-label>{{ 'people.fields.fullName' | transloco }}</mat-label>
            <input matInput formControlName="full_name" maxlength="255" required cdkFocusInitial />
            <mat-error>{{ 'people.required' | transloco }}</mat-error>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.hiredAt' | transloco }}</mat-label>
            <input matInput [matDatepicker]="dp1" formControlName="hired_at" required /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
            <mat-error>{{ 'people.required' | transloco }}</mat-error>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.employmentType' | transloco }}</mat-label>
            <mat-select formControlName="employment_type">
              @for (t of types; track t) {
                <mat-option [value]="t">{{ 'people.employmentType.' + t | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          @if (data.employee) {
            <mat-form-field>
              <mat-label>{{ 'people.fields.status' | transloco }}</mat-label>
              <mat-select formControlName="status">
                <mat-option value="active">{{ 'people.status.active' | transloco }}</mat-option>
                <mat-option value="on_leave">{{ 'people.status.on_leave' | transloco }}</mat-option>
              </mat-select>
            </mat-form-field>
          }
          <mat-form-field>
            <mat-label>{{ 'people.fields.workEmail' | transloco }}</mat-label>
            <input matInput type="email" formControlName="work_email" maxlength="255" />
            <mat-error>{{ 'people.invalidEmail' | transloco }}</mat-error>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.phone' | transloco }}</mat-label>
            <input matInput formControlName="phone" maxlength="32" />
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
            <mat-select formControlName="branch_id">
              <mat-option [value]="null">—</mat-option>
              @for (b of branches(); track b.id) {
                <mat-option [value]="b.id">{{ b.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.department' | transloco }}</mat-label>
            <mat-select formControlName="department_id">
              <mat-option [value]="null">—</mat-option>
              @for (d of departments(); track d.id) {
                <mat-option [value]="d.id">{{ d.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.position' | transloco }}</mat-label>
            <mat-select formControlName="position_id">
              <mat-option [value]="null">—</mat-option>
              @for (p of positions(); track p.id) {
                <mat-option [value]="p.id">{{ p.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.manager' | transloco }}</mat-label>
            <mat-select formControlName="manager_id">
              <mat-option [value]="null">—</mat-option>
              @for (m of managers(); track m.id) {
                <mat-option [value]="m.id">{{ m.full_name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <p class="full section">{{ 'people.tabs.personal' | transloco }}</p>
          <mat-form-field>
            <mat-label>{{ 'people.fields.birthDate' | transloco }}</mat-label>
            <input matInput [matDatepicker]="dp2" formControlName="birth_date" /><mat-datepicker-toggle matIconSuffix [for]="dp2" /><mat-datepicker #dp2 />
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'people.fields.personalEmail' | transloco }}</mat-label>
            <input matInput type="email" formControlName="personal_email" maxlength="255" />
            <mat-error>{{ 'people.invalidEmail' | transloco }}</mat-error>
          </mat-form-field>
          <mat-form-field class="full">
            <mat-label>{{ 'people.fields.address' | transloco }}</mat-label>
            <input matInput formControlName="address" maxlength="1000" />
          </mat-form-field>
          <mat-form-field class="full">
            <mat-label>{{ 'people.fields.emergencyContact' | transloco }}</mat-label>
            <input matInput formControlName="emergency_contact" maxlength="1000" />
          </mat-form-field>
        </div>
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'people.save' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; min-width: min(40rem, 80vw); }
    .full { grid-column: 1 / -1; }
    .section { margin: 0.5rem 0; font: var(--mat-sys-title-small); color: var(--app-muted); }
    .error { color: var(--app-danger); margin: 0; }
    @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
  `,
})
export class EmployeeDialog implements OnInit {
  protected readonly data = inject<EmployeeDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<EmployeeDialog, Employee>>(MatDialogRef);
  private readonly directory = inject(DirectoryService);
  private readonly api = inject(PeopleService);

  protected readonly types = EMPLOYMENT_TYPES;
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly departments = signal<DictionaryItem[]>([]);
  protected readonly positions = signal<DictionaryItem[]>([]);
  protected readonly managers = signal<Employee[]>([]);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly e = this.data.employee;
  protected readonly form = inject(NonNullableFormBuilder).group({
    full_name: [this.e?.full_name ?? '', [Validators.required, Validators.maxLength(255)]],
    hired_at: [this.e ? fromIsoDate(this.e.hired_at) : today(), Validators.required],
    employment_type: [this.e?.employment_type ?? ('full_time' as EmploymentType)],
    status: [(this.e?.status === 'on_leave' ? 'on_leave' : 'active') as EditableStatus],
    work_email: [this.e?.work_email ?? '', Validators.email],
    phone: [this.e?.phone ?? ''],
    branch_id: [this.e?.branch_id ?? this.e?.branch?.id ?? (null as number | null)],
    department_id: [this.e?.department_id ?? this.e?.department?.id ?? (null as number | null)],
    position_id: [this.e?.position_id ?? this.e?.position?.id ?? (null as number | null)],
    manager_id: [this.e?.manager_id ?? this.e?.manager?.id ?? (null as number | null)],
    birth_date: [fromIsoDate(this.e?.birth_date)],
    personal_email: [this.e?.personal_email ?? '', Validators.email],
    address: [this.e?.address ?? ''],
    emergency_contact: [this.e?.emergency_contact ?? ''],
  });

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l), error: () => undefined });
    this.directory.active('departments').subscribe({ next: (l) => this.departments.set(l), error: () => undefined });
    this.directory.active('positions').subscribe({ next: (l) => this.positions.set(l), error: () => undefined });
    this.api.list({ perPage: 200 }).subscribe({
      next: (page) => this.managers.set(page.data.filter((m) => m.id !== this.e?.id)),
      error: () => undefined,
    });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    const text = (s: string): string | null => (s.trim() === '' ? null : s.trim());
    const body: SaveEmployee = {
      full_name: v.full_name.trim(),
      hired_at: toIsoDate(v.hired_at),
      employment_type: v.employment_type,
      work_email: text(v.work_email),
      phone: text(v.phone),
      branch_id: v.branch_id,
      department_id: v.department_id,
      position_id: v.position_id,
      manager_id: v.manager_id,
      birth_date: toIsoDateOrNull(v.birth_date),
      personal_email: text(v.personal_email),
      address: text(v.address),
      emergency_contact: text(v.emergency_contact),
      ...(this.e ? { status: v.status } : {}),
    };
    this.saving.set(true);
    this.error.set(null);
    const call = this.e ? this.api.update(this.e.id, body) : this.api.create(body);
    call.subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }
}

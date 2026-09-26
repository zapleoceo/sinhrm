import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, numberAttribute, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { DirectoryService } from '../directory/directory.service';
import { DictionaryItem } from '../directory/directory.model';
import { Employee } from '../people/people.model';
import { PeopleService } from '../people/people.service';
import { FormField, HIRING_PRIORITIES, HiringPriority, HiringReason, SaveHiringRequest, missingFields } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';
import { fromIsoDate, toIsoDate, toIsoDateOrNull } from '../../core/date/iso-date';

type Extra = Record<string, string | number | boolean>;

/**
 * Create / edit-draft wizard (tz2): 1) position and place, 2) reason, dates, salary, requirements and the configurable
 * fields of the admin form, 3) review → "Save draft" or "Send for approval". /hiring-requests/new, /hiring-requests/:id/edit.
 */
@Component({
  selector: 'app-hiring-wizard-page',
  imports: [DecimalPipe, MatButtonModule, MatCheckboxModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, ReactiveFormsModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <a mat-button routerLink="/hiring-requests"><mat-icon>arrow_back</mat-icon>{{ 'hiring.list.title' | transloco }}</a>
        <h1>{{ (id() ? 'hiring.wizard.editTitle' : 'hiring.wizard.title') | transloco }}</h1>
      </div>
    </header>
    <ol class="steps" [attr.aria-label]="'hiring.wizard.steps' | transloco">
      @for (s of [0, 1, 2]; track s) {
        <li [class.active]="step() === s" [class.done]="step() > s">{{ 'hiring.wizard.step' + s | transloco }}</li>
      }
    </ol>
    <form class="panel body" [formGroup]="form" (submit)="$event.preventDefault()">
      @switch (step()) {
        @case (0) {
          <mat-form-field class="wide">
            <mat-label>{{ 'hiring.fields.title' | transloco }}</mat-label>
            <input matInput formControlName="title" maxlength="255" required />
          </mat-form-field>
          <div class="grid">
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.branch' | transloco }}</mat-label>
              <mat-select formControlName="branch_id" required>
                @for (b of branches(); track b.id) {
                  <mat-option [value]="b.id">{{ b.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.department' | transloco }}</mat-label>
              <mat-select formControlName="department_id">
                <mat-option [value]="null">—</mat-option>
                @for (d of departments(); track d.id) {
                  <mat-option [value]="d.id">{{ d.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.position' | transloco }}</mat-label>
              <mat-select formControlName="position_id">
                <mat-option [value]="null">—</mat-option>
                @for (p of positions(); track p.id) {
                  <mat-option [value]="p.id">{{ p.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.headcount' | transloco }}</mat-label>
              <input matInput type="number" min="1" max="500" formControlName="headcount" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.priority' | transloco }}</mat-label>
              <mat-select formControlName="priority">
                @for (p of priorities; track p) {
                  <mat-option [value]="p">{{ 'hiring.priority.' + p | transloco }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          </div>
        }
        @case (1) {
          <div class="grid">
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.reason' | transloco }}</mat-label>
              <mat-select formControlName="reason">
                <mat-option value="new_position">{{ 'hiring.reason.new_position' | transloco }}</mat-option>
                <mat-option value="replacement">{{ 'hiring.reason.replacement' | transloco }}</mat-option>
              </mat-select>
            </mat-form-field>
            @if (form.controls.reason.value === 'replacement') {
              <mat-form-field>
                <mat-label>{{ 'hiring.fields.searchEmployee' | transloco }}</mat-label>
                <input matInput #q (keyup.enter)="searchPeople(q.value)" (blur)="searchPeople(q.value)" />
              </mat-form-field>
              <mat-form-field>
                <mat-label>{{ 'hiring.fields.replaced' | transloco }}</mat-label>
                <mat-select formControlName="replaced_employee_id">
                  @for (e of people(); track e.id) {
                    <mat-option [value]="e.id">{{ e.full_name }}</mat-option>
                  }
                </mat-select>
              </mat-form-field>
            }
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.startDate' | transloco }}</mat-label>
              <input matInput [matDatepicker]="dp1" formControlName="desired_start_date" /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.salaryMin' | transloco }}</mat-label>
              <input matInput type="number" min="0" formControlName="salary_min" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.salaryMax' | transloco }}</mat-label>
              <input matInput type="number" min="0" formControlName="salary_max" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'hiring.fields.currency' | transloco }}</mat-label>
              <input matInput formControlName="currency" maxlength="3" placeholder="UAH" />
            </mat-form-field>
          </div>
          <mat-form-field class="wide">
            <mat-label>{{ 'hiring.fields.requirements' | transloco }}</mat-label>
            <textarea matInput rows="5" formControlName="requirements" maxlength="10000"></textarea>
          </mat-form-field>
          @for (f of fields(); track f.key) {
            @switch (f.type) {
              @case ('checkbox') {
                <mat-checkbox [checked]="extra()[f.key] === true" (change)="setExtra(f.key, $event.checked)">{{ f.label }}{{ f.required ? ' *' : '' }}</mat-checkbox>
              }
              @case ('select') {
                <mat-form-field class="wide">
                  <mat-label>{{ f.label }}</mat-label>
                  <mat-select [value]="extra()[f.key]" (valueChange)="setExtra(f.key, $event)" [required]="f.required">
                    @for (o of f.options ?? []; track o) {
                      <mat-option [value]="o">{{ o }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
              }
              @case ('date') {
                <mat-form-field class="wide">
                  <mat-label>{{ f.label }}</mat-label>
                  <input matInput [matDatepicker]="fieldDate" [value]="dateOf(f.key)" (dateChange)="setDate(f, $event.value)" [required]="f.required" />
                  <mat-datepicker-toggle matIconSuffix [for]="fieldDate" />
                  <mat-datepicker #fieldDate />
                </mat-form-field>
              }
              @default {
                <mat-form-field class="wide">
                  <mat-label>{{ f.label }}</mat-label>
                  @if (f.type === 'textarea') {
                    <textarea matInput rows="3" [value]="text(f.key)" (input)="setText(f, $event)" [required]="f.required"></textarea>
                  } @else {
                    <input matInput [type]="f.type === 'number' ? 'number' : 'text'" [value]="text(f.key)" (input)="setText(f, $event)" [required]="f.required" />
                  }
                </mat-form-field>
              }
            }
          }
        }
        @case (2) {
          <dl class="review">
            <dt>{{ 'hiring.fields.title' | transloco }}</dt><dd>{{ form.controls.title.value }} × {{ form.controls.headcount.value }}</dd>
            <dt>{{ 'hiring.fields.branch' | transloco }}</dt><dd>{{ nameOf(branches(), form.controls.branch_id.value) }}</dd>
            <dt>{{ 'hiring.fields.reason' | transloco }}</dt><dd>{{ 'hiring.reason.' + form.controls.reason.value | transloco }}</dd>
            <dt>{{ 'hiring.fields.priority' | transloco }}</dt><dd>{{ 'hiring.priority.' + form.controls.priority.value | transloco }}</dd>
            @if (form.controls.salary_min.value !== null || form.controls.salary_max.value !== null) {
              <dt>{{ 'hiring.fields.salary' | transloco }}</dt>
              <dd>{{ form.controls.salary_min.value ?? '…' | number }} – {{ form.controls.salary_max.value ?? '…' | number }} {{ form.controls.currency.value }}</dd>
            }
            @for (f of fields(); track f.key) {
              <dt>{{ f.label }}</dt><dd>{{ extra()[f.key] ?? '—' }}</dd>
            }
          </dl>
          @if (missing().length > 0) {
            <p class="warn" role="alert">{{ 'hiring.wizard.missing' | transloco: { fields: missing().join(', ') } }}</p>
          }
        }
      }
      @if (error(); as e) {
        <p class="warn" role="alert">{{ e | transloco }}</p>
      }
      <div class="actions">
        @if (step() > 0) {
          <button mat-button type="button" (click)="step.set(step() - 1)"><mat-icon>arrow_back</mat-icon>{{ 'hiring.wizard.back' | transloco }}</button>
        }
        <span class="spacer"></span>
        @if (step() < 2) {
          <button mat-flat-button type="button" [disabled]="step() === 0 && !stepOneValid()" (click)="step.set(step() + 1)">
            {{ 'hiring.wizard.next' | transloco }}<mat-icon iconPositionEnd>arrow_forward</mat-icon>
          </button>
        } @else {
          <button mat-stroked-button type="button" [disabled]="saving()" (click)="save(false)">{{ 'hiring.wizard.saveDraft' | transloco }}</button>
          <button mat-flat-button type="button" [disabled]="saving() || missing().length > 0" (click)="save(true)">
            <mat-icon>send</mat-icon>{{ 'hiring.wizard.submit' | transloco }}
          </button>
        }
      </div>
    </form>
  `,
  styles: `
    .steps { display: flex; gap: 1rem; list-style: none; padding: 0; margin: 0 0 0.75rem; counter-reset: s; }
    .steps li { color: var(--app-muted); }
    .steps li::before { counter-increment: s; content: counter(s) '. '; }
    .steps li.active { color: inherit; font-weight: 600; }
    .steps li.done { color: var(--mat-sys-primary); }
    .body { padding: 1rem; display: flex; flex-direction: column; gap: 0.25rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0 1rem; }
    .wide { width: 100%; }
    .review { display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1rem; }
    .review dt { color: var(--app-muted); }
    .review dd { margin: 0; }
    .actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
    .spacer { flex: 1; }
    .warn { color: var(--app-danger); }
  `,
})
export class HiringWizardPage implements OnInit {
  /** Route param of /hiring-requests/:id/edit (a draft). */
  readonly id = input(undefined, { transform: (v: unknown) => (v === undefined ? undefined : numberAttribute(v)) });
  private readonly api = inject(HiringRequestsService);
  private readonly directory = inject(DirectoryService);
  private readonly peopleApi = inject(PeopleService);
  private readonly router = inject(Router);
  protected readonly priorities = HIRING_PRIORITIES;
  protected readonly step = signal(0);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly departments = signal<DictionaryItem[]>([]);
  protected readonly positions = signal<DictionaryItem[]>([]);
  protected readonly people = signal<Employee[]>([]);
  protected readonly fields = signal<FormField[]>([]);
  protected readonly extra = signal<Extra>({});
  protected readonly missing = computed(() => missingFields(this.fields(), this.extra()));
  protected readonly form = inject(NonNullableFormBuilder).group({
    title: ['', [Validators.required, Validators.maxLength(255)]],
    branch_id: [null as number | null, Validators.required],
    department_id: [null as number | null],
    position_id: [null as number | null],
    headcount: [1, [Validators.min(1), Validators.max(500)]],
    priority: ['normal' as HiringPriority],
    reason: ['new_position' as HiringReason],
    replaced_employee_id: [null as number | null],
    desired_start_date: [null as Date | null],
    salary_min: [null as number | null],
    salary_max: [null as number | null],
    currency: [''],
    requirements: [''],
  });

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l) });
    this.directory.active('departments').subscribe({ next: (l) => this.departments.set(l) });
    this.directory.active('positions').subscribe({ next: (l) => this.positions.set(l) });
    this.api.meta().subscribe({ next: (m) => this.fields.set(m.form_fields) });
    const id = this.id();
    if (id !== undefined) {
      this.api.get(id).subscribe({
        next: (r) => {
          this.form.patchValue({
            title: r.title,
            branch_id: r.branch.id,
            department_id: r.department?.id ?? null,
            position_id: r.position?.id ?? null,
            headcount: r.headcount,
            priority: r.priority,
            reason: r.reason,
            replaced_employee_id: r.replaced_employee?.id ?? null,
            desired_start_date: fromIsoDate(r.desired_start_date),
            salary_min: r.salary_min,
            salary_max: r.salary_max,
            currency: r.currency ?? '',
            requirements: r.requirements ?? '',
          });
          if (r.replaced_employee) {
            this.people.set([{ id: r.replaced_employee.id, full_name: r.replaced_employee.full_name } as Employee]);
          }
          this.extra.set({ ...r.extra });
        },
        error: (e: unknown) => this.error.set(hiringErrorKey(e)),
      });
    }
  }

  protected stepOneValid(): boolean {
    const c = this.form.controls;
    return c.title.valid && c.branch_id.valid && c.headcount.valid;
  }

  protected searchPeople(q: string): void {
    if (q.trim().length < 2) {
      return;
    }
    this.peopleApi.list({ q: q.trim(), perPage: 20 }).subscribe({ next: (page) => this.people.set(page.data) });
  }

  protected setExtra(key: string, value: string | number | boolean): void {
    this.extra.update((e) => ({ ...e, [key]: value }));
  }

  protected setText(f: FormField, event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLTextAreaElement).value;
    if (value === '') {
      this.extra.update((e) => Object.fromEntries(Object.entries(e).filter(([k]) => k !== f.key)));
      return;
    }
    this.setExtra(f.key, f.type === 'number' ? Number(value) : value);
  }

  protected setDate(f: FormField, date: Date | null): void {
    const iso = toIsoDate(date);
    if (iso === '') {
      this.extra.update((e) => Object.fromEntries(Object.entries(e).filter(([k]) => k !== f.key)));
      return;
    }
    this.setExtra(f.key, iso);
  }

  protected dateOf(key: string): Date | null {
    return fromIsoDate(this.text(key));
  }

  protected text(key: string): string {
    const v = this.extra()[key];
    return v === undefined ? '' : String(v);
  }

  protected nameOf(list: DictionaryItem[], id: number | null): string {
    return list.find((i) => i.id === id)?.name ?? '—';
  }

  protected save(submit: boolean): void {
    const v = this.form.getRawValue();
    if (v.branch_id === null) {
      return;
    }
    const body: SaveHiringRequest = {
      title: v.title.trim(),
      branch_id: v.branch_id,
      department_id: v.department_id,
      position_id: v.position_id,
      headcount: v.headcount,
      priority: v.priority,
      reason: v.reason,
      replaced_employee_id: v.reason === 'replacement' ? v.replaced_employee_id : null,
      desired_start_date: toIsoDateOrNull(v.desired_start_date),
      salary_min: v.salary_min,
      salary_max: v.salary_max,
      currency: v.currency.trim() || null,
      requirements: v.requirements.trim() || null,
      extra: this.extra(),
    };
    this.saving.set(true);
    this.error.set(null);
    const id = this.id();
    const call = id === undefined ? this.api.create({ ...body, submit }) : this.api.update(id, body);
    call.subscribe({
      next: (saved) => {
        if (id !== undefined && submit) {
          this.api.submit(saved.id).subscribe({
            next: () => void this.router.navigate(['/hiring-requests', saved.id]),
            error: (e: unknown) => this.fail(e),
          });
          return;
        }
        void this.router.navigate(['/hiring-requests', saved.id]);
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  private fail(e: unknown): void {
    this.saving.set(false);
    this.error.set(hiringErrorKey(e));
  }
}

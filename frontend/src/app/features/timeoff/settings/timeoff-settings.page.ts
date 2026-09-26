import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { ACCRUAL_MODES, AccrualMode, Holiday, LeavePolicy, LeaveType } from '../timeoff.model';
import { TimeOffService, timeoffErrorKey } from '../timeoff.service';

/**
 * Leave settings (superadmin, admin): leave types, policies (company default + per branch, Sintegrum-style
 * "vacation days per branch"), public holidays of a year. Small inline forms; changes apply immediately.
 */
@Component({
  selector: 'app-timeoff-settings-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
    MatTabsModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'timeoff.settings.title' | transloco }}</h1>
        <p class="muted">{{ 'timeoff.settings.subtitle' | transloco }}</p>
      </div>
    </header>

    <mat-tab-group mat-stretch-tabs="false" animationDuration="0ms">
      <mat-tab [label]="'timeoff.settings.types' | transloco">
        <ul class="rows">
          @for (t of types(); track t.id) {
            <li>
              <span class="dot" [style.background]="t.color"></span>
              <strong>{{ t.name }}</strong>
              <code>{{ t.code }}</code>
              <span class="muted">
                {{ (t.paid ? 'timeoff.settings.paid' : 'timeoff.settings.unpaid') | transloco }} ·
                {{ (t.tracks_balance ? 'timeoff.settings.tracked' : 'timeoff.settings.unlimited') | transloco }} ·
                {{ (t.requires_approval ? 'timeoff.settings.withApproval' : 'timeoff.settings.autoApproved') | transloco }}
              </span>
              <span class="spacer"></span>
              <mat-slide-toggle [checked]="t.active" (change)="saveType(t, { active: $event.checked })">{{ 'timeoff.settings.active' | transloco }}</mat-slide-toggle>
            </li>
          }
        </ul>
        <form class="inline" [formGroup]="typeForm" (ngSubmit)="addType()">
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'timeoff.settings.name' | transloco }}</mat-label><input matInput formControlName="name" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'timeoff.settings.code' | transloco }}</mat-label><input matInput formControlName="code" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="narrow"><mat-label>{{ 'timeoff.settings.color' | transloco }}</mat-label><input matInput type="color" formControlName="color" /></mat-form-field>
          <mat-checkbox formControlName="paid">{{ 'timeoff.settings.paid' | transloco }}</mat-checkbox>
          <mat-checkbox formControlName="tracks_balance">{{ 'timeoff.settings.tracked' | transloco }}</mat-checkbox>
          <mat-checkbox formControlName="requires_approval">{{ 'timeoff.settings.withApproval' | transloco }}</mat-checkbox>
          <button mat-flat-button type="submit"><mat-icon>add</mat-icon>{{ 'timeoff.settings.add' | transloco }}</button>
        </form>
      </mat-tab>

      <mat-tab [label]="'timeoff.settings.policies' | transloco">
        <ul class="rows">
          @for (p of policies(); track p.id) {
            <li>
              <strong>{{ p.leave_type.name }}</strong>
              <span>{{ p.branch?.name ?? ('timeoff.settings.defaultPolicy' | transloco) }}</span>
              <span class="muted">
                {{ 'timeoff.settings.perYear' | transloco: { n: p.annual_days } }} ·
                {{ 'timeoff.accrual.' + p.accrual_mode | transloco }} ·
                {{ p.carry_over_max === null ? ('timeoff.settings.carryAll' | transloco) : ('timeoff.settings.carryMax' | transloco: { n: p.carry_over_max }) }}
              </span>
              <span class="spacer"></span>
              <mat-slide-toggle [checked]="p.active" (change)="savePolicy(p, { active: $event.checked })">{{ 'timeoff.settings.active' | transloco }}</mat-slide-toggle>
            </li>
          }
        </ul>
        <form class="inline" [formGroup]="policyForm" (ngSubmit)="addPolicy()">
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'timeoff.fields.type' | transloco }}</mat-label>
            <mat-select formControlName="leave_type_id">
              @for (t of trackedTypes(); track t.id) {
                <mat-option [value]="t.id">{{ t.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
            <mat-select formControlName="branch_id">
              <mat-option [value]="null">{{ 'timeoff.settings.defaultPolicy' | transloco }}</mat-option>
              @for (b of branches(); track b.id) {
                <mat-option [value]="b.id">{{ b.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="narrow"><mat-label>{{ 'timeoff.settings.annualDays' | transloco }}</mat-label><input matInput type="number" min="0" max="366" formControlName="annual_days" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'timeoff.settings.accrual' | transloco }}</mat-label>
            <mat-select formControlName="accrual_mode">
              @for (m of modes; track m) {
                <mat-option [value]="m">{{ 'timeoff.accrual.' + m | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="narrow"><mat-label>{{ 'timeoff.settings.carryOver' | transloco }}</mat-label><input matInput type="number" min="0" formControlName="carry_over_max" /></mat-form-field>
          <button mat-flat-button type="submit"><mat-icon>add</mat-icon>{{ 'timeoff.settings.add' | transloco }}</button>
        </form>
      </mat-tab>

      <mat-tab [label]="'timeoff.settings.holidays' | transloco">
        <div class="filters">
          <button mat-icon-button type="button" (click)="setYear(year() - 1)" [attr.aria-label]="'timeoff.calendar.prev' | transloco"><mat-icon>chevron_left</mat-icon></button>
          <strong>{{ year() }}</strong>
          <button mat-icon-button type="button" (click)="setYear(year() + 1)" [attr.aria-label]="'timeoff.calendar.next' | transloco"><mat-icon>chevron_right</mat-icon></button>
        </div>
        <ul class="rows">
          @for (h of holidays(); track h.id) {
            <li>
              <code>{{ h.date }}</code>
              <strong>{{ h.name }}</strong>
              <span class="muted">{{ h.branch?.name ?? ('timeoff.settings.allBranches' | transloco) }}</span>
              <span class="spacer"></span>
              <button mat-icon-button type="button" (click)="deleteHoliday(h)" [attr.aria-label]="'timeoff.settings.delete' | transloco"><mat-icon>delete</mat-icon></button>
            </li>
          } @empty {
            <li class="muted">{{ 'timeoff.settings.noHolidays' | transloco }}</li>
          }
        </ul>
        <form class="inline" [formGroup]="holidayForm" (ngSubmit)="addHoliday()">
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'timeoff.settings.date' | transloco }}</mat-label><input matInput type="date" formControlName="date" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'timeoff.settings.name' | transloco }}</mat-label><input matInput formControlName="name" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
            <mat-select formControlName="branch_id">
              <mat-option [value]="null">{{ 'timeoff.settings.allBranches' | transloco }}</mat-option>
              @for (b of branches(); track b.id) {
                <mat-option [value]="b.id">{{ b.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <button mat-flat-button type="submit"><mat-icon>add</mat-icon>{{ 'timeoff.settings.add' | transloco }}</button>
        </form>
      </mat-tab>
    </mat-tab-group>
  `,
  styles: `
    .rows { list-style: none; margin: 0; padding: 0.5rem 0; }
    .rows li { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .dot { width: 0.9rem; height: 0.9rem; border-radius: 50%; }
    .spacer { flex: 1; }
    .inline { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; padding: 1rem 0; }
    .narrow { width: 8rem; }
  `,
})
export class TimeOffSettingsPage implements OnInit {
  private readonly api = inject(TimeOffService);
  private readonly directory = inject(DirectoryService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly modes = ACCRUAL_MODES;
  protected readonly types = signal<LeaveType[]>([]);
  protected readonly trackedTypes = signal<LeaveType[]>([]);
  protected readonly policies = signal<LeavePolicy[]>([]);
  protected readonly holidays = signal<Holiday[]>([]);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly year = signal(new Date().getFullYear());

  protected readonly typeForm = this.fb.group({
    name: ['', Validators.required],
    code: ['', [Validators.required, Validators.pattern(/^[a-z0-9_]+$/)]],
    color: ['#4f7cff'],
    paid: [true],
    tracks_balance: [true],
    requires_approval: [true],
  });
  protected readonly policyForm = this.fb.group({
    leave_type_id: [null as number | null, Validators.required],
    branch_id: [null as number | null],
    annual_days: [24, [Validators.required, Validators.min(0), Validators.max(366)]],
    accrual_mode: ['yearly_upfront' as AccrualMode],
    carry_over_max: [null as number | null],
  });
  protected readonly holidayForm = this.fb.group({
    date: ['', Validators.required],
    name: ['', Validators.required],
    branch_id: [null as number | null],
  });

  ngOnInit(): void {
    this.loadTypes();
    this.api.policies().subscribe({ next: (l) => this.policies.set(l), error: () => undefined });
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l), error: () => undefined });
    this.loadHolidays();
  }

  protected saveType(type: LeaveType, patch: Partial<LeaveType>): void {
    this.run(this.api.saveType(type.id, patch), () => this.loadTypes());
  }

  protected addType(): void {
    if (this.typeForm.invalid) {
      this.typeForm.markAllAsTouched();
      return;
    }
    this.run(this.api.saveType(null, this.typeForm.getRawValue()), () => {
      this.typeForm.reset();
      this.loadTypes();
    });
  }

  protected savePolicy(policy: LeavePolicy, patch: Partial<LeavePolicy>): void {
    this.run(this.api.savePolicy(policy.id, patch), (saved) => this.policies.update((l) => l.map((p) => (p.id === saved.id ? saved : p))));
  }

  protected addPolicy(): void {
    if (this.policyForm.invalid) {
      this.policyForm.markAllAsTouched();
      return;
    }
    const v = this.policyForm.getRawValue();
    this.run(this.api.savePolicy(null, { ...v, leave_type_id: v.leave_type_id ?? undefined }), (saved) => this.policies.update((l) => [...l, saved]));
  }

  protected setYear(year: number): void {
    this.year.set(year);
    this.loadHolidays();
  }

  protected addHoliday(): void {
    if (this.holidayForm.invalid) {
      this.holidayForm.markAllAsTouched();
      return;
    }
    this.run(this.api.saveHoliday(null, this.holidayForm.getRawValue()), () => {
      this.holidayForm.reset();
      this.loadHolidays();
    });
  }

  protected deleteHoliday(holiday: Holiday): void {
    this.run(this.api.deleteHoliday(holiday.id), () => this.holidays.update((l) => l.filter((h) => h.id !== holiday.id)));
  }

  private loadTypes(): void {
    this.api.types(true).subscribe({
      next: (types) => {
        this.types.set(types);
        this.trackedTypes.set(types.filter((t) => t.tracks_balance));
      },
      error: () => undefined,
    });
  }

  private loadHolidays(): void {
    this.api.holidays(this.year()).subscribe({ next: (l) => this.holidays.set(l), error: () => undefined });
  }

  private run<T>(call: Observable<T>, done: (value: T) => void): void {
    call.subscribe({
      next: done,
      error: (e: unknown) => this.snack.open(this.i18n.translate(timeoffErrorKey(e)), undefined, { duration: 4000 }),
    });
  }
}

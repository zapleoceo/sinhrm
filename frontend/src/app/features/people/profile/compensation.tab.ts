import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { ClientTable, DATE_RANGE, NUMBER_RANGE, TEXT_FILTER, translatedSelect } from '../../../core/ui/table/client-table';
import { ColumnHeader } from '../../../core/ui/table/column-header';
import { TableSortDirective } from '../../../core/ui/table/table-sort.directive';
import { ColumnFilter } from '../../../core/ui/table/table-state';
import { TableUrlState } from '../../../core/ui/table/table-url-state';
import { CURRENCIES, Compensation, CompensationRecord, PAY_PERIODS } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';

/**
 * Compensation history. HR staff (canManage) see records of anyone; adding one (canAdd) is a decision — never on one's
 * own record (API: canDecideOrBreakGlass, 403 otherwise), so the form follows access.decide. The employee sees own
 * read-only (self → /api/me/employee/compensation). Current = latest effective date not in the future.
 * A refused or invalid save is shown above the form (role=alert), never swallowed.
 * The history table sorts and filters in its headers (core/ui/table; URL `comp_sort`, `comp_<column>`).
 */
@Component({
  selector: 'app-compensation-tab',
  imports: [DatePipe, DecimalPipe, ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (data(); as d) {
      <p>
        <strong>{{ 'people.compensation.current' | transloco }}:</strong>
        @if (d.current; as c) {
          {{ +c.amount | number: '1.0-2' }} {{ c.currency }} / {{ 'people.compensation.period.' + c.period | transloco }}
        } @else {
          —
        }
      </p>
      <div class="scroll">
        <table class="app-table history" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
          <thead>
            <tr>
              <th scope="col" app-column-header key="effective_on" [label]="'people.compensation.effectiveOn' | transloco"
                [filter]="dateRange" [filterValue]="table.filterValue('effective_on')" (filterChange)="table.setFilter('effective_on', $event)"></th>
              <th scope="col" class="num" app-column-header key="amount" [label]="'people.compensation.amount' | transloco"
                [filter]="numberRange" [filterValue]="table.filterValue('amount')" (filterChange)="table.setFilter('amount', $event)"></th>
              <th scope="col" app-column-header key="currency" [label]="'people.compensation.currency' | transloco"
                [filter]="currencyFilter()" [filterValue]="table.filterValue('currency')" (filterChange)="table.setFilter('currency', $event)"></th>
              <th scope="col" app-column-header key="period" [label]="'people.compensation.periodLabel' | transloco"
                [filter]="periodFilter()" [filterValue]="table.filterValue('period')" (filterChange)="table.setFilter('period', $event)"></th>
              <th scope="col" app-column-header key="reason" [label]="'people.compensation.reason' | transloco"
                [filter]="textFilter" [filterValue]="table.filterValue('reason')" (filterChange)="table.setFilter('reason', $event)"></th>
            </tr>
          </thead>
          <tbody>
            @for (r of table.rows(); track r.id) {
              <tr [class.current]="r.current">
                <td class="app-num">{{ r.effective_on | date: 'dd.MM.yyyy' }}</td>
                <td class="num app-num">{{ +r.amount | number: '1.0-2' }}</td>
                <td>{{ r.currency }}</td>
                <td>{{ 'people.compensation.period.' + r.period | transloco }}</td>
                <td class="muted">{{ r.reason }}</td>
              </tr>
            } @empty {
              <tr><td colspan="5" class="muted">{{ (history().length ? 'table.noMatches' : 'people.compensation.empty') | transloco }}</td></tr>
            }
          </tbody>
        </table>
      </div>
    }
    @if (canAdd()) {
      @if (error(); as key) {
        <p class="error" role="alert">{{ key | transloco }}</p>
      }
      <form class="add" [formGroup]="form" (ngSubmit)="add()">
        <mat-form-field>
          <mat-label>{{ 'people.compensation.amount' | transloco }}</mat-label>
          <input matInput type="number" min="0" formControlName="amount" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.currency' | transloco }}</mat-label>
          <mat-select formControlName="currency">
            @for (c of currencies; track c) {
              <mat-option [value]="c">{{ c }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.periodLabel' | transloco }}</mat-label>
          <mat-select formControlName="period">
            @for (p of periods; track p) {
              <mat-option [value]="p">{{ 'people.compensation.period.' + p | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.effectiveOn' | transloco }}</mat-label>
          <input matInput type="date" formControlName="effective_on" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.reason' | transloco }}</mat-label>
          <input matInput formControlName="reason" maxlength="500" />
        </mat-form-field>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">{{ 'people.compensation.add' | transloco }}</button>
      </form>
    }
  `,
  styles: `
    .scroll { overflow-x: auto; margin-bottom: 1rem; }
    .num { text-align: right; }
    .history tr.current { font-weight: 700; }
    .error { color: var(--app-bad-text); margin: 0 0 0.5rem; }
    .add { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; }
  `,
})
export class CompensationTab implements OnInit {
  readonly employeeId = input.required<number>();
  readonly canManage = input(false);
  /** May add a record: HR staff deciding about someone else (access.manage && access.decide). */
  readonly canAdd = input(false);
  readonly self = input(false);

  private readonly api = inject(PeopleService);
  protected readonly data = signal<Compensation | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly currencies = CURRENCIES;
  protected readonly periods = PAY_PERIODS;
  protected readonly history = computed(() => this.data()?.history ?? []);
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  protected readonly dateRange = DATE_RANGE;
  protected readonly currencyFilter = computed<ColumnFilter>(() => ({ type: 'select', options: CURRENCIES.map((c) => ({ value: c, label: c })) }));
  protected readonly periodFilter = translatedSelect(() => PAY_PERIODS, (p) => 'people.compensation.period.' + p);
  /** API order: newest effective date first. */
  protected readonly table = new ClientTable<CompensationRecord>({
    rows: this.history,
    prefix: 'comp',
    defaultSort: { key: 'effective_on', dir: 'desc' },
    columns: [
      { key: 'effective_on', value: (r) => r.effective_on, filter: 'date' },
      { key: 'amount', value: (r) => Number(r.amount), filter: 'number' },
      { key: 'currency', value: (r) => r.currency, filter: 'select' },
      { key: 'period', value: (r) => PAY_PERIODS.indexOf(r.period), filter: 'select', filterValue: (r) => r.period },
      { key: 'reason', value: (r) => r.reason, filter: 'text' },
    ],
  });
  protected readonly form = inject(NonNullableFormBuilder).group({
    amount: [0, [Validators.required, Validators.min(0.01)]],
    currency: ['UAH', Validators.required],
    period: ['month', Validators.required],
    effective_on: ['', Validators.required],
    reason: [''],
  });

  ngOnInit(): void {
    const load: Observable<Compensation> = this.canManage() ? this.api.compensation(this.employeeId()) : this.api.myCompensation();
    load.subscribe({ next: (d) => this.data.set(d), error: () => undefined });
  }

  protected add(): void {
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.error.set(null);
    this.api.addCompensation(this.employeeId(), { ...v, reason: v.reason.trim() || null }).subscribe({
      next: (d) => {
        this.data.set(d);
        this.saving.set(false);
        this.form.reset();
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.error.set(peopleErrorKey(e));
      },
    });
  }
}

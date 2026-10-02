import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TIMESHEET_STATUSES, TIMESHEET_STATUS_TONE, TeamRow, addWeeks, mondayOf } from './time.model';
import { TimeService, timeErrorKey } from './time.service';
import { toIsoDate } from '../../core/date/iso-date';
import { WeekPicker } from './week-picker';
import { ClientColumn, ClientTable, NUMBER_RANGE, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../core/ui/table/table-url-state';

const NUMBER_KEYS = ['expected', 'worked', 'overtime', 'missing', 'absence'] as const;

/** Columns of the team week (all rows are on the page); hour columns sort and filter by range. */
export const TEAM_COLUMNS: readonly ClientColumn<TeamRow>[] = [
  { key: 'employee', value: (r) => r.employee.full_name, filter: 'text' },
  { key: 'status', value: (r) => TIMESHEET_STATUSES.indexOf(r.status), filter: 'select', filterValue: (r) => r.status },
  ...NUMBER_KEYS.map((k): ClientColumn<TeamRow> => ({ key: k, value: (r) => r[k], filter: 'number' })),
];

/**
 * Team overview (/time/team?week=): every visible employee's week — status, expected, worked, overtime, missing;
 * sortable / filterable column headers (state in the URL, kept when the week changes).
 */
@Component({
  selector: 'app-time-team-page',
  imports: [DatePipe, DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, WeekPicker, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'time.team.title' | transloco }}</h1>
        <p class="muted">{{ 'time.team.subtitle' | transloco: { date: (weekStart() | date: 'dd.MM.yyyy') } }} · {{ 'time.team.missingCount' | transloco: { n: missingCount() } }}</p>
      </div>
      <div class="row">
        <button mat-icon-button type="button" (click)="go(-1)" [attr.aria-label]="'time.week.prev' | transloco"><mat-icon>chevron_left</mat-icon></button>
        <button mat-icon-button type="button" (click)="go(1)" [attr.aria-label]="'time.week.next' | transloco"><mat-icon>chevron_right</mat-icon></button>
        <app-week-picker [weekStart]="weekStart()" (weekChange)="goTo($event)" />
      </div>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table class="app-table" [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="employee" [label]="'time.approvals.employee' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('employee')" (filterChange)="table.setFilter('employee', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'time.team.status' | transloco"
              [filter]="statusFilter()" [filterValue]="table.filterValue('status')" (filterChange)="table.setFilter('status', $event)"></th>
            @for (k of numberKeys; track k) {
              <th scope="col" class="num" app-column-header [key]="k" [label]="'time.week.' + k | transloco"
                [filter]="numberFilter" [filterValue]="table.filterValue(k)" (filterChange)="table.setFilter(k, $event)"></th>
            }
          </tr>
        </thead>
        <tbody>
          @for (r of table.rows(); track r.employee.id) {
            <tr>
              <td><a routerLink="/time" [queryParams]="{ week: weekStart(), employee_id: r.employee.id }">{{ r.employee.full_name }}</a></td>
              <td><span class="app-pill" [attr.data-tone]="statusTone[r.status]">{{ 'time.status.' + r.status | transloco }}</span></td>
              <td class="num">{{ r.expected | number: '1.0-2' }}</td>
              <td class="num">{{ r.worked | number: '1.0-2' }}</td>
              <td class="num" [class.over]="r.overtime > 0">{{ r.overtime | number: '1.0-2' }}</td>
              <td class="num" [class.short]="r.missing > 0">{{ r.missing | number: '1.0-2' }}</td>
              <td class="num">{{ r.absence | number: '1.0-2' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="7" class="muted">{{ (rows().length ? 'table.noMatches' : 'time.team.empty') | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .row { display: flex; gap: 0.25rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    td.num { font-family: var(--app-font-mono); font-size: 0.8rem; font-weight: 500; }
    .over { color: var(--app-warn-text); }
    .short { color: var(--app-bad-text); }
  `,
})
export class TimeTeamPage {
  readonly week = input<string | undefined>(undefined);
  private readonly api = inject(TimeService);
  protected readonly statusTone = TIMESHEET_STATUS_TONE;
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly rows = signal<TeamRow[]>([]);
  protected readonly loading = signal(false);
  protected readonly weekStart = computed(() => mondayOf(this.week() ?? toIsoDate(new Date())));
  protected readonly table = new ClientTable({ rows: this.rows, columns: TEAM_COLUMNS });
  protected readonly numberKeys = NUMBER_KEYS;
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberFilter = NUMBER_RANGE;
  protected readonly statusFilter = translatedSelect(() => TIMESHEET_STATUSES, (s) => 'time.status.' + s);
  protected readonly missingCount = computed(() => this.rows().filter((r) => r.missing > 0 && r.status !== 'submitted' && r.status !== 'approved').length);

  constructor() {
    effect(() => this.load(this.weekStart()));
  }

  protected go(delta: number): void {
    this.goTo(addWeeks(this.weekStart(), delta));
  }

  protected goTo(week: string): void {
    // Merge: the header sort and filters stay when the week changes.
    void this.router.navigate([], { queryParams: { week }, queryParamsHandling: 'merge' });
  }

  private load(week: string): void {
    this.loading.set(true);
    this.api.team(week).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}

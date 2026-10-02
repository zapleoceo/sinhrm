import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TimesheetApproval } from './time.model';
import { TimeService, timeErrorKey } from './time.service';
import { ClientColumns, ClientTable } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';

/** Columns of the approvals list (all submitted weeks are on the page). */
export const APPROVAL_COLUMNS: ClientColumns<TimesheetApproval> = {
  employee: { sort: (t) => t.employee.full_name, filter: 'text', match: (t) => t.employee.full_name },
  week: { sort: (t) => t.week_start, filter: 'range', match: (t) => t.week_start },
  expected: { sort: (t) => t.expected, filter: 'range', match: (t) => t.expected },
  worked: { sort: (t) => t.worked, filter: 'range', match: (t) => t.worked },
  overtime: { sort: (t) => t.overtime, filter: 'range', match: (t) => t.overtime },
};

/** Manager approvals (/time/approvals): submitted weeks of the subtree (admins: everyone), open the grid or decide. */
@Component({
  selector: 'app-time-approvals-page',
  imports: [DatePipe, DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'time.approvals.title' | transloco }}</h1>
        <p class="muted">{{ 'time.approvals.subtitle' | transloco }}</p>
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
              [filter]="textFilter" [filterValue]="table.filter('employee')" (filterChange)="table.setFilter('employee', $event)"></th>
            <th scope="col" app-column-header key="week" [label]="'time.approvals.week' | transloco"
              [filter]="dateFilter" [filterValue]="table.filter('week')" (filterChange)="table.setFilter('week', $event)"></th>
            <th scope="col" class="num" app-column-header key="expected" [label]="'time.week.expected' | transloco"
              [filter]="numberFilter" [filterValue]="table.filter('expected')" (filterChange)="table.setFilter('expected', $event)"></th>
            <th scope="col" class="num" app-column-header key="worked" [label]="'time.week.worked' | transloco"
              [filter]="numberFilter" [filterValue]="table.filter('worked')" (filterChange)="table.setFilter('worked', $event)"></th>
            <th scope="col" class="num" app-column-header key="overtime" [label]="'time.week.overtime' | transloco"
              [filter]="numberFilter" [filterValue]="table.filter('overtime')" (filterChange)="table.setFilter('overtime', $event)"></th>
            <th scope="col"><span class="visually-hidden">{{ 'time.approvals.actions' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (t of rows(); track t.id) {
            <tr>
              <td>{{ t.employee.full_name }}</td>
              <td><a routerLink="/time" [queryParams]="{ week: t.week_start, employee_id: t.employee.id }">{{ t.week_start | date: 'dd.MM.yyyy' }}</a></td>
              <td class="num">{{ t.expected | number: '1.0-2' }}</td>
              <td class="num">{{ t.worked | number: '1.0-2' }}</td>
              <td class="num" [class.over]="t.overtime > 0">{{ t.overtime | number: '1.0-2' }}</td>
              <td class="actions">
                <button mat-icon-button type="button" (click)="decide(t, true)" [attr.aria-label]="'time.approvals.approve' | transloco"><mat-icon>check</mat-icon></button>
                <button mat-icon-button type="button" (click)="decide(t, false)" [attr.aria-label]="'time.approvals.reject' | transloco"><mat-icon>close</mat-icon></button>
              </td>
            </tr>
          } @empty {
            <tr><td colspan="6" class="muted">{{ (items().length ? 'table.noMatches' : 'time.approvals.empty') | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    /* The hidden «actions» column title is position: absolute — keep it inside the scrolling panel, or it widens the page on phones. */
    .panel { position: relative; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    td.num { font-family: var(--app-font-mono); font-size: 0.8rem; font-weight: 500; }
    .over { color: var(--app-warn-text); }
    .actions { white-space: nowrap; text-align: right; }
  `,
})
export class TimeApprovalsPage implements OnInit {
  private readonly api = inject(TimeService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly items = signal<TimesheetApproval[]>([]);
  protected readonly loading = signal(false);
  protected readonly table = new ClientTable(APPROVAL_COLUMNS);
  protected readonly rows = this.table.rows(this.items);
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly numberFilter: ColumnFilter = { type: 'range', input: 'number' };
  protected readonly dateFilter: ColumnFilter = { type: 'range', input: 'date' };

  ngOnInit(): void {
    this.load();
  }

  protected decide(t: TimesheetApproval, approve: boolean): void {
    const comment = approve ? null : window.prompt(this.i18n.translate('time.approvals.reason'));
    if (!approve && !comment) {
      return;
    }
    this.api.decide(t.id, approve, comment).subscribe({
      next: () => this.items.update((list) => list.filter((x) => x.id !== t.id)),
      error: (e: unknown) => this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 }),
    });
  }

  private load(): void {
    this.loading.set(true);
    this.api.approvals().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}

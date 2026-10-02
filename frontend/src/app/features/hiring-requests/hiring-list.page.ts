import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { HIRING_STATUSES, HiringRequest, HiringStatus, PILL_TONE, statusTone } from './hiring-requests.model';
import { ClientColumns, ClientTable, ClientTableQuery, enumFilter } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';

type ListMode = 'all' | 'mine' | 'inbox';

/**
 * Columns of the registry. Status is also sent to the API outside the inbox (the list is capped), and matched on the
 * page for the inbox (its API has no status filter). Status sorts in workflow order, progress by the hired share.
 */
export const HIRING_COLUMNS: ClientColumns<HiringRequest> = {
  title: { sort: (r) => r.title, filter: 'text', match: (r) => r.title },
  requester: { sort: (r) => r.requester?.name, filter: 'text', match: (r) => r.requester?.name },
  status: { sort: (r) => HIRING_STATUSES.indexOf(r.status), filter: 'select', values: HIRING_STATUSES, match: (r) => r.status },
  step: { sort: (r) => r.current_step?.name, filter: 'text', match: (r) => r.current_step?.name },
  progress: { sort: (r) => (r.progress && r.vacancy ? r.progress.percent : null) },
  created: { sort: (r) => r.created_at, filter: 'range', match: (r) => r.created_at },
};

function statusOf(q: ClientTableQuery): HiringStatus | null {
  const s = q.filters['status'];
  return typeof s === 'string' ? (s as HiringStatus) : null;
}

/**
 * Hiring requests (tz2 "Вакансії → Заявки"): registry with author, status, current step and progress;
 * /hiring-requests/inbox — requests waiting for my decision (the same page in "inbox" mode).
 */
@Component({
  selector: 'app-hiring-list-page',
  imports: [DatePipe, MatButtonModule, MatButtonToggleModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ (mode() === 'inbox' ? 'hiring.inbox.title' : 'hiring.list.title') | transloco }}</h1>
        <p class="muted">{{ (mode() === 'inbox' ? 'hiring.inbox.subtitle' : 'hiring.list.subtitle') | transloco }}</p>
      </div>
      @if (canCreate()) {
        <a mat-flat-button routerLink="/hiring-requests/new"><mat-icon>add</mat-icon>{{ 'hiring.list.new' | transloco }}</a>
      }
    </header>
    <div class="filters">
      <mat-button-toggle-group [value]="mode()" (change)="setMode($event.value)" hideSingleSelectionIndicator>
        <mat-button-toggle value="all">{{ 'hiring.list.all' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="mine">{{ 'hiring.list.mine' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="inbox">{{ 'hiring.inbox.tab' | transloco }}</mat-button-toggle>
      </mat-button-toggle-group>
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <!-- The status filter is in its column header (sent to the API outside the inbox); sort and filters live in the URL. -->
    <div class="panel">
      <table class="app-table requests" [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="title" [label]="'hiring.fields.title' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('title')" (filterChange)="table.setFilter('title', $event)"></th>
            <th scope="col" app-column-header key="requester" [label]="'hiring.fields.requester' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('requester')" (filterChange)="table.setFilter('requester', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'hiring.fields.status' | transloco"
              [filter]="statusFilter()" [filterValue]="table.filter('status')" (filterChange)="table.setFilter('status', $event)"></th>
            <th scope="col" app-column-header key="step" [label]="'hiring.fields.step' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('step')" (filterChange)="table.setFilter('step', $event)"></th>
            <th scope="col" app-column-header key="progress" [label]="'hiring.fields.progress' | transloco"></th>
            <th scope="col" app-column-header key="created" [label]="'hiring.fields.created' | transloco"
              [filter]="dateFilter" [filterValue]="table.filter('created')" (filterChange)="table.setFilter('created', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (r of rows(); track r.id) {
            <tr [attr.data-overdue]="r.overdue">
              <td>
                <a [routerLink]="['/hiring-requests', r.id]">{{ r.title }}</a>
                <br /><span class="muted small">{{ r.branch.name }} · ×{{ r.headcount }} · {{ 'hiring.priority.' + r.priority | transloco }}</span>
              </td>
              <td>{{ r.requester?.name ?? '—' }}</td>
              <td><span class="app-pill" [attr.data-tone]="pill[tone(r.status)]">{{ 'hiring.status.' + r.status | transloco }}</span></td>
              <td>
                @if (r.current_step; as step) {
                  {{ step.name }}
                  @if (step.overdue) {
                    <mat-icon inline class="warn" [attr.aria-label]="'hiring.overdue' | transloco">alarm</mat-icon>
                  }
                } @else {
                  —
                }
              </td>
              <td>
                @if (r.progress && r.vacancy) {
                  <span class="app-num">{{ r.progress.hired }}/{{ r.progress.headcount }}</span>
                } @else {
                  —
                }
              </td>
              <td class="app-num">{{ r.created_at | date: 'dd.MM.yyyy' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="6" class="muted">{{ (items().length ? 'table.noMatches' : 'hiring.list.empty') | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .filters { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
    .requests td { vertical-align: top; }
    tr[data-overdue='true'] td:first-child { box-shadow: inset 4px 0 0 var(--app-danger); }
    .app-num { font-size: 0.8rem; white-space: nowrap; }
    .warn { color: var(--app-bad-text); }
    .small { font-size: 0.8rem; }
    .panel { overflow-x: auto; }
  `,
})
export class HiringListPage implements OnInit {
  /** Route data: "inbox" opens the approval inbox. */
  readonly view = input<ListMode>('all');
  private readonly api = inject(HiringRequestsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly mode = signal<ListMode>('all');
  protected readonly status = signal<HiringStatus | null>(null);
  protected readonly items = signal<HiringRequest[]>([]);
  protected readonly loading = signal(false);
  protected readonly canCreate = signal(false);
  protected readonly tone = statusTone;
  protected readonly pill = PILL_TONE;
  private started = false;
  // Declared after the fields the URL callback uses: the URL is read right away.
  protected readonly table = new ClientTable(HIRING_COLUMNS, null, (q) => this.onQuery(q));
  protected readonly rows = this.table.rows(this.items);
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly dateFilter: ColumnFilter = { type: 'range', input: 'date' };
  protected readonly statusFilter = enumFilter(HIRING_STATUSES, 'hiring.status.');

  ngOnInit(): void {
    this.mode.set(this.view());
    this.api.meta().subscribe({ next: (m) => this.canCreate.set(m.can_create), error: () => this.canCreate.set(false) });
    this.started = true;
    this.load();
  }

  protected setMode(mode: ListMode): void {
    this.mode.set(mode);
    this.load();
  }

  /** URL changed: a new status reloads the list (outside the inbox the API filters by it). */
  private onQuery(q: ClientTableQuery): void {
    const status = statusOf(q);
    if (status === this.status()) return;
    this.status.set(status);
    if (this.started && this.mode() !== 'inbox') this.load();
  }
  private load(): void {
    this.loading.set(true);
    const mode = this.mode();
    const call = mode === 'inbox' ? this.api.inbox() : this.api.list({ status: this.status() ?? undefined, mine: mode === 'mine' });
    call.subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(hiringErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}

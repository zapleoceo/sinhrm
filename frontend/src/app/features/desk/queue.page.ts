import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subscription } from 'rxjs';
import { CASE_STATUSES, CASE_STATUS_TONE, CaseStatus, DeskCase, DeskCategory, QueueQuery, slaState } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';
import { SlaBadge } from './sla-badge';
import { ClientColumns, ClientTable, ClientTableQuery, enumFilter } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter, FilterValue } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';

const SLA_STATES = ['breached', 'due', 'ok'] as const;
/** «All cases» in the URL (no param = the default «open» view). */
const ALL = 'all';

/**
 * Columns of the queue. Status and category are server filters (the API caps the list); the other filters and every
 * sort work on the page. Status sorts in workflow order, SLA from breached to met.
 */
export const QUEUE_COLUMNS: ClientColumns<DeskCase> = {
  id: { sort: (c) => c.id },
  subject: { sort: (c) => c.subject, filter: 'text', match: (c) => c.subject },
  employee: { sort: (c) => c.employee.full_name, filter: 'text', match: (c) => c.employee.full_name },
  category: { sort: (c) => c.category.name, filter: 'select' },
  assignee: { sort: (c) => c.assignee?.name, filter: 'text', match: (c) => c.assignee?.name },
  status: { sort: (c) => CASE_STATUSES.indexOf(c.status), filter: 'select', values: [...CASE_STATUSES, ALL] },
  sla: { sort: (c) => SLA_STATES.indexOf(slaState(c)), filter: 'select', values: SLA_STATES, match: (c) => slaState(c) },
};

/** API query of the table state: no status = open cases, ll = every case; junk category ids are dropped. */
export function queueQueryOf(q: ClientTableQuery): QueueQuery {
  const status = q.filters['status'];
  const category = q.filters['category'];
  const categoryId = typeof category === 'string' && /^\d+$/.test(category) ? Number(category) : undefined;
  if (typeof status !== 'string') return { open: true, category_id: categoryId };
  return status === ALL ? { category_id: categoryId } : { status: status as CaseStatus, category_id: categoryId };
}

/**
 * HR queue (/desk/queue): open cases by default with SLA badges, sortable / filterable column headers (state in the URL);
 * categories with their SLA hours.
 */
@Component({
  selector: 'app-desk-queue-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
    SlaBadge,
    TableSortDirective,
    ColumnHeader,
  ],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'desk.queue.title' | transloco }}</h1>
        <p class="muted">{{ 'desk.queue.subtitle' | transloco: { breached: breached() } }}</p>
      </div>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <!-- Status (default «open»: a server filter, the list is capped) and category go to the API; the rest is
         filtered on the page. Sort and filters live in the URL. -->
    <div class="panel">
      <table class="app-table queue" [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="id" label="#"></th>
            <th scope="col" app-column-header key="subject" [label]="'desk.subject' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('subject')" (filterChange)="table.setFilter('subject', $event)"></th>
            <th scope="col" app-column-header key="employee" [label]="'desk.employee' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('employee')" (filterChange)="table.setFilter('employee', $event)"></th>
            <th scope="col" app-column-header key="category" [label]="'desk.category' | transloco"
              [filter]="categoryFilter()" [filterValue]="table.filter('category')" (filterChange)="table.setFilter('category', $event)"></th>
            <th scope="col" app-column-header key="assignee" [label]="'desk.assignee' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('assignee')" (filterChange)="table.setFilter('assignee', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'desk.statusLabel' | transloco"
              [filter]="statusFilter()" [filterValue]="statusValue()" (filterChange)="setStatus($event)"></th>
            <th scope="col" app-column-header key="sla" label="SLA"
              [filter]="slaFilter()" [filterValue]="table.filter('sla')" (filterChange)="table.setFilter('sla', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (c of rows(); track c.id) {
            <tr [attr.data-sla]="sla(c)">
              <td class="app-num">{{ c.id }}</td>
              <td><a [routerLink]="['/desk/cases', c.id]">{{ c.subject }}</a><br /><span class="muted small app-num">{{ c.created_at | date: 'dd.MM HH:mm' }}</span></td>
              <td>{{ c.employee.full_name }}</td>
              <td>{{ c.category.name }}</td>
              <td>{{ c.assignee?.name ?? '—' }}</td>
              <td><span class="app-pill" [attr.data-tone]="statusTone[c.status]">{{ 'desk.status.' + c.status | transloco }}</span></td>
              <td><app-sla-badge [c]="c" /></td>
            </tr>
          } @empty {
            <tr><td colspan="7" class="muted">{{ (items().length ? 'table.noMatches' : 'desk.queue.empty') | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>

    <section class="panel cats">
      <h2>{{ 'desk.categories.title' | transloco }}</h2>
      <p class="muted small">{{ 'desk.categories.hint' | transloco }}</p>
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'desk.categories.name' | transloco }}</th>
            <th scope="col" class="num">{{ 'desk.categories.firstResponse' | transloco }}</th>
            <th scope="col" class="num">{{ 'desk.categories.resolve' | transloco }}</th>
            <th scope="col">{{ 'desk.categories.active' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (k of categories(); track k.id) {
            <tr>
              <td>{{ k.name }}</td>
              <td class="num app-num">{{ k.first_response_hours ?? '—' }}</td>
              <td class="num app-num">{{ k.resolve_hours ?? '—' }}</td>
              <td><mat-slide-toggle [checked]="k.active" (change)="toggleCategory(k, $event.checked)" [attr.aria-label]="k.name" /></td>
            </tr>
          }
        </tbody>
      </table>
      <form class="row" (submit)="$event.preventDefault(); addCategory(name.value, first.value, resolve.value); name.value = ''">
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.name' | transloco }}</mat-label><input matInput #name maxlength="120" required /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.firstResponse' | transloco }}</mat-label><input matInput #first type="number" min="1" max="2160" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.resolve' | transloco }}</mat-label><input matInput #resolve type="number" min="1" max="2160" /></mat-form-field>
        <button mat-stroked-button type="submit"><mat-icon>add</mat-icon>{{ 'desk.categories.add' | transloco }}</button>
      </form>
    </section>
  `,
  styles: `
    .queue td { vertical-align: top; }
    tr[data-sla='breached'] td:first-child { box-shadow: inset 4px 0 0 var(--app-danger); }
    /* Categories (a short settings list, not the queue): its own compact table. */
    .cats table { width: 100%; border-collapse: collapse; }
    .cats th, .cats td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: var(--app-border-w) solid var(--app-track); font-weight: normal; vertical-align: top; }
    .cats thead th { color: var(--app-muted); font: var(--mat-sys-label-medium); font-weight: 700; border-bottom-color: var(--app-border); white-space: nowrap; }
    .cats tbody tr:hover { background: var(--app-row-hover); }
    .app-num { font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .cats { margin-top: var(--app-gap); padding: 1rem 1.25rem; }
    .cats h2 { font: var(--mat-sys-title-medium); margin: 0; }
    .row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1rem; }
    .small { font-size: 0.8rem; }
  `,
})
export class DeskQueuePage implements OnInit {
  private readonly api = inject(DeskService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statusTone = CASE_STATUS_TONE;
  protected readonly items = signal<DeskCase[]>([]);
  protected readonly categories = signal<DeskCategory[]>([]);
  protected readonly loading = signal(false);
  protected readonly breached = computed(() => this.items().filter((c) => slaState(c) === 'breached').length);
  private apiQuery: QueueQuery | null = null;
  private request?: Subscription;
  // Declared after the fields the URL callback uses: the URL is read right away.
  protected readonly table = new ClientTable(QUEUE_COLUMNS, null, (q) => this.onQuery(q));
  protected readonly rows = this.table.rows(this.items);
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly statusFilter = enumFilter(CASE_STATUSES, 'desk.status.', [{ value: 'open', key: 'desk.queue.open' }]);
  protected readonly slaFilter = enumFilter(SLA_STATES, 'desk.sla.');
  protected readonly categoryFilter = computed<ColumnFilter>(() => ({ type: 'select', options: this.categories().map((k) => ({ value: String(k.id), label: k.name })) }));
  /** Status shown in the header: «open» by default, null (= «all») for `?status=all`. */
  protected readonly statusValue = computed<FilterValue>(() => {
    const s = this.table.filter('status');
    return s === null ? 'open' : s === ALL ? null : s;
  });

  ngOnInit(): void {
    this.api.categories(true).subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
  }

  protected sla(c: DeskCase): string {
    return slaState(c);
  }

  /** Header status: «open» = default (no param), «all» (cleared) = `?status=all`, else the status. */
  protected setStatus(value: FilterValue): void {
    this.table.setFilter('status', value === 'open' ? null : value === null ? ALL : value);
  }

  protected addCategory(name: string, first: string, resolve: string): void {
    if (name.trim() === '') {
      return;
    }
    const hours = (v: string): number | null => (v === '' ? null : Number(v));
    this.api.saveCategory(null, { name: name.trim(), first_response_hours: hours(first), resolve_hours: hours(resolve) }).subscribe({
      next: (k) => this.categories.update((list) => [...list, k]),
      error: (e: unknown) => this.toast(deskErrorKey(e)),
    });
  }

  protected toggleCategory(k: DeskCategory, active: boolean): void {
    this.api.saveCategory(k.id, { active }).subscribe({
      next: (saved) => this.categories.update((list) => list.map((x) => (x.id === saved.id ? saved : x))),
      error: (e: unknown) => this.toast(deskErrorKey(e)),
    });
  }

  /** URL changed: reload when a server filter (status, category) changed; sort and page filters need no request. */
  private onQuery(q: ClientTableQuery): void {
    const next = queueQueryOf(q);
    if (this.apiQuery && JSON.stringify(next) === JSON.stringify(this.apiQuery)) return;
    this.apiQuery = next;
    this.loading.set(true);
    // A newer filter wins: the previous request is dropped, so an older answer never overwrites the list.
    this.request?.unsubscribe();
    this.request = this.api.queue(next).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(deskErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}

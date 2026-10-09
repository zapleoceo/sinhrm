import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { ActivatedRoute, ParamMap, RouterLink, convertToParamMap } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ClientColumn, ClientTable, NUMBER_RANGE, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { PagedList } from '../../core/ui/table/paged-list';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter, FilterValue, intParam, oneOfParam, sameQuery } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { CASE_STATUSES, CASE_STATUS_TONE, DeskCase, DeskCategory, QueueQuery, slaState } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';
import { SlaBadge } from './sla-badge';
import { NotifyService } from '../../core/ui/notify.service';

const SLA_STATES = ['breached', 'due', 'ok'] as const;
/** «All cases» in the URL (no param = the default «open» view). */
const ALL = 'all';

/**
 * Columns of the queue (sorted and filtered on the page). Status sorts in workflow order, SLA from breached to met.
 * Status is a server filter only (`open` / `all` are not case statuses); category also goes to the API (the list is
 * capped at 300).
 */
const QUEUE_COLUMNS: readonly ClientColumn<DeskCase>[] = [
  { key: 'id', value: (c) => c.id },
  { key: 'subject', value: (c) => c.subject, filter: 'text' },
  { key: 'employee', value: (c) => c.employee.full_name, filter: 'text' },
  { key: 'category', value: (c) => c.category.name, filter: 'select', filterValue: (c) => String(c.category.id) },
  { key: 'assignee', value: (c) => c.assignee?.name, filter: 'text' },
  { key: 'status', value: (c) => CASE_STATUSES.indexOf(c.status) },
  { key: 'sla', value: (c) => SLA_STATES.indexOf(slaState(c)), filter: 'select', filterValue: (c) => slaState(c) },
];

/** API query of the URL: no status = open cases, `all` = every case; junk statuses and category ids are dropped. */
function queueQueryFromParams(params: ParamMap): QueueQuery {
  const category_id = intParam(params, 'category');
  if (params.get('status') === ALL) return { category_id };
  const status = oneOfParam(params, 'status', CASE_STATUSES);
  return status ? { status, category_id } : { open: true, category_id };
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
      <table class="app-table queue" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="id" label="#"></th>
            <th scope="col" app-column-header key="subject" [label]="'desk.subject' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('subject')" (filterChange)="table.setFilter('subject', $event)"></th>
            <th scope="col" app-column-header key="employee" [label]="'desk.employee' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('employee')" (filterChange)="table.setFilter('employee', $event)"></th>
            <th scope="col" app-column-header key="category" [label]="'desk.category' | transloco"
              [filter]="categoryFilter()" [filterValue]="table.filterValue('category')" (filterChange)="table.setFilter('category', $event)"></th>
            <th scope="col" app-column-header key="assignee" [label]="'desk.assignee' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('assignee')" (filterChange)="table.setFilter('assignee', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'desk.statusLabel' | transloco"
              [filter]="statusFilter()" [filterValue]="statusValue()" (filterChange)="setStatus($event)"></th>
            <th scope="col" app-column-header key="sla" label="SLA"
              [filter]="slaFilter()" [filterValue]="table.filterValue('sla')" (filterChange)="table.setFilter('sla', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (c of table.rows(); track c.id) {
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
      <!-- Categories: sort and filter in the headers (core/ui/table), state in the URL as cat_sort / cat_<column>. -->
      <table class="app-table" [appTableSort]="cats.sort()" [appTableSortCount]="cats.rows().length" (appTableSortChange)="cats.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="name" [label]="'desk.categories.name' | transloco"
              [filter]="textFilter" [filterValue]="cats.filterValue('name')" (filterChange)="cats.setFilter('name', $event)"></th>
            <th scope="col" class="num" app-column-header key="first" [label]="'desk.categories.firstResponse' | transloco"
              [filter]="numberRange" [filterValue]="cats.filterValue('first')" (filterChange)="cats.setFilter('first', $event)"></th>
            <th scope="col" class="num" app-column-header key="resolve" [label]="'desk.categories.resolve' | transloco"
              [filter]="numberRange" [filterValue]="cats.filterValue('resolve')" (filterChange)="cats.setFilter('resolve', $event)"></th>
            <th scope="col" app-column-header key="active" [label]="'desk.categories.active' | transloco"
              [filter]="activeFilter()" [filterValue]="cats.filterValue('active')" (filterChange)="cats.setFilter('active', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (k of cats.rows(); track k.id) {
            <tr>
              <td>{{ k.name }}</td>
              <td class="num app-num">{{ k.first_response_hours ?? '—' }}</td>
              <td class="num app-num">{{ k.resolve_hours ?? '—' }}</td>
              <td><mat-slide-toggle [checked]="k.active" (change)="toggleCategory(k, $event.checked)" [attr.aria-label]="k.name" /></td>
            </tr>
          } @empty {
            @if (categories().length) {
              <tr><td colspan="4" class="muted">{{ 'table.noMatches' | transloco }}</td></tr>
            }
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
  `,
})
export class DeskQueuePage implements OnInit {
  private readonly api = inject(DeskService);
  private readonly notify = inject(NotifyService);
  protected readonly statusTone = CASE_STATUS_TONE;
  /** A newer filter wins: the previous request is cancelled, so an older answer never overwrites the list. */
  private readonly list = new PagedList<DeskCase>();
  protected readonly items = this.list.items;
  protected readonly categories = signal<DeskCategory[]>([]);
  protected readonly loading = this.list.loading;
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  protected readonly activeFilter = translatedSelect(() => ['true', 'false'], (v) => (v === 'true' ? 'table.yes' : 'table.no'));
  /** Categories table: API order is by name (the arrow sits there until the user picks another column). */
  protected readonly cats = new ClientTable<DeskCategory>({
    rows: this.categories,
    prefix: 'cat',
    defaultSort: { key: 'name', dir: 'asc' },
    columns: [
      { key: 'name', value: (k) => k.name, filter: 'text' },
      { key: 'first', value: (k) => k.first_response_hours, filter: 'number' },
      { key: 'resolve', value: (k) => k.resolve_hours, filter: 'number' },
      { key: 'active', value: (k) => (k.active ? 0 : 1), filter: 'select', filterValue: (k) => String(k.active) },
    ],
  });
  protected readonly breached = computed(() => this.items().filter((c) => slaState(c) === 'breached').length);
  private readonly url = inject(TableUrlState);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap, { initialValue: convertToParamMap({}) });
  /** Server part of the URL (status, category): only its change reloads the queue. */
  private readonly query = computed(() => queueQueryFromParams(this.params()), { equal: sameQuery });
  protected readonly table = new ClientTable({ rows: this.items, columns: QUEUE_COLUMNS });
  protected readonly statusFilter = translatedSelect(
    () => ['open', ...CASE_STATUSES],
    (s) => (s === 'open' ? 'desk.queue.open' : 'desk.status.' + s),
  );
  protected readonly slaFilter = translatedSelect(() => SLA_STATES, (s) => 'desk.sla.' + s);
  protected readonly categoryFilter = computed<ColumnFilter>(() => ({ type: 'select', options: this.categories().map((k) => ({ value: String(k.id), label: k.name })) }));
  /** Status shown in the header: «open» by default, a status, or null («all») for `?status=all`. */
  protected readonly statusValue = computed<FilterValue>(() => {
    const q = this.query();
    return q.open ? 'open' : (q.status ?? null);
  });

  constructor() {
    effect(() => {
      const query = this.query();
      untracked(() => this.load(query));
    });
  }
  ngOnInit(): void {
    this.api.categories(true).subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
  }

  protected sla(c: DeskCase): string {
    return slaState(c);
  }

  /** Header status: «open» = default (no param), «all» (cleared) = `?status=all`, else the status. */
  protected setStatus(value: FilterValue): void {
    this.url.update({ status: value === 'open' ? null : value === null ? ALL : value });
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

  private load(query: QueueQuery): void {
    this.list.load(this.api.queue(query), { error: (e) => this.toast(deskErrorKey(e)) });
  }
  private toast(key: string): void {
    this.notify.show(key);
  }
}

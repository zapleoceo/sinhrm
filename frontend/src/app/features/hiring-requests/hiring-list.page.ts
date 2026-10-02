import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { ActivatedRoute, ParamMap, RouterLink, convertToParamMap } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ClientColumn, ClientTable, DATE_RANGE, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { LatestRequest } from '../../core/ui/table/latest-request';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { oneOfParam } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { HIRING_STATUSES, HiringRequest, HiringStatus, PILL_TONE, statusTone } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';

type ListMode = 'all' | 'mine' | 'inbox';

/**
 * Columns of the registry (sorted and filtered on the page). Status is also sent to the API outside the inbox (the
 * list is capped at 300); the inbox API has no status filter, so the page match covers it. Status sorts in workflow
 * order, progress by the hired share.
 */
export const HIRING_COLUMNS: readonly ClientColumn<HiringRequest>[] = [
  { key: 'title', value: (r) => r.title, filter: 'text' },
  { key: 'requester', value: (r) => r.requester?.name, filter: 'text' },
  { key: 'status', value: (r) => HIRING_STATUSES.indexOf(r.status), filter: 'select', filterValue: (r) => r.status },
  { key: 'step', value: (r) => r.current_step?.name, filter: 'text' },
  { key: 'progress', value: (r) => (r.progress && r.vacancy ? r.progress.percent : null) },
  { key: 'created', value: (r) => r.created_at, filter: 'date' },
];

/** Status of the URL for the API (anything else is dropped). */
export function hiringStatusFromParams(params: ParamMap): HiringStatus | null {
  return oneOfParam(params, 'status', HIRING_STATUSES) ?? null;
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
      <table class="app-table requests" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="title" [label]="'hiring.fields.title' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('title')" (filterChange)="table.setFilter('title', $event)"></th>
            <th scope="col" app-column-header key="requester" [label]="'hiring.fields.requester' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('requester')" (filterChange)="table.setFilter('requester', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'hiring.fields.status' | transloco"
              [filter]="statusFilter()" [filterValue]="table.filterValue('status')" (filterChange)="table.setFilter('status', $event)"></th>
            <th scope="col" app-column-header key="step" [label]="'hiring.fields.step' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('step')" (filterChange)="table.setFilter('step', $event)"></th>
            <th scope="col" app-column-header key="progress" [label]="'hiring.fields.progress' | transloco"></th>
            <th scope="col" app-column-header key="created" [label]="'hiring.fields.created' | transloco"
              [filter]="dateFilter" [filterValue]="table.filterValue('created')" (filterChange)="table.setFilter('created', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (r of table.rows(); track r.id) {
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
  protected readonly items = signal<HiringRequest[]>([]);
  protected readonly loading = signal(false);
  protected readonly canCreate = signal(false);
  protected readonly tone = statusTone;
  protected readonly pill = PILL_TONE;
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap, { initialValue: convertToParamMap({}) });
  /** Status of the URL: sent to the API outside the inbox; a change reloads. */
  private readonly status = computed(() => hiringStatusFromParams(this.params()));
  private started = false;
  /** Status the shown list was loaded with (undefined = not loaded yet). */
  private loadedStatus: HiringStatus | null | undefined;
  private readonly request = new LatestRequest();
  protected readonly table = new ClientTable({ rows: this.items, columns: HIRING_COLUMNS });
  protected readonly textFilter = TEXT_FILTER;
  protected readonly dateFilter = DATE_RANGE;
  protected readonly statusFilter = translatedSelect(() => HIRING_STATUSES, (s) => 'hiring.status.' + s);

  constructor() {
    effect(() => {
      const status = this.status();
      untracked(() => {
        if (this.started && this.mode() !== 'inbox' && status !== this.loadedStatus) this.load();
      });
    });
  }

  ngOnInit(): void {
    this.mode.set(this.view());
    this.api.meta().subscribe({ next: (m) => this.canCreate.set(m.can_create), error: () => this.canCreate.set(false) });
    this.load();
    this.started = true;
  }

  protected setMode(mode: ListMode): void {
    this.mode.set(mode);
    this.load();
  }
  private load(): void {
    this.loading.set(true);
    const mode = this.mode();
    this.loadedStatus = this.status();
    const call = mode === 'inbox' ? this.api.inbox() : this.api.list({ status: this.loadedStatus ?? undefined, mine: mode === 'mine' });
    // A newer mode or status wins: the previous request is cancelled, so an older answer never overwrites the list.
    this.request.run(call, {
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

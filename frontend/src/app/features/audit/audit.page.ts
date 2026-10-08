import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTableModule } from '@angular/material/table';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { PagedList } from '../../core/ui/table/paged-list';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import {
  ColumnFilter,
  FilterOption,
  FilterValue,
  RangeValue,
  TableSort,
  dateRangeToParams,
  idToFilter,
  sameQuery,
} from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { AuditRow, auditActionKey, auditEntityKey, toAuditRow } from './audit.format';
import { AUDIT_ACTIONS, AUDIT_ENTITY_TYPES, AuditOptions, AuditQuery } from './audit.model';
import { AUDIT_PAGE_SIZE, auditQueryFromParams } from './audit.query';
import { AuditService } from './audit.service';

/** API order without ?sort: newest first — the time column carries the ↓ arrow. */
const DEFAULT_SORT: TableSort = { key: 'time', dir: 'desc' };

/** A choice with its i18n key when there is one (unknown codes show as they are). */
function codeOption(value: string, key: string | null): FilterOption {
  return key ? { value, label: key, i18n: true } : { value, label: value };
}

/**
 * Superadmin audit log: a paginated table of changes. Column headers sort and filter (core/ui/table): time (date
 * range), user, action, entity type; the state lives in the URL, so a link opens the same slice of the log.
 */
@Component({
  selector: 'app-audit-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatTableModule,
    RouterLink,
    TableSortDirective,
    ColumnHeader,
    TranslocoPipe,
  ],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './audit.page.html',
  styleUrl: './audit.page.scss',
})
export class AuditPage implements OnInit {
  private readonly api = inject(AuditService);
  private readonly url = inject(TableUrlState);
  /** Select value of an optional id of the query (none → «all»). */
  protected readonly idValue = idToFilter;
  /** A newer query cancels the request still in flight: an old answer never lands over the new filters. */
  private readonly list = new PagedList<AuditRow>();
  private loaded = false;

  protected readonly columns = ['time', 'user', 'action', 'entity', 'changes'];
  protected readonly query = signal<AuditQuery>({ page: 1, perPage: AUDIT_PAGE_SIZE });
  protected readonly rows = this.list.items;
  protected readonly total = this.list.total;
  protected readonly loading = this.list.loading;
  protected readonly failed = this.list.failed;
  private readonly options = signal<AuditOptions | null>(null);

  protected readonly dateFilter: ColumnFilter = { type: 'range', input: 'date' };
  protected readonly userFilter = computed<ColumnFilter>(() => ({
    type: 'select',
    options: (this.options()?.users ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  }));
  protected readonly entityFilter = computed<ColumnFilter>(() => ({
    type: 'select',
    options: (this.options()?.entity_types ?? [...AUDIT_ENTITY_TYPES]).map((v) => codeOption(v, auditEntityKey(v))),
  }));
  protected readonly actionFilter = computed<ColumnFilter>(() => ({
    type: 'select',
    options: (this.options()?.actions ?? [...AUDIT_ACTIONS]).map((v) => codeOption(v, auditActionKey(v))),
  }));
  /** Shown sort: the URL one, or the API default (newest first). */
  protected readonly sort = computed<TableSort>(() => {
    const q = this.query();
    return q.sort ? { key: q.sort, dir: q.dir ?? 'asc' } : DEFAULT_SORT;
  });
  protected readonly range = computed<RangeValue | null>(() => {
    const q = this.query();
    return q.from || q.to ? { from: q.from ?? null, to: q.to ?? null } : null;
  });

  ngOnInit(): void {
    this.url.watch(auditQueryFromParams, (query) => this.apply(query));
    this.api.options().subscribe({
      next: (o) => this.options.set(o),
      error: () => this.options.set(null),
    });
  }

  protected load(): void {
    this.loaded = true;
    this.list.load(this.api.list(this.query()), { map: toAuditRow });
  }

  protected onPage(e: PageEvent): void {
    this.url.setPage(e);
  }

  protected onSort(sort: TableSort | null): void {
    this.url.setSort(sort);
  }

  /** Header filters: the chosen value; cleared → removed from the URL (and the page goes back to 1). */
  protected setFilter(name: 'user_id' | 'action' | 'entity_type', value: FilterValue): void {
    this.url.setFilter(name, value);
  }

  protected setRange(value: FilterValue): void {
    this.url.update(dateRangeToParams(value, 'from', 'to'));
  }

  /** New query from the URL: loads unless it is the one already shown. */
  private apply(query: AuditQuery): void {
    if (this.loaded && sameQuery(query, this.query())) return;
    this.query.set(query);
    this.load();
  }
}

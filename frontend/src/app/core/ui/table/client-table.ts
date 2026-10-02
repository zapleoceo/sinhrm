import { Signal, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ParamMap, Params } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { ColumnFilter, FilterOption, FilterValue, RangeValue, TableSort, filterToParam, rangeFromParams, rangeToParams, sortFromParams, sortToParams, textParam } from './table-state';
import { TableUrlState } from './table-url-state';

/**
 * Tables without server paging (all rows already came from the API): sort and filter on the page, state in the URL
 * (docs/guides/tables.md → «без пагинации»). The header markup is the same `th[app-column-header]` as on /people.
 */

/** What a cell sorts / matches by: text, a number (amounts, enum order), an ISO date string; empty = null. */
export type CellValue = string | number | null | undefined;

export interface ClientColumn<T> {
  /** Sort value of a row; omitted = the column does not sort. */
  sort?: (row: T) => CellValue;
  /** Filter kind of the column header (the URL param is the column key; a range uses `{key}_from` / `{key}_to`). */
  filter?: 'text' | 'select' | 'range';
  /** Allowed values of a select filter (anything else in the URL is dropped). */
  values?: readonly string[];
  /**
   * Value the filter matches on the page (text: «contains», case-insensitive; select: equal; range: from ≤ v ≤ to).
   * Omitted = the page sends the filter to the API (a server filter, e.g. status), the rows come already filtered.
   */
  match?: (row: T) => CellValue;
}

export type ClientColumns<T> = Readonly<Record<string, ClientColumn<T>>>;

export interface ClientTableQuery {
  sort: TableSort | null;
  filters: Readonly<Record<string, FilterValue>>;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const NUMBER = /^-?\d+(\.\d+)?$/;

function isEmpty(v: CellValue): v is null | undefined | '' {
  return v === null || v === undefined || v === '';
}

/** Junk-free range from the URL: dates as YYYY-MM-DD, numbers as plain decimals. */
function cleanRange(range: RangeValue | null): RangeValue | null {
  if (!range) return null;
  const ok = (v: string | null): string | null => (v !== null && (ISO_DATE.test(v) || NUMBER.test(v)) ? v : null);
  const from = ok(range.from);
  const to = ok(range.to);
  return from || to ? { from, to } : null;
}

/** Sort and filters from the URL; unknown columns and values outside the allowed list are dropped. */
export function clientQueryFromParams<T>(params: ParamMap, columns: ClientColumns<T>): ClientTableQuery {
  const sortable = Object.keys(columns).filter((k) => columns[k].sort);
  const filters: Record<string, FilterValue> = {};
  for (const [key, col] of Object.entries(columns)) {
    if (col.filter === 'range') {
      const range = cleanRange(rangeFromParams(params, key));
      if (range) filters[key] = range;
    } else if (col.filter) {
      const value = textParam(params, key);
      if (value !== undefined && (!col.values || col.values.includes(value))) filters[key] = value.slice(0, 100);
    }
  }
  return { sort: sortFromParams(params, sortable), filters };
}

/** URL params of one column filter (null / empty removes them). */
export function clientFilterParams<T>(columns: ClientColumns<T>, key: string, value: FilterValue): Params {
  return columns[key]?.filter === 'range' ? rangeToParams(key, value) : { [key]: filterToParam(value) };
}

/** Compares two non-empty cells: numbers as numbers, text with the interface language (natural order of digits). */
export function compareCells(a: string | number, b: string | number, lang: string): number {
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  return String(a).localeCompare(String(b), lang, { numeric: true, sensitivity: 'base' });
}

/**
 * Rows in the order of `sort`: empty cells always last (in both directions); equal cells keep the API order
 * (stable sort), so the order never jumps between renders.
 */
export function sortRows<T>(rows: readonly T[], sort: TableSort | null, columns: ClientColumns<T>, lang: string): T[] {
  const value = sort ? columns[sort.key]?.sort : undefined;
  if (!sort || !value) return [...rows];
  const sign = sort.dir === 'desc' ? -1 : 1;
  return rows
    .map((row, i) => ({ row, i, v: value(row) }))
    .sort((x, y) => {
      const ex = isEmpty(x.v);
      const ey = isEmpty(y.v);
      if (ex || ey) return ex === ey ? x.i - y.i : ex ? 1 : -1;
      return sign * compareCells(x.v as string | number, y.v as string | number, lang) || x.i - y.i;
    })
    .map((x) => x.row);
}

function matches(cell: CellValue, kind: ClientColumn<unknown>['filter'], value: FilterValue, lang: string): boolean {
  if (value === null) return true;
  if (typeof value === 'string') {
    if (isEmpty(cell)) return false;
    return kind === 'text' ? String(cell).toLocaleLowerCase(lang).includes(value.toLocaleLowerCase(lang)) : String(cell) === value;
  }
  if (isEmpty(cell)) return false;
  // Range: numbers compare as numbers; dates by the day (an ISO date-time cell is cut to YYYY-MM-DD).
  const num = typeof cell === 'number';
  const v = num ? cell : String(cell).slice(0, 10);
  const edge = (e: string | null): number | string | null => (e === null ? null : num ? Number(e) : e);
  const from = edge(value.from);
  const to = edge(value.to);
  return (from === null || v >= from) && (to === null || v <= to);
}

/** Rows that pass every page-side filter (filters without `match` are the API's job). */
export function filterRows<T>(rows: readonly T[], filters: ClientTableQuery['filters'], columns: ClientColumns<T>, lang: string): T[] {
  const active = Object.entries(filters).filter(([key, value]) => value !== null && columns[key]?.match);
  if (active.length === 0) return [...rows];
  return rows.filter((row) => active.every(([key, value]) => matches(columns[key].match!(row), columns[key].filter, value, lang)));
}

/**
 * Select filter of an enum column with translated labels (`'assets.status.' + value`), re-labelled when the
 * language or its translations load. Call in an injection context (a field initializer).
 */
export function enumFilter(values: readonly string[], prefix: string, extra: readonly { value: string; key: string }[] = []): Signal<ColumnFilter> {
  const i18n = inject(TranslocoService);
  const loaded = toSignal(i18n.selectTranslation(), { initialValue: {} });
  return computed(() => {
    loaded();
    const options: FilterOption[] = [
      ...extra.map((o) => ({ value: o.value, label: i18n.translate(o.key) })),
      ...values.map((v) => ({ value: v, label: i18n.translate(prefix + v) })),
    ];
    return { type: 'select', options };
  });
}

/**
 * Sort + filter state of one page-side table, bound to the URL. Create it in a field initializer of a page that
 * provides `TableUrlState`:
 * `protected readonly table = new ClientTable(COLUMNS, { key: 'name', dir: 'asc' });`
 * `protected readonly rows = this.table.rows(this.items);`
 * and in the template `[appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)"`,
 * `[filterValue]="table.filter('name')" (filterChange)="table.setFilter('name', $event)"`.
 */
export class ClientTable<T> {
  private readonly url = inject(TableUrlState);
  private readonly i18n = inject(TranslocoService);
  private readonly lang = toSignal(this.i18n.langChanges$, { initialValue: this.i18n.getActiveLang() });
  /** Parsed URL state (sort as in the URL: null = default order). */
  readonly query = signal<ClientTableQuery>({ sort: null, filters: {} });
  /** Shown sort: the URL one or the default (so its column carries the arrow). */
  readonly sort = computed<TableSort | null>(() => this.query().sort ?? this.defaultSort);
  /** True when any filter (page-side or server) is set. */
  readonly filtered = computed(() => Object.keys(this.query().filters).length > 0);
  private readonly columns: ClientColumns<T>;
  private readonly defaultSort: TableSort | null;

  /**
   * @param columns     sortable / filterable columns by key (the key is the URL param and the header `key`)
   * @param defaultSort order without `?sort=` (null = keep the API order, no arrow)
   * @param onQuery     called after every URL change (e.g. to reload when a server filter changed)
   */
  constructor(columns: ClientColumns<T>, defaultSort: TableSort | null = null, onQuery?: (query: ClientTableQuery) => void) {
    this.columns = columns;
    this.defaultSort = defaultSort;
    this.url.watch(
      (params) => clientQueryFromParams(params, columns),
      (query) => {
        this.query.set(query);
        onQuery?.(query);
      },
    );
  }

  /** Visible rows: filtered on the page, then sorted. */
  rows(source: Signal<readonly T[]>): Signal<T[]> {
    return computed(() => {
      const q = this.query();
      const lang = this.lang();
      return sortRows(filterRows(source(), q.filters, this.columns, lang), this.sort(), this.columns, lang);
    });
  }

  /** Current filter of a column (header `[filterValue]`). */
  filter(key: string): FilterValue {
    return this.query().filters[key] ?? null;
  }

  setSort(sort: TableSort | null): void {
    this.url.update(sortToParams(sort));
  }

  setFilter(key: string, value: FilterValue): void {
    this.url.update(clientFilterParams(this.columns, key, value));
  }
}

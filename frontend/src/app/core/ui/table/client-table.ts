import { Signal, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, ParamMap, Params, convertToParamMap } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import {
  ColumnFilter,
  FilterValue,
  RangeValue,
  SortDir,
  TableSort,
  filterToParam,
  isFilterActive,
  prefixed,
  rangeFromParams,
  rangeToParams,
  sortFromParams,
  sortToParams,
  textParam,
} from './table-state';
import { TableUrlState } from './table-url-state';

/**
 * Sorting and filtering of a table whose rows all came at once (no server paging) — docs/guides/tables.md, step 1
 * «без пагинации». The same header component (`th[app-column-header]`) drives it; the state lives in the URL like
 * the server tables, with a prefix when a page has several tables (`?src_sort=count&src_dir=desc&src_channel=tg`).
 */

/** A cell value as the table sorts and filters it. null / undefined / '' = empty: always at the end. */
export type CellValue = string | number | boolean | null | undefined;

/** How a header filter matches: text — contains (any case); select — equals; number / date — inclusive range. */
export type ClientFilterKind = 'text' | 'select' | 'number' | 'date';

export interface ClientColumn<T> {
  /** Column key: the URL name and `key` of its header. */
  key: string;
  /** Value to sort by (numbers for numbers, ISO strings for dates, the shown text for text). */
  value: (row: T) => CellValue;
  /** Filter of the column, if any (the header gets the matching `[filter]`). */
  filter?: ClientFilterKind;
  /** Value to filter by when it differs from the sort value (e.g. a code for a select). */
  filterValue?: (row: T) => CellValue;
  /** false = the column filters but does not sort. */
  sortable?: boolean;
}

/** Header filters for the range kinds (text and select are built by the page: a select needs its options). */
export const TEXT_FILTER: ColumnFilter = { type: 'text' };
export const NUMBER_RANGE: ColumnFilter = { type: 'range', input: 'number' };
export const DATE_RANGE: ColumnFilter = { type: 'range', input: 'date' };

function isEmpty(v: CellValue): v is null | undefined | '' {
  return v === null || v === undefined || v === '';
}

/** Comparator of two non-empty cells: numbers as numbers, everything else by the interface language. */
function compareCells(a: CellValue, b: CellValue, collator: Intl.Collator): number {
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  if (typeof a === 'boolean' && typeof b === 'boolean') return Number(a) - Number(b);
  return collator.compare(String(a), String(b));
}

/**
 * Rows sorted by one column. Empty cells go last in both directions; equal cells keep the order of the API
 * (Array.prototype.sort is stable), so the tie-breaker is the server order.
 */
export function sortRows<T>(rows: readonly T[], value: (row: T) => CellValue, dir: SortDir, lang: string): T[] {
  const collator = new Intl.Collator(lang, { numeric: true, sensitivity: 'base' });
  const sign = dir === 'desc' ? -1 : 1;
  return [...rows].sort((x, y) => {
    const a = value(x);
    const b = value(y);
    if (isEmpty(a) || isEmpty(b)) return Number(isEmpty(a)) - Number(isEmpty(b));
    return sign * compareCells(a, b, collator);
  });
}

/** Does a cell pass a header filter? An inactive filter passes everything; an empty cell fails an active one. */
export function matchesFilter(value: CellValue, kind: ClientFilterKind, filter: FilterValue): boolean {
  if (!isFilterActive(filter)) return true;
  if (isEmpty(value)) return false;
  if (typeof filter === 'string') {
    const text = String(value);
    return kind === 'select' ? text === filter : text.toLocaleLowerCase().includes(filter.trim().toLocaleLowerCase());
  }
  const range = filter as RangeValue;
  if (kind === 'date') {
    const day = String(value).slice(0, 10);
    return (!range.from || day >= range.from) && (!range.to || day <= range.to);
  }
  const n = Number(value);
  const from = range.from === null ? NaN : Number(range.from);
  const to = range.to === null ? NaN : Number(range.to);
  return Number.isFinite(n) && (!Number.isFinite(from) || n >= from) && (!Number.isFinite(to) || n <= to);
}

/** Filters, then sorts (pure: the page's `computed` and the unit tests use the same function). */
export function applyClientTable<T>(
  rows: readonly T[],
  columns: readonly ClientColumn<T>[],
  sort: TableSort | null,
  filters: Readonly<Record<string, FilterValue>>,
  lang: string,
): T[] {
  const active = columns.filter((c) => c.filter && isFilterActive(filters[c.key] ?? null));
  const filtered = active.length
    ? rows.filter((r) => active.every((c) => matchesFilter((c.filterValue ?? c.value)(r), c.filter!, filters[c.key] ?? null)))
    : [...rows];
  const column = sort ? columns.find((c) => c.key === sort.key && c.sortable !== false) : undefined;
  return column && sort ? sortRows(filtered, column.value, sort.dir, lang) : filtered;
}

/** Table state of a client table from the URL; unknown sort keys are dropped (an old link shows the default order). */
export function clientStateFromParams<T>(
  params: ParamMap,
  columns: readonly ClientColumn<T>[],
  prefix?: string,
): { sort: TableSort | null; filters: Record<string, FilterValue> } {
  const keys = columns.filter((c) => c.sortable !== false).map((c) => c.key);
  const filters: Record<string, FilterValue> = {};
  for (const c of columns) {
    if (!c.filter) continue;
    const name = prefixed(prefix, c.key);
    filters[c.key] = c.filter === 'number' || c.filter === 'date' ? rangeFromParams(params, name) : (textParam(params, name) ?? null);
  }
  return { sort: sortFromParams(params, keys, prefix), filters };
}

/** Distinct non-empty values of a column, in first-seen order (options of a select filter built from the rows). */
export function distinctValues<T>(rows: readonly T[], value: (row: T) => CellValue): string[] {
  const seen = new Set<string>();
  for (const r of rows) {
    const v = value(r);
    if (!isEmpty(v)) seen.add(String(v));
  }
  return [...seen];
}

/**
 * Select filter whose option labels are translation keys (`label(value)`), rebuilt when the values change. The one
 * way to translate filter options is `FilterOption.i18n` — the header translates the key in the current language,
 * so a language switch needs no rebuild here; this is only a shorthand for a list of codes.
 */
export function translatedSelect(values: () => readonly string[], label: (value: string) => string): Signal<ColumnFilter> {
  return computed(() => ({ type: 'select', options: values().map((value) => ({ value, label: label(value), i18n: true })) }));
}

export interface ClientTableOptions<T> {
  /** All rows as the API returned them. */
  rows: () => readonly T[];
  /** Columns; a signal when they come with the data (a report describes its own columns). */
  columns: readonly ClientColumn<T>[] | (() => readonly ClientColumn<T>[]);
  /** URL prefix when the page has more than one table (`src` → `src_sort`, `src_dir`, `src_<key>`). */
  prefix?: string;
  /** Order of the API, shown with an arrow while the URL has none; null = the API order is not a column. */
  defaultSort?: TableSort | null;
}

/**
 * Client table bound to the URL. Create it in a field of a component that provides `TableUrlState`:
 * `protected readonly table = new ClientTable({ rows: this.items, columns: [...], prefix: 'cat' });`
 * then `[appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)"`, rows — `table.rows()`,
 * a header filter — `[filterValue]="table.filterValue('name')" (filterChange)="table.setFilter('name', $event)"`.
 */
export class ClientTable<T> {
  private readonly url = inject(TableUrlState);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap, { initialValue: convertToParamMap({}) });
  private readonly i18n = inject(TranslocoService);
  private readonly lang = toSignal(this.i18n.langChanges$, { initialValue: this.i18n.getActiveLang() });
  private readonly columns = computed(() => {
    const c = this.options.columns;
    return typeof c === 'function' ? c() : c;
  });
  /** Sort and filters of the URL, parsed only when the URL (or the columns) change — not on every keystroke. */
  private readonly fromUrl = computed(() => clientStateFromParams(this.params(), this.columns(), this.options.prefix));
  /**
   * Filters as last typed in this table's headers. The header's live edit waits LIVE_FILTER_DEBOUNCE_MS before the
   * URL follows, so the rows show a typed value on top of the URL for as long as its params are on their way there
   * (`TableUrlState.writing()`: queued, then navigating — no flash of the old rows in between). Once the URL holds
   * it, or the edit is dropped («back», another link, a navigation that keeps the query), the URL alone speaks.
   */
  private readonly typed = signal<Readonly<Record<string, FilterValue>>>({});
  private readonly state = computed(() => {
    const url = this.fromUrl();
    const writing = this.url.writing();
    if (!writing) return url;
    const typed = this.typed();
    const overlay: Record<string, FilterValue> = {};
    for (const column of this.columns()) {
      if (column.key in typed && onTheWay(this.filterParams(column, typed[column.key]), writing)) overlay[column.key] = typed[column.key];
    }
    return Object.keys(overlay).length ? { ...url, filters: { ...url.filters, ...overlay } } : url;
  });

  /** Sort shown on the headers: the URL one or the API default. */
  readonly sort: Signal<TableSort | null> = computed(() => this.state().sort ?? this.options.defaultSort ?? null);
  /** True when a header filter narrows the rows (e.g. a «Total» row then covers more than is shown). */
  readonly filtered = computed(() => Object.values(this.state().filters).some((f) => isFilterActive(f)));
  /** True when the URL holds any sort or filter of this table. */
  readonly touched = computed(() => this.filtered() || this.state().sort !== null);
  /** Filtered and sorted rows. Without a sort in the URL the API order stays as is. */
  readonly rows: Signal<T[]> = computed(() =>
    applyClientTable(this.options.rows(), this.columns(), this.state().sort, this.state().filters, this.lang()),
  );

  private readonly options: ClientTableOptions<T>;

  constructor(options: ClientTableOptions<T>) {
    this.options = options;
  }

  filterValue(key: string): FilterValue {
    return this.state().filters[key] ?? null;
  }

  setSort(sort: TableSort | null): void {
    this.url.update(sortToParams(sort, this.options.prefix), { paging: true });
  }

  setFilter(key: string, value: FilterValue): void {
    const column = this.columns().find((c) => c.key === key);
    if (!column?.filter) return;
    this.typed.update((t) => ({ ...t, [key]: value }));
    this.url.update(this.filterParams(column, value), { paging: true });
  }

  /**
   * Removes every column filter of this table from the URL in one navigation; the sort and other params stay.
   * For a page that switches what the rows are (e.g. another grouping), where old filter values mean nothing.
   */
  clearFilters(): void {
    const params = this.columns()
      .filter((c) => c.filter)
      .reduce<Params>((acc, c) => ({ ...acc, ...this.filterParams(c, null) }), {});
    this.typed.set({});
    this.url.update(params, { paging: true });
  }

  private filterParams(column: ClientColumn<T>, value: FilterValue): Params {
    const name = prefixed(this.options.prefix, column.key);
    return column.filter === 'number' || column.filter === 'date' ? rangeToParams(name, value) : { [name]: filterToParam(value) };
  }
}

/** True when every param of a filter is queued / navigating with exactly this value (null = removed). */
function onTheWay(params: Params, writing: Params): boolean {
  return Object.entries(params).every(([name, value]) => name in writing && (writing[name] ?? null) === (value ?? null));
}

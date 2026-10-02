import { ParamMap, Params } from '@angular/router';

/** Sort of a table: a column key and a direction. `null` = the list's default order. */
export type SortDir = 'asc' | 'desc';
export interface TableSort {
  key: string;
  dir: SortDir;
}

/** What a column header can filter by. Values are strings, as in the URL and the API query. */
export interface FilterOption {
  value: string;
  label: string;
}
export type ColumnFilter =
  | { type: 'text' }
  | { type: 'select'; options: readonly FilterOption[] }
  | { type: 'range'; input: 'date' | 'number' };

export interface RangeValue {
  from: string | null;
  to: string | null;
}
/** text/select → string; range → {from, to}; nothing set → null. */
export type FilterValue = string | RangeValue | null;

/**
 * Next sort after a click on a column title: another column starts ascending, the same column flips
 * asc → desc → asc; with `clearable` the third click returns to the default order (null).
 */
export function nextSort(current: TableSort | null, key: string, clearable = false): TableSort | null {
  if (!current || current.key !== key) return { key, dir: 'asc' };
  if (current.dir === 'asc') return { key, dir: 'desc' };
  return clearable ? null : { key, dir: 'asc' };
}

/** aria-sort of a column header for the current sort. */
export function ariaSort(current: TableSort | null, key: string): 'ascending' | 'descending' | 'none' {
  if (!current || current.key !== key) return 'none';
  return current.dir === 'asc' ? 'ascending' : 'descending';
}

/** True when a filter value narrows the list (empty strings and empty ranges do not). */
export function isFilterActive(value: FilterValue): boolean {
  if (value === null) return false;
  if (typeof value === 'string') return value.trim() !== '';
  return !!(value.from || value.to);
}

// ── URL query (?sort=name&dir=desc&page=2): the table state lives in the URL, so a link and «back» restore it ──

/**
 * URL name of a table param. A page with several tables gives each a prefix: `prefixed('src', 'sort')` → `src_sort`;
 * without a prefix the name stays as is (`sort`).
 */
export function prefixed(prefix: string | undefined, name: string): string {
  return prefix ? `${prefix}_${name}` : name;
}

/**
 * Sort from `?sort=&dir=` (or `?<prefix>_sort=&<prefix>_dir=`); an unknown column (old link, hand-edited URL) is
 * ignored, not sent to the API.
 */
export function sortFromParams(params: ParamMap, keys: readonly string[], prefix?: string): TableSort | null {
  const key = params.get(prefixed(prefix, 'sort'));
  if (!key || !keys.includes(key)) return null;
  return { key, dir: params.get(prefixed(prefix, 'dir')) === 'desc' ? 'desc' : 'asc' };
}

/** Query params of a sort; null values remove the params (Router `queryParamsHandling: 'merge'`). */
export function sortToParams(sort: TableSort | null, prefix?: string): Params {
  return { [prefixed(prefix, 'sort')]: sort?.key ?? null, [prefixed(prefix, 'dir')]: sort?.dir ?? null };
}

/** Positive integer param or undefined (`?page=abc` → undefined). */
export function intParam(params: ParamMap, name: string): number | undefined {
  const raw = params.get(name);
  if (raw === null || !/^\d+$/.test(raw)) return undefined;
  const n = Number(raw);
  return n > 0 ? n : undefined;
}

/** Non-empty trimmed text param or undefined. */
export function textParam(params: ParamMap, name: string): string | undefined {
  const raw = params.get(name)?.trim();
  return raw ? raw : undefined;
}

/** Param value of a filter: text/select as is, empty → null (removed from the URL). */
export function filterToParam(value: FilterValue): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

/** `{prefix}_from` / `{prefix}_to` params of a range filter. */
export function rangeToParams(prefix: string, value: FilterValue): Params {
  const range = value !== null && typeof value === 'object' ? value : { from: null, to: null };
  return { [`${prefix}_from`]: range.from || null, [`${prefix}_to`]: range.to || null };
}

export function rangeFromParams(params: ParamMap, prefix: string): RangeValue | null {
  const from = textParam(params, `${prefix}_from`) ?? null;
  const to = textParam(params, `${prefix}_to`) ?? null;
  return from || to ? { from, to } : null;
}

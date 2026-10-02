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
  /** Visible text; with `i18n: true` it is a translation key (the header translates it in the current language). */
  label: string;
  i18n?: boolean;
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

/** Longest text filter the APIs accept (`max:100`); a longer hand-edited URL is cut, not answered with 422. */
export const TEXT_PARAM_MAX = 100;

/** Non-empty trimmed text param (at most TEXT_PARAM_MAX characters) or undefined. */
export function textParam(params: ParamMap, name: string): string | undefined {
  const raw = params.get(name)?.trim().slice(0, TEXT_PARAM_MAX).trim();
  return raw ? raw : undefined;
}

/** One of the allowed values or undefined (`?status=junk` → undefined, never sent to the API). */
export function oneOfParam<T extends string>(params: ParamMap, name: string, allowed: readonly T[]): T | undefined {
  const raw = params.get(name);
  return raw !== null && (allowed as readonly string[]).includes(raw) ? (raw as T) : undefined;
}

const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A real calendar day `YYYY-MM-DD` (2026-02-31 is not; the API checks the same). */
export function isIsoDay(value: string | null | undefined): value is string {
  const m = value ? ISO_DAY.exec(value) : null;
  if (!m) return false;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const date = new Date(Date.UTC(y, mo - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d;
}

/**
 * Date range of the URL (`?from=2026-09-01&to=2026-09-30`, names given): only real `YYYY-MM-DD` days are kept;
 * a reversed range is swapped, so the API never answers 422 to a hand-edited link.
 */
export function dateRangeFromParams(params: ParamMap, fromName: string, toName: string): RangeValue | null {
  const day = (name: string): string | null => {
    const raw = params.get(name);
    return isIsoDay(raw) ? raw : null;
  };
  const from = day(fromName);
  const to = day(toName);
  if (from && to && to < from) return { from: to, to: from };
  return from || to ? { from, to } : null;
}

/** Params of a date range under the given names; an empty range removes both. */
export function dateRangeToParams(value: FilterValue, fromName: string, toName: string): Params {
  const range = value !== null && typeof value === 'object' ? value : { from: null, to: null };
  const day = (v: string | null): string | null => (isIsoDay(v) ? v : null);
  const [from, to] = [day(range.from), day(range.to)];
  return from && to && to < from ? { [fromName]: to, [toName]: from } : { [fromName]: from, [toName]: to };
}

/** Same flat query? (the URL emits again on navigations that do not change it — no second request). */
export function sameQuery<Q extends object>(a: Q, b: Q): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)] as (keyof Q)[]);
  return [...keys].every((k) => a[k] === b[k]);
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

/** Types of the Reports API (backend app/Modules/Reports). */

export type ReportGroup = 'general' | 'hr' | 'performance' | 'recruiting';
export type ReportFilter = 'from' | 'to' | 'branch_id' | 'weeks' | 'period';
export type ColumnType = 'string' | 'number' | 'percent' | 'date';
export type Cell = string | number | boolean | null;
export type Row = Record<string, Cell>;

export interface ReportInfo {
  key: string;
  group: ReportGroup;
  filters: ReportFilter[];
  columns: { key: string; type: ColumnType }[];
  chart: { label: string; value: string } | null;
}

export interface CatalogGroup {
  group: ReportGroup;
  reports: ReportInfo[];
}

export interface ReportResult {
  report: ReportInfo;
  filters: Partial<Record<ReportFilter, string | number>>;
  rows: Row[];
}

export interface DatasetInfo {
  key: string;
  columns: { key: string; type: 'string' | 'number' | 'date'; pii: boolean }[];
}

export const BUILDER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains'] as const;
export type BuilderOperator = (typeof BUILDER_OPERATORS)[number];
export const BUILDER_AGGREGATES = ['count', 'sum', 'avg'] as const;
export type BuilderAggregate = (typeof BUILDER_AGGREGATES)[number];

export interface BuilderFilter {
  column: string;
  op: BuilderOperator;
  value: string | number | null;
}

export interface BuilderSpec {
  dataset: string;
  columns: string[];
  filters: BuilderFilter[];
  group_by?: string | null;
  aggregate?: { fn: BuilderAggregate; column?: string | null } | null;
}

export interface BuilderResult {
  spec: BuilderSpec;
  columns: string[];
  rows: Row[];
  truncated: boolean;
}

export type SavedKind = 'builder' | 'catalog';

export interface SavedReport {
  id: number;
  name: string;
  kind: SavedKind;
  definition: BuilderSpec | { key: string; filters: Partial<Record<ReportFilter, string>> };
  updated_at: string | null;
}

/** Bar length in percent of the largest value (0 when nothing to compare). */
export function barPercent(value: Cell, max: number): number {
  const n = typeof value === 'number' ? value : Number(value);
  return max > 0 && Number.isFinite(n) && n > 0 ? Math.round((n / max) * 100) : 0;
}

/** The largest numeric value of a column. */
export function columnMax(rows: readonly Row[], key: string): number {
  return Math.max(0, ...rows.map((r) => Number(r[key])).filter((n) => Number.isFinite(n)));
}

/** Only filled filters, as query parameters. */
export function filterParams(filters: Partial<Record<ReportFilter, string | number | null | undefined>>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== '') {
      out[k] = String(v);
    }
  }
  return out;
}

/** Drops builder filters without a column (half-filled rows of the form). */
export function cleanSpec(spec: BuilderSpec): BuilderSpec {
  const grouped = spec.group_by !== undefined && spec.group_by !== null && spec.group_by !== '';
  return {
    dataset: spec.dataset,
    columns: grouped ? [] : spec.columns,
    filters: spec.filters.filter((f) => f.column !== ''),
    group_by: grouped ? spec.group_by : null,
    aggregate: grouped ? (spec.aggregate ?? { fn: 'count' }) : null,
  };
}

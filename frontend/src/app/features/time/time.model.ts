/** Types and pure helpers of the Time API (backend app/Modules/Time). */

export type TimesheetStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

export interface TimeDay {
  date: string;
  weekday: number;
  scheduled: number;
  expected: number;
  worked: number;
  holiday: boolean;
  leave: { type: string; fraction: number } | null;
  absence: number;
}

export interface TimeEntry {
  id?: number;
  date: string;
  hours: number;
  project: string | null;
  category: string | null;
  note: string | null;
}

export interface WeekTotals {
  expected: number;
  worked: number;
  overtime: number;
  missing: number;
  absence: number;
}

export interface TimeWeek {
  employee: { id: number; full_name: string };
  week_start: string;
  timesheet_id: number | null;
  status: TimesheetStatus;
  schedule: { days: number[]; hours_per_day: number; source: 'employee' | 'branch' | 'company' | 'default' };
  days: TimeDay[];
  totals: WeekTotals;
  entries: TimeEntry[];
  submitted_at: string | null;
  decided_by: { id: number; name: string } | null;
  decided_at: string | null;
  decision_comment: string | null;
  can: { edit: boolean; decide: boolean };
}

export interface TimesheetApproval {
  id: number;
  employee: { id: number; full_name: string };
  week_start: string;
  status: TimesheetStatus;
  expected: number;
  worked: number;
  overtime: number;
  submitted_at: string | null;
  entries: number;
}

export interface TeamRow extends WeekTotals {
  employee: { id: number; full_name: string };
  timesheet_id: number | null;
  status: TimesheetStatus;
}

export interface WorkScheduleRow {
  id: number;
  branch: { id: number; name: string } | null;
  days: number[];
  hours_per_day: number;
}

/** One line of the week grid: what (project/category/note) × hours per day (7 cells, Monday first). */
export interface GridRow {
  project: string;
  category: string;
  note: string;
  hours: number[];
}

export const TIME_ERROR_CODES = ['no_employee', 'not_editable', 'invalid_status', 'outside_week', 'day_overflow'] as const;

/** Y-m-d of the Monday of the week containing the date (local calendar, no timezone shift). */
export function mondayOf(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const day = new Date(Date.UTC(y, m - 1, d));
  const iso = (day.getUTCDay() + 6) % 7;
  day.setUTCDate(day.getUTCDate() - iso);
  return day.toISOString().slice(0, 10);
}

/** Y-m-d shifted by n weeks. */
export function addWeeks(date: string, n: number): string {
  const [y, m, d] = date.split('-').map(Number);
  const day = new Date(Date.UTC(y, m - 1, d + n * 7));
  return day.toISOString().slice(0, 10);
}

/** Entries → grid rows grouped by project/category/note (a new row per distinct line). */
export function rowsFromEntries(entries: readonly TimeEntry[], weekStart: string): GridRow[] {
  const rows = new Map<string, GridRow>();
  const start = Date.parse(`${weekStart}T00:00:00Z`);
  for (const e of entries) {
    const key = [e.project ?? '', e.category ?? '', e.note ?? ''].join('\u0000');
    const row = rows.get(key) ?? { project: e.project ?? '', category: e.category ?? '', note: e.note ?? '', hours: [0, 0, 0, 0, 0, 0, 0] };
    const index = Math.round((Date.parse(`${e.date}T00:00:00Z`) - start) / 86400000);
    if (index >= 0 && index < 7) {
      row.hours[index] = round2(row.hours[index] + e.hours);
    }
    rows.set(key, row);
  }
  return [...rows.values()];
}

/** Grid rows → entries for PUT /api/time/week (zero cells are dropped). */
export function entriesFromRows(rows: readonly GridRow[], weekStart: string): TimeEntry[] {
  const out: TimeEntry[] = [];
  for (const row of rows) {
    row.hours.forEach((h, i) => {
      if (h > 0) {
        out.push({ date: addDays(weekStart, i), hours: round2(h), project: row.project.trim() || null, category: row.category.trim() || null, note: row.note.trim() || null });
      }
    });
  }
  return out;
}

/** Sum of each day over the rows. */
export function dayTotals(rows: readonly GridRow[]): number[] {
  return [0, 1, 2, 3, 4, 5, 6].map((i) => round2(rows.reduce((sum, r) => sum + (r.hours[i] ?? 0), 0)));
}

/** Quick entry: a row with the expected hours of every day (schedule − leave − holidays). */
export function expectedRow(days: readonly TimeDay[], project = ''): GridRow {
  return { project, category: '', note: '', hours: days.map((d) => d.expected) };
}

/** Overtime and missing of the grid vs the expected hours (same formula as the backend WeekCalculator). */
export function gridTotals(rows: readonly GridRow[], days: readonly TimeDay[]): WeekTotals {
  const worked = round2(dayTotals(rows).reduce((a, b) => a + b, 0));
  const expected = round2(days.reduce((a, d) => a + d.expected, 0));
  const absence = round2(days.reduce((a, d) => a + d.absence, 0));
  return { expected, worked, overtime: round2(Math.max(0, worked - expected)), missing: round2(Math.max(0, expected - worked)), absence };
}

/** Y-m-d shifted by n days. */
export function addDays(date: string, n: number): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
}

function round2(n: number): number {
  return Math.round(n * 100) / 100;
}

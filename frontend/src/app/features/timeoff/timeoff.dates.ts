import { Absence } from './timeoff.model';

/**
 * Date helpers for the leave UI. Dates are plain "YYYY-MM-DD" strings (no time zone): every calculation works on
 * UTC midnights so a DST switch never shifts a day.
 */

export function toIso(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function parseIso(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
}

export function addDays(iso: string, days: number): string {
  const d = parseIso(iso);
  d.setUTCDate(d.getUTCDate() + days);
  return toIso(d);
}

export function isWeekend(iso: string): boolean {
  const day = parseIso(iso).getUTCDay();
  return day === 0 || day === 6;
}

/** First and last day of the month of `iso`. */
export function monthRange(iso: string): { from: string; to: string } {
  const d = parseIso(iso);
  const from = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
  const to = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0));
  return { from: toIso(from), to: toIso(to) };
}

/** First day of the month `delta` months away from the month of `iso`. */
export function shiftMonth(iso: string, delta: number): string {
  const d = parseIso(iso);
  return toIso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + delta, 1)));
}

/** Every date of the month of `iso` (1..28-31). */
export function monthDays(iso: string): string[] {
  const { from, to } = monthRange(iso);
  const days: string[] = [];
  for (let day = from; day <= to; day = addDays(day, 1)) {
    days.push(day);
  }
  return days;
}

export interface CalendarRow {
  employee: Absence['employee'];
  /** date → the absence covering it (first one wins) */
  cells: Record<string, Absence>;
}

/** One row per employee, alphabetically, with the absences laid over the dates of the month. */
export function calendarRows(absences: readonly Absence[], days: readonly string[]): CalendarRow[] {
  const rows = new Map<number, CalendarRow>();
  for (const a of absences) {
    const row = rows.get(a.employee.id) ?? { employee: a.employee, cells: {} };
    for (const day of days) {
      if (day >= a.starts_on && day <= a.ends_on && !(day in row.cells)) {
        row.cells[day] = a;
      }
    }
    rows.set(a.employee.id, row);
  }
  return [...rows.values()].sort((x, y) => x.employee.full_name.localeCompare(y.employee.full_name));
}

/** Rough client-side estimate (Mon–Fri, half days) shown while the server preview is loading. */
export function estimateDays(from: string, to: string, halfDay: 'none' | 'start' | 'end', holidays: readonly string[] = []): number {
  if (!from || !to || to < from) {
    return 0;
  }
  const skip = new Set(holidays);
  let days = 0;
  const working: string[] = [];
  for (let day = from; day <= to && working.length <= 366; day = addDays(day, 1)) {
    if (!isWeekend(day) && !skip.has(day)) {
      days++;
      working.push(day);
    }
  }
  const edge = halfDay === 'start' ? from : halfDay === 'end' ? to : null;
  return edge !== null && working.includes(edge) ? days - 0.5 : days;
}

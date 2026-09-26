/**
 * Timezone-safe conversion between the API's calendar dates ('YYYY-MM-DD') and local `Date` objects used by
 * the Material datepicker. Never goes through UTC (`toISOString()` / `new Date('YYYY-MM-DD')` shift the day
 * for users west or east of UTC), always reads/writes the local calendar fields.
 */
const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})/;
const ISO_LOCAL_DATE_TIME = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/;

const pad = (n: number): string => String(n).padStart(2, '0');

function isValidDate(value: unknown): value is Date {
  return value instanceof Date && !Number.isNaN(value.getTime());
}

/** Local `Date` → 'YYYY-MM-DD' (its local calendar day); `null`/invalid → ''. */
export function toIsoDate(date: Date | null | undefined): string {
  if (!isValidDate(date)) return '';
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Same as `toIsoDate`, but `null` for an empty value (optional API fields). */
export function toIsoDateOrNull(date: Date | null | undefined): string | null {
  return toIsoDate(date) || null;
}

/** 'YYYY-MM-DD' (a longer ISO string is cut to its date part) → local midnight; empty/invalid → `null`. */
export function fromIsoDate(value: string | null | undefined): Date | null {
  const m = value ? ISO_DATE.exec(value) : null;
  if (!m) return null;
  const [year, month, day] = [Number(m[1]), Number(m[2]) - 1, Number(m[3])];
  const date = new Date(year, month, day);
  // Reject overflow like 2026-02-31 (the Date constructor would roll it into March).
  return date.getFullYear() === year && date.getMonth() === month && date.getDate() === day ? date : null;
}

/** Local `Date` → 'YYYY-MM-DDTHH:mm' (what `datetime-local` produced; no timezone, no UTC shift). */
export function toIsoLocalDateTime(date: Date | null | undefined): string {
  if (!isValidDate(date)) return '';
  return `${toIsoDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** 'YYYY-MM-DDTHH:mm[...]' → local `Date` with those wall-clock fields; a bare date → local midnight. */
export function fromIsoLocalDateTime(value: string | null | undefined): Date | null {
  const date = fromIsoDate(value);
  const m = value ? ISO_LOCAL_DATE_TIME.exec(value) : null;
  if (date && m) date.setHours(Number(m[4]), Number(m[5]), 0, 0);
  return date;
}

/** Date part of `day` + time part of `time` as a new local `Date`; `null` if either is missing. */
export function combineDateAndTime(day: Date | null | undefined, time: Date | null | undefined): Date | null {
  if (!isValidDate(day) || !isValidDate(time)) return null;
  return new Date(day.getFullYear(), day.getMonth(), day.getDate(), time.getHours(), time.getMinutes(), 0, 0);
}

/** 'HH:mm' of a local `Date`; `null`/invalid → ''. */
export function toTimeString(date: Date | null | undefined): string {
  return isValidDate(date) ? `${pad(date.getHours())}:${pad(date.getMinutes())}` : '';
}

/** 'HH:mm' → today's local `Date` with that time (value holder for `mat-timepicker`); invalid → `null`. */
export function fromTimeString(value: string | null | undefined): Date | null {
  const m = value ? /^(\d{1,2}):(\d{2})/.exec(value) : null;
  if (!m || Number(m[1]) > 23 || Number(m[2]) > 59) return null;
  const date = new Date();
  date.setHours(Number(m[1]), Number(m[2]), 0, 0);
  return date;
}

/** Today at local midnight (default value for date pickers). */
export function today(): Date {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

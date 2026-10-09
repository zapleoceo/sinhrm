/**
 * Calendar-day arithmetic on 'YYYY-MM-DD' strings through UTC midnights: adding days, months or weeks never crosses
 * a DST switch, because UTC has none. The result is again a 'YYYY-MM-DD' string.
 *
 * Not the same as `iso-date.ts`: that one converts between API strings and LOCAL `Date`s for the datepicker (the user's
 * calendar day). Here a `Date` is only an intermediate UTC midnight — never show it or read its local fields.
 */

/** 'YYYY-MM-DD' → that day's UTC midnight (overflow such as 31.02 rolls over, like `Date.UTC`). */
export function isoDayToUtc(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
}

/** UTC midnight (or any moment) → its UTC calendar day 'YYYY-MM-DD'. */
export function utcToIsoDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}

/** 'YYYY-MM-DD' shifted by `days` (negative — back). */
export function addIsoDays(iso: string, days: number): string {
  const d = isoDayToUtc(iso);
  d.setUTCDate(d.getUTCDate() + days);
  return utcToIsoDay(d);
}

/** Day of week of 'YYYY-MM-DD': 0 = Sunday … 6 = Saturday (as `Date.getUTCDay`). */
export function isoWeekday(iso: string): number {
  return isoDayToUtc(iso).getUTCDay();
}

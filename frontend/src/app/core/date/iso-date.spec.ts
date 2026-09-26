import {
  combineDateAndTime,
  fromIsoDate,
  fromIsoLocalDateTime,
  fromTimeString,
  toIsoDate,
  toIsoDateOrNull,
  toIsoLocalDateTime,
  toTimeString,
} from './iso-date';

describe('iso-date', () => {
  it('formats the local calendar day, not the UTC one', () => {
    // 00:30 local on Jan 1 is still Dec 31 in UTC for any positive offset — must stay Jan 1.
    expect(toIsoDate(new Date(2026, 0, 1, 0, 30))).toBe('2026-01-01');
    expect(toIsoDate(new Date(2026, 11, 31, 23, 59))).toBe('2026-12-31');
  });

  it('returns empty/null for missing or invalid dates', () => {
    expect(toIsoDate(null)).toBe('');
    expect(toIsoDate(new Date('nope'))).toBe('');
    expect(toIsoDateOrNull(undefined)).toBeNull();
    expect(toIsoDateOrNull(new Date(2026, 8, 5))).toBe('2026-09-05');
  });

  it('parses ISO dates as local midnight and round-trips', () => {
    const d = fromIsoDate('2026-03-29');
    expect(d?.getFullYear()).toBe(2026);
    expect(d?.getMonth()).toBe(2);
    expect(d?.getDate()).toBe(29);
    expect(d?.getHours()).toBe(0);
    expect(toIsoDate(d)).toBe('2026-03-29');
    expect(toIsoDate(fromIsoDate('2026-09-26T10:00:00Z'))).toBe('2026-09-26');
  });

  it('rejects empty, malformed and overflowing dates', () => {
    expect(fromIsoDate('')).toBeNull();
    expect(fromIsoDate(null)).toBeNull();
    expect(fromIsoDate('26.09.2026')).toBeNull();
    expect(fromIsoDate('2026-02-31')).toBeNull();
  });

  it('handles local date-times without timezone conversion', () => {
    const d = fromIsoLocalDateTime('2026-10-25T09:15');
    expect(d?.getHours()).toBe(9);
    expect(d?.getMinutes()).toBe(15);
    expect(toIsoLocalDateTime(d)).toBe('2026-10-25T09:15');
    expect(toIsoLocalDateTime(fromIsoLocalDateTime('2026-10-25'))).toBe('2026-10-25T00:00');
    expect(toIsoLocalDateTime(null)).toBe('');
  });

  it('combines a picked day with a picked time', () => {
    const merged = combineDateAndTime(new Date(2026, 4, 2), new Date(1999, 0, 1, 14, 45));
    expect(toIsoLocalDateTime(merged)).toBe('2026-05-02T14:45');
    expect(combineDateAndTime(null, new Date())).toBeNull();
  });

  it('converts HH:mm strings', () => {
    expect(toTimeString(fromTimeString('07:05'))).toBe('07:05');
    expect(fromTimeString('25:00')).toBeNull();
    expect(fromTimeString('')).toBeNull();
    expect(toTimeString(null)).toBe('');
  });
});

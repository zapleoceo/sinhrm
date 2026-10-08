import { addIsoDays, isoDayToUtc, isoWeekday, utcToIsoDay } from './iso-day';

describe('iso-day (calendar days through UTC midnights)', () => {
  it('reads a day as its UTC midnight and writes it back unchanged', () => {
    expect(isoDayToUtc('2026-10-08').toISOString()).toBe('2026-10-08T00:00:00.000Z');
    expect(utcToIsoDay(isoDayToUtc('2026-02-28'))).toBe('2026-02-28');
    // Overflow rolls over like Date.UTC (callers pass real days; this documents the behaviour).
    expect(utcToIsoDay(isoDayToUtc('2026-02-31'))).toBe('2026-03-03');
  });

  it('adds days across month, year and DST boundaries without drift', () => {
    expect(addIsoDays('2026-03-28', 2)).toBe('2026-03-30'); // spring switch (29.03)
    expect(addIsoDays('2026-10-24', 2)).toBe('2026-10-26'); // autumn switch (25.10)
    expect(addIsoDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addIsoDays('2026-03-01', -1)).toBe('2026-02-28');
    expect(addIsoDays('2028-02-28', 1)).toBe('2028-02-29');
    expect(addIsoDays('2026-10-08', 0)).toBe('2026-10-08');
  });

  it('knows the weekday of a day (0 = Sunday)', () => {
    expect(isoWeekday('2026-10-11')).toBe(0);
    expect(isoWeekday('2026-10-12')).toBe(1);
    expect(isoWeekday('2026-10-17')).toBe(6);
  });
});

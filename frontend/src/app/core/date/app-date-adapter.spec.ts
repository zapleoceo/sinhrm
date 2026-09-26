import { TestBed } from '@angular/core/testing';
import { DateAdapter, MAT_DATE_LOCALE } from '@angular/material/core';
import { APP_DATE_INPUT, AppDateAdapter } from './app-date-adapter';

describe('AppDateAdapter', () => {
  let adapter: DateAdapter<Date>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        { provide: MAT_DATE_LOCALE, useValue: 'uk-UA' },
        { provide: DateAdapter, useClass: AppDateAdapter },
      ],
    });
    adapter = TestBed.inject(DateAdapter<Date>);
  });

  it('starts the week on Monday', () => {
    expect(adapter.getFirstDayOfWeek()).toBe(1);
  });

  it('formats inputs as dd.MM.yyyy regardless of locale', () => {
    adapter.setLocale('en-US');
    expect(adapter.format(new Date(2026, 8, 5), APP_DATE_INPUT)).toBe('05.09.2026');
  });

  it('parses dd.MM.yyyy, d.M.yyyy and ISO as local dates', () => {
    expect(adapter.format(adapter.parse('05.09.2026', APP_DATE_INPUT) as Date, APP_DATE_INPUT)).toBe('05.09.2026');
    expect(adapter.parse('5/9/2026', APP_DATE_INPUT)?.getDate()).toBe(5);
    expect(adapter.parse('2026-09-05', APP_DATE_INPUT)?.getHours()).toBe(0);
    expect(adapter.parse('', APP_DATE_INPUT)).toBeNull();
    expect(adapter.isValid(adapter.parse('31.02.2026', APP_DATE_INPUT) as Date)).toBe(false);
    expect(adapter.isValid(adapter.parse('hello', APP_DATE_INPUT) as Date)).toBe(false);
  });

  it('deserializes API dates without a UTC shift', () => {
    const d = adapter.deserialize('2026-01-01');
    expect(d?.getFullYear()).toBe(2026);
    expect(d?.getMonth()).toBe(0);
    expect(d?.getDate()).toBe(1);
  });
});

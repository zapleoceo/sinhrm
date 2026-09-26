import { Injectable } from '@angular/core';
import { MatDateFormats, NativeDateAdapter } from '@angular/material/core';
import { AppLang } from '../auth/auth.model';
import { fromIsoDate } from './iso-date';

/** `displayFormat` marker for the text inside date inputs: always dd.MM.yyyy, whatever the locale. */
export const APP_DATE_INPUT = 'dd.MM.yyyy';

/** BCP-47 locale for month/weekday names per UI language (en → en-GB: day-month order). */
export const DATE_LOCALES: Readonly<Record<AppLang, string>> = { uk: 'uk-UA', ru: 'ru-RU', en: 'en-GB' };

const TIME_FORMAT: Intl.DateTimeFormatOptions = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };

export const APP_DATE_FORMATS: MatDateFormats = {
  parse: { dateInput: APP_DATE_INPUT, timeInput: 'HH:mm' },
  display: {
    dateInput: APP_DATE_INPUT,
    monthLabel: { month: 'short' },
    monthYearLabel: { year: 'numeric', month: 'short' },
    dateA11yLabel: { year: 'numeric', month: 'long', day: 'numeric' },
    monthYearA11yLabel: { year: 'numeric', month: 'long' },
    timeInput: TIME_FORMAT,
    timeOptionLabel: TIME_FORMAT,
  },
};

const pad = (n: number): string => String(n).padStart(2, '0');
const DMY = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/;
const YMD = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Native `Date` adapter tuned for the app: week starts on Monday, input text is dd.MM.yyyy (also accepts
 * d.M.yyyy, dd/MM/yyyy, dd-MM-yyyy and ISO), and ISO date strings are read as local dates (no UTC shift).
 */
@Injectable()
export class AppDateAdapter extends NativeDateAdapter {
  override getFirstDayOfWeek(): number {
    return 1;
  }

  override format(date: Date, displayFormat: object | string): string {
    if (displayFormat === APP_DATE_INPUT) {
      if (!this.isValid(date)) throw Error('AppDateAdapter: Cannot format invalid date.');
      return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()}`;
    }
    return super.format(date, displayFormat);
  }

  override parse(value: unknown, parseFormat?: unknown): Date | null {
    if (typeof value !== 'string') return super.parse(value, parseFormat);
    const text = value.trim();
    if (text === '') return null;
    const dmy = DMY.exec(text);
    const iso = dmy ? `${dmy[3]}-${dmy[2].padStart(2, '0')}-${dmy[1].padStart(2, '0')}` : YMD.test(text) ? text : '';
    return fromIsoDate(iso) ?? this.invalid();
  }

  /** 'YYYY-MM-DD' from the API → local midnight (the base class would parse it as UTC midnight). */
  override deserialize(value: unknown): Date | null {
    if (typeof value === 'string' && YMD.test(value)) return fromIsoDate(value) ?? this.invalid();
    return super.deserialize(value);
  }
}

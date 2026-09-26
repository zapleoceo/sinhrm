import { EnvironmentProviders, Injector, inject, makeEnvironmentProviders, provideEnvironmentInitializer } from '@angular/core';
import { DateAdapter, MAT_DATE_FORMATS, MAT_DATE_LOCALE } from '@angular/material/core';
import { DEFAULT_LANG } from '../auth/auth.model';
import { APP_DATE_FORMATS, AppDateAdapter, DATE_LOCALES } from './app-date-adapter';

/**
 * App-wide Material date setup: Monday-first native adapter, dd.MM.yyyy inputs, translated labels.
 * The locale (month/weekday names) follows the UI language — `LanguageService` calls `DateAdapter.setLocale`.
 */
export function provideAppDates(): EnvironmentProviders {
  return makeEnvironmentProviders([
    { provide: MAT_DATE_LOCALE, useValue: DATE_LOCALES[DEFAULT_LANG] },
    { provide: DateAdapter, useClass: AppDateAdapter },
    { provide: MAT_DATE_FORMATS, useValue: APP_DATE_FORMATS },
    provideEnvironmentInitializer(() => {
      const injector = inject(Injector);
      // Lazy chunk: keeps @angular/material/datepicker out of the initial bundle.
      void import('./datepicker-intl').then((m) => m.bindDatepickerIntl(injector));
    }),
  ]);
}

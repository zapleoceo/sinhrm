import { DestroyRef, Injector } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatDatepickerIntl } from '@angular/material/datepicker';
import { TranslocoService } from '@jsverse/transloco';

type Labels = Partial<Record<string, string>>;

/**
 * Keeps the root `MatDatepickerIntl` (button/aria labels of every datepicker) translated from Transloco
 * (`common.datepicker.*`), re-applied on language change. Loaded with a dynamic `import()` from
 * `provideAppDates()`: a static import would pull the whole datepicker into the initial bundle (+~340 kB).
 */
export function bindDatepickerIntl(injector: Injector): void {
  const intl = injector.get(MatDatepickerIntl);
  injector
    .get(TranslocoService)
    .selectTranslateObject<Labels>('common.datepicker')
    .pipe(takeUntilDestroyed(injector.get(DestroyRef)))
    .subscribe((t) => applyLabels(intl, t));
}

export function applyLabels(intl: MatDatepickerIntl, t: Labels | null | undefined): void {
  if (!t || typeof t !== 'object') return;
  intl.calendarLabel = t['calendar'] ?? intl.calendarLabel;
  intl.openCalendarLabel = t['open'] ?? intl.openCalendarLabel;
  intl.closeCalendarLabel = t['close'] ?? intl.closeCalendarLabel;
  intl.prevMonthLabel = t['prevMonth'] ?? intl.prevMonthLabel;
  intl.nextMonthLabel = t['nextMonth'] ?? intl.nextMonthLabel;
  intl.prevYearLabel = t['prevYear'] ?? intl.prevYearLabel;
  intl.nextYearLabel = t['nextYear'] ?? intl.nextYearLabel;
  intl.prevMultiYearLabel = t['prevYears'] ?? intl.prevMultiYearLabel;
  intl.nextMultiYearLabel = t['nextYears'] ?? intl.nextMultiYearLabel;
  intl.switchToMonthViewLabel = t['chooseDate'] ?? intl.switchToMonthViewLabel;
  intl.switchToMultiYearViewLabel = t['chooseMonthYear'] ?? intl.switchToMultiYearViewLabel;
  intl.startDateLabel = t['start'] ?? intl.startDateLabel;
  intl.endDateLabel = t['end'] ?? intl.endDateLabel;
  intl.changes.next();
}

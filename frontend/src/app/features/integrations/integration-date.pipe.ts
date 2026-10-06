import { formatDate, registerLocaleData } from '@angular/common';
import enGb from '@angular/common/locales/en-GB';
import ru from '@angular/common/locales/ru';
import uk from '@angular/common/locales/uk';
import { Pipe, PipeTransform } from '@angular/core';

// Date formatting is separate from Transloco/Material; data stay in the lazy feature.
registerLocaleData(uk);
registerLocaleData(ru);
registerLocaleData(enGb);

/** API instants in the UI locale and browser timezone. Malformed dates must not break a card. */
@Pipe({ name: 'integrationDate', standalone: true })
export class IntegrationDatePipe implements PipeTransform {
  transform(value: string | null, locale: string): string {
    if (!value?.trim()) return '';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return '';
    return formatDate(date, 'short', locale);
  }
}

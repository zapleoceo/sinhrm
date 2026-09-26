import { MatDatepickerIntl } from '@angular/material/datepicker';
import { applyLabels } from './datepicker-intl';

describe('applyLabels', () => {
  it('sets translated labels and notifies the datepickers', () => {
    const intl = new MatDatepickerIntl();
    let changes = 0;
    intl.changes.subscribe(() => changes++);
    applyLabels(intl, { open: 'Відкрити календар', nextMonth: 'Наступний місяць' });
    expect(intl.openCalendarLabel).toBe('Відкрити календар');
    expect(intl.nextMonthLabel).toBe('Наступний місяць');
    expect(intl.prevMonthLabel).toBe('Previous month');
    expect(changes).toBe(1);
  });

  it('ignores a missing translation object', () => {
    const intl = new MatDatepickerIntl();
    applyLabels(intl, undefined);
    expect(intl.openCalendarLabel).toBe('Open calendar');
  });
});

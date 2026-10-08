import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { clickTitle, column, header, openTablePage, sortCount } from '../../../../testing/table-page';
import { CompensationRecord } from '../people.model';
import { PeopleService } from '../people.service';
import { CompensationTab } from './compensation.tab';

const rec = (id: number, effective_on: string, amount: string, currency: CompensationRecord['currency'], reason: string | null, current = false): CompensationRecord => ({
  id,
  amount,
  currency,
  period: 'month',
  effective_on,
  reason,
  current,
});

// API order: newest effective date first.
const history = [rec(3, '2026-07-01', '45000.00', 'UAH', 'Підвищення', true), rec(2, '2026-01-01', '1200.00', 'USD', null), rec(1, '2025-06-01', '30000.00', 'UAH', 'Прийом')];

function open(url: string): ReturnType<typeof openTablePage<CompensationTab>> {
  return openTablePage(CompensationTab, url, [{ provide: PeopleService, useValue: { myCompensation: () => of({ current: history[0], history }) } }], { employeeId: 7, self: true });
}

const historyTable = (el: HTMLElement): HTMLTableElement => el.querySelector('table.history') as HTMLTableElement;

describe('CompensationTab history table (header sort and filter)', () => {
  it('has a header row; the arrow marks the API order (newest first)', async () => {
    const { el } = await open('/');
    const table = historyTable(el);
    expect([...table.querySelectorAll('thead th')].map((th) => th.getAttribute('aria-label'))).toEqual([
      'people.compensation.effectiveOn',
      'people.compensation.amount',
      'people.compensation.currency',
      'people.compensation.periodLabel',
      'people.compensation.reason',
    ]);
    expect(header(table, 'people.compensation.effectiveOn').getAttribute('aria-sort')).toBe('descending');
    expect(column(table, 0)).toEqual(['01.07.2026', '01.01.2026', '01.06.2025']);
    expect(table.querySelector('tbody tr.current')).not.toBeNull();
  });

  it('a click on the amount sorts numerically (not as text) and writes comp_sort to the URL', async () => {
    const { el, router, settle } = await open('/');
    clickTitle(historyTable(el), 'people.compensation.amount');
    await settle();
    expect(router.url).toBe('/?comp_sort=amount&comp_dir=asc');
    expect(column(historyTable(el), 1)).toEqual(['1,200', '30,000', '45,000']);
  });

  it('address → view: currency and date range filters', async () => {
    const { el, fixture } = await open('/?comp_currency=UAH&comp_effective_on_from=2026-01-01');
    expect(column(historyTable(el), 0)).toEqual(['01.07.2026']);
    expect(sortCount(fixture)).toBe(1); // what an open header filter announces
  });
});

describe('CompensationTab — adding a record (money: a decision, never on one\'s own record)', () => {
  function openHr(inputs: { canAdd: boolean }, add = vi.fn(() => of({ current: history[0], history }))) {
    const api = { compensation: vi.fn(() => of({ current: history[0], history })), myCompensation: vi.fn(), addCompensation: add };
    return openTablePage(CompensationTab, '/', [{ provide: PeopleService, useValue: api }], { employeeId: 7, canManage: true, ...inputs }).then(
      (page) => ({ ...page, api }),
    );
  }

  it('HR on someone else: reads that employee\'s history and offers the form', async () => {
    const { el, api } = await openHr({ canAdd: true });
    expect(api.compensation).toHaveBeenCalledWith(7);
    expect(el.querySelector('form.add')).not.toBeNull();
  });

  it('HR on own record (manage without decide): history only, no form — the API would answer 403', async () => {
    const { el, api } = await openHr({ canAdd: false });
    expect(api.compensation).toHaveBeenCalledWith(7);
    expect(el.querySelector('form.add')).toBeNull();
  });

  it('a refused save is shown as an alert and the button is free again (it was silently dropped before)', async () => {
    const refuse = vi.fn(() => throwError(() => new HttpErrorResponse({ status: 403, error: { code: 'forbidden' } })));
    const { el, fixture } = await openHr({ canAdd: true }, refuse);
    const form = (fixture.componentInstance as unknown as { form: { patchValue(v: object): void } }).form;
    form.patchValue({ amount: 50000, effective_on: '2026-11-01', reason: '  ' });
    fixture.detectChanges();
    (el.querySelector('form.add') as HTMLFormElement).dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    expect(refuse).toHaveBeenCalledWith(7, { amount: 50000, currency: 'UAH', period: 'month', effective_on: '2026-11-01', reason: null });
    expect(el.querySelector('p.error[role="alert"]')?.textContent?.trim()).toBe('people.errors.forbidden');
    expect((el.querySelector('form.add button[type="submit"]') as HTMLButtonElement).disabled).toBe(false);
  });
});

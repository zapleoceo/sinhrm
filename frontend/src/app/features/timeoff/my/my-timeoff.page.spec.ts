import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { NotifyService } from '../../../core/ui/notify.service';
import { PeopleService } from '../../people/people.service';
import { Balance, LeaveRequest } from '../timeoff.model';
import { TimeOffService } from '../timeoff.service';
import { MyTimeOffPage } from './my-timeoff.page';

/** /timeoff: own balances (tracked → available days, untracked → used), own requests; no employee record → a notice. */
const balance = (id: number, tracked: boolean, extra: Partial<Balance> = {}): Balance => ({
  leave_type: { id, name: tracked ? 'Відпустка' : 'Лікарняний', code: tracked ? 'vacation' : 'sick', color: '#4f7cff', paid: true },
  tracked,
  balance: tracked ? 24 : null,
  pending: tracked ? 3 : 0,
  available: tracked ? 21 : null,
  used_this_year: tracked ? 4 : 2,
  policy: null,
  ...extra,
});
const request = (id: number): LeaveRequest =>
  ({
    id,
    employee: { id: 5, full_name: 'Worker' },
    leave_type: { id: 1, name: 'Відпустка', color: '#4f7cff' },
    starts_on: '2026-10-12',
    ends_on: '2026-10-16',
    half_day: 'none',
    days: 5,
    comment: null,
    handover_to: null,
    status: 'pending',
    decision_comment: null,
    approver: null,
    can_cancel: true,
    can_decide: false,
  }) as LeaveRequest;

function render(me: Observable<{ id: number }>) {
  const timeoff = {
    balances: vi.fn(() => of([balance(1, true), balance(2, false)])),
    requests: vi.fn(() => of({ data: [request(1)], meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 } })),
    types: vi.fn(() => of([])),
    preview: vi.fn(() => of(null)),
  };
  const notify = { show: vi.fn() };
  TestBed.configureTestingModule({
    imports: [MyTimeOffPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideNativeDateAdapter(),
      { provide: PeopleService, useValue: { me: () => me } },
      { provide: TimeOffService, useValue: timeoff },
      { provide: NotifyService, useValue: notify },
    ],
  });
  const fixture = TestBed.createComponent(MyTimeOffPage);
  fixture.detectChanges();
  return { fixture, el: fixture.nativeElement as HTMLElement, timeoff, notify };
}

const tiles = (el: HTMLElement): string[][] =>
  [...el.querySelectorAll('li.balance')].map((li) => [...li.children].map((c) => c.textContent?.replace(/\s+/g, ' ').trim() ?? ''));

describe('MyTimeOffPage', () => {
  it('shows own balances: a tracked type gives the available days, an untracked one the days used', () => {
    const { el, timeoff } = render(of({ id: 5 }));
    expect(timeoff.balances).toHaveBeenCalledWith(undefined); // own balances: no employee id in the request
    expect(timeoff.requests).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 5 }));
    expect(tiles(el)).toEqual([
      ['Відпустка', '21', 'timeoff.balance.available', 'timeoff.balance.details'],
      ['Лікарняний', '2', 'timeoff.balance.usedUnlimited'],
    ]);
    expect(el.querySelectorAll('app-requests-list li.row').length).toBe(1);
  });

  it('a new request lands in the list and reloads the balances (pending days changed)', () => {
    const { fixture, el, timeoff, notify } = render(of({ id: 5 }));
    expect(timeoff.balances).toHaveBeenCalledTimes(1);
    fixture.componentInstance['created'](request(2));
    fixture.detectChanges();
    expect(timeoff.balances).toHaveBeenCalledTimes(2);
    expect(el.querySelectorAll('app-requests-list li.row').length).toBe(2);
    expect(notify.show).toHaveBeenCalledWith('timeoff.created', { duration: 3000 });
  });

  it('without an employee record: a notice instead of balances, the form and the list; nothing else is asked', () => {
    const { el, timeoff } = render(throwError(() => new HttpErrorResponse({ status: 404, error: { message: 'no_employee' } })));
    expect(el.querySelector('p.state')).not.toBeNull();
    expect(el.querySelector('app-balances-panel')).toBeNull();
    expect(el.querySelector('app-leave-request-form')).toBeNull();
    expect(timeoff.balances).not.toHaveBeenCalled();
    expect(timeoff.requests).not.toHaveBeenCalled();
  });
});

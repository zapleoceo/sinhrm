import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of, throwError } from 'rxjs';
import { CalendarStore } from './calendar/calendar.store';
import { LeaveRequestsStore } from './leave-requests.store';
import { addDays, calendarRows, estimateDays, isWeekend, monthDays, monthRange, shiftMonth } from './timeoff.dates';
import { Absence, LeaveRequest } from './timeoff.model';
import { TimeOffService, timeoffErrorKey } from './timeoff.service';

const absence = (id: number, employee: string, from: string, to: string): Absence => ({
  id,
  employee: { id: id * 10, full_name: employee, avatar_url: null },
  leave_type: { id: 1, name: 'Vacation', color: '#123456' },
  starts_on: from,
  ends_on: to,
  half_day: 'none',
  status: 'approved',
});
const request = (id: number, status: LeaveRequest['status'] = 'pending'): LeaveRequest =>
  ({ id, status, can_cancel: true, can_decide: true }) as LeaveRequest;

describe('timeoff dates', () => {
  it('handles months, weekends and shifts without time-zone drift', () => {
    expect(monthRange('2026-02-14')).toEqual({ from: '2026-02-01', to: '2026-02-28' });
    expect(monthDays('2026-10-05').length).toBe(31);
    expect(shiftMonth('2026-12-15', 1)).toBe('2027-01-01');
    expect(shiftMonth('2026-01-31', -1)).toBe('2025-12-01');
    expect(addDays('2026-03-28', 2)).toBe('2026-03-30');
    expect(isWeekend('2026-10-17')).toBe(true);
    expect(isWeekend('2026-10-16')).toBe(false);
  });

  it('estimates working days like the server (Mon–Fri, half days, holidays)', () => {
    expect(estimateDays('2026-10-12', '2026-10-18', 'none')).toBe(5);
    expect(estimateDays('2026-10-12', '2026-10-16', 'start')).toBe(4.5);
    expect(estimateDays('2026-10-12', '2026-10-18', 'end')).toBe(5);
    expect(estimateDays('2026-10-12', '2026-10-12', 'end')).toBe(0.5);
    expect(estimateDays('2026-10-12', '2026-10-16', 'none', ['2026-10-14'])).toBe(4);
    expect(estimateDays('2026-10-16', '2026-10-12', 'none')).toBe(0);
    expect(estimateDays('', '2026-10-12', 'none')).toBe(0);
  });

  it('lays absences over the month, one row per employee', () => {
    const days = monthDays('2026-10-01');
    const rows = calendarRows(
      [absence(2, 'Zed', '2026-09-28', '2026-10-02'), absence(1, 'Ann', '2026-10-12', '2026-10-13'), { ...absence(3, 'Ann', '2026-10-13', '2026-10-14'), employee: { id: 10, full_name: 'Ann', avatar_url: null } }],
      days,
    );
    expect(rows.map((r) => r.employee.full_name)).toEqual(['Ann', 'Zed']);
    expect(Object.keys(rows[1].cells)).toEqual(['2026-10-01', '2026-10-02']);
    // overlapping days keep the first absence
    expect(Object.keys(rows[0].cells)).toEqual(['2026-10-12', '2026-10-13', '2026-10-14']);
    expect(rows[0].cells['2026-10-13'].id).toBe(1);
  });
});

describe('TimeOffService', () => {
  let api: TimeOffService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(TimeOffService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('previews without comment and override', () => {
    api.preview({ leave_type_id: 1, starts_on: '2026-10-12', ends_on: '2026-10-16', half_day: 'start', comment: 'x', override_balance: true }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/timeoff/requests/preview');
    expect(req.request.params.keys().sort()).toEqual(['ends_on', 'half_day', 'leave_type_id', 'starts_on']);
    req.flush({ data: { days: 4.5 } });
  });

  it('posts decisions and cancels without a body', () => {
    api.decide(3, 'approve', 'ok').subscribe();
    const approve = http.expectOne('/api/timeoff/requests/3/approve');
    expect(approve.request.body).toEqual({ comment: 'ok' });
    approve.flush({ data: request(3, 'approved') });

    api.decide(3, 'cancel').subscribe();
    const cancel = http.expectOne('/api/timeoff/requests/3/cancel');
    expect(cancel.request.body).toEqual({});
    cancel.flush({ data: request(3, 'cancelled') });
  });

  it('reads the calendar and balances of another employee', () => {
    api.calendar('2026-10-01', '2026-10-31', 2).subscribe();
    const cal = http.expectOne((r) => r.url === '/api/timeoff/calendar');
    expect(cal.request.params.get('branch_id')).toBe('2');
    cal.flush({ data: { absences: [], holidays: [] } });

    api.balances(9).subscribe();
    const bal = http.expectOne((r) => r.url === '/api/timeoff/balances');
    expect(bal.request.params.get('employee_id')).toBe('9');
    bal.flush({ data: [] });
  });

  it('maps errors to i18n keys', () => {
    const err = (status: number, code?: string) => new HttpErrorResponse({ status, error: code ? { code } : null });
    expect(timeoffErrorKey(err(422, 'insufficient_balance'))).toBe('timeoff.errors.insufficient_balance');
    expect(timeoffErrorKey(err(422, 'overlap'))).toBe('timeoff.errors.overlap');
    expect(timeoffErrorKey(err(409, 'invalid_status'))).toBe('timeoff.errors.invalid_status');
    expect(timeoffErrorKey(err(422))).toBe('timeoff.errors.validation');
    expect(timeoffErrorKey(err(500))).toBe('common.error');
  });
});

describe('LeaveRequestsStore', () => {
  it('replaces the row after an action and bumps the version; errors go to the callback', () => {
    let fail = false;
    const fake = {
      requests: () => of({ data: [request(1), request(2)], meta: { current_page: 1, per_page: 50, total: 2, last_page: 1 } }),
      decide: (id: number) => (fail ? throwError(() => new HttpErrorResponse({ status: 409, error: { code: 'invalid_status' } })) : of(request(id, 'approved'))),
    };
    TestBed.configureTestingModule({ providers: [LeaveRequestsStore, { provide: TimeOffService, useValue: fake }] });
    const store = TestBed.inject(LeaveRequestsStore);
    store.setQuery({ employee_id: 5 });
    expect(store.query()).toEqual({ perPage: 50, employee_id: 5 });
    expect(store.items().length).toBe(2);

    store.act({ request: request(2), action: 'approve' }, () => undefined);
    expect(store.items()[1].status).toBe('approved');
    expect(store.version()).toBe(1);

    fail = true;
    let key = '';
    store.act({ request: request(1), action: 'approve' }, (k) => (key = k));
    expect(key).toBe('timeoff.errors.invalid_status');
    expect(store.version()).toBe(1);

    store.added(request(3));
    expect(store.items()[0].id).toBe(3);
    expect(store.total()).toBe(3);
  });
});

describe('CalendarStore', () => {
  it('asks for the whole month and moves between months', () => {
    const calls: string[] = [];
    const fake = {
      calendar: (from: string, to: string) => {
        calls.push(`${from}..${to}`);
        return of({ absences: [absence(1, 'Ann', from, from)], holidays: [{ date: to, name: 'H', branch_id: null }] });
      },
    };
    TestBed.configureTestingModule({ providers: [CalendarStore, { provide: TimeOffService, useValue: fake }] });
    const store = TestBed.inject(CalendarStore);
    store.month.set('2026-10-01');
    store.load();
    store.shift(1);

    expect(calls).toEqual(['2026-10-01..2026-10-31', '2026-11-01..2026-11-30']);
    expect(store.rows().length).toBe(1);
    expect(store.holidays().get('2026-11-30')).toBe('H');
  });
});

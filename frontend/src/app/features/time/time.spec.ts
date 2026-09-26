import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TimeDay, addDays, addWeeks, dayTotals, entriesFromRows, expectedRow, gridTotals, mondayOf, rowsFromEntries } from './time.model';
import { TimeService, timeErrorKey } from './time.service';

const day = (date: string, expected: number, extra: Partial<TimeDay> = {}): TimeDay => ({
  date,
  weekday: 1,
  scheduled: 8,
  expected,
  worked: 0,
  holiday: false,
  leave: null,
  absence: 8 - expected,
  ...extra,
});

describe('TimeService', () => {
  let api: TimeService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(TimeService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('calls the time endpoints', () => {
    api.week('2026-10-05').subscribe();
    http.expectOne((r) => r.url === '/api/time/week' && r.params.get('week') === '2026-10-05' && !r.params.has('employee_id')).flush({ data: {} });
    api.week('2026-10-05', 4).subscribe();
    http.expectOne((r) => r.url === '/api/time/week' && r.params.get('employee_id') === '4').flush({ data: {} });
    api.save('2026-10-05', [{ date: '2026-10-05', hours: 8, project: null, category: null, note: null }]).subscribe();
    const put = http.expectOne({ method: 'PUT', url: '/api/time/week' });
    expect(put.request.body).toEqual({ week: '2026-10-05', employee_id: undefined, entries: [{ date: '2026-10-05', hours: 8, project: null, category: null, note: null }] });
    api.submit('2026-10-05').subscribe();
    http.expectOne({ method: 'POST', url: '/api/time/week/submit' }).flush({ data: {} });
    api.decide(9, false, 'Fix Friday').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/time/timesheets/9/decision' }).request.body).toEqual({ decision: 'reject', comment: 'Fix Friday' });
    api.approvals().subscribe();
    http.expectOne('/api/time/approvals').flush({ data: [] });
    api.team('2026-10-05').subscribe();
    http.expectOne((r) => r.url === '/api/time/team' && r.params.get('week') === '2026-10-05').flush({ data: [] });
    api.schedules().subscribe();
    http.expectOne({ method: 'GET', url: '/api/time/schedules' }).flush({ data: [] });
    api.saveSchedule(null, [1, 2], 6).subscribe();
    expect(http.expectOne({ method: 'PUT', url: '/api/time/schedules' }).request.body).toEqual({ branch_id: null, days: [1, 2], hours_per_day: 6 });
    api.deleteSchedule(3).subscribe();
    http.expectOne({ method: 'DELETE', url: '/api/time/schedules/3' }).flush(null);
  });

  it('maps errors', () => {
    expect(timeErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'not_editable' } }))).toBe('time.errors.not_editable');
    expect(timeErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('time.errors.forbidden');
  });
});

describe('time grid helpers', () => {
  it('computes weeks and dates', () => {
    expect(mondayOf('2026-10-11')).toBe('2026-10-05');
    expect(mondayOf('2026-10-05')).toBe('2026-10-05');
    expect(addWeeks('2026-10-05', -1)).toBe('2026-09-28');
    expect(addDays('2026-10-05', 6)).toBe('2026-10-11');
  });

  it('round-trips entries through grid rows and totals overtime', () => {
    const entries = [
      { date: '2026-10-05', hours: 5, project: 'A', category: null, note: null },
      { date: '2026-10-05', hours: 1, project: 'A', category: null, note: null },
      { date: '2026-10-06', hours: 4, project: 'B', category: 'dev', note: null },
    ];
    const rows = rowsFromEntries(entries, '2026-10-05');
    expect(rows.length).toBe(2);
    expect(rows[0].hours).toEqual([6, 0, 0, 0, 0, 0, 0]);
    expect(dayTotals(rows)).toEqual([6, 4, 0, 0, 0, 0, 0]);
    expect(entriesFromRows(rows, '2026-10-05')).toEqual([
      { date: '2026-10-05', hours: 6, project: 'A', category: null, note: null },
      { date: '2026-10-06', hours: 4, project: 'B', category: 'dev', note: null },
    ]);

    const days = [day('2026-10-05', 8), day('2026-10-06', 4, { leave: { type: 'Vacation', fraction: 0.5 } }), day('2026-10-07', 0, { holiday: true, absence: 0 })];
    expect(gridTotals(rows, days)).toEqual({ expected: 12, worked: 10, overtime: 0, missing: 2, absence: 4 });
    expect(expectedRow(days).hours).toEqual([8, 4, 0]);
    expect(gridTotals([{ project: '', category: '', note: '', hours: [10, 6, 2] }], days).overtime).toBe(6);
  });
});

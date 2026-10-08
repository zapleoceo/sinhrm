import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { NotifyService } from '../../../core/ui/notify.service';
import { DirectoryService } from '../../directory/directory.service';
import { Holiday, LeavePolicy, LeaveType } from '../timeoff.model';
import { TimeOffService } from '../timeoff.service';
import { TimeOffSettingsPage } from './timeoff-settings.page';

/**
 * Leave settings: policies (days per year — what balances are made of) only for types that track a balance, sent as
 * numbers; holidays of the year of a fixed clock, the date as a calendar day with no time-zone shift; refusals as a notice.
 */
const type = (id: number, name: string, tracks_balance: boolean): LeaveType => ({
  id,
  name,
  code: name.toLowerCase(),
  paid: true,
  unit: 'days',
  color: '#4f7cff',
  requires_approval: true,
  tracks_balance,
  active: true,
});
const policy = (id: number, extra: Partial<LeavePolicy> = {}): LeavePolicy => ({
  id,
  leave_type_id: 1,
  leave_type: { id: 1, name: 'Vacation' },
  branch_id: null,
  branch: null,
  accrual_mode: 'yearly_upfront',
  annual_days: 24,
  carry_over_max: null,
  active: true,
  ...extra,
});

function render() {
  const api = {
    types: vi.fn(() => of([type(1, 'Vacation', true), type(2, 'Sick', false)])),
    policies: vi.fn(() => of([policy(1)])),
    holidays: vi.fn<(year: number) => Observable<Holiday[]>>(() => of([])),
    savePolicy: vi.fn((_id: number | null, body: Partial<LeavePolicy>) => of(policy(9, { ...body, branch: null }))),
    saveHoliday: vi.fn(() => of({ id: 3, date: '2027-01-01', name: 'New year', branch_id: null, branch: null })),
    saveType: vi.fn(),
    deleteHoliday: vi.fn(),
  };
  const notify = { show: vi.fn() };
  TestBed.configureTestingModule({
    imports: [TimeOffSettingsPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideNativeDateAdapter(),
      { provide: TimeOffService, useValue: api },
      { provide: DirectoryService, useValue: { active: () => of([{ id: 4, name: 'Київ' }]) } },
      { provide: NotifyService, useValue: notify },
    ],
  });
  const fixture = TestBed.createComponent(TimeOffSettingsPage);
  fixture.detectChanges();
  const page = fixture.componentInstance as unknown as {
    trackedTypes: () => LeaveType[];
    policies: () => LeavePolicy[];
    policyForm: { patchValue(v: object): void };
    holidayForm: { patchValue(v: object): void };
    addPolicy(): void;
    addHoliday(): void;
    setYear(y: number): void;
  };
  return { fixture, page, api, notify };
}

describe('TimeOffSettingsPage', () => {
  // A fixed local clock in the night window: the year of the page is the year of that clock, not of the real date.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 9, 15, 1, 30));
  });
  afterEach(() => vi.useRealTimers());

  it('offers policies only for types that track a balance', () => {
    const { page } = render();
    expect(page.trackedTypes().map((t) => t.name)).toEqual(['Vacation']);
  });

  it('a new policy goes out with numbers, the branch and the carry-over, and joins the list', () => {
    const { page, api } = render();
    page.policyForm.patchValue({ leave_type_id: 1, branch_id: 4, annual_days: 28, accrual_mode: 'monthly', carry_over_max: 5 });
    page.addPolicy();
    expect(api.savePolicy).toHaveBeenCalledWith(null, { leave_type_id: 1, branch_id: 4, annual_days: 28, accrual_mode: 'monthly', carry_over_max: 5 });
    expect(page.policies().map((p) => p.id)).toEqual([1, 9]);
  });

  it('an invalid policy (no type, more days than a year has) is not sent', () => {
    const { page, api } = render();
    page.policyForm.patchValue({ leave_type_id: null });
    page.addPolicy();
    page.policyForm.patchValue({ leave_type_id: 1, annual_days: 400 });
    page.addPolicy();
    expect(api.savePolicy).not.toHaveBeenCalled();
  });

  it('holidays of the current year (fixed clock), the next year on a click; a holiday is sent as a calendar day', () => {
    const { page, api } = render();
    expect(api.holidays).toHaveBeenLastCalledWith(2026);
    page.setYear(2027);
    expect(api.holidays).toHaveBeenLastCalledWith(2027);
    page.holidayForm.patchValue({ date: new Date(2027, 0, 1), name: 'New year', branch_id: null });
    page.addHoliday();
    expect(api.saveHoliday).toHaveBeenCalledWith(null, { date: '2027-01-01', name: 'New year', branch_id: null });
  });

  it('a refusal of the API is a notice; the list stays as it was', () => {
    const { page, api, notify } = render();
    api.savePolicy.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 403 })));
    page.policyForm.patchValue({ leave_type_id: 1 });
    page.addPolicy();
    expect(notify.show).toHaveBeenCalledTimes(1);
    expect(page.policies().map((p) => p.id)).toEqual([1]);
  });
});

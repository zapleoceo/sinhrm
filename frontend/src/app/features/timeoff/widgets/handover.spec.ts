import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { provideNativeDateAdapter } from '@angular/material/core';
import { LeaveRequest } from '../timeoff.model';
import { TimeOffService, timeoffErrorKey } from '../timeoff.service';
import { LeaveRequestForm } from './leave-request-form';
import { RequestsList } from './requests-list';

/** PROD-13: optional "who takes over the work" on a leave request. */
const TRANSLOCO = TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } });

const row = (handover: LeaveRequest['handover_to']): LeaveRequest =>
  ({
    id: 1,
    employee: { id: 5, full_name: 'Worker' },
    leave_type: { id: 1, name: 'Vacation', color: '#123456' },
    starts_on: '2026-10-12',
    ends_on: '2026-10-16',
    half_day: 'none',
    days: 5,
    comment: null,
    handover_to: handover,
    status: 'pending',
    decision_comment: null,
    approver: null,
    can_cancel: false,
    can_decide: false,
  }) as LeaveRequest;

function form() {
  const api = {
    types: vi.fn(() => of([{ id: 1, name: 'Vacation' }])),
    preview: vi.fn(() => of(null)),
    create: vi.fn(() => of(row(null))),
  };
  TestBed.configureTestingModule({
    imports: [LeaveRequestForm, TRANSLOCO],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideNativeDateAdapter(), { provide: TimeOffService, useValue: api }],
  });
  const fixture = TestBed.createComponent(LeaveRequestForm);
  fixture.detectChanges();
  const cmp = fixture.componentInstance as unknown as { form: { patchValue(v: object): void } };
  const submit = () => (fixture.nativeElement.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit'));
  return { fixture, api, cmp, submit, el: fixture.nativeElement as HTMLElement };
}

describe('Leave request handover', () => {
  it('offers an optional person picker and omits the field when nobody is chosen', () => {
    const { api, cmp, submit, el } = form();
    expect(el.querySelector('app-person-picker')).not.toBeNull();
    cmp.form.patchValue({ starts_on: new Date(2026, 9, 12), ends_on: new Date(2026, 9, 16) });
    submit();
    expect(api.create).toHaveBeenCalledTimes(1);
    expect(api.create.mock.calls[0]).toEqual([expect.not.objectContaining({ handover_to_employee_id: expect.anything() })]);
  });

  it('sends the chosen colleague id and clears it after saving', () => {
    const { api, cmp, submit } = form();
    cmp.form.patchValue({ starts_on: new Date(2026, 9, 12), ends_on: new Date(2026, 9, 16), handover_to_employee_id: 42 });
    submit();
    expect(api.create).toHaveBeenCalledWith(expect.objectContaining({ handover_to_employee_id: 42, starts_on: '2026-10-12' }));
    submit();
    expect(api.create.mock.calls[1]).toEqual([expect.not.objectContaining({ handover_to_employee_id: expect.anything() })]);
  });

  it('shows the colleague in the request row only when set', () => {
    TestBed.configureTestingModule({ imports: [RequestsList, TRANSLOCO], providers: [provideRouter([])] });
    const fixture = TestBed.createComponent(RequestsList);
    fixture.componentRef.setInput('requests', [row({ id: 7, full_name: 'Peer Person' }), { ...row(null), id: 2 }]);
    fixture.detectChanges();
    const rows = (fixture.nativeElement as HTMLElement).querySelectorAll('li');
    expect(rows[0].textContent).toContain('timeoff.fields.handover');
    expect(rows[0].textContent).toContain('Peer Person');
    expect(rows[1].textContent).not.toContain('timeoff.fields.handover');
  });

  it('translates the invalid_handover refusal', () => {
    expect(timeoffErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'invalid_handover' } }))).toBe('timeoff.errors.invalid_handover');
  });
});

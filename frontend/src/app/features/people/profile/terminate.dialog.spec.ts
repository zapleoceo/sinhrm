import { TestBed } from '@angular/core/testing';
import { FormGroup } from '@angular/forms';
import { provideNativeDateAdapter } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { Employee } from '../people.model';
import { PeopleService } from '../people.service';
import { TerminateDialog } from './terminate.dialog';

/** Owner decision 2026-10-07 (A): optional "who takes over the work" on termination. */
const worker: Employee = { id: 7, full_name: 'Worker Person', status: 'active' } as Employee;

type Terminate = (id: number, firedAt: string, reason: string | null, handoverTo: number | null) => Observable<Employee>;

describe('TerminateDialog handover', () => {
  function setup(terminate = vi.fn<Terminate>(() => of({ ...worker, status: 'terminated' } as Employee))) {
    const close = vi.fn();
    TestBed.configureTestingModule({
      imports: [TerminateDialog, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { defaultLang: 'uk' } })],
      providers: [
        provideNativeDateAdapter(),
        { provide: MAT_DIALOG_DATA, useValue: worker },
        { provide: MatDialogRef, useValue: { close } },
        { provide: PeopleService, useValue: { terminate, lookupPeople: () => of([]), searchPeople: () => of([]) } },
      ],
    });
    const fixture = TestBed.createComponent(TerminateDialog);
    fixture.detectChanges();
    const form = (fixture.componentInstance as unknown as { form: FormGroup }).form;
    const el = fixture.nativeElement as HTMLElement;
    const submit = () => {
      el.querySelector('form')?.dispatchEvent(new Event('submit'));
      fixture.detectChanges();
    };
    return { fixture, form, el, submit, terminate, close };
  }

  it('offers an optional, labelled person picker with a hint', () => {
    const { el } = setup();
    expect(el.querySelector('app-person-picker')).not.toBeNull();
    expect(el.textContent).toContain('people.terminate.handoverHint');
  });

  it('sends null when nobody is chosen', () => {
    const { form, submit, terminate, close } = setup();
    form.patchValue({ fired_at: new Date(2026, 6, 14) });
    submit();
    expect(terminate).toHaveBeenCalledWith(7, '2026-07-14', null, null);
    expect(close).toHaveBeenCalled();
  });

  it('sends the chosen colleague id', () => {
    const { form, submit, terminate } = setup();
    form.patchValue({ fired_at: new Date(2026, 6, 20), reason: ' Relocation ', handover_to_employee_id: 12 });
    submit();
    expect(terminate).toHaveBeenCalledWith(7, '2026-07-20', 'Relocation', 12);
  });

  it('shows the invalid_handover error from the API in an alert', () => {
    const terminate = vi.fn<Terminate>(() => throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'invalid_handover' } })));
    const { form, submit, el, close } = setup(terminate);
    form.patchValue({ handover_to_employee_id: 7 });
    submit();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('people.errors.invalid_handover');
    expect(close).not.toHaveBeenCalled();
  });
});

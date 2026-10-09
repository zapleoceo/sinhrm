import { TestBed } from '@angular/core/testing';
import { FormGroup } from '@angular/forms';
import { provideNativeDateAdapter } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { DirectoryService } from '../../directory/directory.service';
import { Employee, RestoreEmployee } from '../people.model';
import { PeopleService } from '../people.service';
import { RestoreDialog } from './restore.dialog';
import { isAfterToday } from './terminate.dialog';

const gone: Employee = {
  id: 7,
  full_name: 'Gone Person',
  status: 'terminated',
  position_id: 3,
  department_id: 4,
  branch_id: null,
  manager_id: 9,
  fired_at: '2026-07-01',
} as Employee;

describe('RestoreDialog', () => {
  function setup(restore = vi.fn<(id: number, body: RestoreEmployee) => Observable<Employee>>(() => of({ ...gone, status: 'active' } as Employee))) {
    const close = vi.fn();
    TestBed.configureTestingModule({
      imports: [RestoreDialog, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { defaultLang: 'uk' } })],
      providers: [
        provideNativeDateAdapter(),
        { provide: MAT_DIALOG_DATA, useValue: gone },
        { provide: MatDialogRef, useValue: { close } },
        { provide: DirectoryService, useValue: { active: () => of([{ id: 3, name: 'Dev' }, { id: 5, name: 'Lead' }]) } },
        { provide: PeopleService, useValue: { restore, lookupPeople: () => of([]), searchPeople: () => of([]) } },
      ],
    });
    const fixture = TestBed.createComponent(RestoreDialog);
    fixture.detectChanges();
    const form = (fixture.componentInstance as unknown as { form: FormGroup }).form;
    const submit = (): void => {
      (fixture.nativeElement as HTMLElement).querySelector('form')?.dispatchEvent(new Event('submit'));
    };
    return { fixture, form, submit, restore, close, el: fixture.nativeElement as HTMLElement };
  }

  it('keeps the previous placement: nothing changed sends an empty body', () => {
    const { submit, restore, close } = setup();
    submit();
    expect(restore).toHaveBeenCalledWith(7, {});
    expect(close).toHaveBeenCalledWith(expect.objectContaining({ status: 'active' }));
  });

  it('sends only the changed fields and the new hire date', () => {
    const { form, submit, restore } = setup();
    form.patchValue({ position_id: 5, manager_id: null, hired_at: new Date(2026, 7, 3) });
    submit();
    expect(restore).toHaveBeenCalledWith(7, { position_id: 5, manager_id: null, hired_at: '2026-08-03' });
  });

  it('shows the API error in an alert and stays open', () => {
    const error = new HttpErrorResponse({ status: 409, error: { code: 'not_terminated' } });
    const { fixture, submit, close, el } = setup(vi.fn<(id: number, body: RestoreEmployee) => Observable<Employee>>(() => throwError(() => error)));
    submit();
    fixture.detectChanges();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('people.errors.not_terminated');
    expect(close).not.toHaveBeenCalled();
  });

  it('labels every field and lets hints wrap', () => {
    const { el } = setup();
    expect(el.querySelectorAll('mat-select').length).toBe(3);
    expect(el.querySelector('app-person-picker')).not.toBeNull();
    el.querySelectorAll('mat-form-field').forEach((f) => expect(f.querySelector('mat-label')).not.toBeNull());
  });
});

describe('isAfterToday (terminate dialog: schedule vs. terminate now)', () => {
  // A fixed clock: `now` here and today() inside must be the same day even when the run crosses midnight.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-15T22:30:00Z')); // 01:30 next day in Kyiv: UTC and Kyiv dates differ
  });
  afterEach(() => vi.useRealTimers());

  it('is true only for a day after today', () => {
    const now = new Date();
    expect(isAfterToday(null)).toBe(false);
    expect(isAfterToday(now)).toBe(false);
    expect(isAfterToday(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1))).toBe(false);
    expect(isAfterToday(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1))).toBe(true);
  });
});

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { UserRole } from '../../../core/auth/auth.model';
import { AuthService } from '../../../core/auth/auth.service';
import { NotifyService } from '../../../core/ui/notify.service';
import { Employee, EmployeeAccess } from '../people.model';
import { PeopleService } from '../people.service';
import { ProfilePage } from './profile.page';

/**
 * Profile actions and tiers follow the API's access flags only: termination for HR / a manager above (never oneself),
 * restore for a terminated person, PII rows only with access.pii, the compensation (money) tab for HR and the person.
 */
const NONE: EmployeeAccess = { job: false, pii: false, decide: false, manage: false, self: false, terminate: false };
const employee = (access: Partial<EmployeeAccess>, extra: Partial<Employee> = {}): Employee => ({
  id: 7,
  full_name: 'Коваленко Олена',
  avatar_url: null,
  work_email: 'olena@sinhrm.test',
  phone: null,
  status: 'active',
  branch: null,
  department: null,
  position: null,
  manager: null,
  access: { ...NONE, ...access },
  ...extra,
});

function render(e: Employee, roles: UserRole[] = ['employee']) {
  const people = {
    get: vi.fn(() => of(e)),
    me: vi.fn(() => of(e)),
    changeRequests: vi.fn(() => of({ data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } })),
  };
  TestBed.configureTestingModule({
    imports: [ProfilePage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideNativeDateAdapter(),
      { provide: PeopleService, useValue: people },
      { provide: AuthService, useValue: { user: signal({ id: 1, roles, approval_emails: true }) } },
      { provide: NotifyService, useValue: { show: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(ProfilePage);
  fixture.componentRef.setInput('id', '7');
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;
  return { el, people };
}

const actions = (el: HTMLElement): string[] =>
  [...el.querySelectorAll('header .actions > button')].map((b) => b.textContent?.replace(/^\s*\S+\s*(?=people\.)/, '').trim() ?? '');
const tabs = (el: HTMLElement): string[] => [...el.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim() ?? '');
const facts = (el: HTMLElement): string[] => [...el.querySelectorAll('dl.facts dt')].map((d) => d.textContent?.trim() ?? '');

describe('ProfilePage — access', () => {
  it('directory tier only: no actions, no personal data, no money, no job tab', () => {
    const { el, people } = render(employee({}));
    expect(people.get).toHaveBeenCalledWith(7);
    expect(actions(el)).toEqual([]);
    expect(facts(el)).toEqual(['people.fields.workEmail', 'people.fields.phone', 'people.fields.manager']);
    expect(tabs(el)).toEqual(['people.tabs.overview']);
  });

  it('own profile: a change request and the compensation tab, but never «terminate» (not even with manage)', () => {
    const { el } = render(employee({ self: true, job: true, pii: true }));
    expect(actions(el)).toEqual(['people.changes.new']);
    expect(tabs(el)).toContain('people.tabs.compensation');
    expect(el.textContent).not.toContain('people.terminate.action');
  });

  it('HR on an active employee: edit and terminate; personal data rows appear with access.pii', () => {
    const { el } = render(employee({ manage: true, terminate: true, job: true, pii: true, decide: true }), ['hr_manager']);
    expect(actions(el)).toEqual(['people.edit.title', 'people.terminate.action']);
    expect(facts(el)).toEqual(expect.arrayContaining(['people.fields.birthDate', 'people.fields.personalEmail', 'people.fields.address']));
    expect(tabs(el)).toContain('people.tabs.compensation');
  });

  it('a manager above without manage: may terminate, but sees no compensation and cannot edit', () => {
    const { el } = render(employee({ terminate: true, job: true }), ['employee']);
    expect(actions(el)).toEqual(['people.terminate.action']);
    expect(tabs(el)).not.toContain('people.tabs.compensation');
  });

  it('a scheduled termination: «cancel» instead of «terminate» and the date as a calendar day', () => {
    const { el } = render(
      employee({ manage: true, terminate: true, job: true }, { fired_at: '2026-10-31', termination_scheduled: true, handover_to: { id: 8, full_name: 'Петренко' } }),
      ['hr_manager'],
    );
    expect(actions(el)).toEqual(['people.edit.title', 'people.terminate.cancel']);
    expect(el.querySelector('p.scheduled')?.textContent).toContain('people.terminate.scheduled');
  });

  it('a terminated employee: restore instead of terminate', () => {
    const { el } = render(employee({ manage: true, terminate: true, job: true }, { status: 'terminated', fired_at: '2026-09-30' }), ['hr_manager']);
    expect(actions(el)).toEqual(['people.edit.title', 'people.restore.action']);
  });
});

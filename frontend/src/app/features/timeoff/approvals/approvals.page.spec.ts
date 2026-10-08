import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import uk from '../../../../../public/i18n/uk.json';
import { ChangeRequest } from '../../people/people.model';
import { PeopleService } from '../../people/people.service';
import { TimeOffService } from '../timeoff.service';
import { ApprovalsPage } from './approvals.page';

const change: ChangeRequest = {
  id: 7,
  employee: { id: 3, full_name: 'Synthetic Person' },
  changes: { phone: '+380000000000' },
  hidden_changes: ['address', 'personal_email'],
  status: 'pending',
  comment: null,
  requested_by: null,
  decided_by: null,
  decided_at: null,
  decision_comment: null,
  can_decide: true,
  created_at: null,
} as ChangeRequest;

/** Security audit 2026-10: a manager decides a change request without seeing the subordinate's personal data. */
describe('ApprovalsPage — hidden personal fields of a change request', () => {
  it('shows which fields are hidden instead of their values', () => {
    TestBed.configureTestingModule({
      imports: [ApprovalsPage, TranslocoTestingModule.forRoot({ langs: { uk }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' }, preloadLangs: true })],
      providers: [
        provideRouter([]),
        { provide: TimeOffService, useValue: { approvals: () => of([]) } },
        { provide: PeopleService, useValue: { changeRequests: () => of({ data: [change], meta: {} }) } },
      ],
    });
    const fixture = TestBed.createComponent(ApprovalsPage);
    fixture.detectChanges();
    const text = ((fixture.nativeElement as HTMLElement).textContent ?? '').replace(/\s+/g, ' ');

    expect(text).toContain('Приховано: Адреса, Особистий e-mail');
    expect(text).not.toContain('@');
  });
});

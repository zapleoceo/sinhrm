import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { NO_ERRORS_SCHEMA, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import uk from '../../../../../public/i18n/uk.json';
import { AuthService } from '../../../core/auth/auth.service';
import { AuditHistory } from '../../audit/audit-history';
import { GoogleService } from '../../google-workspace/google.service';
import { PrivacyActions } from '../../privacy/privacy-actions';
import { EvaluationBadge } from '../../scripts/evaluation/evaluation-badge';
import { TasksWidget } from '../../scripts/tasks/tasks-widget';
import { Application, Candidate, applicationCvUrl } from '../recruiting.model';
import { CandidateCard } from './candidate-card';
import { CandidateCardStore } from './candidate-card.store';
import { InterviewersPanel } from './interviewers-panel';
import { OfferPanel } from './offer-panel';
import { ScreeningPanel } from './screening-panel';
import { TouchBody } from './touch-body';
import { TouchComposer } from './touch-composer';

const app = (over: Partial<Application>): Application =>
  ({
    id: 7,
    candidate_id: 1,
    vacancy_id: 3,
    stage_id: 1,
    status: 'active',
    reject_reason_id: null,
    reject_reason: null,
    rejected_note: null,
    stage_entered_at: null,
    last_touch_at: null,
    is_stale: false,
    closed_at: null,
    created_at: null,
    vacancy: { id: 3, title: 'Synthetic vacancy', status: 'open' },
    route: [],
    ...over,
  }) as Application;

/** The card with the store replaced by plain signals; heavy child panels are stubbed out (only the route block matters). */
function render(applications: Application[]): HTMLElement {
  const candidate = { id: 1, full_name: 'Synthetic Candidate', tags: [], utm: {}, source: 'site', applications } as unknown as Candidate;
  const store = {
    candidate: signal<Candidate | null>(candidate),
    loading: signal(false),
    failed: signal(false),
    filters: signal([]),
    timeline: signal([]),
    timelineTotal: signal(0),
    timelineLoading: signal(false),
    rejectReasons: signal([]),
    open: vi.fn(),
  };
  TestBed.configureTestingModule({
    imports: [CandidateCard, TranslocoTestingModule.forRoot({ langs: { uk }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' }, preloadLangs: true })],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideRouter([]),
      { provide: AuthService, useValue: { user: signal({ roles: ['viewer'] }) } },
      { provide: GoogleService, useValue: { calendarConnected: () => of(false) } },
    ],
  });
  TestBed.overrideComponent(CandidateCard, {
    remove: { imports: [InterviewersPanel, OfferPanel, TouchComposer, TouchBody, EvaluationBadge, TasksWidget, ScreeningPanel, AuditHistory, PrivacyActions] },
    // Added after the component's own CandidateCardStore, so the fake wins.
    add: { schemas: [NO_ERRORS_SCHEMA], providers: [{ provide: CandidateCardStore, useValue: store }] },
  });
  const fixture = TestBed.createComponent(CandidateCard);
  fixture.componentRef.setInput('candidateId', 1);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('CandidateCard — career-site CV', () => {
  it('shows a download link with the file name and size for an application with a CV', () => {
    const el = render([app({ cv: { filename: 'cv-synthetic.pdf', size: 48_213, mime: 'application/pdf', uploaded_at: null } })]);
    const link = el.querySelector<HTMLAnchorElement>('.cv a');
    expect(link?.getAttribute('href')).toBe('/api/applications/7/cv');
    expect(link?.hasAttribute('download')).toBe(true);
    expect(link?.textContent).toContain('Резюме: cv-synthetic.pdf');
    expect(el.querySelector('.cv .muted')?.textContent).toContain('47 KB');
  });

  it('shows nothing when the application has no CV', () => {
    const el = render([app({ cv: null }), app({ id: 8 })]);
    expect(el.querySelector('.cv')).toBeNull();
  });

  it('builds the same-origin download URL from the application id', () => {
    expect(applicationCvUrl(42)).toBe('/api/applications/42/cv');
  });
});

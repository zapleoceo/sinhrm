import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RecruitingService, duplicateOf, recruitingErrorKey } from './recruiting.service';
import { toParams } from '../../core/api/http-params';
import { canWriteRecruiting, isRecruitingAdmin } from './recruiting.access';
import { RECRUITING_ERROR_CODES } from './recruiting.model';
import uk from '../../../../public/i18n/uk.json';
import ru from '../../../../public/i18n/ru.json';
import en from '../../../../public/i18n/en.json';

const EMPTY = { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } };

describe('RecruitingService', () => {
  let api: RecruitingService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(RecruitingService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('drops empty params and stringifies numbers', () => {
    const p = toParams({ q: '', status: undefined, page: 2, perPage: 20, x: null });
    expect(p.keys().sort()).toEqual(['page', 'perPage']);
    expect(p.get('perPage')).toBe('20');
  });

  it('lists vacancies and candidates with filters', () => {
    api.vacancies({ status: 'open', q: 'sales' }).subscribe();
    const v = http.expectOne((r) => r.url === '/api/vacancies');
    expect(v.request.params.get('status')).toBe('open');
    v.flush(EMPTY);

    api.vacancies({ active: true }).subscribe();
    const active = http.expectOne((r) => r.url === '/api/vacancies');
    expect(active.request.params.get('active')).toBe('1');
    active.flush(EMPTY);

    api.vacancyText({ section: 'requirements', title: 'Tutor' }).subscribe();
    const text = http.expectOne('/api/vacancy-text');
    expect(text.request.method).toBe('POST');
    text.flush({ data: { status: 'done', request_id: 1, text: 'x', error: null } });

    api.saveVacancyTemplate('Base', { work_format: 'remote' }).subscribe();
    const tpl = http.expectOne('/api/vacancy-templates');
    expect(tpl.request.body).toEqual({ name: 'Base', data: { work_format: 'remote' } });
    tpl.flush({ data: { id: 1, name: 'Base', data: {}, created_at: null } });

    api.candidates({ q: 'ann', source: 'work_ua' }).subscribe();
    const c = http.expectOne((r) => r.url === '/api/candidates');
    expect(c.request.params.get('source')).toBe('work_ua');
    c.flush(EMPTY);
  });

  it('sends screening ranking with pagination and filters, omitting sort when disabled', () => {
    api.candidates({ sort: 'screening_score', vacancy_id: 4, status: 'active', page: 2, perPage: 20 }).subscribe();
    const ranked = http.expectOne((r) => r.url === '/api/candidates');
    expect(ranked.request.params.get('sort')).toBe('screening_score');
    expect(ranked.request.params.get('vacancy_id')).toBe('4');
    expect(ranked.request.params.get('status')).toBe('active');
    expect(ranked.request.params.get('page')).toBe('2');
    expect(ranked.request.params.get('perPage')).toBe('20');
    ranked.flush(EMPTY);
    api.candidates({ sort: undefined, vacancy_id: 4, page: 1 }).subscribe();
    const ordinary = http.expectOne((r) => r.url === '/api/candidates');
    expect(ordinary.request.params.has('sort')).toBe(false);
    expect(ordinary.request.params.get('vacancy_id')).toBe('4');
    ordinary.flush(EMPTY);
  });
  it('sends the timeline filter as a comma list and omits it when empty', () => {
    api.timeline(5, ['call', 'stage'], 2, 30).subscribe();
    const req = http.expectOne((r) => r.url === '/api/candidates/5/timeline');
    expect(req.request.params.get('channel')).toBe('call,stage');
    expect(req.request.params.get('page')).toBe('2');
    req.flush(EMPTY);

    api.timeline(5, []).subscribe();
    const all = http.expectOne((r) => r.url === '/api/candidates/5/timeline');
    expect(all.request.params.has('channel')).toBe(false);
    all.flush(EMPTY);
  });

  it('posts moves, touches, inbox actions and unwraps data', () => {
    let stage = 0;
    api.move(9, { stage_id: 4, reject_reason_id: 1 }).subscribe((a) => (stage = a.stage_id));
    const move = http.expectOne({ method: 'POST', url: '/api/applications/9/move' });
    expect(move.request.body).toEqual({ stage_id: 4, reject_reason_id: 1 });
    move.flush({ data: { stage_id: 4 } });
    expect(stage).toBe(4);

    api.logTouch(3, { channel: 'note', body: 'hi' }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/candidates/3/touchpoints' }).flush({ data: {} });

    api.linkInbox(7, 3).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/inbox/7/link' }).request.body).toEqual({ candidate_id: 3 });
    api.createFromInbox(7, { full_name: 'X' }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/inbox/7/create-candidate' }).flush({ data: {} });
    http.match(() => true).forEach((r) => r.flush({ data: {} }));
  });

  it('loads assignable users and replaces interviewers', () => {
    let names: string[] = [];
    api.assignableUsers('ann').subscribe((list) => (names = list.map((u) => u.name)));
    const u = http.expectOne((r) => r.url === '/api/recruiting/assignable-users');
    expect(u.request.params.get('q')).toBe('ann');
    u.flush({ data: [{ id: 3, name: 'Ann' }] });
    expect(names).toEqual(['Ann']);

    api.setInterviewers(9, [3, 4]).subscribe();
    const p = http.expectOne('/api/applications/9/interviewers');
    expect(p.request.method).toBe('PUT');
    expect(p.request.body).toEqual({ user_ids: [3, 4] });
    p.flush({ data: { id: 9 } });

    api.assignableUsers().subscribe();
    const all = http.expectOne((r) => r.url === '/api/recruiting/assignable-users');
    expect(all.request.params.has('q')).toBe(false);
    all.flush({ data: [] });
  });

  it('requests stale with days and reports with a range', () => {
    api.stale(3).subscribe();
    expect(http.expectOne((r) => r.url === '/api/recruiting/stale').request.params.get('days')).toBe('3');
    api.funnelReport({ from: '2026-09-01', to: '2026-09-30' }, 4).subscribe();
    const f = http.expectOne((r) => r.url === '/api/reports/funnel');
    expect(f.request.params.get('vacancy_id')).toBe('4');
    expect(f.request.params.get('from')).toBe('2026-09-01');
    http.match(() => true).forEach((r) => r.flush({ data: [] }));
  });
});

describe('recruiting error helpers', () => {
  const err = (status: number, body: unknown) => new HttpErrorResponse({ status, error: body });

  it('maps codes, 403 and 422, falls back to generic', () => {
    expect(recruitingErrorKey(err(422, { code: 'reject_reason_required' }))).toBe('recruiting.errors.reject_reason_required');
    expect(recruitingErrorKey(err(403, { message: 'This action is unauthorized.' }))).toBe('recruiting.errors.forbidden');
    expect(recruitingErrorKey(err(409, { code: 'duplicate_candidate', restricted: true }))).toBe('recruiting.errors.duplicate_restricted');
    expect(recruitingErrorKey(err(422, { errors: {} }))).toBe('recruiting.errors.validation');
    expect(recruitingErrorKey(new Error('x'))).toBe('recruiting.errors.generic');
    expect(recruitingErrorKey(err(409, { code: 'duplicate_candidate' }))).toBe('recruiting.errors.duplicate_candidate');
    expect(recruitingErrorKey(err(403, null))).toBe('recruiting.errors.forbidden');
    expect(recruitingErrorKey(err(404, null))).toBe('recruiting.errors.generic');
  });

  it('maps the offer errors (422 offer_too_long with max_bytes, template_not_offer) to their own texts', () => {
    expect(recruitingErrorKey(err(422, { code: 'offer_too_long', max_bytes: 65535 }))).toBe('recruiting.errors.offer_too_long');
    expect(recruitingErrorKey(err(422, { code: 'template_not_offer' }))).toBe('recruiting.errors.template_not_offer');
  });

  it('has a text for every error code in uk, ru and en', () => {
    for (const [lang, dict] of Object.entries({ uk, ru, en })) {
      const errors = (dict as { recruiting: { errors: Record<string, string> } }).recruiting.errors;
      for (const code of RECRUITING_ERROR_CODES) {
        expect(errors[code], `${lang}: recruiting.errors.${code}`).toBeTruthy();
      }
    }
    expect(uk.recruiting.errors.offer_too_long).toContain('64');
  });

  it('extracts the existing candidate of a duplicate', () => {
    expect(duplicateOf(err(409, { code: 'duplicate_candidate', existing_id: 12, matched_by: 'email' }))?.existing_id).toBe(12);
    expect(duplicateOf(err(409, { code: 'already_applied' }))).toBeNull();
    expect(duplicateOf(err(422, { code: 'duplicate_candidate', existing_id: 1 }))).toBeNull();
  });

  it('only viewers are read-only', () => {
    expect(canWriteRecruiting(['viewer'])).toBe(false);
    expect(canWriteRecruiting(['recruiter'])).toBe(true);
    expect(canWriteRecruiting([])).toBe(false);
    expect(canWriteRecruiting(['admin'])).toBe(true);
    expect(canWriteRecruiting(['superadmin'])).toBe(true);
    expect(canWriteRecruiting(['hr_manager', 'employee'])).toBe(false);
  });

  it('only superadmin and admin manage recruiting directories', () => {
    expect(isRecruitingAdmin(['superadmin'])).toBe(true);
    expect(isRecruitingAdmin(['admin'])).toBe(true);
    expect(isRecruitingAdmin(['recruiter', 'hr_manager'])).toBe(false);
  });
});

import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RecruitingService, duplicateOf, recruitingErrorKey, toParams } from './recruiting.service';
import { canWriteRecruiting } from './recruiting.access';

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

    api.candidates({ q: 'ann', source: 'work_ua' }).subscribe();
    const c = http.expectOne((r) => r.url === '/api/candidates');
    expect(c.request.params.get('source')).toBe('work_ua');
    c.flush(EMPTY);
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
  });
});

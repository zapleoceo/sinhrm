import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ScriptsService, scriptsErrorKey } from './scripts.service';
import { emptyContent } from './scripts.model';

describe('ScriptsService', () => {
  let service: ScriptsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(ScriptsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists scripts, with archived on request', () => {
    service.list().subscribe();
    http.expectOne((r) => r.url === '/api/scripts' && !r.params.has('archived')).flush({ data: [] });
    service.list(true).subscribe();
    http.expectOne((r) => r.url === '/api/scripts' && r.params.get('archived') === '1').flush({ data: [] });
  });

  it('creates, saves the draft, publishes and activates', () => {
    service.create('Call', 'call').subscribe();
    const create = http.expectOne({ method: 'POST', url: '/api/scripts' });
    expect(create.request.body).toEqual({ name: 'Call', channel: 'call' });
    create.flush({ data: { id: 1 } });

    service.saveDraft(1, emptyContent()).subscribe();
    const draft = http.expectOne({ method: 'PUT', url: '/api/scripts/1/draft' });
    expect(draft.request.body.next_step_patterns).toEqual({ positive: [], negative: [] });
    draft.flush({ data: {} });

    service.publish(1).subscribe();
    http.expectOne({ method: 'POST', url: '/api/scripts/1/publish' }).flush({ data: {} });
    service.activate(1, 2).subscribe();
    http.expectOne({ method: 'POST', url: '/api/scripts/1/activate/2' }).flush({ data: {} });
  });

  it('unwraps versions with the active id and posts a test', () => {
    let active: number | null = null;
    service.versions(3).subscribe((r) => (active = r.activeVersionId));
    http.expectOne('/api/scripts/3/versions').flush({ data: [], meta: { active_version_id: 9 } });
    expect(active).toBe(9);

    service.test(3, 'hello', 'active').subscribe();
    const test = http.expectOne({ method: 'POST', url: '/api/scripts/3/test' });
    expect(test.request.body).toEqual({ text: 'hello', version: 'active' });
    test.flush({ data: {} });
  });

  it('reads templates, evaluations and tasks', () => {
    service.candidateTemplates(5).subscribe();
    http.expectOne('/api/candidates/5/templates').flush({ data: [] });
    service.evaluation(7).subscribe();
    http.expectOne('/api/touchpoints/7/evaluation').flush({ data: {} });

    service.tasks({ mine: true, due: 'today' }).subscribe();
    http.expectOne((r) => r.url === '/api/tasks' && r.params.get('mine') === '1' && r.params.get('due') === 'today' && !r.params.has('done')).flush({ data: [] });
    service.tasks({ candidate_id: 4 }).subscribe();
    http.expectOne((r) => r.url === '/api/tasks' && r.params.get('candidate_id') === '4' && !r.params.has('mine')).flush({ data: [] });

    service.setTaskDone(8, true).subscribe();
    const done = http.expectOne({ method: 'PATCH', url: '/api/tasks/8' });
    expect(done.request.body).toEqual({ done: true });
    done.flush({ data: {} });
  });

  it('maps errors to i18n keys', () => {
    expect(scriptsErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'no_draft' } }))).toBe('scripts.errors.no_draft');
    expect(scriptsErrorKey(new HttpErrorResponse({ status: 422, error: { errors: {} } }))).toBe('scripts.errors.validation');
    expect(scriptsErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('scripts.errors.forbidden');
    expect(scriptsErrorKey(new Error('x'))).toBe('scripts.errors.generic');
  });
});

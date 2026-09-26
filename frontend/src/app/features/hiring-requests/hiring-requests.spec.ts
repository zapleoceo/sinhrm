import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { missingFields, salaryRange, statusTone, stepIcon } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';
import { moveItem } from './hiring-settings.page';

describe('HiringRequestsService', () => {
  let api: HiringRequestsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(HiringRequestsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('calls the hiring request endpoints', () => {
    api.list({ status: 'pending', mine: true }).subscribe();
    http.expectOne((r) => r.url === '/api/hiring-requests' && r.params.get('status') === 'pending' && r.params.get('mine') === '1').flush({ data: [] });
    api.list().subscribe();
    http.expectOne((r) => r.url === '/api/hiring-requests' && r.params.keys().length === 0).flush({ data: [] });
    api.inbox().subscribe();
    http.expectOne('/api/hiring-requests/inbox').flush({ data: [] });
    api.meta().subscribe();
    http.expectOne('/api/hiring-requests/meta').flush({ data: {} });

    api.create({ title: 'QA', branch_id: 1, reason: 'new_position', submit: true }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/hiring-requests' }).request.body).toEqual({ title: 'QA', branch_id: 1, reason: 'new_position', submit: true });
    api.update(3, { headcount: 2 }).subscribe();
    http.expectOne({ method: 'PATCH', url: '/api/hiring-requests/3' }).flush({ data: {} });
    api.submit(3).subscribe();
    http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/submit' }).flush({ data: {} });
    api.decide(3, false, 'No budget').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/decision' }).request.body).toEqual({ decision: 'reject', comment: 'No budget', recruiter_id: undefined });
    api.decide(3, true, null, 9).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/decision' }).request.body).toEqual({ decision: 'approve', comment: null, recruiter_id: 9 });
    api.cancel(3).subscribe();
    http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/cancel' }).flush({ data: {} });
    api.close(3).subscribe();
    http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/close' }).flush({ data: {} });
    api.createVacancy(3, null).subscribe();
    http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/vacancy' }).flush({ data: {} });
    api.linkVacancy(3, 7).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/hiring-requests/3/link-vacancy' }).request.body).toEqual({ vacancy_id: 7 });
    api.settings().subscribe();
    http.expectOne({ method: 'GET', url: '/api/hiring-requests/settings' }).flush({ data: {} });
    api.saveSettings({ auto_vacancy: false }).subscribe();
    expect(http.expectOne({ method: 'PUT', url: '/api/hiring-requests/settings' }).request.body).toEqual({ auto_vacancy: false });
  });

  it('maps errors', () => {
    expect(hiringErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'required_fields' } }))).toBe('hiring.errors.required_fields');
    expect(hiringErrorKey(new HttpErrorResponse({ status: 404 }))).toBe('hiring.errors.not_found');
  });
});

describe('hiring helpers', () => {
  it('tones, icons, salary, required fields, reorder', () => {
    expect(statusTone('in_progress')).toBe('success');
    expect(statusTone('rejected')).toBe('danger');
    expect(statusTone('draft')).toBe('neutral');
    expect(stepIcon({ status: 'pending', overdue: true })).toBe('alarm');
    expect(stepIcon({ status: 'approved', overdue: false })).toBe('check_circle');
    expect(salaryRange({ salary_min: 1000, salary_max: null, currency: 'USD' })).toMatch(/^≥ 1\D?000 USD$/);
    expect(salaryRange({ salary_min: null, salary_max: null, currency: null })).toBe('');
    const fields = [
      { key: 'a', label: 'A', type: 'text' as const, required: true },
      { key: 'b', label: 'B', type: 'checkbox' as const, required: true },
      { key: 'c', label: 'C', type: 'text' as const, required: false },
    ];
    expect(missingFields(fields, { a: 'x', b: false })).toEqual(['b']);
    expect(moveItem(['x', 'y', 'z'], 0, 1)).toEqual(['y', 'x', 'z']);
    expect(moveItem(['x', 'y'], 0, -1)).toEqual(['x', 'y']);
  });
});

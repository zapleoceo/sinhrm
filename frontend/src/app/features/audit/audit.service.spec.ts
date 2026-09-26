import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AuditService } from './audit.service';
import { AuditOptions, AuditPage } from './audit.model';

const PAGE: AuditPage = { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } };

describe('AuditService', () => {
  let service: AuditService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(AuditService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists with only the filled filters', () => {
    service.list({ user_id: 3, entity_type: 'employee', action: undefined, from: '2026-09-01', to: '', page: 2, perPage: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/audit');
    expect(req.request.params.keys().sort()).toEqual(['entity_type', 'from', 'page', 'perPage', 'user_id']);
    expect(req.request.params.get('user_id')).toBe('3');
    req.flush(PAGE);
  });

  it('loads filter options', () => {
    let got: AuditOptions | undefined;
    service.options().subscribe((o) => (got = o));
    const body: AuditOptions = { entity_types: ['user'], actions: ['created'], users: [{ id: 1, name: 'A' }] };
    http.expectOne({ method: 'GET', url: '/api/audit/options' }).flush(body);
    expect(got).toEqual(body);
  });

  it('loads employee and candidate history pages', () => {
    service.employeeHistory(5, { page: 1, perPage: 10 }).subscribe();
    const a = http.expectOne((r) => r.url === '/api/people/5/history');
    expect(a.request.params.get('perPage')).toBe('10');
    a.flush(PAGE);
    service.candidateHistory(9, { page: 2, perPage: 10 }).subscribe();
    const b = http.expectOne((r) => r.url === '/api/candidates/9/history');
    expect(b.request.params.get('page')).toBe('2');
    b.flush(PAGE);
  });
});

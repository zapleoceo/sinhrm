import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DeskCase, slaState } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';

const sla = (over: Partial<DeskCase['sla']> = {}): DeskCase['sla'] => ({
  first_response_due: null,
  resolve_due: null,
  first_response_breached: false,
  resolve_breached: false,
  ...over,
});

describe('DeskService', () => {
  let api: DeskService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(DeskService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('calls the desk endpoints', () => {
    api.categories().subscribe();
    http.expectOne((r) => r.url === '/api/desk/categories' && !r.params.has('all')).flush({ data: [] });
    api.categories(true).subscribe();
    http.expectOne((r) => r.url === '/api/desk/categories' && r.params.get('all') === '1').flush({ data: [] });
    api.saveCategory(null, { name: 'Pay' }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/desk/categories' }).request.body).toEqual({ name: 'Pay' });
    api.saveCategory(3, { active: false }).subscribe();
    http.expectOne({ method: 'PATCH', url: '/api/desk/categories/3' }).flush({ data: {} });

    api.mine().subscribe();
    http.expectOne('/api/desk/cases/mine').flush({ data: [] });
    api.queue({ open: true }).subscribe();
    http.expectOne((r) => r.url === '/api/desk/cases' && r.params.get('open') === '1' && !r.params.has('status')).flush({ data: [] });
    api.queue({ status: 'waiting' }).subscribe();
    http.expectOne((r) => r.url === '/api/desk/cases' && r.params.get('status') === 'waiting').flush({ data: [] });

    api.open({ category_id: 1, subject: 'S', body: 'B' }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/desk/cases' }).request.body).toEqual({ category_id: 1, subject: 'S', body: 'B' });
    api.update(5, { status: 'resolved' }).subscribe();
    expect(http.expectOne({ method: 'PATCH', url: '/api/desk/cases/5' }).request.body).toEqual({ status: 'resolved' });
    api.comment(5, { body: 'x', internal: true, article_id: 2 }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/desk/cases/5/comments' }).request.body).toEqual({ body: 'x', internal: true, article_id: 2 });
    api.attach(5, new File(['x'], 'a.pdf')).subscribe();
    const up = http.expectOne({ method: 'POST', url: '/api/desk/cases/5/attachments' });
    expect(up.request.body instanceof FormData && up.request.body.has('file')).toBe(true);
    expect(api.attachmentUrl(5, 7)).toBe('/api/desk/cases/5/attachments/7');
  });

  it('maps errors', () => {
    expect(deskErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'no_employee' } }))).toBe('desk.errors.no_employee');
    expect(deskErrorKey(new HttpErrorResponse({ status: 404 }))).toBe('desk.errors.not_found');
    expect(deskErrorKey(new HttpErrorResponse({ status: 500 }))).toBe('common.error');
  });
});

describe('slaState', () => {
  it('is breached, due or ok', () => {
    expect(slaState({ status: 'new', sla: sla({ first_response_breached: true }) })).toBe('breached');
    expect(slaState({ status: 'new', sla: sla({ resolve_due: '2026-10-06T09:00:00Z' }) })).toBe('due');
    expect(slaState({ status: 'resolved', sla: sla({ resolve_due: '2026-10-06T09:00:00Z' }) })).toBe('ok');
    expect(slaState({ status: 'new', sla: sla() })).toBe('ok');
  });
});

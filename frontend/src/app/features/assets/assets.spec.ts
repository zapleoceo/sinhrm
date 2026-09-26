import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AssetsService, assetsErrorKey } from './assets.service';

describe('AssetsService', () => {
  let api: AssetsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(AssetsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('manages the inventory and moves assets', () => {
    api.types().subscribe();
    http.expectOne('/api/assets/types').flush({ data: [] });
    api.createType('Laptop').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/assets/types' }).request.body).toEqual({ name: 'Laptop' });
    api.list({ status: 'assigned', q: 'mac' }).subscribe();
    http.expectOne((r) => r.url === '/api/assets' && r.params.get('status') === 'assigned' && r.params.get('q') === 'mac').flush({ data: [] });
    api.save(null, { inventory_number: 'INV-1', name: 'Laptop' }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/assets' }).flush({ data: {} });
    api.save(2, { status: 'repair' }).subscribe();
    http.expectOne({ method: 'PATCH', url: '/api/assets/2' }).flush({ data: {} });
    api.get(2).subscribe();
    http.expectOne('/api/assets/2').flush({ data: {} });

    api.assign(2, 9, '2026-10-01', '').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/assets/2/assign' }).request.body).toEqual({ employee_id: 9, date: '2026-10-01', condition: undefined });
    api.return(2, 'in_stock', '', 'ok').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/assets/2/return' }).request.body).toEqual({ status: 'in_stock', date: undefined, condition: 'ok' });
    api.ofEmployee(9).subscribe();
    http.expectOne('/api/assets/employee/9').flush({ data: [] });
  });

  it('maps errors', () => {
    expect(assetsErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'inventory_number_taken' } }))).toBe('assets.errors.inventory_number_taken');
    expect(assetsErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('assets.errors.forbidden');
  });
});

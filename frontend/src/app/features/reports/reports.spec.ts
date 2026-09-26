import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { barPercent, cleanSpec, columnMax, filterParams } from './reports.model';
import { ReportsService } from './reports.service';

describe('ReportsService', () => {
  let api: ReportsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(ReportsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('runs catalog reports and exports CSV as a blob', () => {
    api.catalog().subscribe();
    http.expectOne('/api/reports/catalog').flush({ data: [] });
    api.run('headcount', { to: '2026-10-01', branch_id: null }).subscribe();
    http.expectOne((r) => r.url === '/api/reports/catalog/headcount' && r.params.get('to') === '2026-10-01' && !r.params.has('branch_id')).flush({ data: {} });
    api.csv('headcount', {}).subscribe();
    const csv = http.expectOne('/api/reports/catalog/headcount/csv');
    expect(csv.request.responseType).toBe('blob');
    csv.flush(new Blob(['a']));
  });

  it('builds, exports and saves builder reports', () => {
    const spec = { dataset: 'employees', columns: ['full_name'], filters: [] };
    api.datasets().subscribe();
    http.expectOne('/api/reports/builder/datasets').flush({ data: [] });
    api.build(spec).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/reports/builder/run' }).request.body).toEqual(spec);
    api.buildCsv(spec).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/reports/builder/csv' }).request.responseType).toBe('blob');
    api.saved().subscribe();
    http.expectOne('/api/reports/saved').flush({ data: [] });
    api.save(null, 'Mine', 'builder', spec).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/reports/saved' }).request.body).toEqual({ name: 'Mine', kind: 'builder', definition: spec });
    api.save(4, 'Mine', 'catalog', { key: 'age', filters: {} }).subscribe();
    http.expectOne({ method: 'PUT', url: '/api/reports/saved/4' }).flush({ data: {} });
    api.remove(4).subscribe();
    http.expectOne({ method: 'DELETE', url: '/api/reports/saved/4' }).flush(null);
    api.savedCsv(4).subscribe();
    http.expectOne((r) => r.url === '/api/reports/saved/4/run' && r.params.get('format') === 'csv').flush(new Blob(['a']));
  });
});

describe('report helpers', () => {
  it('computes bars and cleans specs', () => {
    expect(barPercent(5, 10)).toBe(50);
    expect(barPercent(null, 10)).toBe(0);
    expect(barPercent(-3, 10)).toBe(0);
    expect(columnMax([{ v: 3 }, { v: '7' }, { v: null }], 'v')).toBe(7);
    expect(filterParams({ from: '2026-01-01', to: '', branch_id: null, weeks: 4 })).toEqual({ from: '2026-01-01', weeks: '4' });
    expect(
      cleanSpec({ dataset: 'employees', columns: ['full_name'], filters: [{ column: '', op: 'eq', value: 'x' }], group_by: 'status', aggregate: null }),
    ).toEqual({ dataset: 'employees', columns: [], filters: [], group_by: 'status', aggregate: { fn: 'count' } });
    expect(cleanSpec({ dataset: 'assets', columns: ['name'], filters: [], group_by: null, aggregate: { fn: 'sum', column: 'cost' } })).toEqual({
      dataset: 'assets',
      columns: ['name'],
      filters: [],
      group_by: null,
      aggregate: null,
    });
  });
});

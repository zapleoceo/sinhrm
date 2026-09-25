import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DirectoryService, MAX_PER_PAGE, directoryErrorKey } from './directory.service';
import { DictionaryItem, ImportReport } from './directory.model';

const ITEM: DictionaryItem = {
  id: 3,
  external_id: null,
  name: 'Branch A',
  status: 'active',
  created_at: null,
  updated_at: null,
  city_id: null,
  city: null,
};

const EMPTY_PAGE = { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } };

describe('DirectoryService', () => {
  let service: DirectoryService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(DirectoryService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists a dictionary with only the filled params', () => {
    service.list('positions', { q: 'ana', status: undefined, page: 2, perPage: 50 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/directory/positions');
    expect(req.request.params.keys().sort()).toEqual(['page', 'perPage', 'q']);
    req.flush(EMPTY_PAGE);
  });

  it('loads active items for pickers with the max page size', () => {
    let items: DictionaryItem[] = [];
    service.active('branches').subscribe((list) => (items = list));
    const req = http.expectOne((r) => r.url === '/api/directory/branches');
    expect(req.request.params.get('status')).toBe('active');
    expect(req.request.params.get('perPage')).toBe(String(MAX_PER_PAGE));
    req.flush({ ...EMPTY_PAGE, data: [ITEM] });
    expect(items).toEqual([ITEM]);
  });

  it('creates, updates and unwraps the resource', () => {
    let created: DictionaryItem | undefined;
    service.create('branches', { name: 'Branch A' }).subscribe((i) => (created = i));
    http.expectOne({ method: 'POST', url: '/api/directory/branches' }).flush({ data: ITEM });
    expect(created).toEqual(ITEM);

    service.update('cities', 7, { status: 'disabled' }).subscribe();
    const req = http.expectOne({ method: 'PATCH', url: '/api/directory/cities/7' });
    expect(req.request.body).toEqual({ status: 'disabled' });
    req.flush({ data: { ...ITEM, id: 7, status: 'disabled' } });
  });

  it('runs the import and returns the report', () => {
    const counts = { created: 1, updated: 0, skipped: 0 };
    const report: ImportReport = { branches: counts, cities: counts, departments: counts, positions: counts, total: counts };
    let got: ImportReport | undefined;
    service.import().subscribe((r) => (got = r));
    http.expectOne({ method: 'POST', url: '/api/directory/import' }).flush({ data: report });
    expect(got).toEqual(report);
  });
});

describe('directoryErrorKey', () => {
  const err = (status: number, body: unknown) => new HttpErrorResponse({ status, error: body });

  it('maps known codes', () => {
    expect(directoryErrorKey(err(422, { code: 'integration_not_configured' }))).toBe('directory.errors.integration_not_configured');
    expect(directoryErrorKey(err(502, { code: 'sintegrum_unauthorized' }))).toBe('directory.errors.sintegrum_unauthorized');
  });

  it('maps validation and falls back to generic', () => {
    expect(directoryErrorKey(err(422, { message: 'x', errors: {} }))).toBe('directory.errors.validation');
    expect(directoryErrorKey(err(502, { code: 'sintegrum_http_500' }))).toBe('directory.errors.generic');
    expect(directoryErrorKey(new Error('x'))).toBe('directory.errors.generic');
  });
});

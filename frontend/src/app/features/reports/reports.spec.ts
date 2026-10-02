import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { ReportTable } from './report-table';
import { Row, barPercent, cleanSpec, columnMax, filterParams } from './reports.model';
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

describe('ReportTable total row', () => {
  const columns = [
    { key: 'source', type: 'string' as const },
    { key: 'candidates', type: 'number' as const, total: 'sum' as const },
    { key: 'hire_rate_pct', type: 'percent' as const, total: 'ratio' as const },
    { key: 'median', type: 'number' as const },
  ];

  function footer(rows: Row[], totals: Row | null): string[] | null {
    TestBed.configureTestingModule({
      imports: [ReportTable, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([])],
    });
    const fixture = TestBed.createComponent(ReportTable);
    fixture.componentRef.setInput('columns', columns);
    fixture.componentRef.setInput('rows', rows);
    fixture.componentRef.setInput('totals', totals);
    fixture.detectChanges();
    const tr = (fixture.nativeElement as HTMLElement).querySelector('tfoot tr');
    return tr ? [...tr.querySelectorAll('td')].map((td) => td.textContent?.trim() ?? '') : null;
  }

  it('renders the backend totals with «—» for columns without a total', () => {
    const rows = [
      { source: 'a', candidates: 1000, hire_rate_pct: 10, median: 5 },
      { source: 'b', candidates: 234, hire_rate_pct: 20, median: 6 },
    ];
    expect(footer(rows, { source: null, candidates: 1234, hire_rate_pct: 12.5, median: null })).toEqual(['reports.total', '1,234', '12.5%', '—']);
  });

  it('keeps the label in the first cell when that column is summable', () => {
    const rows = [
      { source: 'a', candidates: 1, hire_rate_pct: 1, median: 1 },
      { source: 'b', candidates: 2, hire_rate_pct: 1, median: 1 },
    ];
    TestBed.resetTestingModule();
    expect(footer(rows, { source: 'x', candidates: 3, hire_rate_pct: null, median: null })?.[0].replace(/\s+/g, ' ')).toBe('reports.total x');
  });

  it('has no total row for a single row or without totals', () => {
    expect(footer([{ source: 'a', candidates: 1, hire_rate_pct: 1, median: 1 }], { source: null, candidates: 1, hire_rate_pct: 1, median: null })).toBeNull();
    TestBed.resetTestingModule();
    expect(footer([{ source: 'a' }, { source: 'b' }], null)).toBeNull();
  });
});

describe('ReportTable headers: sort and filter in the browser, «Разом» stays at the bottom', () => {
  const columns = [
    { key: 'source', type: 'string' as const },
    { key: 'candidates', type: 'number' as const, total: 'sum' as const },
    { key: 'hired_on', type: 'date' as const },
  ];
  const rows: Row[] = [
    { source: 'Work.ua', candidates: 5, hired_on: '2026-09-02' },
    { source: 'robota', candidates: 12, hired_on: null },
    { source: 'Djinni', candidates: null, hired_on: '2026-09-20' },
  ];
  const totals: Row = { source: null, candidates: 17, hired_on: null };

  async function render(url: string): Promise<{ el: HTMLElement; router: Router; detect: () => Promise<void> }> {
    TestBed.configureTestingModule({
      imports: [ReportTable, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([])],
    });
    const router = TestBed.inject(Router);
    await router.navigateByUrl(url);
    const fixture = TestBed.createComponent(ReportTable);
    fixture.componentRef.setInput('columns', columns);
    fixture.componentRef.setInput('rows', rows);
    fixture.componentRef.setInput('totals', totals);
    fixture.componentRef.setInput('chart', { label: 'source', value: 'candidates' });
    fixture.detectChanges();
    const detect = async (): Promise<void> => {
      await fixture.whenStable();
      fixture.detectChanges();
    };
    return { el: fixture.nativeElement as HTMLElement, router, detect };
  }

  const firstColumn = (el: HTMLElement): string[] => [...el.querySelectorAll('tbody tr')].map((tr) => tr.querySelector('td')?.textContent?.trim() ?? '');
  const footerLabel = (el: HTMLElement): string => el.querySelector('tfoot td')?.textContent?.trim() ?? '';

  it('every column header sorts and filters by its type; the API order shows until a click', async () => {
    const { el } = await render('/');
    const headers = [...el.querySelectorAll('thead th')];
    expect(headers.map((th) => th.getAttribute('aria-sort'))).toEqual(['none', 'none', 'none']);
    expect(headers.every((th) => th.querySelector('button.title') && th.querySelector('button.filter'))).toBe(true);
    expect(firstColumn(el)).toEqual(['Work.ua', 'robota', 'Djinni']);
  });

  it('a click on a title sorts the body (empty cells last) and writes r_sort to the URL; the total row does not move', async () => {
    const { el, router, detect } = await render('/?from=2026-09-01');
    const candidates = el.querySelectorAll('thead th')[1];
    (candidates.querySelector('button.title') as HTMLButtonElement).click();
    await detect();
    expect(router.url).toBe('/?from=2026-09-01&r_sort=candidates&r_dir=asc');
    expect(candidates.getAttribute('aria-sort')).toBe('ascending');
    expect(firstColumn(el)).toEqual(['Work.ua', 'robota', 'Djinni']);
    (candidates.querySelector('button.title') as HTMLButtonElement).click();
    await detect();
    expect(firstColumn(el)).toEqual(['robota', 'Work.ua', 'Djinni']);
    // The chart follows the table order; «Разом» stays the only footer row, after the body.
    expect([...el.querySelectorAll('.bar-row .label')].map((l) => l.textContent?.trim())).toEqual(['robota', 'Work.ua', 'Djinni']);
    expect(el.querySelectorAll('tfoot tr').length).toBe(1);
    expect(footerLabel(el)).toBe('reports.total');
  });

  it('URL filters narrow the rows; the total row then says it covers the whole report', async () => {
    const { el } = await render('/?r_sort=source&r_dir=asc&r_hired_on_from=2026-09-10');
    expect(firstColumn(el)).toEqual(['Djinni']);
    expect(footerLabel(el)).toBe('reports.totalAll');
    TestBed.resetTestingModule();
    const none = await render('/?r_source=zzz');
    expect(firstColumn(none.el)).toEqual(['reports.noMatches']);
    TestBed.resetTestingModule();
    const sorted = await render('/?r_sort=source&r_dir=asc');
    expect(firstColumn(sorted.el)).toEqual(['Djinni', 'robota', 'Work.ua']);
  });
});

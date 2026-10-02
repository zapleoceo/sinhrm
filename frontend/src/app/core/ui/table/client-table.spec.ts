import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, convertToParamMap, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { ClientColumns, ClientTable, clientFilterParams, clientQueryFromParams, compareCells, filterRows, sortRows } from './client-table';
import { TableUrlState } from './table-url-state';

interface Row {
  id: number;
  name: string | null;
  status: 'new' | 'done';
  hours: number | null;
  day: string | null;
}

const COLUMNS: ClientColumns<Row> = {
  name: { sort: (r) => r.name, filter: 'text', match: (r) => r.name },
  status: { sort: (r) => ['new', 'done'].indexOf(r.status), filter: 'select', values: ['new', 'done'], match: (r) => r.status },
  hours: { sort: (r) => r.hours, filter: 'range', match: (r) => r.hours },
  day: { sort: (r) => r.day, filter: 'range', match: (r) => r.day },
  /** Server filter: parsed from the URL, never applied on the page. */
  owner: { filter: 'select' },
};

const ROWS: Row[] = [
  { id: 1, name: 'Ярема', status: 'done', hours: 8, day: '2026-09-28' },
  { id: 2, name: null, status: 'new', hours: null, day: null },
  { id: 3, name: 'Анна', status: 'new', hours: 40, day: '2026-10-01T09:00:00Z' },
  { id: 4, name: 'Єва', status: 'done', hours: 8, day: '2026-09-30' },
  { id: 5, name: 'пункт 10', status: 'new', hours: 0, day: '2026-10-02' },
  { id: 6, name: 'пункт 9', status: 'new', hours: 2.5, day: '2026-10-05' },
];
const ids = (rows: Row[]) => rows.map((r) => r.id);

describe('client table: sort and filter on the page', () => {
  it('reads the URL defensively: unknown sort, values outside the list and junk ranges are dropped', () => {
    const q = clientQueryFromParams(
      convertToParamMap({ sort: 'hours', dir: 'desc', name: '  Ann ', status: 'deleted', hours_from: '2', hours_to: 'abc', day_to: '2026-10-01', owner: '7' }),
      COLUMNS,
    );
    expect(q.sort).toEqual({ key: 'hours', dir: 'desc' });
    expect(q.filters).toEqual({ name: 'Ann', hours: { from: '2', to: null }, day: { from: null, to: '2026-10-01' }, owner: '7' });
    expect(clientQueryFromParams(convertToParamMap({ sort: 'owner' }), COLUMNS).sort).toBeNull(); // not sortable
    expect(clientQueryFromParams(convertToParamMap({ sort: 'id; drop' }), COLUMNS).sort).toBeNull();
    expect(clientQueryFromParams(convertToParamMap({ name: 'x'.repeat(300) }), COLUMNS).filters['name']).toHaveLength(100);
  });

  it('writes filters to the URL: text/select as one param, range as _from/_to, empty removes', () => {
    expect(clientFilterParams(COLUMNS, 'name', ' Ann ')).toEqual({ name: 'Ann' });
    expect(clientFilterParams(COLUMNS, 'name', null)).toEqual({ name: null });
    expect(clientFilterParams(COLUMNS, 'hours', { from: '1', to: null })).toEqual({ hours_from: '1', hours_to: null });
    expect(clientFilterParams(COLUMNS, 'hours', null)).toEqual({ hours_from: null, hours_to: null });
  });

  it('compares text in the interface language with natural numbers', () => {
    expect(compareCells('Єва', 'Ярема', 'uk')).toBeLessThan(0);
    expect(compareCells('Анна', 'Єва', 'uk')).toBeLessThan(0);
    expect(compareCells('пункт 9', 'пункт 10', 'uk')).toBeLessThan(0);
    expect(compareCells(2, 10, 'uk')).toBeLessThan(0);
  });

  it('sorts both ways with empty cells last and ties in the API order', () => {
    expect(ids(sortRows(ROWS, { key: 'name', dir: 'asc' }, COLUMNS, 'uk'))).toEqual([3, 4, 6, 5, 1, 2]);
    expect(ids(sortRows(ROWS, { key: 'name', dir: 'desc' }, COLUMNS, 'uk'))).toEqual([1, 5, 6, 4, 3, 2]);
    expect(ids(sortRows(ROWS, { key: 'hours', dir: 'asc' }, COLUMNS, 'uk'))).toEqual([5, 6, 1, 4, 3, 2]);
    expect(ids(sortRows(ROWS, { key: 'hours', dir: 'desc' }, COLUMNS, 'uk'))).toEqual([3, 1, 4, 6, 5, 2]);
    expect(ids(sortRows(ROWS, null, COLUMNS, 'uk'))).toEqual([1, 2, 3, 4, 5, 6]);
    expect(ids(sortRows(ROWS, { key: 'owner', dir: 'asc' }, COLUMNS, 'uk'))).toEqual([1, 2, 3, 4, 5, 6]);
  });

  it('filters: text contains (any case), select equals, number and date ranges inclusive; server filters skipped', () => {
    expect(ids(filterRows(ROWS, { name: 'ПУНКТ' }, COLUMNS, 'uk'))).toEqual([5, 6]);
    expect(ids(filterRows(ROWS, { status: 'done' }, COLUMNS, 'uk'))).toEqual([1, 4]);
    expect(ids(filterRows(ROWS, { hours: { from: '0', to: '8' } }, COLUMNS, 'uk'))).toEqual([1, 4, 5, 6]);
    expect(ids(filterRows(ROWS, { hours: { from: '10', to: null } }, COLUMNS, 'uk'))).toEqual([3]);
    expect(ids(filterRows(ROWS, { day: { from: '2026-09-30', to: '2026-10-01' } }, COLUMNS, 'uk'))).toEqual([3, 4]);
    expect(ids(filterRows(ROWS, { owner: '7', status: 'new' }, COLUMNS, 'uk'))).toEqual([2, 3, 5, 6]);
    expect(ids(filterRows(ROWS, {}, COLUMNS, 'uk'))).toEqual([1, 2, 3, 4, 5, 6]);
  });
});

@Component({ template: '', providers: [TableUrlState] })
class HostPage {
  readonly items = signal<Row[]>(ROWS);
  readonly queries: unknown[] = [];
  readonly table = new ClientTable(COLUMNS, { key: 'name', dir: 'asc' }, (q) => this.queries.push(q));
  readonly rows = this.table.rows(this.items);
}

describe('ClientTable: state in the URL', () => {
  let harness: RouterTestingHarness;
  let page: HostPage;
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([{ path: 'list', component: HostPage }])],
    });
    harness = await RouterTestingHarness.create();
    page = await harness.navigateByUrl('/list?status=new&page=3', HostPage);
  });

  it('applies the URL on open: default sort shown, filter applied, callback called once', () => {
    expect(page.table.sort()).toEqual({ key: 'name', dir: 'asc' });
    expect(page.table.filter('status')).toBe('new');
    expect(page.table.filtered()).toBe(true);
    expect(ids(page.rows())).toEqual([3, 6, 5, 2]);
    expect(page.queries).toHaveLength(1);
  });

  it('sort and filters go to the URL (page dropped), the rows follow', async () => {
    page.table.setSort({ key: 'hours', dir: 'desc' });
    await harness.fixture.whenStable();
    expect(url().searchParams.get('sort')).toBe('hours');
    expect(url().searchParams.get('dir')).toBe('desc');
    expect(url().searchParams.has('page')).toBe(false);
    expect(ids(page.rows())).toEqual([3, 6, 5, 2]);

    page.table.setFilter('hours', { from: '1', to: null });
    await harness.fixture.whenStable();
    expect(url().searchParams.get('hours_from')).toBe('1');
    expect(ids(page.rows())).toEqual([3, 6]);

    page.table.setFilter('status', null);
    await harness.fixture.whenStable();
    expect(url().searchParams.has('status')).toBe(false);
    expect(ids(page.rows())).toEqual([3, 1, 4, 6]);
  });
});

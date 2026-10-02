import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, convertToParamMap, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import {
  ClientColumn,
  ClientTable,
  applyClientTable,
  clientStateFromParams,
  distinctValues,
  matchesFilter,
  sortRows,
  translatedSelect,
} from './client-table';
import { ColumnHeader } from './column-header';
import { TableSortDirective } from './table-sort.directive';
import { prefixed, sortFromParams, sortToParams } from './table-state';
import { TableUrlState } from './table-url-state';

interface R {
  id: number;
  name: string | null;
  n: number | null;
  day: string | null;
  kind: string;
}

const rows: R[] = [
  { id: 1, name: 'Ярема', n: 10, day: '2026-03-01', kind: 'a' },
  { id: 2, name: 'Антон', n: 2, day: null, kind: 'b' },
  { id: 3, name: null, n: null, day: '2026-01-15', kind: 'a' },
  { id: 4, name: 'Єва', n: 2, day: '2026-02-10T08:00:00Z', kind: 'b' },
  { id: 5, name: 'антон', n: 33, day: '2026-02-11', kind: 'a' },
];
const ids = (list: R[]): number[] => list.map((r) => r.id);

const columns: ClientColumn<R>[] = [
  { key: 'name', value: (r) => r.name, filter: 'text' },
  { key: 'n', value: (r) => r.n, filter: 'number' },
  { key: 'day', value: (r) => r.day, filter: 'date' },
  { key: 'kind', value: (r) => r.kind, filter: 'select' },
  { key: 'only', value: (r) => r.id, sortable: false },
];

describe('client table helpers', () => {
  it('sorts by the interface language with empties last in both directions and keeps ties in API order', () => {
    // Ukrainian alphabet: А … Є … Я; case does not split equal names, and ties (2, 5) keep the API order.
    expect(ids(sortRows(rows, (r) => r.name, 'asc', 'uk'))).toEqual([2, 5, 4, 1, 3]);
    expect(ids(sortRows(rows, (r) => r.name, 'desc', 'uk'))).toEqual([1, 4, 2, 5, 3]);
    // Numbers as numbers (2 < 10 < 33), not as text; the equal 2s keep the order 2, 4.
    expect(ids(sortRows(rows, (r) => r.n, 'asc', 'uk'))).toEqual([2, 4, 1, 5, 3]);
    expect(ids(sortRows(rows, (r) => r.n, 'desc', 'uk'))).toEqual([5, 1, 2, 4, 3]);
    expect(ids(sortRows(rows, (r) => r.day, 'desc', 'uk'))).toEqual([1, 5, 4, 3, 2]);
    expect(rows.map((r) => r.id)).toEqual([1, 2, 3, 4, 5]); // the input is not mutated
  });

  it('matches header filters: text contains any case, select equals, ranges are inclusive, empties fail', () => {
    expect(matchesFilter('Антон', 'text', 'НТО')).toBe(true);
    expect(matchesFilter('Антон', 'text', 'x')).toBe(false);
    expect(matchesFilter(null, 'text', 'a')).toBe(false);
    expect(matchesFilter(null, 'text', null)).toBe(true);
    expect(matchesFilter('ab', 'select', 'a')).toBe(false);
    expect(matchesFilter(true, 'select', 'true')).toBe(true);
    expect(matchesFilter(5, 'number', { from: '5', to: '5' })).toBe(true);
    expect(matchesFilter(5, 'number', { from: '6', to: null })).toBe(false);
    expect(matchesFilter(5, 'number', { from: 'abc', to: null })).toBe(true); // junk bound is ignored
    expect(matchesFilter('2026-02-10T23:59:00Z', 'date', { from: null, to: '2026-02-10' })).toBe(true);
    expect(matchesFilter('2026-02-11', 'date', { from: null, to: '2026-02-10' })).toBe(false);
  });

  it('filters then sorts; a non-sortable or unknown column keeps the API order', () => {
    const filters = { name: 'а', n: { from: '2', to: '20' } };
    expect(ids(applyClientTable(rows, columns, { key: 'n', dir: 'desc' }, filters, 'uk'))).toEqual([1, 2, 4]);
    expect(ids(applyClientTable(rows, columns, { key: 'only', dir: 'desc' }, {}, 'uk'))).toEqual([1, 2, 3, 4, 5]);
    expect(ids(applyClientTable(rows, columns, null, { kind: 'b' }, 'uk'))).toEqual([2, 4]);
  });

  it('reads the state from prefixed URL params and drops unknown sort keys', () => {
    const params = convertToParamMap({ t_sort: 'n', t_dir: 'desc', t_name: ' ан ', t_day_from: '2026-02-01', sort: 'name', t_kind: '' });
    expect(clientStateFromParams(params, columns, 't')).toEqual({
      sort: { key: 'n', dir: 'desc' },
      filters: { name: 'ан', n: null, day: { from: '2026-02-01', to: null }, kind: null },
    });
    expect(clientStateFromParams(convertToParamMap({ t_sort: 'only' }), columns, 't').sort).toBeNull();
    expect(clientStateFromParams(convertToParamMap({ sort: 'name' }), columns).sort).toEqual({ key: 'name', dir: 'asc' });
  });

  it('prefixes sort params only when asked (the unprefixed form stays as before)', () => {
    expect(prefixed(undefined, 'sort')).toBe('sort');
    expect(prefixed('src', 'sort')).toBe('src_sort');
    expect(sortToParams({ key: 'count', dir: 'desc' }, 'src')).toEqual({ src_sort: 'count', src_dir: 'desc' });
    expect(sortFromParams(convertToParamMap({ src_sort: 'count', src_dir: 'desc' }), ['count'], 'src')).toEqual({ key: 'count', dir: 'desc' });
    expect(sortFromParams(convertToParamMap({ src_sort: 'count' }), ['count'])).toBeNull();
  });

  it('lists distinct non-empty values in first-seen order', () => {
    expect(distinctValues(rows, (r) => r.kind)).toEqual(['a', 'b']);
    expect(distinctValues(rows, (r) => r.name)).toEqual(['Ярема', 'Антон', 'Єва', 'антон']);
  });
});

@Component({
  imports: [TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  template: `
    <table [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
      <thead>
        <tr>
          <th scope="col" app-column-header key="name" label="Name" [filter]="{ type: 'text' }" [filterValue]="table.filterValue('name')"
            (filterChange)="table.setFilter('name', $event)"><i class="lead-icon">*</i></th>
          <th scope="col" app-column-header key="n" label="N" [filter]="kinds()"></th>
        </tr>
      </thead>
      <tbody>
        @for (r of table.rows(); track r.id) {
          <tr><td>{{ r.id }}</td></tr>
        }
      </tbody>
    </table>
  `,
})
class HostComponent {
  readonly data = signal(rows);
  readonly table = new ClientTable<R>({ rows: this.data, columns, prefix: 't', defaultSort: { key: 'n', dir: 'asc' } });
  readonly kinds = translatedSelect(() => ['a', 'b'], (v) => 'kind.' + v);
}

describe('ClientTable bound to the URL', () => {
  async function setup(url: string): Promise<{ el: HTMLElement; host: HostComponent; router: Router; detect: () => Promise<void> }> {
    TestBed.configureTestingModule({
      imports: [HostComponent, TranslocoTestingModule.forRoot({ langs: { uk: { kind: { a: 'Альфа', b: 'Бета' } } }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([])],
    });
    const router = TestBed.inject(Router);
    await router.navigateByUrl(url);
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const detect = async (): Promise<void> => {
      await fixture.whenStable();
      fixture.detectChanges();
    };
    return { el: fixture.nativeElement as HTMLElement, host: fixture.componentInstance, router, detect };
  }

  const shown = (el: HTMLElement): number[] => [...el.querySelectorAll('tbody td')].map((td) => Number(td.textContent));

  it('URL → rows: prefixed sort and filter apply; without them the API order shows and the default arrow stays', async () => {
    const { el, host } = await setup('/?t_sort=name&t_dir=desc&t_name=%D0%B0');
    expect(shown(el)).toEqual([1, 4, 2, 5]);
    expect(host.table.filtered()).toBe(true);
    TestBed.resetTestingModule();
    const plain = await setup('/');
    expect(shown(plain.el)).toEqual([1, 2, 3, 4, 5]);
    expect(plain.host.table.sort()).toEqual({ key: 'n', dir: 'asc' });
    expect(plain.el.querySelectorAll('th')[1].getAttribute('aria-sort')).toBe('ascending');
    expect(plain.host.table.touched()).toBe(false);
  });

  it('a click on a title writes the prefixed sort to the URL and keeps other params', async () => {
    const { el, router, detect } = await setup('/?tab=2&page=3');
    (el.querySelector('th button.title') as HTMLButtonElement).click();
    await detect();
    expect(router.url).toBe('/?tab=2&page=3&t_sort=name&t_dir=asc');
    expect(shown(el)).toEqual([2, 5, 4, 1, 3]);
    (el.querySelector('th button.title') as HTMLButtonElement).click();
    await detect();
    expect(router.url).toContain('t_dir=desc');
  });

  it('setFilter writes text and range params, null clears them; unknown columns are ignored', async () => {
    const { host, router, detect } = await setup('/');
    host.table.setFilter('n', { from: '3', to: null });
    await detect();
    expect(router.url).toBe('/?t_n_from=3');
    host.table.setFilter('name', 'Єва');
    await detect();
    expect(decodeURIComponent(router.url)).toBe('/?t_n_from=3&t_name=Єва');
    host.table.setFilter('n', null);
    host.table.setFilter('only', 'x');
    await detect();
    expect(decodeURIComponent(router.url)).toBe('/?t_name=Єва');
  });

  it('clearFilters drops every filter of the table in one go; the sort and foreign params stay', async () => {
    const { host, router, detect } = await setup('/?x=1&t_sort=name&t_dir=desc&t_name=a&t_n_from=2&t_n_to=9');
    expect(host.table.filtered()).toBe(true);
    host.table.clearFilters();
    await detect();
    expect(router.url).toBe('/?x=1&t_sort=name&t_dir=desc');
    expect(host.table.filtered()).toBe(false);
  });

  it('the header shows projected lead content inside the sort button; select labels are translated', async () => {
    const { el, host } = await setup('/');
    const button = el.querySelector('th button.title') as HTMLButtonElement;
    expect(button.querySelector('.lead-icon')).not.toBeNull();
    expect(el.querySelector('th')?.getAttribute('aria-label')).toBe('Name');
    const filter = host.kinds();
    expect(filter.type === 'select' ? filter.options : []).toEqual([
      { value: 'a', label: 'Альфа' },
      { value: 'b', label: 'Бета' },
    ]);
  });
});

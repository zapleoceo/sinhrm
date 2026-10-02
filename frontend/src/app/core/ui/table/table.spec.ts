import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { ColumnHeader } from './column-header';
import { TableSortDirective } from './table-sort.directive';
import {
  ColumnFilter,
  FilterValue,
  TableSort,
  ariaSort,
  filterToParam,
  intParam,
  isFilterActive,
  nextSort,
  rangeFromParams,
  rangeToParams,
  sortFromParams,
  sortToParams,
  textParam,
} from './table-state';

describe('table state helpers', () => {
  it('nextSort: a new column starts ascending, the same column flips, clearable adds «no sort»', () => {
    expect(nextSort(null, 'name')).toEqual({ key: 'name', dir: 'asc' });
    expect(nextSort({ key: 'name', dir: 'asc' }, 'name')).toEqual({ key: 'name', dir: 'desc' });
    expect(nextSort({ key: 'name', dir: 'desc' }, 'name')).toEqual({ key: 'name', dir: 'asc' });
    expect(nextSort({ key: 'name', dir: 'desc' }, 'name', true)).toBeNull();
    expect(nextSort({ key: 'name', dir: 'desc' }, 'branch')).toEqual({ key: 'branch', dir: 'asc' });
  });

  it('ariaSort follows the sorted column only', () => {
    expect(ariaSort({ key: 'a', dir: 'asc' }, 'a')).toBe('ascending');
    expect(ariaSort({ key: 'a', dir: 'desc' }, 'a')).toBe('descending');
    expect(ariaSort({ key: 'a', dir: 'desc' }, 'b')).toBe('none');
    expect(ariaSort(null, 'b')).toBe('none');
  });

  it('isFilterActive ignores blanks and empty ranges', () => {
    expect(isFilterActive(null)).toBe(false);
    expect(isFilterActive('  ')).toBe(false);
    expect(isFilterActive('x')).toBe(true);
    expect(isFilterActive({ from: null, to: null })).toBe(false);
    expect(isFilterActive({ from: '2026-01-01', to: null })).toBe(true);
  });

  it('reads the URL defensively: unknown sort, junk numbers and blanks are dropped', () => {
    const keys = ['name', 'branch'];
    expect(sortFromParams(convertToParamMap({ sort: 'branch', dir: 'desc' }), keys)).toEqual({ key: 'branch', dir: 'desc' });
    expect(sortFromParams(convertToParamMap({ sort: 'branch', dir: 'sideways' }), keys)).toEqual({ key: 'branch', dir: 'asc' });
    expect(sortFromParams(convertToParamMap({ sort: 'id; drop' }), keys)).toBeNull();
    expect(intParam(convertToParamMap({ page: '3' }), 'page')).toBe(3);
    for (const page of ['0', '-1', 'abc', '2.5', '']) expect(intParam(convertToParamMap({ page }), 'page')).toBeUndefined();
    expect(textParam(convertToParamMap({ q: '  ann ' }), 'q')).toBe('ann');
    expect(textParam(convertToParamMap({ q: '   ' }), 'q')).toBeUndefined();
  });

  it('writes the URL: null removes a param', () => {
    expect(sortToParams({ key: 'name', dir: 'desc' })).toEqual({ sort: 'name', dir: 'desc' });
    expect(sortToParams(null)).toEqual({ sort: null, dir: null });
    expect(filterToParam(' ann ')).toBe('ann');
    expect(filterToParam('')).toBeNull();
    expect(filterToParam(null)).toBeNull();
    expect(rangeToParams('hired', { from: '2026-01-01', to: null })).toEqual({ hired_from: '2026-01-01', hired_to: null });
    expect(rangeToParams('hired', null)).toEqual({ hired_from: null, hired_to: null });
    expect(rangeFromParams(convertToParamMap({ hired_to: '2026-02-01' }), 'hired')).toEqual({ from: null, to: '2026-02-01' });
    expect(rangeFromParams(convertToParamMap({}), 'hired')).toBeNull();
  });
});

@Component({
  imports: [TableSortDirective, ColumnHeader],
  template: `
    <table [appTableSort]="sort()" [appTableSortClearable]="clearable()" (appTableSortChange)="onSort($event)">
      <thead>
        <tr>
          <th scope="col" app-column-header key="name" label="Name" [filter]="text" [filterValue]="name()" (filterChange)="name.set($event)"></th>
          <th scope="col" app-column-header key="status" label="Status" [filter]="status" [filterValue]="statusValue()" (filterChange)="statusValue.set($event)"></th>
          <th scope="col" app-column-header key="hired" label="Hired" [filter]="range" [filterValue]="hired()" (filterChange)="hired.set($event)"></th>
          <th scope="col" app-column-header key="contact" label="Contact" [sortable]="false"></th>
        </tr>
      </thead>
    </table>
  `,
})
class Host {
  readonly sort = signal<TableSort | null>({ key: 'name', dir: 'asc' });
  readonly clearable = signal(false);
  readonly name = signal<FilterValue>(null);
  readonly statusValue = signal<FilterValue>(null);
  readonly hired = signal<FilterValue>(null);
  readonly emitted: (TableSort | null)[] = [];
  readonly text: ColumnFilter = { type: 'text' };
  readonly status: ColumnFilter = { type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'gone', label: 'Gone' }] };
  readonly range: ColumnFilter = { type: 'range', input: 'date' };

  onSort(sort: TableSort | null): void {
    this.emitted.push(sort);
    this.sort.set(sort);
  }
}

describe('ColumnHeader + appTableSort', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  const th = (i: number) => fixture.nativeElement.querySelectorAll('th')[i] as HTMLTableCellElement;
  const titleOf = (i: number) => th(i).querySelector('button.title') as HTMLButtonElement;
  const funnelOf = (i: number) => th(i).querySelector('button.filter') as HTMLButtonElement;
  const panel = () => document.querySelector('form.popover') as HTMLFormElement | null;
  const settle = async () => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    });
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    await settle();
  });

  afterEach(() => document.querySelectorAll('.cdk-overlay-container').forEach((c) => (c.innerHTML = '')));

  it('puts aria-sort on the th: the sorted column, «none» elsewhere, nothing on a column that cannot sort', () => {
    expect(th(0).getAttribute('aria-sort')).toBe('ascending');
    expect(th(1).getAttribute('aria-sort')).toBe('none');
    expect(th(3).hasAttribute('aria-sort')).toBe(false);
    expect(th(3).querySelector('button')).toBeNull();
  });

  it('a click on a title sorts by it; the second click reverses', async () => {
    titleOf(1).click();
    await settle();
    expect(host.emitted.at(-1)).toEqual({ key: 'status', dir: 'asc' });
    expect(th(1).getAttribute('aria-sort')).toBe('ascending');
    expect(th(0).getAttribute('aria-sort')).toBe('none');

    titleOf(1).click();
    await settle();
    expect(host.emitted.at(-1)).toEqual({ key: 'status', dir: 'desc' });
    expect(th(1).getAttribute('aria-sort')).toBe('descending');
    expect(th(1).querySelector('.arrow')?.getAttribute('data-dir')).toBe('desc');
  });

  it('clearable: the third click returns to the default order', async () => {
    host.clearable.set(true);
    host.sort.set({ key: 'name', dir: 'desc' });
    await settle();
    titleOf(0).click();
    await settle();
    expect(host.emitted.at(-1)).toBeNull();
    expect(th(0).getAttribute('aria-sort')).toBe('none');
  });

  it('titles and funnels are native buttons (Enter / Space work) with names for screen readers', () => {
    expect(titleOf(0).tagName).toBe('BUTTON');
    expect(titleOf(0).type).toBe('button');
    expect(funnelOf(0).getAttribute('aria-haspopup')).toBe('dialog');
    expect(funnelOf(0).getAttribute('aria-expanded')).toBe('false');
    expect(funnelOf(0).getAttribute('aria-label')).toBe('table.filter.open');
  });

  it('opens the text filter, applies on submit (Enter in the field), shows the dot and returns focus', async () => {
    funnelOf(0).focus();
    funnelOf(0).click();
    await settle();
    const form = panel();
    expect(form).not.toBeNull();
    expect(form?.getAttribute('role')).toBe('dialog');
    expect(funnelOf(0).getAttribute('aria-expanded')).toBe('true');
    expect(funnelOf(0).getAttribute('aria-controls')).toBe(form?.id);
    expect(document.activeElement).toBe(form?.querySelector('input'));

    const input = form!.querySelector('input') as HTMLInputElement;
    input.value = '  Ann ';
    input.dispatchEvent(new Event('input'));
    form!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();

    expect(host.name()).toBe('Ann');
    expect(panel()).toBeNull();
    expect(th(0).querySelector('.dot')).not.toBeNull();
    expect(funnelOf(0).getAttribute('aria-label')).toBe('table.filter.openActive');
    expect(document.activeElement).toBe(funnelOf(0));
  });

  it('Esc closes the filter without applying and gives focus back to the funnel', async () => {
    funnelOf(0).click();
    await settle();
    const input = panel()!.querySelector('input') as HTMLInputElement;
    input.value = 'draft';
    input.dispatchEvent(new Event('input'));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle();

    expect(panel()).toBeNull();
    expect(host.name()).toBeNull();
    expect(document.activeElement).toBe(funnelOf(0));
  });

  it('clears an active filter', async () => {
    host.name.set('Ann');
    await settle();
    funnelOf(0).click();
    await settle();
    expect((panel()!.querySelector('input') as HTMLInputElement).value).toBe('Ann');
    (panel()!.querySelector('button.clear') as HTMLButtonElement).click();
    await settle();

    expect(host.name()).toBeNull();
    expect(th(0).querySelector('.dot')).toBeNull();
  });

  it('select filter: picks one option; «All» clears it', async () => {
    funnelOf(1).click();
    await settle();
    const radios = Array.from(panel()!.querySelectorAll<HTMLInputElement>('input[type=radio]'));
    expect(radios).toHaveLength(3); // All + two options
    radios[2].click();
    await settle();
    panel()!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(host.statusValue()).toBe('gone');

    funnelOf(1).click();
    await settle();
    (panel()!.querySelectorAll<HTMLInputElement>('input[type=radio]')[0]).click();
    await settle();
    panel()!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(host.statusValue()).toBeNull();
  });

  it('range filter: from / to inputs of the given type', async () => {
    funnelOf(2).click();
    await settle();
    const [from, to] = Array.from(panel()!.querySelectorAll<HTMLInputElement>('input'));
    expect(from.type).toBe('date');
    to.value = '2026-03-01';
    to.dispatchEvent(new Event('input'));
    panel()!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(host.hired()).toEqual({ from: null, to: '2026-03-01' });
  });
});

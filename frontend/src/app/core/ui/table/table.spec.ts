import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { COUNT_ANNOUNCE_DELAY_MS, ColumnHeader } from './column-header';
import { TableSortDirective } from './table-sort.directive';
import { LIVE_FILTER_DEBOUNCE_MS } from './table-url-state';
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
    <table [appTableSort]="sort()" [appTableSortClearable]="clearable()" [appTableSortCount]="count()" (appTableSortChange)="onSort($event)">
      <thead>
        <tr>
          <th scope="col" app-column-header key="name" label="Name" [filter]="text" [filterValue]="name()" (filterChange)="onName($event)"></th>
          <th scope="col" app-column-header key="status" label="Status" [filter]="status" [filterValue]="statusValue()" (filterChange)="statusValue.set($event)"></th>
          <th scope="col" app-column-header key="hired" label="Hired" [filter]="range" [filterValue]="hired()" (filterChange)="hired.set($event)"></th>
          <th scope="col" app-column-header key="contact" label="Contact" [sortable]="false"></th>
          <th scope="col" app-column-header key="city" label="City" [sortable]="false" [filter]="city" [filterValue]="cityValue()" (filterChange)="cityValue.set($event)"></th>
        </tr>
      </thead>
    </table>
  `,
})
class Host {
  readonly sort = signal<TableSort | null>({ key: 'name', dir: 'asc' });
  readonly clearable = signal(false);
  readonly count = signal<number | null>(null);
  readonly name = signal<FilterValue>(null);
  readonly names: FilterValue[] = [];
  readonly statusValue = signal<FilterValue>(null);
  readonly hired = signal<FilterValue>(null);
  readonly cityValue = signal<FilterValue>(null);
  readonly emitted: (TableSort | null)[] = [];
  readonly text: ColumnFilter = { type: 'text' };
  readonly status: ColumnFilter = { type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'gone', label: 'Gone' }] };
  readonly range: ColumnFilter = { type: 'range', input: 'date' };
  /** More than SELECT_SEARCH_MIN options: the dialog gets a search over them. */
  readonly city: ColumnFilter = {
    type: 'select',
    options: ['Київ', 'Львів', 'Одеса', 'Дніпро', 'Харків', 'Запоріжжя', 'Вінниця', 'Полтава', 'Чернігів'].map((c, i) => ({ value: String(i + 1), label: c })),
  };

  onSort(sort: TableSort | null): void {
    this.emitted.push(sort);
    this.sort.set(sort);
  }

  onName(value: FilterValue): void {
    this.names.push(value);
    this.name.set(value);
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
  const type = (input: HTMLInputElement, value: string) => {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  };
  const key = (target: Element, k: string) => target.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
  /** Moves the fake clock past the live-filter pause (exactly: no margin, no real waiting). */
  const pause = (ms = LIVE_FILTER_DEBOUNCE_MS) => vi.advanceTimersByTimeAsync(ms);

  beforeEach(async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    TestBed.configureTestingModule({
      imports: [
        Host,
        TranslocoTestingModule.forRoot({
          langs: { uk: { table: { filter: { found: 'Знайдено: {{ n }}' } } } },
          translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
        }),
      ],
    });
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    await settle();
  });

  afterEach(() => {
    vi.useRealTimers();
    document.querySelectorAll('.cdk-overlay-container').forEach((c) => (c.innerHTML = ''));
  });

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

  it('live text filter: typing applies after the pause without Enter, once, trimmed; the field keeps focus and caret', async () => {
    funnelOf(0).focus();
    funnelOf(0).click();
    await settle();
    const form = panel()!;
    expect(form.getAttribute('role')).toBe('dialog');
    expect(funnelOf(0).getAttribute('aria-expanded')).toBe('true');
    expect(funnelOf(0).getAttribute('aria-controls')).toBe(form.id);
    const input = form.querySelector('input') as HTMLInputElement;
    expect(document.activeElement).toBe(input);
    expect(input.getAttribute('aria-label')).toBe('table.filter.field');
    expect(form.querySelector('button[type=submit]')).toBeNull(); // no «apply» to press

    type(input, 'A');
    type(input, 'An');
    type(input, '  Ann ');
    input.setSelectionRange(3, 3);
    await settle();
    expect(host.names).toEqual([]); // still typing: nothing yet

    await pause();
    await settle();
    expect(host.names).toEqual(['Ann']); // one output for the whole word
    expect(host.name()).toBe('Ann');
    expect(panel()).not.toBeNull(); // the dialog stays open
    expect(document.activeElement).toBe(input);
    expect(input.value).toBe('  Ann '); // the URL value does not overwrite the field
    expect(input.selectionStart).toBe(3);
    expect(th(0).querySelector('.dot')).not.toBeNull();
    expect(funnelOf(0).getAttribute('aria-label')).toBe('table.filter.openActive');

    // Erasing the text switches the filter off (null, not an empty string).
    type(input, '');
    await pause();
    expect(host.names).toEqual(['Ann', null]);
  });

  it('Enter applies the typed value at once and closes; focus goes back to the funnel', async () => {
    funnelOf(0).click();
    await settle();
    const input = panel()!.querySelector('input') as HTMLInputElement;
    type(input, 'Bo');
    key(input, 'Enter');
    await settle();
    expect(host.names).toEqual(['Bo']);
    expect(panel()).toBeNull();
    expect(document.activeElement).toBe(funnelOf(0));
    await pause();
    expect(host.names).toEqual(['Bo']); // the pending debounce does not send it twice
  });

  it('Esc closes the dialog and keeps the typed value applied; focus goes back to the funnel', async () => {
    funnelOf(0).click();
    await settle();
    const input = panel()!.querySelector('input') as HTMLInputElement;
    type(input, 'draft');
    key(input, 'Escape');
    await settle();

    expect(panel()).toBeNull();
    expect(host.name()).toBe('draft');
    expect(document.activeElement).toBe(funnelOf(0));
    await pause();
    expect(host.names).toEqual(['draft']);
  });

  it('clears an active filter, and a value still being typed', async () => {
    host.name.set('Ann');
    await settle();
    funnelOf(0).click();
    await settle();
    expect((panel()!.querySelector('input') as HTMLInputElement).value).toBe('Ann');
    (panel()!.querySelector('button.clear') as HTMLButtonElement).click();
    await settle();
    expect(host.name()).toBeNull();
    expect(th(0).querySelector('.dot')).toBeNull();

    funnelOf(0).click();
    await settle();
    const clear = panel()!.querySelector('button.clear') as HTMLButtonElement;
    expect(clear.disabled).toBe(true);
    type(panel()!.querySelector('input') as HTMLInputElement, 'Zo');
    await settle();
    expect(clear.disabled).toBe(false);
    clear.click();
    await pause();
    expect(host.names.at(-1)).toBeNull(); // the typed «Zo» never went out after the clear
    expect(host.names).not.toContain('Zo');
  });

  it('select filter: a click applies at once and the dialog stays open; «All» clears it', async () => {
    funnelOf(1).click();
    await settle();
    expect(panel()!.querySelector('input[type=search]')).toBeNull(); // short list: no search
    const radios = Array.from(panel()!.querySelectorAll<HTMLInputElement>('input[type=radio]'));
    expect(radios).toHaveLength(3); // All + two options
    radios[2].click();
    await settle();
    expect(host.statusValue()).toBe('gone');
    expect(panel()).not.toBeNull();

    (panel()!.querySelectorAll<HTMLInputElement>('input[type=radio]')[0]).click();
    await settle();
    expect(host.statusValue()).toBeNull();
  });

  it('long select: a focused, labelled search narrows the options (contains, any case); arrows and Enter pick', async () => {
    funnelOf(4).click();
    await settle();
    const form = panel()!;
    const search = form.querySelector('input[type=search]') as HTMLInputElement;
    const list = form.querySelector('mat-radio-group') as HTMLElement;
    expect(search).not.toBeNull();
    expect(document.activeElement).toBe(search);
    expect(search.getAttribute('aria-controls')).toBe(list.id);
    expect(form.querySelector(`label[for="${search.id}"]`)?.textContent).toContain('table.filter.searchOptions');
    const labels = () => Array.from(form.querySelectorAll('mat-radio-button')).map((r) => r.textContent?.trim());
    expect(labels()).toHaveLength(10); // All + 9

    type(search, 'ІВ');
    await settle();
    expect(labels()).toEqual(['table.filter.all', 'Львів', 'Харків', 'Чернігів']);
    expect(host.cityValue()).toBeNull(); // searching the options is not a filter yet

    key(search, 'ArrowDown');
    expect(document.activeElement).toBe(form.querySelector('input[type=radio]'));

    type(search, 'zzz');
    await settle();
    expect(labels()).toEqual(['table.filter.all']);
    expect(form.textContent).toContain('table.filter.noOptions');

    type(search, 'одеса');
    await settle();
    key(search, 'Enter');
    await settle();
    expect(host.cityValue()).toBe('3');
    expect(panel()).toBeNull();
  });

  it('range filter: applied on change after a short pause, no button', async () => {
    funnelOf(2).click();
    await settle();
    const [from, to] = Array.from(panel()!.querySelectorAll<HTMLInputElement>('input'));
    expect(from.type).toBe('date');
    to.value = '2026-03-01';
    to.dispatchEvent(new Event('input'));
    to.dispatchEvent(new Event('change'));
    expect(host.hired()).toBeNull();
    await pause();
    expect(host.hired()).toEqual({ from: null, to: '2026-03-01' });
    to.dispatchEvent(new Event('blur')); // the same value again: no second output
    expect(panel()).not.toBeNull();
  });

  it('announces the row count of the table in a polite live region of the dialog, once it settles', async () => {
    host.count.set(5);
    await settle();
    funnelOf(0).click();
    await settle();
    const status = panel()!.querySelector('.count') as HTMLElement;
    expect(status.getAttribute('aria-live')).toBe('polite');
    expect(status.textContent?.trim()).toBe('Знайдено: 5'); // the count at opening, at once

    // Every letter changes the count of a client table: only the settled one is spoken.
    for (const n of [4, 3, 2]) {
      host.count.set(n);
      await settle();
      await pause(COUNT_ANNOUNCE_DELAY_MS / 2);
    }
    expect(status.textContent?.trim()).toBe('Знайдено: 5');
    await pause(COUNT_ANNOUNCE_DELAY_MS / 2);
    await settle();
    expect(status.textContent?.trim()).toBe('Знайдено: 2');
    expect(panel()!.querySelector('.count')).toBe(status); // the same region, so the change is announced

    host.count.set(null);
    await settle();
    await pause(COUNT_ANNOUNCE_DELAY_MS);
    await settle();
    expect(status.textContent?.trim()).toBe(''); // count unknown (a server table loading)
  });

  it('choice list: arrow steps wait for the pause (one output), a click right after applies at once, Enter flushes', async () => {
    funnelOf(1).click();
    await settle();
    const radios = () => Array.from(panel()!.querySelectorAll<HTMLInputElement>('input[type=radio]'));
    const group = panel()!.querySelector('mat-radio-group') as HTMLElement;
    const step = (i: number) => {
      key(radios()[i], 'ArrowDown'); // the browser then moves the check: here, the click
      radios()[i].click();
    };

    step(1);
    step(2);
    step(0);
    step(2);
    await settle();
    expect(host.statusValue()).toBeNull(); // still stepping: nothing sent
    await pause();
    await settle();
    expect(host.statusValue()).toBe('gone'); // one output for the whole run

    group.dispatchEvent(new Event('pointerdown'));
    radios()[1].click();
    await settle();
    expect(host.statusValue()).toBe('active'); // a click is a pick

    step(2);
    key(radios()[2], 'Enter');
    await settle();
    expect(host.statusValue()).toBe('gone'); // Enter sends the stepped value now and closes
    expect(panel()).toBeNull();
  });

  it('Enter that confirms an IME composition neither commits nor closes; Esc while composing stays too', async () => {
    funnelOf(0).click();
    await settle();
    const input = panel()!.querySelector('input') as HTMLInputElement;
    type(input, 'にほん');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true, cancelable: true }));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', isComposing: true, bubbles: true, cancelable: true }));
    await settle();
    expect(panel()).not.toBeNull();
    expect(host.names).toEqual([]);

    key(input, 'Enter'); // the composition is over: a real Enter
    await settle();
    expect(host.names).toEqual(['にほん']);
    expect(panel()).toBeNull();
  });

  it('a value changed from outside while the dialog is open («back») replaces the draft; own echoes do not', async () => {
    funnelOf(0).click();
    await settle();
    const input = panel()!.querySelector('input') as HTMLInputElement;
    type(input, 'Ann');
    await pause();
    await settle();
    expect(host.name()).toBe('Ann'); // our own value came back: the field keeps the text as typed
    expect(input.value).toBe('Ann');

    type(input, 'Anna'); // still waiting for the pause: an outside change now is not mixed in
    host.name.set('Bob');
    await settle();
    expect(input.value).toBe('Anna');
    await pause();
    await settle();
    expect(host.name()).toBe('Anna');

    host.name.set('Bob'); // «back»: nothing of ours is on its way
    await settle();
    expect(input.value).toBe('Bob');
    expect(host.names).toEqual(['Ann', 'Anna']); // the field follows the table, nothing is sent back
  });
});

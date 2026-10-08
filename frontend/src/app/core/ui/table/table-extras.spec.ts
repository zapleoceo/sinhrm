import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject } from 'rxjs';
import { ActiveFilter, ActiveFilters } from './active-filters';
import { ColumnHeader } from './column-header';
import { LatestRequest } from './latest-request';
import { TableSortDirective } from './table-sort.directive';
import {
  ColumnFilter,
  FilterValue,
  TEXT_PARAM_MAX,
  dateRangeFromParams,
  dateRangeToParams,
  idToFilter,
  isIsoDay,
  oneOfParam,
  sameQuery,
  textParam,
} from './table-state';

const LANGS = { uk: { statuses: { gone: 'Звільнено' }, people: { name: 'ПІБ' }, table: { active: { remove: 'Прибрати «{{column}}»' } } } };

describe('table state helpers (URL junk never reaches the API)', () => {
  it('textParam cuts a hand-edited value to the API limit instead of letting the API answer 422', () => {
    expect(TEXT_PARAM_MAX).toBe(100);
    expect(textParam(convertToParamMap({ q: 'a'.repeat(150) }), 'q')).toHaveLength(100);
    expect(textParam(convertToParamMap({ q: ' ' + 'b'.repeat(99) + '   c' }), 'q')).toBe('b'.repeat(99));
    expect(textParam(convertToParamMap({ q: '0' }), 'q')).toBe('0');
  });

  it('idToFilter: an id of the query is the select value; no id is «all» (null)', () => {
    expect(idToFilter(3)).toBe('3');
    expect(idToFilter(undefined)).toBeNull();
    expect(idToFilter(null)).toBeNull();
    expect(idToFilter(0)).toBeNull();
  });

    it('oneOfParam keeps allowed values only', () => {
    const allowed = ['active', 'blocked'] as const;
    expect(oneOfParam(convertToParamMap({ s: 'blocked' }), 's', allowed)).toBe('blocked');
    expect(oneOfParam(convertToParamMap({ s: 'Blocked' }), 's', allowed)).toBeUndefined();
    expect(oneOfParam(convertToParamMap({}), 's', allowed)).toBeUndefined();
  });

  it('dates: only real calendar days; a reversed range is swapped both ways', () => {
    expect(isIsoDay('2026-02-28')).toBe(true);
    for (const d of ['2026-02-31', '2026-13-01', '26-01-01', '2026-1-1', '', null]) expect(isIsoDay(d)).toBe(false);
    expect(dateRangeFromParams(convertToParamMap({ a: '2026-09-30', b: '2026-09-01' }), 'a', 'b')).toEqual({ from: '2026-09-01', to: '2026-09-30' });
    expect(dateRangeFromParams(convertToParamMap({ b: '2026-09-01' }), 'a', 'b')).toEqual({ from: null, to: '2026-09-01' });
    expect(dateRangeFromParams(convertToParamMap({ a: 'yesterday' }), 'a', 'b')).toBeNull();
    expect(dateRangeToParams({ from: '2026-09-30', to: '2026-09-01' }, 'a', 'b')).toEqual({ a: '2026-09-01', b: '2026-09-30' });
    expect(dateRangeToParams({ from: 'junk', to: '2026-09-01' }, 'a', 'b')).toEqual({ a: null, b: '2026-09-01' });
    expect(dateRangeToParams(null, 'a', 'b')).toEqual({ a: null, b: null });
  });

  it('sameQuery compares flat queries by value (missing = undefined)', () => {
    expect(sameQuery<Record<string, unknown>>({ q: 'a', page: 1 }, { page: 1, q: 'a', role: undefined })).toBe(true);
    expect(sameQuery<Record<string, unknown>>({ sort: 'name', dir: 'asc' }, { sort: 'name', dir: 'desc' })).toBe(false);
  });
});

describe('LatestRequest', () => {
  it('a new run unsubscribes the previous request; cancel() and destroy stop the last one', () => {
    const request = TestBed.runInInjectionContext(() => new LatestRequest());
    const first = new Subject<number>();
    const second = new Subject<number>();
    const seen: number[] = [];
    request.run(first, { next: (v) => seen.push(v) });
    request.run(second, { next: (v) => seen.push(v) });
    expect(first.observed).toBe(false);
    first.next(1);
    second.next(2);
    expect(seen).toEqual([2]);
    request.cancel();
    expect(second.observed).toBe(false);

    const third = new Subject<number>();
    request.run(third, {});
    TestBed.resetTestingModule(); // destroys the injector: the request in flight is cancelled with it
    expect(third.observed).toBe(false);
  });
});

@Component({
  imports: [TableSortDirective, ColumnHeader, ActiveFilters],
  template: `
    <table [appTableSort]="null">
      <thead>
        <tr>
          <th scope="col" app-column-header key="status" label="Status" [filter]="status" [filterValue]="value()" (filterChange)="value.set($event)"></th>
        </tr>
      </thead>
    </table>
    <app-active-filters [filters]="chips()" (remove)="removed.push($event)" (clearAll)="cleared = cleared + 1" />
  `,
})
class Host {
  readonly value = signal<FilterValue>(null);
  readonly status: ColumnFilter = {
    type: 'select',
    options: [
      { value: 'gone', label: 'statuses.gone', i18n: true },
      { value: 'raw', label: 'Raw label' },
    ],
  };
  readonly chips = signal<ActiveFilter[]>([]);
  readonly removed: string[] = [];
  cleared = 0;
}

describe('select options with i18n labels + active filter chips', () => {
  let fixture: ComponentFixture<Host>;
  const settle = async () => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host, TranslocoTestingModule.forRoot({ langs: LANGS, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' }, preloadLangs: true })],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  afterEach(() => document.querySelectorAll('.cdk-overlay-container').forEach((c) => (c.innerHTML = '')));

  it('translates option labels marked i18n and shows the others as they are', async () => {
    (fixture.nativeElement.querySelector('button.filter') as HTMLButtonElement).click();
    await settle();
    const labels = Array.from(document.querySelectorAll('form.popover mat-radio-button')).map((r) => r.textContent?.trim());
    expect(labels.slice(1)).toEqual(['Звільнено', 'Raw label']);
  });

  it('chips: nothing when no filter is on; one chip per filter with a named remove button; «reset all» from two', async () => {
    expect(fixture.nativeElement.querySelector('app-active-filters [role=group]')).toBeNull();
    fixture.componentInstance.chips.set([{ key: 'name', column: 'people.name', value: 'Ko' }]);
    await settle();
    const remove = fixture.nativeElement.querySelector('app-active-filters button.remove') as HTMLButtonElement;
    expect(fixture.nativeElement.querySelector('app-active-filters .text').textContent.replace(/\s+/g, ' ').trim()).toBe('ПІБ: Ko');
    expect(remove.getAttribute('aria-label')).toBe('Прибрати «ПІБ»');
    expect(fixture.nativeElement.querySelector('app-active-filters button.all')).toBeNull();
    remove.click();
    expect(fixture.componentInstance.removed).toEqual(['name']);

    fixture.componentInstance.chips.set([
      { key: 'name', column: 'people.name', value: 'Ko' },
      { key: 'status', column: 'Status', value: 'statuses.gone', i18n: true },
    ]);
    await settle();
    expect(fixture.nativeElement.querySelectorAll('app-active-filters .chip')[1].textContent).toContain('Звільнено');
    (fixture.nativeElement.querySelector('app-active-filters button.all') as HTMLButtonElement).click();
    expect(fixture.componentInstance.cleared).toBe(1);
  });
});

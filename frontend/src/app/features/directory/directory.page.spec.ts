import { TestBed } from '@angular/core/testing';
import { Router, convertToParamMap, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { DictionaryPage, DictionaryQuery, DictionaryType } from './directory.model';
import { DirectoryPage } from './directory.page';
import { directoryViewFromParams } from './directory.query';
import { DirectoryService } from './directory.service';

const PAGE: DictionaryPage = {
  data: [{ id: 1, name: 'Alpha', status: 'active', created_at: null, updated_at: null, city: { id: 4, name: 'Kyiv' }, city_id: 4 }],
  meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 },
};

describe('directory view in the URL', () => {
  it('reads the tab, filters, sort and paging; city only on branches; junk is dropped', () => {
    expect(directoryViewFromParams(convertToParamMap({ tab: 'branches', q: ' al ', city_id: '4', status: 'disabled', sort: 'city', dir: 'desc', page: '2' }))).toEqual({
      type: 'branches',
      query: { q: 'al', status: 'disabled', city_id: 4, sort: 'city', dir: 'desc', page: 2, perPage: 50 },
    });
    const positions = directoryViewFromParams(convertToParamMap({ tab: 'positions', city_id: '4', sort: 'city' }));
    expect(positions.type).toBe('positions');
    expect(positions.query.city_id).toBeUndefined();
    expect(positions.query.sort).toBeUndefined();
    const junk = directoryViewFromParams(convertToParamMap({ tab: 'planets', status: 'gone', perPage: '999' }));
    expect(junk.type).toBe('branches');
    expect(junk.query.status).toBeUndefined();
    expect(junk.query.perPage).toBe(200);
  });
});

describe('DirectoryPage: tab and headers bound to the URL', () => {
  let calls: { type: DictionaryType; query: DictionaryQuery }[];
  let harness: RouterTestingHarness;

  const th = (label: string) =>
    Array.from(harness.routeNativeElement!.querySelectorAll<HTMLTableCellElement>('th')).find((t) => t.getAttribute('aria-label') === label);
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');

  beforeEach(async () => {
    calls = [];
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: 'admin/directory', component: DirectoryPage }]),
        {
          provide: DirectoryService,
          useValue: {
            list: (type: DictionaryType, query: DictionaryQuery) => (calls.push({ type, query }), of(PAGE)),
            active: () => of([{ id: 4, name: 'Kyiv', status: 'active', created_at: null, updated_at: null }]),
          },
        },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/admin/directory?tab=branches&city_id=4&page=2');
    harness.detectChanges();
  });

  it('loads the URL view once; the city filter shows on its header; name carries the default order', () => {
    expect(calls).toEqual([{ type: 'branches', query: expect.objectContaining({ city_id: 4, page: 2 }) }]);
    expect(th('directory.columns.city')!.querySelector('.dot')).not.toBeNull();
    expect(th('directory.columns.name')!.getAttribute('aria-sort')).toBe('ascending');
  });

  it('a click on the city title sorts by it and drops the page', async () => {
    (th('directory.columns.city')!.querySelector('button.title') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.get('sort')).toBe('city');
    expect(url().searchParams.has('page')).toBe(false);
    expect(calls.at(-1)).toEqual({ type: 'branches', query: expect.objectContaining({ sort: 'city', dir: 'asc', page: 1, city_id: 4 }) });
  });

  it('another tab starts clean (no city filter or sort) and has no city column', async () => {
    await harness.navigateByUrl('/admin/directory?tab=branches&city_id=4&sort=city&dir=desc');
    harness.detectChanges();
    const tabs = harness.routeNativeElement!.querySelectorAll<HTMLElement>('[role=tab]');
    tabs[3].click(); // positions
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.get('tab')).toBe('positions');
    expect(url().searchParams.has('city_id')).toBe(false);
    expect(url().searchParams.has('sort')).toBe(false);
    expect(calls.at(-1)).toEqual({ type: 'positions', query: expect.objectContaining({ city_id: undefined, sort: undefined, page: 1 }) });
    expect(th('directory.columns.city')).toBeUndefined();
  });
});

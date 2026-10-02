import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of } from 'rxjs';
import { LIVE_FILTER_DEBOUNCE_MS } from '../../../core/ui/table/table-url-state';
import { AuthService } from '../../../core/auth/auth.service';
import { DirectoryService } from '../../directory/directory.service';
import { Employee, Paged, PeopleQuery } from '../people.model';
import { PeopleService } from '../people.service';
import { PeoplePage } from './people.page';

const PAGE: Paged<Employee> = {
  data: [{ id: 1, full_name: 'Ann Smith', status: 'active', avatar_url: null, work_email: null, phone: null, branch: null, department: null, position: null, manager: null }],
  meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 },
};

describe('PeoplePage: sortable / filterable headers bound to the URL', () => {
  let queries: PeopleQuery[];
  let empty: boolean;
  /** true: requests stay in flight (never answer), so a newer one must cancel them. */
  let hold: boolean;
  let inflight: { q: PeopleQuery; cancelled: boolean }[];
  let harness: RouterTestingHarness;

  const th = (label: string) =>
    Array.from(harness.routeNativeElement!.querySelectorAll<HTMLTableCellElement>('th')).find((t) => t.querySelector('.title .text')?.textContent?.trim() === label)!;
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');

  function list(q: PeopleQuery): Observable<Paged<Employee>> {
    queries.push(q);
    if (!hold) return of(empty ? { ...PAGE, data: [] } : PAGE);
    return new Observable<Paged<Employee>>(() => {
      const call = { q, cancelled: false };
      inflight.push(call);
      return () => (call.cancelled = true);
    });
  }

  beforeEach(async () => {
    queries = [];
    empty = false;
    hold = false;
    inflight = [];
    // The table view is remembered in localStorage; another spec (people.spec.ts → setView('cards')) may leave «cards»
    // behind in a shared test environment, and then there are no headers to test.
    localStorage.removeItem('sinhrm.people.view');
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: 'people', component: PeoplePage }]),
        { provide: PeopleService, useValue: { list } },
        { provide: DirectoryService, useValue: { active: () => of([{ id: 3, name: 'Analyst', status: 'active' }]) } },
        { provide: AuthService, useValue: { user: signal({ roles: ['viewer'] }) } },
        { provide: MatDialog, useValue: {} },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/people?position_id=3&page=4&sort=name&dir=desc');
    harness.detectChanges();
  });

  it('loads the query from the URL once and shows its sort on the header', () => {
    expect(queries).toHaveLength(1);
    expect(queries[0]).toEqual(expect.objectContaining({ position_id: 3, page: 4, sort: 'name', dir: 'desc' }));
    expect(th('people.fields.fullName').getAttribute('aria-sort')).toBe('descending');
    expect(th('people.fields.position').querySelector('.dot')).not.toBeNull();
  });

  it('a click on a title writes sort/dir to the URL, drops the page and reloads; the second click reverses', async () => {
    (th('people.fields.position').querySelector('button.title') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.get('sort')).toBe('position');
    expect(url().searchParams.get('dir')).toBe('asc');
    expect(url().searchParams.has('page')).toBe(false);
    expect(url().searchParams.get('position_id')).toBe('3');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'position', dir: 'asc', page: 1, position_id: 3 }));
    expect(th('people.fields.position').getAttribute('aria-sort')).toBe('ascending');

    (th('people.fields.position').querySelector('button.title') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.get('dir')).toBe('desc');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'position', dir: 'desc' }));
  });

  it('«back» restores the previous sort from the URL', async () => {
    await harness.navigateByUrl('/people?sort=manager&dir=asc');
    harness.detectChanges();
    expect(th('people.fields.manager').getAttribute('aria-sort')).toBe('ascending');
    await harness.navigateByUrl('/people?position_id=3&page=4&sort=name&dir=desc');
    harness.detectChanges();
    expect(th('people.fields.fullName').getAttribute('aria-sort')).toBe('descending');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ page: 4, sort: 'name', dir: 'desc' }));
  });

  it('text filters of hideable columns stay visible and removable as chips (cards view, narrow screens)', async () => {
    await harness.navigateByUrl('/people?name=Ko&manager=lead&position_id=3');
    harness.detectChanges();
    const chips = () => Array.from(harness.routeNativeElement!.querySelectorAll<HTMLElement>('app-active-filters .chip'));
    expect(chips().map((c) => c.querySelector('.text')?.textContent?.replace(/\s+/g, ' ').trim())).toEqual([
      'people.fields.fullName: Ko',
      'people.fields.manager: lead',
    ]);

    chips()[1].querySelector<HTMLButtonElement>('button.remove')!.click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.has('manager')).toBe(false);
    expect(url().searchParams.get('name')).toBe('Ko');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ name: 'Ko', manager: undefined, page: 1 }));
    expect(chips()).toHaveLength(1);

    await harness.navigateByUrl('/people?name=Ko&contact=050');
    harness.detectChanges();
    harness.routeNativeElement!.querySelector<HTMLButtonElement>('app-active-filters button.all')!.click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(url().searchParams.has('name')).toBe(false);
    expect(url().searchParams.has('contact')).toBe(false);
    expect(chips()).toHaveLength(0);
  });

  it('an empty result keeps the table headers, so the filter that emptied it can be cleared', async () => {
    empty = true;
    await harness.navigateByUrl('/people?name=nobody');
    harness.detectChanges();
    expect(th('people.fields.fullName')).toBeTruthy();
    expect(harness.routeNativeElement!.textContent).toContain('people.directory.empty');
  });
  it('live «ПІБ» filter: typing sends one request after the pause (page 1), a newer one cancels the request in flight', async () => {
    const pause = () => new Promise((resolve) => setTimeout(resolve, LIVE_FILTER_DEBOUNCE_MS + 50));
    const settle = async () => {
      await harness.fixture.whenStable();
      harness.detectChanges();
    };
    hold = true;
    const loaded = queries.length;
    (th('people.fields.fullName').querySelector('button.filter') as HTMLButtonElement).click();
    await settle();
    const input = document.querySelector<HTMLInputElement>('.popover input[type=search]')!;
    for (const value of ['К', 'Ко']) {
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    await settle();
    expect(queries).toHaveLength(loaded); // no request per keystroke, no Enter pressed

    await pause();
    await settle();
    expect(queries).toHaveLength(loaded + 1);
    expect(queries.at(-1)).toEqual(expect.objectContaining({ name: 'Ко', page: 1, position_id: 3, sort: 'name', dir: 'desc' }));
    expect(url().searchParams.get('name')).toBe('Ко');
    expect(url().searchParams.has('page')).toBe(false);
    expect(inflight.map((c) => c.cancelled)).toEqual([false]);

    // The answer for «Ко» is still pending when the user types on: it is cancelled, not just ignored.
    input.value = 'Кон';
    input.dispatchEvent(new Event('input'));
    await pause();
    await settle();
    expect(queries).toHaveLength(loaded + 2);
    expect(queries.at(-1)?.name).toBe('Кон');
    expect(inflight.map((c) => c.cancelled)).toEqual([true, false]);
    expect(document.activeElement).toBe(input); // the dialog stayed open through both URL changes
    document.querySelectorAll('.cdk-overlay-container').forEach((c) => (c.innerHTML = ''));
  });
});

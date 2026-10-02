import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
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
  let harness: RouterTestingHarness;

  const th = (label: string) =>
    Array.from(harness.routeNativeElement!.querySelectorAll<HTMLTableCellElement>('th')).find((t) => t.querySelector('.title .text')?.textContent?.trim() === label)!;
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');

  beforeEach(async () => {
    queries = [];
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: 'people', component: PeoplePage }]),
        { provide: PeopleService, useValue: { list: (q: PeopleQuery) => (queries.push(q), of(PAGE)) } },
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
});

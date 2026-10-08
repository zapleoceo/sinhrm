import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router, convertToParamMap, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, Subject, of } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { DirectoryService } from '../directory/directory.service';
import { AdminUser, UsersPage as UsersPageData, UsersQuery } from './users.model';
import { UsersPage } from './users.page';
import { USERS_PAGE_SIZE, usersQueryFromParams } from './users.query';
import { UsersService } from './users.service';
import { sortCount } from '../../../testing/table-page';

const USER: AdminUser = {
  id: 2,
  name: 'Ann Viewer',
  email: 'ann@sinhrm.test',
  avatar_url: null,
  roles: ['viewer'],
  status: 'active',
  branches: [],
  locale: 'uk',
  safe_speak_handler: false,
  invited_by: null,
  last_login_at: null,
  created_at: null,
};
const PAGE: UsersPageData = { data: [USER], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } };

describe('users query in the URL', () => {
  it('reads filters, sort and paging; junk is dropped, not sent to the API', () => {
    expect(
      usersQueryFromParams(
        convertToParamMap({ q: ' ann ', role: 'viewer', status: 'blocked', last_login_from: '2026-09-01', last_login_to: '2026-09-30', sort: 'last_login', dir: 'desc', page: '2', perPage: '50' }),
      ),
    ).toEqual({
      q: 'ann',
      role: 'viewer',
      status: 'blocked',
      last_login_from: '2026-09-01',
      last_login_to: '2026-09-30',
      sort: 'last_login',
      dir: 'desc',
      page: 2,
      perPage: 50,
    });
    const junk = usersQueryFromParams(
      convertToParamMap({ role: 'king', status: 'gone', sort: 'email', last_login_from: '2026-02-31', last_login_to: 'yesterday', page: '0', perPage: '500', q: 'x'.repeat(130) }),
    );
    expect(junk).toEqual({
      q: 'x'.repeat(100),
      role: undefined,
      status: undefined,
      last_login_from: undefined,
      last_login_to: undefined,
      sort: undefined,
      dir: undefined,
      page: 1,
      perPage: 100,
    });
    expect(usersQueryFromParams(convertToParamMap({})).perPage).toBe(USERS_PAGE_SIZE);
  });
});

describe('UsersPage: sortable / filterable headers bound to the URL', () => {
  let queries: UsersQuery[];
  let answers: Subject<UsersPageData>[];
  let harness: RouterTestingHarness;

  const th = (label: string) =>
    Array.from(harness.routeNativeElement!.querySelectorAll<HTMLTableCellElement>('th')).find((t) => t.getAttribute('aria-label') === label)!;
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');
  const click = async (el: Element | null) => {
    (el as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.detectChanges();
  };

  beforeEach(async () => {
    queries = [];
    answers = [];
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: 'admin/users', component: UsersPage }]),
        {
          provide: UsersService,
          useValue: {
            list: (q: UsersQuery): Observable<UsersPageData> => {
              queries.push(q);
              const answer = new Subject<UsersPageData>();
              answers.push(answer);
              return answer;
            },
          },
        },
        { provide: DirectoryService, useValue: { active: () => of([]) } },
        { provide: AuthService, useValue: { user: signal({ id: 1, roles: ['superadmin'] }) } },
        { provide: MatDialog, useValue: {} },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/admin/users?role=viewer&page=3&sort=last_login&dir=desc');
    answers.at(-1)!.next(PAGE);
    harness.detectChanges();
  });

  it('loads the URL query once; its sort and filter show on the headers', () => {
    expect(queries).toHaveLength(1);
    expect(queries[0]).toEqual(expect.objectContaining({ role: 'viewer', page: 3, sort: 'last_login', dir: 'desc' }));
    expect(sortCount(harness.fixture)).toBe(PAGE.meta.total); // the server total, announced by an open filter
    expect(th('users.columns.lastLogin').getAttribute('aria-sort')).toBe('descending');
    expect(th('users.columns.user').getAttribute('aria-sort')).toBe('none');
    expect(th('users.columns.role').querySelector('.dot')).not.toBeNull();
    // Roles and branches are many-valued: no sort button.
    expect(th('users.columns.role').querySelector('button.title')).toBeNull();
    expect(th('users.columns.role').hasAttribute('aria-sort')).toBe(false);
  });

  it('a click on a title writes sort/dir to the URL and drops the page; the second click reverses', async () => {
    await click(th('users.columns.status').querySelector('button.title'));
    expect(url().searchParams.get('sort')).toBe('status');
    expect(url().searchParams.get('dir')).toBe('asc');
    expect(url().searchParams.has('page')).toBe(false);
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'status', dir: 'asc', page: 1, role: 'viewer' }));

    await click(th('users.columns.status').querySelector('button.title'));
    expect(url().searchParams.get('dir')).toBe('desc');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'status', dir: 'desc' }));
  });

  it('the user filter goes to ?q=, a newer query cancels the request still in flight', async () => {
    await click(th('users.columns.user').querySelector('button.filter'));
    const input = document.querySelector<HTMLInputElement>('.popover input[type=search]')!;
    input.value = 'ann';
    input.dispatchEvent(new Event('input'));
    document.querySelector<HTMLFormElement>('.popover')!.dispatchEvent(new Event('submit'));
    await harness.fixture.whenStable();
    expect(url().searchParams.get('q')).toBe('ann');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ q: 'ann', page: 1 }));
    const inFlight = answers.at(-1)!;
    expect(inFlight.observed).toBe(true);
    await harness.navigateByUrl('/admin/users?q=bob');
    expect(inFlight.observed).toBe(false);
  });

  it('a failed load shows the error state and stops the progress bar', async () => {
    await harness.navigateByUrl('/admin/users?q=err');
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('mat-progress-bar')).not.toBeNull();
    answers.at(-1)!.error(new Error('down'));
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('mat-progress-bar')).toBeNull();
    expect(harness.routeNativeElement!.textContent).toContain('users.loadError');
  });

  it('keeps the headers when nothing matches, so the filter can be cleared in place', async () => {
    await harness.navigateByUrl('/admin/users?q=nobody');
    answers.at(-1)!.next({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } });
    harness.detectChanges();
    expect(th('users.columns.user')).toBeTruthy();
    expect(harness.routeNativeElement!.textContent).toContain('users.empty');
  });
});

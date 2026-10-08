import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, CanActivateFn, Route, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { routes } from './app.routes';
import { authGuard, guestGuard, moduleGuard } from './core/auth/auth.guards';
import { USER_ROLES, UserRole } from './core/auth/auth.model';
import { AuthService } from './core/auth/auth.service';

/**
 * Access table of the SPA routes: who opens which page. The API is the real gate (403); this keeps the menu and the
 * router from showing an admin page to a role that only gets errors there — and catches a new admin route added
 * without a roleGuard. A change of this table is a change of access: it must be visible in the PR diff.
 */
const shell = routes.find((r) => r.path === '' && r.children) as Route;
const children = shell.children ?? [];

/** Roles that pass the route's canActivate guards (all of them, in order). */
async function allowedRoles(route: Route): Promise<UserRole[]> {
  const allowed: UserRole[] = [];
  for (const role of USER_ROLES) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: { load: () => Promise.resolve(), isLoggedIn: () => true, hasRole: (r: UserRole) => r === role } },
      ],
    });
    let ok = true;
    for (const guard of (route.canActivate ?? []) as CanActivateFn[]) {
      const result = await TestBed.runInInjectionContext(() => guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot));
      if (result !== true) {
        ok = false;
        if (result instanceof UrlTree) expect(TestBed.inject(Router).serializeUrl(result)).toBe('/');
      }
    }
    if (ok) allowed.push(role);
  }
  return allowed;
}

const SUPERADMIN = ['superadmin'];
const ADMIN = ['superadmin', 'admin'];
const HR_STAFF = ['superadmin', 'admin', 'hr_manager'];

/** Every role-restricted page and who may open it. */
const EXPECTED: Record<string, string[]> = {
  status: ADMIN,
  'admin/scripts': ADMIN,
  'admin/scripts/:id': ADMIN,
  'admin/workflows': HR_STAFF,
  'admin/workflows/:id': HR_STAFF,
  'admin/perform/reviews': HR_STAFF,
  'admin/pulse': HR_STAFF,
  'admin/documents/templates': HR_STAFF,
  'admin/hiring-requests': HR_STAFF,
  'admin/acquisition-channels': ADMIN,
  'admin/privacy': ADMIN,
  'admin/time': HR_STAFF,
  'desk/queue': HR_STAFF,
  'safe-speak/inbox': HR_STAFF,
  'admin/knowledge/:id': HR_STAFF,
  'admin/assets': HR_STAFF,
  'admin/users': SUPERADMIN,
  'admin/audit': SUPERADMIN,
  'admin/timeoff': HR_STAFF,
  'admin/directory': ADMIN,
  'admin/mail': SUPERADMIN,
  'admin/sheets-import': SUPERADMIN,
  'admin/modules': SUPERADMIN,
  'admin/errors': SUPERADMIN,
  'admin/integrations': SUPERADMIN,
};

describe('app routes — access table', () => {
  it('the login page is for guests; the career pages are public; everything else sits behind authGuard + moduleGuard', () => {
    expect(routes.find((r) => r.path === 'login')?.canActivate).toEqual([guestGuard]);
    for (const path of ['jobs', 'jobs/:slug']) {
      const route = routes.find((r) => r.path === path);
      expect(route?.canActivate ?? []).toEqual([]);
      expect(route?.children).toBeUndefined();
    }
    expect(shell.canActivate).toEqual([authGuard]);
    expect(shell.canActivateChild).toEqual([moduleGuard]);
    // No other top-level route: a page added next to the shell would skip both guards.
    expect(routes.map((r) => r.path)).toEqual(['login', 'jobs', 'jobs/:slug', '', '**']);
  });

  it('every admin page has a role guard (a new admin/* route without one fails here)', () => {
    const unguarded = children.filter((r) => r.path?.startsWith('admin/') && !(r.canActivate?.length));
    expect(unguarded.map((r) => r.path)).toEqual([]);
  });

  it('role-restricted pages open exactly for the roles of the table; recruiters, employees and viewers get none', async () => {
    const actual: Record<string, string[]> = {};
    for (const route of children.filter((r) => r.canActivate?.length)) {
      actual[route.path ?? ''] = await allowedRoles(route);
    }
    expect(actual).toEqual(EXPECTED);
  });

  it('a guest is sent to /login by a role-restricted page too', async () => {
    const route = children.find((r) => r.path === 'admin/users') as Route;
    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: AuthService, useValue: { load: () => Promise.resolve(), isLoggedIn: () => false, hasRole: () => false } }],
    });
    const guard = (route.canActivate ?? [])[0] as CanActivateFn;
    const result = await TestBed.runInInjectionContext(() => guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot));
    expect(result instanceof UrlTree && TestBed.inject(Router).serializeUrl(result)).toBe('/login');
  });
});

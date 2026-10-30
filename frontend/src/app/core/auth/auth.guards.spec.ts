import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { CanActivateFn, Router, Routes, UrlTree, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { authGuard, guestGuard, moduleGuard, roleGuard } from './auth.guards';
import { AuthService } from './auth.service';
import { CurrentUser, UserRole } from './auth.model';
import { windowOutletRoute } from '../windows/window-route';
import { routes as appRoutes } from '../../app.routes';
import { WINDOW_MODULE_DATA, windowRouteDefinitions } from '../windows/window-context';

@Component({ template: '' })
class Blank {}

function fakeAuth(roles: UserRole[] | null, modules: string[] = []): Partial<AuthService> {
  const user = signal<CurrentUser | null>(
    roles ? {
      id: 1,
      name: 'U',
      email: 'u@example.com',
      avatar_url: null,
      locale: 'uk',
      roles,
      modules,
      active_role: roles[0] ?? null,
      status: 'active',
    } : null,
  );
  return {
    load: () => Promise.resolve(),
    user: user.asReadonly(),
    isLoggedIn: () => user() !== null,
    hasRole: (role: UserRole) => user()?.roles.includes(role) ?? false,
    hasModule: (key: string) => user()?.modules?.includes(key) ?? false,
  } as Partial<AuthService>;
}

async function run(guard: CanActivateFn, roles: UserRole[] | null): Promise<boolean | string> {
  TestBed.configureTestingModule({ providers: [provideRouter([]), { provide: AuthService, useValue: fakeAuth(roles) }] });
  const result = await TestBed.runInInjectionContext(() =>
    guard({} as never, {} as never),
  );
  return result instanceof UrlTree ? TestBed.inject(Router).serializeUrl(result) : (result as boolean);
}

describe('auth guards', () => {
  it('authGuard lets a signed-in user in', async () => {
    expect(await run(authGuard, ['viewer'])).toBe(true);
  });

  it('authGuard sends a guest to /login', async () => {
    expect(await run(authGuard, null)).toBe('/login');
  });

  it('roleGuard allows the role', async () => {
    expect(await run(roleGuard('superadmin'), ['superadmin'])).toBe(true);
  });

  it('roleGuard sends other roles to /', async () => {
    expect(await run(roleGuard('superadmin'), ['recruiter'])).toBe('/');
  });

  it('roleGuard accepts any of several roles', async () => {
    expect(await run(roleGuard('superadmin', 'admin'), ['admin'])).toBe(true);
    TestBed.resetTestingModule();
    expect(await run(roleGuard('superadmin', 'admin'), ['viewer'])).toBe('/');
  });

  it('roleGuard sends a guest to /login', async () => {
    expect(await run(roleGuard('superadmin'), null)).toBe('/login');
  });

  it('guestGuard sends a signed-in user to /', async () => {
    expect(await run(guestGuard, ['viewer'])).toBe('/');
    TestBed.resetTestingModule();
    expect(await run(guestGuard, null)).toBe(true);
  });
});

describe('moduleGuard', () => {
  const routes: Routes = [{
    path: '',
    component: Blank,
    canActivateChild: [moduleGuard],
    children: [
      { path: '', pathMatch: 'full', component: Blank },
      { path: 'candidates/:id', component: Blank },
      { path: 'perform/objectives', component: Blank },
      { path: 'admin/users', component: Blank },
      { path: 'module-off', component: Blank },
      windowOutletRoute('os1', [
        { path: 'tasks', component: Blank, data: { windowRoute: 'tasks', module: null } },
        { path: 'people', component: Blank, data: { windowRoute: 'people', module: 'people' } },
        { path: 'profile', component: Blank, data: { module: 'people' } },
      ]),
    ],
  }];

  async function visit(url: string, modules: string[], routeConfig: Routes = routes, roles: UserRole[] = []): Promise<{ navigated: boolean; url: string }> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routeConfig),
        {
          provide: AuthService,
          useValue: fakeAuth(roles, modules),
        },
      ],
    });
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/');
    const router = TestBed.inject(Router);
    // The initial root navigation above exercises the guard for the core home route.
    if (url === '/') return { navigated: true, url: router.url };
    const navigated = await router.navigateByUrl(url);
    return { navigated, url: router.url };
  }

  it('lets pages of an available module through', async () => {
    expect(await visit('/candidates/5', ['recruiting'])).toEqual({ navigated: true, url: '/candidates/5' });
  });

  it('sends pages of a switched-off module to the friendly page', async () => {
    expect(await visit('/perform/objectives', ['recruiting'])).toEqual({ navigated: true, url: '/module-off?module=perform' });
  });

  it('never blocks core pages', async () => {
    expect(await visit('/admin/users', [])).toEqual({ navigated: true, url: '/admin/users' });
    expect(await visit('/', [])).toEqual({ navigated: true, url: '/' });
  });

  it('allows registered auxiliary tasks and rejects a disabled auxiliary module', async () => {
    expect(await visit('/(os1:window/tasks)', [])).toEqual({ navigated: true, url: '/(os1:window/tasks)' });
    expect(await visit('/(os1:window/people)', [])).toEqual({ navigated: false, url: '/' });
  });

  it('rejects an auxiliary route outside the window allowlist', async () => {
    expect(await visit('/(os1:window/profile)', ['people'])).toEqual({ navigated: false, url: '/' });
  });

  it('uses generated core-module metadata and preserves route role denial in auxiliary windows', async () => {
    const appShell = appRoutes.find((route) => route.path === '' && route.children);
    const generated = windowRouteDefinitions(appShell?.children ?? []);
    const docs = generated.find((route) => route.path === 'docs')!;
    const people = generated.find((route) => route.path === 'people')!;
    const users = generated.find((route) => route.path === 'admin/users')!;
    expect(docs.route.data?.[WINDOW_MODULE_DATA]).toBeNull();

    const catalogRoutes: Routes = [{ path: '', component: Blank, canActivateChild: [moduleGuard], children: [windowOutletRoute('os1', [docs.route, people.route, users.route])] }];
    expect(await visit('/(os1:window/docs)', [], catalogRoutes, ['viewer'])).toEqual({ navigated: true, url: '/(os1:window/docs)' });
    expect(await visit('/(os1:window/people)', [], catalogRoutes, ['viewer'])).toEqual({ navigated: false, url: '/' });
    // The role guard redirects the denied auxiliary route to the primary root; Router resolves the redirect as a successful navigation.
    expect(await visit('/(os1:window/admin/users)', [], catalogRoutes, ['viewer'])).toEqual({ navigated: true, url: '/' });
  });
});

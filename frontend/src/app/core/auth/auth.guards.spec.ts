import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, CanActivateFn, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { signal } from '@angular/core';
import { authGuard, guestGuard, roleGuard } from './auth.guards';
import { AuthService } from './auth.service';
import { CurrentUser, UserRole } from './auth.model';

function fakeAuth(roles: UserRole[] | null): Partial<AuthService> {
  const user = signal<CurrentUser | null>(
    roles ? { id: 1, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'uk', roles, status: 'active' } : null,
  );
  return {
    load: () => Promise.resolve(),
    isLoggedIn: () => user() !== null,
    hasRole: (role: UserRole) => user()?.roles.includes(role) ?? false,
  } as Partial<AuthService>;
}

async function run(guard: CanActivateFn, roles: UserRole[] | null): Promise<boolean | string> {
  TestBed.configureTestingModule({ providers: [provideRouter([]), { provide: AuthService, useValue: fakeAuth(roles) }] });
  const result = await TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
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

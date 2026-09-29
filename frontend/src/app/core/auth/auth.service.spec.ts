import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AuthService } from './auth.service';
import { CurrentUser } from './auth.model';

const USER: CurrentUser = {
  id: 1,
  name: 'Test User',
  email: 'test@example.com',
  avatar_url: null,
  locale: 'en',
  roles: ['superadmin'],
  status: 'active',
};

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the current user once', async () => {
    const first = service.load();
    const second = service.load();
    http.expectOne('/api/auth/me').flush(USER);
    await Promise.all([first, second]);

    expect(service.user()).toEqual({ ...USER, assigned_roles: ['superadmin'], active_role: null });
    expect(service.isLoggedIn()).toBe(true);
    expect(service.loading()).toBe(false);
    expect(service.hasRole('superadmin')).toBe(true);
    expect(service.hasRole('viewer')).toBe(false);
  });

  it('treats 401 as a guest', async () => {
    const done = service.load();
    http.expectOne('/api/auth/me').flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });
    await done;

    expect(service.user()).toBeNull();
    expect(service.isLoggedIn()).toBe(false);
  });

  it('logout clears the user even when the request fails', async () => {
    const loaded = service.load();
    http.expectOne('/api/auth/me').flush(USER);
    await loaded;

    const out = service.logout();
    http.expectOne({ method: 'POST', url: '/api/auth/logout' }).flush(null, { status: 500, statusText: 'Error' });
    await expect(out).rejects.toBeTruthy();
    expect(service.user()).toBeNull();
  });

  it('setLocale updates the loaded user', async () => {
    const loaded = service.load();
    http.expectOne('/api/auth/me').flush(USER);
    await loaded;

    service.setLocale('ru');
    expect(service.user()?.locale).toBe('ru');
  });

  it('setActiveRole narrows roles and modules to the server answer and null restores them', async () => {
    const loaded = service.load();
    http.expectOne('/api/auth/me').flush({ ...USER, roles: ['superadmin', 'recruiter'], effective_roles: ['superadmin', 'recruiter'], active_role: null });
    await loaded;

    const narrowed = service.setActiveRole('recruiter');
    const req = http.expectOne({ method: 'PUT', url: '/api/auth/active-role' });
    expect(req.request.body).toEqual({ role: 'recruiter' });
    req.flush({ ...USER, roles: ['superadmin', 'recruiter'], active_role: 'recruiter', effective_roles: ['recruiter'], modules: ['recruiting'] });
    await narrowed;

    expect(service.user()?.active_role).toBe('recruiter');
    expect(service.user()?.assigned_roles).toEqual(['superadmin', 'recruiter']);
    expect(service.hasRole('superadmin')).toBe(false);
    expect(service.hasRole('recruiter')).toBe(true);
    expect(service.hasModule('users')).toBe(false);

    const restored = service.setActiveRole(null);
    http.expectOne({ method: 'PUT', url: '/api/auth/active-role' }).flush({ ...USER, roles: ['superadmin', 'recruiter'], active_role: null, effective_roles: ['superadmin', 'recruiter'] });
    await restored;
    expect(service.hasRole('superadmin')).toBe(true);
    expect(service.user()?.active_role).toBeNull();
  });
});

import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { UsersService, userErrorKey } from './users.service';
import { AdminUser } from './users.model';

const USER: AdminUser = {
  id: 2,
  name: 'Rita',
  email: 'rita@example.com',
  avatar_url: null,
  roles: ['recruiter'],
  status: 'active',
  branches: [],
  locale: 'uk',
  safe_speak_handler: false,
  invited_by: 1,
  last_login_at: null,
  created_at: null,
};

describe('UsersService', () => {
  let service: UsersService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(UsersService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists with only the filled query params', () => {
    service.list({ q: 'ri', role: 'recruiter', status: undefined, page: 2, perPage: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/users');
    expect(req.request.params.keys().sort()).toEqual(['page', 'perPage', 'q', 'role']);
    expect(req.request.params.get('perPage')).toBe('20');
    req.flush({ data: [], meta: { current_page: 2, per_page: 20, total: 0, last_page: 1 } });
  });

  it('invites and unwraps the resource', () => {
    let created: AdminUser | undefined;
    service.invite({ email: 'rita@example.com', name: 'Rita', role: 'recruiter' }).subscribe((u) => (created = u));
    const req = http.expectOne({ method: 'POST', url: '/api/users' });
    expect(req.request.body).toEqual({ email: 'rita@example.com', name: 'Rita', role: 'recruiter' });
    req.flush({ data: USER });
    expect(created).toEqual(USER);
  });

  it('updates by id', () => {
    service.update(2, { status: 'blocked' }).subscribe();
    const req = http.expectOne({ method: 'PATCH', url: '/api/users/2' });
    expect(req.request.body).toEqual({ status: 'blocked' });
    req.flush({ data: { ...USER, status: 'blocked' } });
  });

  it('sends branch ids as a full replacement', () => {
    service.update(2, { branch_ids: [3, 5] }).subscribe();
    const req = http.expectOne({ method: 'PATCH', url: '/api/users/2' });
    expect(req.request.body).toEqual({ branch_ids: [3, 5] });
    req.flush({ data: { ...USER, branches: [{ id: 3, name: 'B3', status: 'active' }] } });
  });
});

describe('userErrorKey', () => {
  const err = (status: number, body: unknown) => new HttpErrorResponse({ status, error: body });

  it('maps business codes', () => {
    expect(userErrorKey(err(409, { code: 'email_taken' }))).toBe('users.errors.email_taken');
    expect(userErrorKey(err(422, { code: 'last_superadmin' }))).toBe('users.errors.last_superadmin');
    expect(userErrorKey(err(422, { code: 'self_change_forbidden' }))).toBe('users.errors.self_change_forbidden');
  });

  it('falls back to generic', () => {
    expect(userErrorKey(err(500, null))).toBe('users.errors.generic');
    expect(userErrorKey(err(422, { code: 'other' }))).toBe('users.errors.generic');
    expect(userErrorKey(new Error('x'))).toBe('users.errors.generic');
  });
});

import { TestBed } from '@angular/core/testing';
import { HttpClient, HttpXsrfTokenExtractor, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { CSRF_COOKIE_URL, XSRF_HEADER, csrfInterceptor } from './csrf.interceptor';

describe('csrfInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([csrfInterceptor])),
        provideHttpClientTesting(),
        { provide: HttpXsrfTokenExtractor, useValue: { getToken: () => 'tok' } },
      ],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  it('does not touch GET requests', () => {
    http.get('/api/auth/me').subscribe();
    backend.expectOne('/api/auth/me').flush({});
    backend.expectNone(CSRF_COOKIE_URL);
  });

  it('fetches the csrf cookie once before mutating requests and sends the header', () => {
    http.post('/api/auth/logout', {}).subscribe();
    backend.expectOne(CSRF_COOKIE_URL).flush(null, { status: 204, statusText: 'No Content' });
    const first = backend.expectOne('/api/auth/logout');
    expect(first.request.headers.get(XSRF_HEADER)).toBe('tok');
    first.flush(null);

    http.patch('/api/auth/me/locale', { locale: 'en' }).subscribe();
    backend.expectNone(CSRF_COOKIE_URL);
    backend.expectOne('/api/auth/me/locale').flush({});
  });

  it('refreshes the cookie and retries once on 419', () => {
    http.post('/api/users', {}).subscribe();
    backend.expectOne(CSRF_COOKIE_URL).flush(null);
    backend.expectOne('/api/users').flush(null, { status: 419, statusText: 'Page Expired' });
    backend.expectOne(CSRF_COOKIE_URL).flush(null);
    backend.expectOne('/api/users').flush({});
  });
});

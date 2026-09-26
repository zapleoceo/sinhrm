import { HttpClient, HttpErrorResponse, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../auth/auth.service';
import { CLIENT_ERRORS_URL, ErrorReporter, describeError, normalizeApiPath } from './error-reporter.service';
import { GlobalErrorHandler } from './global-error-handler';
import { serverErrorInterceptor } from './server-error.interceptor';

describe('client error reporting', () => {
  const user = signal<object | null>({ id: 1 });
  let http: HttpTestingController;

  beforeEach(() => {
    user.set({ id: 1 });
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([serverErrorInterceptor])),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: { user } },
        GlobalErrorHandler,
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('describes an Error by name, message and first stack frame', () => {
    const e = new TypeError('x is undefined');
    e.stack = 'TypeError: x is undefined\n    at f (https://sinhrm.vercel.app/chunk-AB12.js:10:5)';
    expect(describeError(e)).toEqual({ kind: 'TypeError', message: 'x is undefined', location: 'chunk-AB12.js:10:5' });
    expect(describeError({ rejection: 'boom' })).toEqual({ kind: 'Error', message: 'boom', location: null });
  });

  it('normalizes API paths: no ids, no query', () => {
    expect(normalizeApiPath('/api/people/42/documents?q=ivan')).toBe('/api/people/{id}/documents');
  });

  it('posts a JS error once, deduplicates repeats, and not for a guest', () => {
    const handler = TestBed.inject(GlobalErrorHandler);
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const boom = () => new Error('boom'); // same code location both times
    handler.handleError(boom());
    handler.handleError(boom());
    const req = http.expectOne(CLIENT_ERRORS_URL);
    expect(req.request.body.kind).toBe('Error');
    expect(req.request.body.message).toBe('boom');
    expect(req.request.body.route).toBe(location.pathname);
    req.flush(null, { status: 204, statusText: 'No Content' });

    user.set(null);
    handler.handleError(new Error('guest'));
    http.expectNone(CLIENT_ERRORS_URL);
  });

  it('reports a 5xx through the interceptor and passes the error on; 4xx and the report endpoint itself are not reported', () => {
    const client = TestBed.inject(HttpClient);
    let status = 0;
    client.get('/api/people/7').subscribe({ error: (e: HttpErrorResponse) => (status = e.status) });
    http.expectOne('/api/people/7').flush(null, { status: 502, statusText: 'Bad Gateway' });
    expect(status).toBe(502);
    const report = http.expectOne(CLIENT_ERRORS_URL);
    expect(report.request.body).toEqual(expect.objectContaining({ kind: 'HTTP 502', message: 'GET /api/people/{id}', location: '/api/people/{id}' }));
    report.flush(null, { status: 500, statusText: 'Server Error' });
    http.expectNone(CLIENT_ERRORS_URL);

    client.get('/api/people/8').subscribe({ error: () => undefined });
    http.expectOne('/api/people/8').flush(null, { status: 404, statusText: 'Not Found' });
    http.expectNone(CLIENT_ERRORS_URL);
  });

  it('never throws from report()', () => {
    const reporter = TestBed.inject(ErrorReporter);
    expect(() => reporter.report({ kind: 'Error', message: '', location: null })).not.toThrow();
    http.expectOne(CLIENT_ERRORS_URL).flush(null);
  });
});

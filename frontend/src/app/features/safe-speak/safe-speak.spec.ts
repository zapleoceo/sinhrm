import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { SafeSpeakService, safeSpeakErrorKey } from './safe-speak.service';

describe('SafeSpeakService', () => {
  let api: SafeSpeakService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(SafeSpeakService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends the code only in the body, never in the URL', () => {
    let code = '';
    api.submit({ category: 'safety', subject: 'S', body: 'B' }).subscribe((r) => (code = r.code));
    http.expectOne({ method: 'POST', url: '/api/safe-speak/public/reports' }).flush({ data: { code: 'ABCD-EFGH-JKMN-PQRS', report: {} } });
    expect(code).toBe('ABCD-EFGH-JKMN-PQRS');

    api.followUp('ABCD-EFGH-JKMN-PQRS').subscribe();
    const follow = http.expectOne({ method: 'POST', url: '/api/safe-speak/public/follow-up' });
    expect(follow.request.body).toEqual({ code: 'ABCD-EFGH-JKMN-PQRS' });
    expect(follow.request.urlWithParams).not.toContain('ABCD');
    follow.flush({ data: {} });

    api.reply('ABCD-EFGH-JKMN-PQRS', 'more').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/safe-speak/public/reply' }).request.body).toEqual({ code: 'ABCD-EFGH-JKMN-PQRS', body: 'more' });
  });

  it('calls the handler endpoints', () => {
    let handler = false;
    api.isHandler().subscribe((h) => (handler = h));
    http.expectOne('/api/safe-speak/me').flush({ data: { handler: true } });
    expect(handler).toBe(true);
    api.inbox('new').subscribe();
    http.expectOne((r) => r.url === '/api/safe-speak/reports' && r.params.get('status') === 'new').flush({ data: [] });
    api.get(4).subscribe();
    http.expectOne('/api/safe-speak/reports/4').flush({ data: {} });
    api.answer(4, 'ok').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/safe-speak/reports/4/messages' }).request.body).toEqual({ body: 'ok' });
    api.setStatus(4, 'closed').subscribe();
    expect(http.expectOne({ method: 'PATCH', url: '/api/safe-speak/reports/4' }).request.body).toEqual({ status: 'closed' });
  });

  it('maps errors including the rate limit', () => {
    expect(safeSpeakErrorKey(new HttpErrorResponse({ status: 429, error: { code: 'too_many_attempts' } }))).toBe('safeSpeak.errors.too_many_attempts');
    expect(safeSpeakErrorKey(new HttpErrorResponse({ status: 404, error: { code: 'invalid_code' } }))).toBe('safeSpeak.errors.invalid_code');
    expect(safeSpeakErrorKey(new HttpErrorResponse({ status: 429 }))).toBe('safeSpeak.errors.rate_limited');
  });
});

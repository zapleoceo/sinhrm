import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SenderRule, UnknownSender, isSenderPattern } from './mail.model';
import { mailErrorKey } from './mail.service';
import { MailStore } from './mail.store';

const RULE: SenderRule = { id: 1, pattern: '@jobs.example.test', kind: 'job_board', parser: 'generic', hits: 0, last_seen_at: null, created_at: null };
const SENDER: UnknownSender = {
  id: 5,
  email: 'robot@board.example.test',
  sample_subject: 'New application',
  count: 2,
  first_seen_at: '2026-09-29T10:00:00+00:00',
  last_seen_at: '2026-09-29T10:00:00+00:00',
  suggested_kind: 'job_board',
  suggested_parser: 'generic',
};
const STATUS = {
  connection: { service: 'gmail', integration_key: 'google_gmail', status: 'connected', connected: true, account_email: 'box@example.test', scopes: [], error: null, connected_at: null },
  last_sync: null,
  counts: { rules: 1, unknown: 1, processed_24h: 0 },
};

describe('mail.model', () => {
  it('validates sender patterns like the backend', () => {
    expect(isSenderPattern('@work.ua')).toBe(true);
    expect(isSenderPattern(' HR@Site.Example.Test ')).toBe(true);
    expect(isSenderPattern('work.ua')).toBe(false);
    expect(isSenderPattern('@localhost')).toBe(false);
  });

  it('maps error codes', () => {
    expect(mailErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'duplicate_rule' } }))).toBe('mail.errors.duplicate_rule');
    expect(mailErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'google_gmail_not_connected' } }))).toBe('mail.errors.google_gmail_not_connected');
    expect(mailErrorKey(new HttpErrorResponse({ status: 422 }))).toBe('mail.errors.validation');
    expect(mailErrorKey(null)).toBe('mail.errors.generic');
  });
});

describe('MailStore', () => {
  let store: MailStore;
  let http: HttpTestingController;

  const flushLoad = (): void => {
    http.expectOne('/api/mail/status').flush({ data: STATUS });
    http.expectOne('/api/mail/rules').flush({ data: [RULE] });
    http.expectOne('/api/mail/unknown-senders').flush({ data: [SENDER] });
    http.expectOne('/api/mail/messages').flush({ data: [] });
  };

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [MailStore, provideHttpClient(), provideHttpClientTesting()] });
    store = TestBed.inject(MailStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads everything at once', () => {
    store.load();
    flushLoad();
    expect(store.status()?.counts.rules).toBe(1);
    expect(store.rules()).toEqual([RULE]);
    expect(store.unknown()).toEqual([SENDER]);
    expect(store.loading()).toBe(false);
  });

  it('syncs, keeps the counters and reloads; a failure is reported as an i18n key', () => {
    const errors: string[] = [];
    store.sync((k) => errors.push(k));
    http.expectOne({ method: 'POST', url: '/api/mail/sync' }).flush({ data: { processed: 3, application: 1 } });
    flushLoad();
    expect(store.lastSync()?.application).toBe(1);

    store.sync((k) => errors.push(k));
    http.expectOne({ method: 'POST', url: '/api/mail/sync' }).flush({ code: 'reconnect_required' }, { status: 409, statusText: 'Conflict' });
    flushLoad();
    expect(errors).toEqual(['mail.errors.reconnect_required']);
    expect(store.syncing()).toBe(false);
  });

  it('deletes a rule optimistically and restores it on failure', () => {
    store.rules.set([RULE]);
    const errors: string[] = [];
    store.deleteRule(RULE, (k) => errors.push(k));
    expect(store.rules()).toEqual([]);
    http.expectOne({ method: 'DELETE', url: '/api/mail/rules/1' }).flush(null, { status: 500, statusText: 'Error' });
    expect(store.rules()).toEqual([RULE]);
    expect(errors).toEqual(['mail.errors.generic']);
  });

  it('assigns an unknown sender to a domain rule and refreshes both lists', () => {
    let done = false;
    store.assign(SENDER, { kind: 'job_board', parser: 'robota_ua', scope: 'domain' }, () => (done = true), () => undefined);
    const req = http.expectOne({ method: 'POST', url: '/api/mail/unknown-senders/5/assign' });
    expect(req.request.body).toEqual({ kind: 'job_board', parser: 'robota_ua', scope: 'domain' });
    req.flush({ data: { ...RULE, id: 2, pattern: '@board.example.test' } });
    http.expectOne('/api/mail/rules').flush({ data: [RULE] });
    http.expectOne('/api/mail/unknown-senders').flush({ data: [] });
    expect(done).toBe(true);
    expect(store.unknown()).toEqual([]);
  });
});

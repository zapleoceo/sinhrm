import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { IntegrationsService, buildUpdate, checkResultKey, integrationErrorKey } from './integrations.service';
import { Integration, IntegrationField } from './integrations.model';

const TELEGRAM: Integration = {
  key: 'telegram_business',
  group: 'messengers',
  status: 'off',
  supports_check: true,
  last_checked_at: null,
  last_error: null,
  updated_at: null,
  fields: [
    { name: 'bot_token', type: 'secret', required: true, options: [], default: null, secret: { is_set: true, updated_at: null, masked: '••••9876' } },
  ],
};

describe('IntegrationsService', () => {
  let service: IntegrationsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(IntegrationsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists integrations with the AI policy', () => {
    let enabled: boolean | undefined;
    service.list().subscribe((l) => (enabled = l.ai_policy.enabled));
    http.expectOne({ method: 'GET', url: '/api/integrations' }).flush({ data: [TELEGRAM], ai_policy: { enabled: false } });
    expect(enabled).toBe(false);
  });

  it('updates by key and unwraps the resource', () => {
    let saved: Integration | undefined;
    service.update('telegram_business', { secrets: { bot_token: null } }).subscribe((i) => (saved = i));
    const req = http.expectOne({ method: 'PUT', url: '/api/integrations/telegram_business' });
    expect(req.request.body).toEqual({ secrets: { bot_token: null } });
    req.flush({ data: TELEGRAM });
    expect(saved).toEqual(TELEGRAM);
  });

  it('runs check, switches status, reads logs and sets the AI policy', () => {
    service.check('ai_broker').subscribe();
    http.expectOne({ method: 'POST', url: '/api/integrations/ai_broker/check' }).flush({ data: TELEGRAM });

    service.setStatus('viber', 'demo').subscribe();
    const status = http.expectOne({ method: 'POST', url: '/api/integrations/viber/status' });
    expect(status.request.body).toEqual({ status: 'demo' });
    status.flush({ data: TELEGRAM });

    let count = -1;
    service.logs('viber').subscribe((l) => (count = l.length));
    http.expectOne({ method: 'GET', url: '/api/integrations/viber/logs' }).flush({ data: [] });
    expect(count).toBe(0);

    service.setAiPolicy(true).subscribe();
    const ai = http.expectOne({ method: 'PUT', url: '/api/integrations/ai-policy' });
    expect(ai.request.body).toEqual({ enabled: true });
    ai.flush({ data: { enabled: true } });
  });
});

describe('buildUpdate', () => {
  const fields: IntegrationField[] = [
    { name: 'base_url', type: 'url', required: true, options: [], default: 'https://a.example' },
    { name: 'project_key', type: 'secret', required: true, options: [], default: null },
    { name: 'api_key', type: 'secret', required: true, options: [], default: null },
  ];

  it('sends settings in full, typed secrets as values, cleared as null, empty secrets not at all', () => {
    const body = buildUpdate(fields, { base_url: 'https://b.example', project_key: '', api_key: 'fake-new' }, new Set());
    expect(body).toEqual({ settings: { base_url: 'https://b.example' }, secrets: { api_key: 'fake-new' } });

    expect(buildUpdate(fields, { base_url: 'x', project_key: 'typed', api_key: '' }, new Set(['project_key']))).toEqual({
      settings: { base_url: 'x' },
      secrets: { project_key: null },
    });
  });

  it('omits empty sections', () => {
    expect(buildUpdate([], {}, new Set())).toEqual({});
  });
});

describe('error and check-result keys', () => {
  const err = (status: number, body: unknown) => new HttpErrorResponse({ status, error: body });

  it('maps business codes and falls back to generic', () => {
    expect(integrationErrorKey(err(422, { code: 'check_not_supported' }))).toBe('integrations.errors.check_not_supported');
    expect(integrationErrorKey(err(500, null))).toBe('integrations.errors.generic');
    expect(integrationErrorKey(new Error('x'))).toBe('integrations.errors.generic');
  });

  it('maps last_error codes', () => {
    expect(checkResultKey('missing_secret:bot_token')).toBe('integrations.check.missing_secret');
    expect(checkResultKey('http_503')).toBe('integrations.check.http');
    expect(checkResultKey('unauthorized')).toBe('integrations.check.unauthorized');
    expect(checkResultKey('blocked_host')).toBe('integrations.check.blocked_host');
    expect(checkResultKey('invalid_token')).toBe('integrations.check.invalid_token');
    expect(checkResultKey('something else')).toBeNull();
    expect(checkResultKey(null)).toBeNull();
  });
});

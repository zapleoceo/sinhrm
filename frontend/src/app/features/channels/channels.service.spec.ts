import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChannelInfo } from './channels.model';
import { ChannelsService, channelErrorCode, channelErrorKey, webhookUrlForConsole } from './channels.service';

const INFO: ChannelInfo = {
  key: 'binotel',
  channel: 'call',
  mode: 'demo',
  webhook_url: 'https://app.example.test/api/webhooks/binotel',
  auth: 'query_token',
  can_register: false,
  can_send: false,
  can_call: false,
  handshake: false,
};

describe('ChannelsService', () => {
  let service: ChannelsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(ChannelsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads availability once and answers modes by timeline channel', () => {
    expect(service.modeOf('telegram')).toBe('off');
    service.ensureAvailability();
    service.ensureAvailability();
    http.expectOne({ method: 'GET', url: '/api/channels' }).flush({
      data: [
        { key: 'telegram_business', channel: 'telegram', mode: 'demo' },
        { key: 'viber', channel: 'viber', mode: 'off' },
        { key: 'phonet', channel: 'call', mode: 'off' },
        { key: 'ringostat', channel: 'call', mode: 'live' },
      ],
    });
    expect(service.modeOf('telegram')).toBe('demo');
    expect(service.modeOf('viber')).toBe('off');
    expect(service.modeOf('call')).toBe('live');
    expect(service.modeOf('whatsapp')).toBe('off');
  });

  it('knows why e-mail sending is off (Gmail connected read-only)', () => {
    service.ensureAvailability();
    http.expectOne('/api/channels').flush({
      data: [{ key: 'google_gmail', channel: 'email', mode: 'off', reason: 'reconnect_to_send' }],
    });
    expect(service.modeOf('email')).toBe('off');
    expect(service.reasonOf('email')).toBe('reconnect_to_send');
    expect(service.reasonOf('telegram')).toBeNull();
  });

  it('sends an e-mail with a subject', () => {
    service.send(7, { channel: 'email', text: 'Hi', subject: 'Interview' }).subscribe();
    const req = http.expectOne({ method: 'POST', url: '/api/candidates/7/messages' });
    expect(req.request.body).toEqual({ channel: 'email', text: 'Hi', subject: 'Interview' });
    req.flush({ data: { id: 43 } });
  });

  it('retries availability after a failure', () => {
    service.ensureAvailability();
    http.expectOne('/api/channels').flush(null, { status: 500, statusText: 'Server Error' });
    service.ensureAvailability();
    http.expectOne('/api/channels').flush({ data: [] });
  });

  it('sends a message from the card', () => {
    let id: number | undefined;
    service.send(7, { channel: 'whatsapp', text: 'Hi' }).subscribe((t) => (id = t.id));
    const req = http.expectOne({ method: 'POST', url: '/api/candidates/7/messages' });
    expect(req.request.body).toEqual({ channel: 'whatsapp', text: 'Hi' });
    req.flush({ data: { id: 42 } });
    expect(id).toBe(42);
  });

  it('calls the admin endpoints', () => {
    service.registerWebhook('telegram_business').subscribe();
    http.expectOne({ method: 'POST', url: '/api/channels/telegram_business/register-webhook' }).flush({ data: { registered: true } });
    service.sendTest('viber', 'abc==', '').subscribe();
    expect(http.expectOne('/api/channels/viber/test').request.body).toEqual({ to: 'abc==', text: undefined });
    let created = 0;
    service.simulate('phonet', {}).subscribe((r) => (created = r.created));
    http.expectOne({ method: 'POST', url: '/api/channels/phonet/simulate' }).flush({ data: { events: 1, created: 1 } });
    expect(created).toBe(1);
  });

  it('maps error codes to i18n keys', () => {
    const err = (status: number, code?: string) => new HttpErrorResponse({ status, error: code ? { code } : null });
    expect(channelErrorCode(err(422, 'channel_not_connected'))).toBe('channel_not_connected');
    expect(channelErrorKey(err(422, 'template_required'))).toBe('channels.errors.template_required');
    expect(channelErrorKey(err(422, 'something_else'))).toBe('channels.errors.generic');
    expect(channelErrorKey(err(403))).toBe('recruiting.errors.forbidden');
    expect(channelErrorCode(new Error('x'))).toBeNull();
  });

  it('adds the token placeholder only for query-token webhooks', () => {
    expect(webhookUrlForConsole(INFO, 'TOKEN')).toBe('https://app.example.test/api/webhooks/binotel?token=TOKEN');
    expect(webhookUrlForConsole({ ...INFO, auth: 'hmac' }, 'TOKEN')).toBe('https://app.example.test/api/webhooks/binotel');
  });
});

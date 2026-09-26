import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RecruitingService } from '../recruiting.service';
import { ChannelsService, channelErrorKey, ruleLabel } from './channels.service';

describe('ChannelsService', () => {
  let api: ChannelsService;
  let recruiting: RecruitingService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(ChannelsService);
    recruiting = TestBed.inject(RecruitingService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('calls the acquisition channel endpoints', () => {
    recruiting.channels().subscribe();
    http.expectOne((r) => r.url === '/api/acquisition-channels' && !r.params.has('all')).flush({ data: [] });
    recruiting.channels(true).subscribe();
    http.expectOne((r) => r.url === '/api/acquisition-channels' && r.params.get('all') === '1').flush({ data: [] });
    recruiting.vacancySources(5).subscribe();
    http.expectOne('/api/vacancies/5/sources').flush({ data: [] });

    api.save(null, { code: 'jooble', name: 'Jooble', type: 'job_board' }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/acquisition-channels' }).request.body).toEqual({ code: 'jooble', name: 'Jooble', type: 'job_board' });
    api.save(2, { active: false }).subscribe();
    http.expectOne({ method: 'PATCH', url: '/api/acquisition-channels/2' }).flush({ data: {} });
    api.addRule(2, { utm_source: 'jooble', utm_medium: null, utm_campaign: null, priority: 10 }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/acquisition-channels/2/utm-rules' }).flush({ data: {} });
    api.deleteRule(7).subscribe();
    http.expectOne({ method: 'DELETE', url: '/api/acquisition-channels/utm-rules/7' }).flush(null);
    api.addCost(2, { period_start: '2026-10-01', period_end: '2026-10-31', amount: 100 }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/acquisition-channels/2/costs' }).flush({ data: {} });
    api.deleteCost(8).subscribe();
    http.expectOne({ method: 'DELETE', url: '/api/acquisition-channels/costs/8' }).flush(null);
    api.preview({ utm_source: 'facebook', utm_medium: 'paid', utm_campaign: null }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/acquisition-channels/resolve' }).flush({ data: { channel_id: 1, rule_id: 2 } });
  });

  it('labels rules and maps errors', () => {
    expect(ruleLabel({ utm_source: 'facebook', utm_medium: 'paid', utm_campaign: null })).toBe('utm_source=facebook · utm_medium=paid');
    expect(channelErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'channel_code_taken' } }))).toBe('recruiting.channels.errors.channel_code_taken');
  });
});

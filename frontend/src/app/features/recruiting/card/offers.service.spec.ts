import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Offer, OfferTemplateRef, OffersService } from './offers.service';

const OFFER: Offer = { id: 1, position: 'Tutor', salary: '1000', start_date: null, conditions: null, content_md: '# Offer', status: 'draft' };

describe('OffersService', () => {
  let api: OffersService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(OffersService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the offer of an application (null when none) and the templates', () => {
    let offer: Offer | null | undefined;
    api.offer(7).subscribe((o) => (offer = o));
    http.expectOne({ method: 'GET', url: '/api/applications/7/offer' }).flush({ data: null });
    expect(offer).toBeNull();

    let templates: OfferTemplateRef[] = [];
    api.templates().subscribe((t) => (templates = t));
    http.expectOne({ method: 'GET', url: '/api/offer-templates' }).flush({ data: [{ id: 2, name: 'Standard' }] });
    expect(templates).toEqual([{ id: 2, name: 'Standard' }]);
  });

  it('creates, sends and records the decision', () => {
    let got: Offer | undefined;
    const body = { template_id: 2, position: 'Tutor', salary: '1000', start_date: null, conditions: null };
    api.create(7, body).subscribe((o) => (got = o));
    const create = http.expectOne({ method: 'POST', url: '/api/applications/7/offer' });
    expect(create.request.body).toEqual(body);
    create.flush({ data: OFFER });
    expect(got).toEqual(OFFER);

    api.act(7, 'send').subscribe();
    const send = http.expectOne({ method: 'POST', url: '/api/applications/7/offer/send' });
    expect(send.request.body).toEqual({});
    send.flush({ data: { ...OFFER, status: 'sent' } });

    api.act(7, 'decision', 'accepted').subscribe((o) => (got = o));
    const decision = http.expectOne({ method: 'POST', url: '/api/applications/7/offer/decision' });
    expect(decision.request.body).toEqual({ status: 'accepted' });
    decision.flush({ data: { ...OFFER, status: 'accepted' } });
    expect(got?.status).toBe('accepted');
  });
});

import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject, of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { Application } from '../recruiting.model';
import { OfferPanel } from './offer-panel';
import { Offer, OffersService } from './offers.service';

const OFFER: Offer = { id: 1, position: 'Tutor', salary: '1000', start_date: null, conditions: null, content_md: 'Text', status: 'draft' };
const APP = { id: 7, stage: { id: 3, name: 'Offer', kind: 'hire', position: 4, is_terminal: false, is_reject: false, is_hire: false }, vacancy: { title: 'Tutor' } } as unknown as Application;

function setup(existing: Offer | null) {
  const created = new Subject<Offer>();
  const api = {
    offer: vi.fn(() => of(existing)),
    templates: vi.fn(() => of([{ id: 2, name: 'Standard' }])),
    create: vi.fn(() => created),
    act: vi.fn(() => of({ ...OFFER, status: 'sent' as const })),
  };
  TestBed.configureTestingModule({
    imports: [OfferPanel, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [{ provide: OffersService, useValue: api }],
  });
  const fixture = TestBed.createComponent(OfferPanel);
  fixture.componentRef.setInput('application', APP);
  fixture.detectChanges();
  return { fixture, api, created, el: fixture.nativeElement as HTMLElement };
}

describe('OfferPanel', () => {
  it('loads the offer of the application through OffersService and sends it', () => {
    const { fixture, api, el } = setup(OFFER);
    expect(api.offer).toHaveBeenCalledWith(7);
    expect(el.textContent).toContain('Tutor');
    (el.querySelector('button') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(api.act).toHaveBeenCalledWith(7, 'send', undefined);
    expect(el.textContent).toContain('careers.offer.status.sent');
  });

  it('opens the form with templates and creates the offer with empty optional fields as null', () => {
    const { fixture, api, created, el } = setup(null);
    (el.querySelector('button') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(api.templates).toHaveBeenCalled();
    const panel = fixture.componentInstance as unknown as { form: { patchValue(v: object): void } };
    panel.form.patchValue({ template_id: 2, salary: '1000', conditions: '  ' });
    (el.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    expect(api.create).toHaveBeenCalledWith(7, { template_id: 2, position: 'Tutor', salary: '1000', start_date: null, conditions: null });
    created.next(OFFER);
    fixture.detectChanges();
    expect(el.textContent).toContain('careers.offer.status.draft');
  });

  it('shows the recruiting error text when the API refuses', () => {
    const { fixture, api, el } = setup(OFFER);
    api.act.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 403 })));
    (el.querySelector('button') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('recruiting.errors.forbidden');
  });
});

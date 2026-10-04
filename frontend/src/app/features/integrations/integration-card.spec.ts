import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of, throwError } from 'rxjs';
import { IntegrationCard } from './integration-card';
import { Integration } from './integrations.model';
import { IntegrationsService } from './integrations.service';
import { IntegrationsStore } from './integrations.store';
import { NotifyService } from '../../core/ui/notify.service';
import en from '../../../../public/i18n/en.json';

const gmail: Integration = {
  key: 'google_gmail', group: 'google', status: 'error', supports_check: false,
  last_checked_at: null, last_error: 'reconnect_required', updated_at: null, fields: [],
};

function setup(focused = false, failLogs = false) {
  TestBed.configureTestingModule({
    imports: [IntegrationCard, TranslocoTestingModule.forRoot({
      langs: { en }, preloadLangs: true, translocoConfig: { availableLangs: ['en'], defaultLang: 'en' },
    })],
    providers: [
      { provide: IntegrationsStore, useValue: { pending: () => new Set<string>() } },
      { provide: IntegrationsService, useValue: { logs: () => failLogs ? throwError(() => new Error('synthetic failure')) : of([
        { id: 1, level: 'warning', message: 'reconnect_required', created_at: null, context: {} },
        { id: 2, level: 'info', message: 'google_connected', created_at: null, context: {} },
      ]) } },
      { provide: NotifyService, useValue: { show: () => undefined } },
    ],
  });
  const fixture = TestBed.createComponent(IntegrationCard);
  fixture.componentRef.setInput('item', gmail);
  fixture.componentRef.setInput('focused', focused);
  fixture.detectChanges();
  return { fixture, element: fixture.nativeElement as HTMLElement };
}

describe('Google integration reconnect card', () => {
  it('offers consent navigation with the actual three-service scope before expanding', () => {
    const { element } = setup();
    const action = element.querySelector<HTMLAnchorElement>('.google-connect a');
    expect(action?.getAttribute('href')).toBe('/api/google/connect?services=gmail,calendar,sheets');
    expect(action?.textContent).toContain(en.integrations.google.reconnect);
    expect(element.querySelector('.google-connect p')?.textContent).toContain(en.integrations.google.scope);
    expect(element.querySelector('.result')?.textContent).toContain(en.integrations.check.reconnect_required);
    expect(element.textContent).not.toContain('reconnect_required');
  });

  it('opens the contextual card and translates connection logs without the obsolete setup hint', () => {
    const { element } = setup(true);
    expect(element.querySelector('article.open')?.id).toBe('integration-google_gmail');
    expect(element.querySelector('.form')?.textContent).toContain(en.integrations.google.noFields);
    expect(element.textContent).not.toContain(en.integrations.noFields);
    expect(element.querySelector('.logs')?.textContent).toContain(en.integrations.logs.messages.google_connected);
    expect(element.querySelector('.logs')?.textContent).not.toContain('integrations.logs.messages.');
  });

  it('keeps reconnect available when the log request fails and offers a retry', () => {
    const { element } = setup(true, true);
    expect(element.querySelector('.logs')?.textContent).toContain(en.integrations.logs.error);
    expect(element.querySelector('.logs button')?.textContent).toContain(en.common.retry);
    expect(element.querySelector('.google-connect a')).not.toBeNull();
  });

  it('does not offer Google consent for another integration', () => {
    const { fixture, element } = setup();
    fixture.componentRef.setInput('item', { ...gmail, key: 'viber', group: 'messengers', last_error: null });
    fixture.detectChanges();
    expect(element.querySelector('.google-connect')).toBeNull();
  });
});

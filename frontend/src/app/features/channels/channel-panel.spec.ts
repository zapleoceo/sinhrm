import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { ChannelPanel } from './channel-panel';
import { ChannelInfo } from './channels.model';
import { ChannelsService } from './channels.service';
import { NotifyService } from '../../core/ui/notify.service';
import en from '../../../../public/i18n/en.json';

const PHONET: ChannelInfo = {
  key: 'phonet',
  channel: 'call',
  mode: 'demo',
  webhook_url: 'https://app.example.test/api/webhooks/phonet',
  auth: 'header_token',
  can_register: false,
  can_send: false,
  can_call: false,
  handshake: false,
  legacy_query_token: false,
};

function render(info: ChannelInfo): HTMLElement {
  TestBed.configureTestingModule({
    imports: [ChannelPanel, TranslocoTestingModule.forRoot({
      langs: { en }, preloadLangs: true, translocoConfig: { availableLangs: ['en'], defaultLang: 'en' },
    })],
    providers: [
      { provide: ChannelsService, useValue: { adminOverview: () => of([info]) } },
      { provide: NotifyService, useValue: { show: () => undefined } },
    ],
  });
  const fixture = TestBed.createComponent(ChannelPanel);
  fixture.componentRef.setInput('key', info.key);
  fixture.componentRef.setInput('status', 'demo');
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('ChannelPanel (HRM-26 telephony token)', () => {
  it('shows the plain URL and the header instruction with a placeholder, never ?token=', () => {
    const element = render(PHONET);
    const codes = Array.from(element.querySelectorAll('code')).map((c) => c.textContent?.trim());
    expect(codes).toEqual(['https://app.example.test/api/webhooks/phonet', `X-Webhook-Token: ${en.channels.panel.tokenPlaceholder}`]);
    expect(element.textContent).toContain(en.channels.auth.header_token);
    expect(element.textContent).not.toContain('?token=YOUR');
    expect(element.querySelector('.legacy')).toBeNull();
  });

  it('warns while the deprecated ?token= is still allowed', () => {
    const element = render({ ...PHONET, legacy_query_token: true });
    expect(element.querySelector('.legacy')?.textContent).toContain(en.channels.panel.legacyQueryToken);
  });

  it('has no header instruction for signature-based messengers', () => {
    const element = render({ ...PHONET, key: 'viber', channel: 'viber', auth: 'hmac' });
    expect(element.querySelectorAll('code').length).toBe(1);
  });
});

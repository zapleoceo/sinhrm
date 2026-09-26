import { TestBed } from '@angular/core/testing';
import { faGoogle, faLinkedin, faMeta, faTelegram, faViber, faWhatsapp } from '@fortawesome/free-brands-svg-icons';
import { faBriefcase, faCircleQuestion, faEnvelope, faPhoneVolume, faRobot, faTable, faWaveSquare } from '@fortawesome/free-solid-svg-icons';
import { ChannelIcon } from './channel-icon';
import { CHANNEL_ICON_SPECS, channelIconSpec } from './channel-icons';

describe('channelIconSpec', () => {
  it('maps messengers and brands to brand icons in brand colors', () => {
    expect(channelIconSpec('telegram')).toEqual({ icon: faTelegram, color: '#26A5E4', badge: null });
    expect(channelIconSpec('telegram_business').icon).toBe(faTelegram);
    expect(channelIconSpec('whatsapp').icon).toBe(faWhatsapp);
    expect(channelIconSpec('whatsapp_cloud').icon).toBe(faWhatsapp);
    expect(channelIconSpec('viber').icon).toBe(faViber);
    expect(channelIconSpec('linkedin').icon).toBe(faLinkedin);
    expect(channelIconSpec('meta_ads').icon).toBe(faMeta);
    expect(channelIconSpec('meta_lead_ads').icon).toBe(faMeta);
    expect(channelIconSpec('google').icon).toBe(faGoogle);
  });

  it('gives job boards without a Free brand icon a briefcase with a letter badge', () => {
    for (const [key, badge] of [['work_ua', 'W'], ['robota_ua', 'R'], ['djinni', 'Dj'], ['dou', 'D']]) {
      expect(channelIconSpec(key)).toEqual({ icon: faBriefcase, color: null, badge });
    }
  });

  it('maps telephony, Google services and AI providers', () => {
    for (const key of ['phonet', 'ringostat', 'binotel']) {
      expect(channelIconSpec(key).icon).toBe(faPhoneVolume);
    }
    expect(channelIconSpec('google_gmail').icon).toBe(faEnvelope);
    expect(channelIconSpec('sheets').icon).toBe(faTable);
    expect(channelIconSpec('openrouter').icon).toBe(faRobot);
    expect(channelIconSpec('deepgram').icon).toBe(faWaveSquare);
  });

  it('keeps generic channels in the text color', () => {
    expect(channelIconSpec('email')).toEqual({ icon: faEnvelope, color: null, badge: null });
    expect(channelIconSpec('call').color).toBeNull();
  });

  it('falls back to a neutral icon for unknown or empty keys (also prototype keys)', () => {
    expect(channelIconSpec('new_source').icon).toBe(faCircleQuestion);
    expect(channelIconSpec(null).icon).toBe(faCircleQuestion);
    expect(channelIconSpec('toString').icon).toBe(faCircleQuestion);
  });

  it('covers every timeline channel, candidate source and integration key', () => {
    const keys = ['call', 'telegram', 'whatsapp', 'viber', 'email', 'note', 'meeting', 'system',
      'manual', 'work_ua', 'robota_ua', 'djinni', 'linkedin', 'dou', 'meta_ads', 'site', 'referral', 'inbox', 'import',
      'ai_broker', 'openrouter', 'deepgram', 'google_gmail', 'google_calendar', 'google_sheets', 'telegram_business',
      'whatsapp_cloud', 'wazzup', 'phonet', 'ringostat', 'binotel', 'meta_lead_ads', 'kep_signing',
      'mail', 'extension', 'webhook', 'gmail', 'calendar'];
    expect(keys.filter((k) => !Object.hasOwn(CHANNEL_ICON_SPECS, k))).toEqual([]);
  });
});

describe('ChannelIcon', () => {
  function render(key: string, label: string | null): HTMLElement {
    const fixture = TestBed.createComponent(ChannelIcon);
    fixture.componentRef.setInput('key', key);
    fixture.componentRef.setInput('label', label);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('announces the label and paints the brand color', () => {
    const el = render('telegram', 'Telegram');
    expect(el.getAttribute('aria-label')).toBe('Telegram');
    expect(el.getAttribute('role')).toBe('img');
    expect(el.getAttribute('title')).toBe('Telegram');
    expect(el.style.color).not.toBe('');
    expect(el.querySelector('svg')).not.toBeNull();
  });

  it('is decorative without a label and shows the job-board badge', () => {
    const el = render('work_ua', null);
    expect(el.getAttribute('aria-hidden')).toBe('true');
    expect(el.getAttribute('aria-label')).toBeNull();
    expect(el.querySelector('.badge')?.textContent?.trim()).toBe('W');
  });
});

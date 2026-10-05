import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService, TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { vi } from 'vitest';
import { AppLang } from '../../core/auth/auth.model';
import { LanguageService } from '../../core/i18n/language.service';
import { NotifyService } from '../../core/ui/notify.service';
import { IntegrationCard } from './integration-card';
import { IntegrationDatePipe } from './integration-date.pipe';
import { Integration, IntegrationLog } from './integrations.model';
import { IntegrationsService } from './integrations.service';
import { IntegrationsStore } from './integrations.store';
import en from '../../../../public/i18n/en.json';
import ru from '../../../../public/i18n/ru.json';
import uk from '../../../../public/i18n/uk.json';

const localInstant = new Date(2026, 9, 1, 13, 5).toISOString();
const expected: Record<AppLang, string> = {
  uk: '01.10.26, 13:05', ru: '01.10.2026, 13:05', en: '01/10/2026, 13:05',
};

function setup(lang: AppLang, timestamp: string | null = localInstant) {
  const current = signal(lang);
  const reads = vi.fn(() => of<IntegrationLog[]>([
    { id: 1, level: 'warning', message: 'checked', created_at: timestamp, context: {} },
  ]));
  const writes = vi.fn();
  TestBed.configureTestingModule({
    imports: [IntegrationCard, TranslocoTestingModule.forRoot({
      langs: { uk, ru, en }, preloadLangs: true,
      translocoConfig: { availableLangs: ['uk', 'ru', 'en'], defaultLang: lang },
    })],
    providers: [
      { provide: LanguageService, useValue: { current: current.asReadonly() } },
      { provide: IntegrationsStore, useValue: { pending: () => new Set<string>(), setStatus: writes, save: writes, check: writes } },
      { provide: IntegrationsService, useValue: { logs: reads } },
      { provide: NotifyService, useValue: { show: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(IntegrationCard);
  const item: Integration = {
    key: 'google_gmail', group: 'google', status: 'error', supports_check: false,
    last_checked_at: timestamp, last_error: 'reconnect_required', updated_at: null, fields: [],
  };
  fixture.componentRef.setInput('item', item);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  element.querySelector<HTMLButtonElement>('button[aria-expanded]')!.click();
  fixture.detectChanges();
  return { fixture, element, current, reads, writes, item };
}

describe('Integration timestamps follow the UI locale', () => {
  for (const lang of ['uk', 'ru', 'en'] as const) {
    it(`formats both check and log timestamps with ${lang}, preserving local time`, () => {
      const { element, writes } = setup(lang);
      expect(element.querySelector('.result .muted')?.textContent?.trim()).toBe(`· ${expected[lang]}`);
      expect(element.querySelector('.logs li .muted')?.textContent?.trim()).toBe(expected[lang]);
      expect(element.querySelector('.logs li .muted')?.textContent).not.toMatch(/AM|PM/);
      expect(writes).not.toHaveBeenCalled();
    });
  }

  it('rerenders existing timestamps on a language switch without reloading logs or writing settings', () => {
    const { fixture, element, current, reads, writes, item } = setup('uk');
    for (const lang of ['ru', 'en', 'uk'] as const) {
      current.set(lang);
      TestBed.inject(TranslocoService).setActiveLang(lang);
      fixture.detectChanges();
      expect(element.querySelector('.result .muted')?.textContent?.trim()).toBe(`· ${expected[lang]}`);
      expect(element.querySelector('.logs li .muted')?.textContent?.trim()).toBe(expected[lang]);
    }
    expect(reads).toHaveBeenCalledTimes(1);
    expect(writes).not.toHaveBeenCalled();
    expect(item.last_checked_at).toBe(localInstant);
  });

  it.each([null, '', '   ', 'not-a-timestamp'])('keeps missing/invalid timestamp %s empty', (timestamp) => {
    const { element } = setup('uk', timestamp);
    expect(element.querySelector('.result .muted')).toBeNull();
    expect(element.querySelector('.logs li .muted')?.textContent?.trim()).toBe('');
    expect(element.textContent).not.toContain('Invalid Date');
  });
});

describe('IntegrationDatePipe preserves API instants', () => {
  const pipe = new IntegrationDatePipe();

  it('honors explicit source offsets instead of treating them as browser local time', () => {
    for (const locale of ['uk-UA', 'ru-RU', 'en-GB']) {
      expect(pipe.transform('2026-10-02T01:05:00+03:00', locale))
        .toBe(pipe.transform('2026-10-01T22:05:00Z', locale));
      expect(pipe.transform('2026-10-01T15:05:00-07:00', locale))
        .toBe(pipe.transform('2026-10-01T22:05:00Z', locale));
    }
  });

  it('does not discard the Unix epoch as a missing date', () => {
    expect(pipe.transform('1970-01-01T00:00:00Z', 'en-GB')).not.toBe('');
  });
});

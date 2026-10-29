import { TestBed } from '@angular/core/testing';
import { FormControl, FormGroup } from '@angular/forms';
import { TRANSLOCO_LOADER, Translation, TranslocoLoader, TranslocoService, TranslocoTestingModule, provideTransloco } from '@jsverse/transloco';
import { Observable, Subject } from 'rxjs';
import { AppearancePage } from './appearance.page';
import { AccentColor, ThemePreference, ThemeService } from '../../core/theme/theme.service';

class DeferredAppearanceLoader implements TranslocoLoader {
  readonly requests = new Map<string, Subject<Translation>>();

  getTranslation(lang: string): Observable<Translation> {
    const request = new Subject<Translation>();
    this.requests.set(lang, request);
    return request;
  }

  reply(lang: 'uk' | 'ru'): void {
    const request = this.requests.get(lang);
    if (!request) throw new Error(`Translation ${lang} was not requested`);
    request.next({ appearance: lang === 'uk'
      ? { custom: 'Власна', sintegrum: 'Sintegrum', mist: 'Туман', sand: 'Пісок', dusk: 'Сутінки' }
      : { custom: 'Своя', sintegrum: 'Sintegrum', mist: 'Туман', sand: 'Песок', dusk: 'Сумерки' } });
    request.complete();
  }
}

describe('AppearancePage', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [AppearancePage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    });
  });

  it('applies appearance changes immediately and reset restores defaults', () => {
    const theme = TestBed.inject(ThemeService);
    const fixture = TestBed.createComponent(AppearancePage);
    fixture.detectChanges();
    const page = fixture.componentInstance as unknown as {
      form: FormGroup<{ accent: FormControl<AccentColor>; fastMode: FormControl<boolean>; themePreference: FormControl<ThemePreference> }>;
      reset(): void;
    };

    page.form.controls.accent.setValue('green');
    expect(theme.preferences().accent).toBe('green');
    expect(document.documentElement.dataset['accent']).toBe('green');

    page.form.controls.fastMode.setValue(true);
    expect(theme.preferences().fastMode).toBe(true);
    expect(document.documentElement.dataset['performance']).toBe('fast');

    page.reset();
    fixture.detectChanges();
    expect(theme.preferences().accent).toBe('blue');
    expect(theme.preferences().fastMode).toBe(false);
  });

  it('syncs external theme changes without undoing them on the next form edit', () => {
    const theme = TestBed.inject(ThemeService);
    const fixture = TestBed.createComponent(AppearancePage);
    fixture.detectChanges();
    const page = fixture.componentInstance as unknown as {
      form: FormGroup<{ accent: FormControl<AccentColor>; fastMode: FormControl<boolean>; themePreference: FormControl<ThemePreference> }>;
    };

    theme.toggle();
    fixture.detectChanges();
    expect(page.form.controls.themePreference.value).toBe('dark');

    page.form.controls.fastMode.setValue(true);
    expect(theme.preferences().themePreference).toBe('dark');
    expect(theme.theme()).toBe('dark');
  });

  it('retains native selected values while translations load asynchronously and preferences change', async () => {
    TestBed.resetTestingModule();
    localStorage.clear();
    const loader = new DeferredAppearanceLoader();
    TestBed.configureTestingModule({
      imports: [AppearancePage],
      providers: [
        provideTransloco({ config: { availableLangs: ['uk', 'ru'], defaultLang: 'uk', fallbackLang: 'uk', reRenderOnLangChange: true, prodMode: true } }),
        { provide: TRANSLOCO_LOADER, useValue: loader },
      ],
    });
    const theme = TestBed.inject(ThemeService);
    theme.updatePreferences({ colorScheme: 'custom', wallpaper: 'mist' });
    const translations = TestBed.inject(TranslocoService);
    const fixture = TestBed.createComponent(AppearancePage);
    fixture.detectChanges();

    const selected = (): { value: string; label: string }[] => Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLSelectElement>('select[matNativeControl]'),
      (select) => ({ value: select.value, label: select.selectedOptions[0]?.textContent?.trim() ?? '' }),
    );
    expect(selected().map(({ value }) => value)).toEqual(['custom', 'mist']);
    expect(loader.requests.has('uk')).toBe(true);
    loader.reply('uk');
    await fixture.whenStable();
    fixture.detectChanges();
    expect(selected()).toEqual([
      { value: 'custom', label: 'Власна' },
      { value: 'mist', label: 'Туман' },
    ]);

    theme.updatePreferences({ fastMode: true, wallpaper: 'dusk' });
    fixture.detectChanges();
    expect(selected().map(({ value }) => value)).toEqual(['custom', 'dusk']);
    translations.setActiveLang('ru');
    fixture.detectChanges();
    expect(loader.requests.has('ru')).toBe(true);
    loader.reply('ru');
    await fixture.whenStable();
    fixture.detectChanges();
    expect(selected()).toEqual([
      { value: 'custom', label: 'Своя' },
      { value: 'dusk', label: 'Сумерки' },
    ]);
  });
});

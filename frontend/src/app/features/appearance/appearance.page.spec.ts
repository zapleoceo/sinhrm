import { TestBed } from '@angular/core/testing';
import { FormControl, FormGroup } from '@angular/forms';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { AppearancePage } from './appearance.page';
import { AccentColor, ThemePreference, ThemeService } from '../../core/theme/theme.service';

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

  it('keeps Material select values rendered across unrelated preference updates', async () => {
    const theme = TestBed.inject(ThemeService);
    theme.updatePreferences({ colorScheme: 'custom', wallpaper: 'mist' });
    const fixture = TestBed.createComponent(AppearancePage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const selectedText = (): string[] => Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('.mat-mdc-select-value-text'),
      (element) => element.textContent?.trim() ?? '',
    );
    expect(selectedText()).toEqual(['appearance.custom', 'appearance.mist']);

    theme.updatePreferences({ fastMode: true });
    await fixture.whenStable();
    fixture.detectChanges();
    expect(selectedText()).toEqual(['appearance.custom', 'appearance.mist']);
  });
});

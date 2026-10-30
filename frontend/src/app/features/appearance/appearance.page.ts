import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject, signal, untracked } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { DesktopWallpaperStore, WallpaperImageError } from '../../core/theme/desktop-wallpaper.store';
import {
  AccentColor,
  AppearancePreferences,
  ColorScheme,
  InterfaceStyle,
  ThemePreference,
  ThemeService,
  Wallpaper,
} from '../../core/theme/theme.service';

type AppearanceForm = {
  [Key in keyof AppearancePreferences]: FormControl<AppearancePreferences[Key]>;
};

@Component({
  selector: 'app-appearance-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSlideToggleModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="appearance" [formGroup]="form">
      <header class="page-head">
        <div>
          <h1>{{ 'appearance.title' | transloco }}</h1>
          <p class="muted">{{ 'appearance.subtitle' | transloco }}</p>
        </div>
        <button mat-stroked-button type="button" data-testid="appearance-reset" (click)="reset()">
          <mat-icon>restart_alt</mat-icon>{{ 'appearance.reset' | transloco }}
        </button>
      </header>

      <section class="settings-card" data-testid="appearance-theme">
        <h2>{{ 'appearance.theme' | transloco }}</h2>
        <mat-button-toggle-group formControlName="themePreference" data-testid="appearance-theme-choice" [attr.aria-label]="'appearance.theme' | transloco" hideSingleSelectionIndicator>
          <mat-button-toggle value="system">{{ 'appearance.system' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="light">{{ 'appearance.light' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="dark">{{ 'appearance.dark' | transloco }}</mat-button-toggle>
        </mat-button-toggle-group>
        <mat-form-field appearance="outline">
          <mat-label>{{ 'appearance.scheme' | transloco }}</mat-label>
          <select matNativeControl formControlName="colorScheme" data-testid="appearance-scheme">
            <option value="custom">{{ 'appearance.custom' | transloco }}</option>
            <option value="sintegrum">{{ 'appearance.sintegrum' | transloco }}</option>
          </select>
        </mat-form-field>
      </section>

      <section class="settings-card" data-testid="appearance-accent">
        <h2>{{ 'appearance.accent' | transloco }}</h2>
        <mat-button-toggle-group formControlName="accent" [attr.aria-label]="'appearance.accent' | transloco" hideSingleSelectionIndicator>
          <mat-button-toggle value="blue">{{ 'appearance.blue' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="violet">{{ 'appearance.violet' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="green">{{ 'appearance.green' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="graphite">{{ 'appearance.graphite' | transloco }}</mat-button-toggle>
        </mat-button-toggle-group>
      </section>

      <section class="settings-card" data-testid="appearance-style">
        <h2>{{ 'appearance.style' | transloco }}</h2>
        <mat-button-toggle-group formControlName="interfaceStyle" [attr.aria-label]="'appearance.style' | transloco" hideSingleSelectionIndicator>
          <mat-button-toggle value="glass">{{ 'appearance.glass' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="solid">{{ 'appearance.solid' | transloco }}</mat-button-toggle>
        </mat-button-toggle-group>
        <mat-form-field appearance="outline">
          <mat-label>{{ 'appearance.wallpaper' | transloco }}</mat-label>
          <select matNativeControl formControlName="wallpaper" data-testid="appearance-wallpaper">
            <option value="mist">{{ 'appearance.mist' | transloco }}</option>
            <option value="sand">{{ 'appearance.sand' | transloco }}</option>
            <option value="dusk">{{ 'appearance.dusk' | transloco }}</option>
          </select>
        </mat-form-field>
        <label class="range-field">
          <span>{{ 'appearance.transparency' | transloco }} <output>{{ form.controls.transparency.value }}%</output></span>
          <input type="range" min="0" max="100" step="1" formControlName="transparency" data-testid="appearance-transparency" />
        </label>
      </section>

      <section class="settings-card custom-wallpaper" data-testid="appearance-custom-wallpaper">
        <div>
          <h2>{{ 'appearance.customWallpaper' | transloco }}</h2>
          <p class="muted">{{ 'appearance.customWallpaperHint' | transloco }}</p>
        </div>
        <div class="wallpaper-image-controls">
          <input #wallpaperFile type="file" tabindex="-1" accept="image/jpeg,image/png,image/webp" data-testid="appearance-wallpaper-file"
                 [attr.aria-label]="'appearance.chooseWallpaper' | transloco" (change)="selectWallpaper($event)" />
          <button mat-stroked-button type="button" data-testid="appearance-wallpaper-choose" (click)="wallpaperFile.click()">
            <mat-icon>image</mat-icon>{{ 'appearance.chooseWallpaper' | transloco }}
          </button>
          @if (wallpaperStore.dataUrl(); as preview) {
            <figure class="wallpaper-preview">
              <img data-testid="appearance-wallpaper-preview" [src]="preview" [alt]="'appearance.wallpaperPreview' | transloco" />
              <button mat-button type="button" data-testid="appearance-wallpaper-remove" (click)="removeWallpaper()">
                <mat-icon>delete_outline</mat-icon>{{ 'appearance.removeWallpaper' | transloco }}
              </button>
            </figure>
          }
          @if (wallpaperError(); as error) {
            <p class="wallpaper-error" role="alert" data-testid="appearance-wallpaper-error">{{ ('appearance.wallpaperErrors.' + error) | transloco }}</p>
          }
        </div>
      </section>

      <section class="settings-card performance">
        <h2>{{ 'appearance.fastMode' | transloco }}</h2>
        <p class="muted">{{ 'appearance.fastModeHint' | transloco }}</p>
        <mat-slide-toggle formControlName="fastMode" data-testid="appearance-fast-mode">{{ 'appearance.fastMode' | transloco }}</mat-slide-toggle>
        <mat-slide-toggle formControlName="reducedMotion" data-testid="appearance-reduced-motion">{{ 'appearance.reducedMotion' | transloco }}</mat-slide-toggle>
      </section>
    </main>
  `,
  styles: `
    .appearance { display: grid; gap: 1rem; max-width: 70rem; }
    .page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
    .page-head h1, .settings-card h2 { margin: 0; }
    .page-head p { margin: 0.4rem 0 0; }
    .settings-card { display: grid; grid-template-columns: minmax(10rem, 1fr) 2fr; align-items: center; gap: 1rem; padding: 1.25rem; border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius); background: var(--app-card); }
    .settings-card mat-form-field { width: min(100%, 20rem); margin: 0; }
    .range-field { display: grid; gap: 0.5rem; grid-column: 2; }
    .range-field span { display: flex; justify-content: space-between; }
    .range-field input { width: 100%; accent-color: var(--app-accent); }
    .performance { grid-template-columns: 1fr 2fr; }
    .performance p { grid-column: 2; margin: 0; }
    .performance mat-slide-toggle { grid-column: 2; }
    .custom-wallpaper { align-items: start; }
    .custom-wallpaper h2 { margin: 0; }
    .custom-wallpaper p { margin: 0.4rem 0 0; }
    .wallpaper-image-controls { display: grid; justify-items: start; gap: 0.75rem; min-width: 0; }
    .wallpaper-image-controls input[type='file'] { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .wallpaper-image-controls input[type='file']:focus-visible + button { outline: 2px solid var(--app-focus-ring, var(--mat-sys-primary)); outline-offset: 2px; }
    .wallpaper-preview { display: grid; justify-items: start; gap: 0.5rem; margin: 0; }
    .wallpaper-preview img { display: block; width: min(100%, 20rem); max-height: 10rem; object-fit: cover; border: 1px solid var(--app-border); border-radius: var(--app-radius-sm); }
    .wallpaper-error { color: var(--app-danger); }
    @media (max-width: 48rem) {
      .page-head { align-items: stretch; flex-direction: column; }
      .settings-card, .performance { grid-template-columns: 1fr; }
      .range-field, .performance p, .performance mat-slide-toggle { grid-column: 1; }
      .settings-card mat-button-toggle-group { max-width: 100%; overflow-x: auto; }
    }
  `,
})
export class AppearancePage {
  private readonly theme = inject(ThemeService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly wallpaperStore = inject(DesktopWallpaperStore);
  protected readonly wallpaperError = signal<WallpaperImageError | null>(null);

  protected readonly form = this.createForm(this.theme.preferences());

  constructor() {
    effect(() => {
      const preferences = this.theme.preferences();
      untracked(() => this.form.setValue(preferences, { emitEvent: false }));
    });
    this.form.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((value) => {
      const patch: Partial<AppearancePreferences> = value;
      this.theme.updatePreferences(patch);
    });
  }

  protected reset(): void {
    this.theme.reset();
    this.form.reset(this.theme.preferences());
    try {
      this.wallpaperStore.remove();
      this.wallpaperError.set(null);
    } catch {
      this.wallpaperError.set('storage');
    }
  }

  protected async selectWallpaper(event: Event): Promise<void> {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.wallpaperError.set(null);
    try {
      await this.wallpaperStore.save(file);
    } catch (error) {
      const code = error instanceof Error ? error.message : '';
      this.wallpaperError.set(['unsupported', 'tooLarge', 'invalid', 'compression', 'storage'].includes(code)
        ? code as WallpaperImageError
        : 'compression');
    }
  }

  protected removeWallpaper(): void {
    try {
      this.wallpaperStore.remove();
      this.wallpaperError.set(null);
    } catch {
      this.wallpaperError.set('storage');
    }
  }

  private createForm(preferences: AppearancePreferences): FormGroup<AppearanceForm> {
    return new FormGroup<AppearanceForm>({
      themePreference: new FormControl<ThemePreference>(preferences.themePreference, { nonNullable: true }),
      colorScheme: new FormControl<ColorScheme>(preferences.colorScheme, { nonNullable: true }),
      accent: new FormControl<AccentColor>(preferences.accent, { nonNullable: true }),
      interfaceStyle: new FormControl<InterfaceStyle>(preferences.interfaceStyle, { nonNullable: true }),
      wallpaper: new FormControl<Wallpaper>(preferences.wallpaper, { nonNullable: true }),
      transparency: new FormControl<number>(preferences.transparency, { nonNullable: true }),
      fastMode: new FormControl<boolean>(preferences.fastMode, { nonNullable: true }),
      reducedMotion: new FormControl<boolean>(preferences.reducedMotion, { nonNullable: true }),
    });
  }
}

import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject, untracked } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
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
    MatSelectModule,
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
          <mat-select formControlName="colorScheme" data-testid="appearance-scheme">
            <mat-option value="custom">{{ 'appearance.custom' | transloco }}</mat-option>
            <mat-option value="sintegrum">{{ 'appearance.sintegrum' | transloco }}</mat-option>
          </mat-select>
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
          <mat-select formControlName="wallpaper" data-testid="appearance-wallpaper">
            <mat-option value="mist">{{ 'appearance.mist' | transloco }}</mat-option>
            <mat-option value="sand">{{ 'appearance.sand' | transloco }}</mat-option>
            <mat-option value="dusk">{{ 'appearance.dusk' | transloco }}</mat-option>
          </mat-select>
        </mat-form-field>
        <label class="range-field">
          <span>{{ 'appearance.transparency' | transloco }} <output>{{ form.controls.transparency.value }}%</output></span>
          <input type="range" min="0" max="40" step="1" formControlName="transparency" data-testid="appearance-transparency" />
        </label>
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

  protected readonly form = this.createForm(this.theme.preferences());

  constructor() {
    effect(() => {
      const preferences = this.theme.preferences();
      untracked(() => this.syncForm(preferences));
    });
    this.form.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((value) => {
      const patch: Partial<AppearancePreferences> = value;
      this.theme.updatePreferences(patch);
    });
  }

  protected reset(): void {
    this.theme.reset();
    this.form.reset(this.theme.preferences());
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

  private syncForm(preferences: AppearancePreferences): void {
    const current = this.form.getRawValue();
    const patch: { -readonly [Key in keyof AppearancePreferences]?: AppearancePreferences[Key] } = {};
    if (current.themePreference !== preferences.themePreference) patch.themePreference = preferences.themePreference;
    if (current.colorScheme !== preferences.colorScheme) patch.colorScheme = preferences.colorScheme;
    if (current.accent !== preferences.accent) patch.accent = preferences.accent;
    if (current.interfaceStyle !== preferences.interfaceStyle) patch.interfaceStyle = preferences.interfaceStyle;
    if (current.wallpaper !== preferences.wallpaper) patch.wallpaper = preferences.wallpaper;
    if (current.transparency !== preferences.transparency) patch.transparency = preferences.transparency;
    if (current.fastMode !== preferences.fastMode) patch.fastMode = preferences.fastMode;
    if (current.reducedMotion !== preferences.reducedMotion) patch.reducedMotion = preferences.reducedMotion;
    if (Object.keys(patch).length > 0) this.form.patchValue(patch, { emitEvent: false });
  }
}

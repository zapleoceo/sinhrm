import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { APP_LANGS, AppLang } from '../../core/auth/auth.model';
import { LanguageService } from '../../core/i18n/language.service';

@Component({
  selector: 'app-language-switcher',
  imports: [MatButtonToggleModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-button-toggle-group
      hideSingleSelectionIndicator
      [value]="language.current()"
      (change)="select($event.value)"
      [attr.aria-label]="'shell.menu.language' | transloco"
    >
      @for (lang of langs; track lang) {
        <mat-button-toggle [value]="lang" [attr.aria-label]="'langs.' + lang | transloco" class="code">{{ lang }}</mat-button-toggle>
      }
    </mat-button-toggle-group>
  `,
  styles: `
    .code { text-transform: uppercase; }
  `,
})
export class LanguageSwitcher {
  protected readonly language = inject(LanguageService);
  protected readonly langs = APP_LANGS;

  protected select(lang: AppLang): void {
    void this.language.use(lang);
  }
}

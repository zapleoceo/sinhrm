import { Injectable, inject } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, Subject, of, switchMap } from 'rxjs';

export const APP_TITLE = 'SinHRM';

/** "SinHRM · Кандидати"; just "SinHRM" when a route has no title (or its key is not translated yet). */
export function formatTitle(page: string | null | undefined): string {
  const text = page?.trim();
  return text ? `${APP_TITLE} · ${text}` : APP_TITLE;
}

/**
 * Browser tab title from the route `title`, which holds an i18n key (e.g. `titles.candidates`).
 * Re-translated when the UI language changes (selectTranslate follows the active language).
 */
@Injectable()
export class TranslatedTitleStrategy extends TitleStrategy {
  private readonly title = inject(Title);
  private readonly transloco = inject(TranslocoService);
  private readonly keys = new Subject<string | undefined>();

  constructor() {
    super();
    this.keys
      .pipe(switchMap((key): Observable<string | null> => (key ? this.transloco.selectTranslate<string>(key) : of(null))))
      .subscribe((text) => this.title.setTitle(formatTitle(text)));
  }

  override updateTitle(snapshot: RouterStateSnapshot): void {
    this.keys.next(this.buildTitle(snapshot));
  }
}

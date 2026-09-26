import { ApplicationConfig, ErrorHandler, inject, isDevMode, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withFetch, withInterceptors, withXsrfConfiguration } from '@angular/common/http';
import { TitleStrategy, provideRouter, withComponentInputBinding } from '@angular/router';
import { MAT_ICON_DEFAULT_OPTIONS } from '@angular/material/icon';
import { provideTransloco } from '@jsverse/transloco';
import { routes } from './app.routes';
import { provideAppDates } from './core/date/provide-app-dates';
import { APP_LANGS, DEFAULT_LANG } from './core/auth/auth.model';
import { AuthService } from './core/auth/auth.service';
import { csrfInterceptor, XSRF_HEADER } from './core/http/csrf.interceptor';
import { GlobalErrorHandler } from './core/errors/global-error-handler';
import { serverErrorInterceptor } from './core/errors/server-error.interceptor';
import { LanguageService } from './core/i18n/language.service';
import { TranslocoHttpLoader } from './core/i18n/transloco-loader';
import { TranslatedTitleStrategy } from './core/i18n/translated-title.strategy';
import { ThemeService } from './core/theme/theme.service';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // JS exceptions and 5xx → the in-app error log (Адміністрування → Помилки).
    { provide: ErrorHandler, useClass: GlobalErrorHandler },
    // Same origin as the API (Vercel rewrite) → cookies are sent without withCredentials.
    provideHttpClient(
      withFetch(),
      withXsrfConfiguration({ cookieName: 'XSRF-TOKEN', headerName: XSRF_HEADER }),
      withInterceptors([csrfInterceptor, serverErrorInterceptor]),
    ),
    provideRouter(routes, withComponentInputBinding()),
    // Route `title` = i18n key → "SinHRM · <page>" in the browser tab, re-translated on language change.
    { provide: TitleStrategy, useClass: TranslatedTitleStrategy },
    provideTransloco({
      config: {
        availableLangs: [...APP_LANGS],
        defaultLang: DEFAULT_LANG,
        fallbackLang: DEFAULT_LANG,
        reRenderOnLangChange: true,
        prodMode: !isDevMode(),
      },
      loader: TranslocoHttpLoader,
    }),
    // Material datepicker/timepicker: native Date, Monday-first, dd.MM.yyyy, locale follows the UI language.
    provideAppDates(),
    { provide: MAT_ICON_DEFAULT_OPTIONS, useValue: { fontSet: 'material-symbols-outlined' } },
    provideAppInitializer(async () => {
      inject(ThemeService).init();
      const language = inject(LanguageService);
      await inject(AuthService).load();
      language.init();
    }),
  ],
};

import { ApplicationConfig, inject, isDevMode, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withFetch, withInterceptors, withXsrfConfiguration } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { MAT_ICON_DEFAULT_OPTIONS } from '@angular/material/icon';
import { provideTransloco } from '@jsverse/transloco';
import { routes } from './app.routes';
import { APP_LANGS, DEFAULT_LANG } from './core/auth/auth.model';
import { AuthService } from './core/auth/auth.service';
import { csrfInterceptor, XSRF_HEADER } from './core/http/csrf.interceptor';
import { LanguageService } from './core/i18n/language.service';
import { TranslocoHttpLoader } from './core/i18n/transloco-loader';
import { ThemeService } from './core/theme/theme.service';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Same origin as the API (Vercel rewrite) → cookies are sent without withCredentials.
    provideHttpClient(
      withFetch(),
      withXsrfConfiguration({ cookieName: 'XSRF-TOKEN', headerName: XSRF_HEADER }),
      withInterceptors([csrfInterceptor]),
    ),
    provideRouter(routes, withComponentInputBinding()),
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
    { provide: MAT_ICON_DEFAULT_OPTIONS, useValue: { fontSet: 'material-symbols-outlined' } },
    provideAppInitializer(async () => {
      inject(ThemeService).init();
      const language = inject(LanguageService);
      await inject(AuthService).load();
      language.init();
    }),
  ],
};

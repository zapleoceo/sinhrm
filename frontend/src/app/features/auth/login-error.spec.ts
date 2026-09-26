import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { LoginPage } from './login.page';
import { loginErrorKey } from './login-error';
import { LanguageService } from '../../core/i18n/language.service';

describe('loginErrorKey', () => {
  it('maps known codes to their message', () => {
    expect(loginErrorKey('not_invited')).toBe('login.errors.not_invited');
    expect(loginErrorKey('blocked')).toBe('login.errors.blocked');
    expect(loginErrorKey('email_unverified')).toBe('login.errors.email_unverified');
    expect(loginErrorKey('oauth_failed')).toBe('login.errors.oauth_failed');
  });

  it('maps unknown codes to a generic message and no code to nothing', () => {
    expect(loginErrorKey('<script>')).toBe('login.errors.unknown');
    expect(loginErrorKey(undefined)).toBeNull();
    expect(loginErrorKey('')).toBeNull();
  });
});

describe('LoginPage', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [
        LoginPage,
        TranslocoTestingModule.forRoot({
          langs: { en: { login: { errors: { blocked: 'Access blocked' }, google: 'Continue with Google' } } },
          translocoConfig: { availableLangs: ['en'], defaultLang: 'en' },
        }),
      ],
      providers: [{ provide: LanguageService, useValue: { current: () => 'en', use: () => Promise.resolve() } }],
    });
  });

  it('shows the translated error from ?error', () => {
    const fixture = TestBed.createComponent(LoginPage);
    fixture.componentRef.setInput('error', 'blocked');
    fixture.detectChanges();

    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('[role=alert]')?.textContent?.trim()).toBe('Access blocked');
    expect(el.querySelector('a')?.getAttribute('href')).toBe('/api/auth/google/redirect');
  });

  it('shows no error without a code', () => {
    const fixture = TestBed.createComponent(LoginPage);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('[role=alert]')).toBeNull();
  });

  it('places the language switcher inside the card and links the Google button to the login flow', () => {
    const fixture = TestBed.createComponent(LoginPage);
    fixture.detectChanges();
    const card = (fixture.nativeElement as HTMLElement).querySelector('section.card');
    expect(card?.querySelector('app-language-switcher')).not.toBeNull();
    expect(card?.querySelector('app-logo')).not.toBeNull();
    const button = card?.querySelector<HTMLAnchorElement>('a.google');
    expect(button?.getAttribute('href')).toBe('/api/auth/google/redirect');
    expect(button?.textContent?.trim()).toBe('Continue with Google');
  });
});

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { LOGIN_ROUTE, LoginPage } from './login.page';

async function render(error?: string): Promise<HTMLElement> {
  TestBed.configureTestingModule({
    imports: [
      LoginPage,
      TranslocoTestingModule.forRoot({
        langs: { uk: { login: { title: 'Вхід', google: 'Продовжити з Google', errors: { blocked: 'Заблоковано' } } } },
        translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
      }),
    ],
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  const fixture = TestBed.createComponent(LoginPage);
  if (error !== undefined) fixture.componentRef.setInput('error', error);
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('LoginPage', () => {
  it('draws a decorative route: one station per stage kind in funnel order, the last one current', async () => {
    const el = await render();
    const route = el.querySelector('[data-testid="login-route"]');
    expect(route?.getAttribute('aria-hidden')).toBe('true');
    const stations = Array.from(route?.querySelectorAll('.app-station') ?? []);
    expect(stations.map((s) => s.getAttribute('data-kind'))).toEqual([...LOGIN_ROUTE]);
    expect(stations.map((s) => s.classList.contains('current'))).toEqual([false, false, false, false, true]);
  });

  it('keeps the Google sign-in link and shows no alert without ?error', async () => {
    const el = await render();
    const google = el.querySelector<HTMLAnchorElement>('a.google');
    expect(google?.getAttribute('href')).toBe('/api/auth/google/redirect');
    expect(google?.textContent?.trim()).toBe('Продовжити з Google');
    expect(el.querySelector('[role="alert"]')).toBeNull();
  });

  it('shows the denial as an alert inside the card', async () => {
    const el = await render('blocked');
    const alert = el.querySelector('.card [role="alert"]');
    expect(alert?.textContent?.trim()).toBe('Заблоковано');
  });

  it('Google link keeps a 3px keyboard focus ring; the language toggles reach 44px on phones', () => {
    const css = ((LoginPage as unknown as { ɵcmp: { styles?: string[] } }).ɵcmp.styles ?? []).join('\n').replace(/\[_ng(content|host)-[^\]]*\]/g, '').replace(/%NS%/g, '');
    expect(css).toMatch(/\.google\s*\{[^}]*outline-width:\s*3px/);
    expect(css).toMatch(/@media \(max-width: 600px\)\s*\{[^}]*\.lang\s*\{[^}]*--mat-button-toggle-height:\s*44px/);
  });
});

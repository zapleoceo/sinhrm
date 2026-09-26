import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { ShellLayout } from './shell.layout';

@Component({ template: '' })
class Blank {}

const USER = { id: 7, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'uk', roles: ['employee'], status: 'active' };

const logout = vi.fn().mockResolvedValue(undefined);
const langUse = vi.fn().mockResolvedValue(undefined);

async function setup(modules?: string[]): Promise<{ el: HTMLElement; router: Router; detect: () => Promise<void> }> {
  logout.mockClear();
  langUse.mockClear();
  TestBed.configureTestingModule({
    imports: [ShellLayout, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter([{ path: '**', component: Blank }]),
      { provide: AuthService, useValue: { user: signal(USER), logout, hasModule: (k: string) => modules === undefined || modules.includes(k) } },
      { provide: LanguageService, useValue: { current: signal('uk'), use: langUse } },
    ],
  });
  const fixture = TestBed.createComponent(ShellLayout);
  const detect = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  await detect();
  return { el: fixture.nativeElement as HTMLElement, router: TestBed.inject(Router), detect };
}

const header = (el: HTMLElement, g: string): HTMLButtonElement =>
  el.querySelector(`button[aria-controls="nav-group-${g}"]`) as HTMLButtonElement;

describe('ShellLayout collapsible nav', () => {
  beforeEach(() => localStorage.clear());

  it('starts with groups collapsed and toggles on header click', async () => {
    const { el, detect } = await setup();
    const btn = header(el, 'people');
    expect(btn.getAttribute('aria-expanded')).toBe('false');
    expect(el.querySelector('#nav-group-people')?.classList.contains('open')).toBe(false);

    btn.click();
    await detect();
    expect(btn.getAttribute('aria-expanded')).toBe('true');
    expect(el.querySelector('#nav-group-people')?.classList.contains('open')).toBe(true);
    expect(JSON.parse(localStorage.getItem('sinhrm.nav.expanded.7') ?? '[]')).toEqual(['people']);

    btn.click();
    await detect();
    expect(btn.getAttribute('aria-expanded')).toBe('false');
  });

  it('auto-expands the group of the active route', async () => {
    const { el, router, detect } = await setup();
    await router.navigateByUrl('/perform/objectives');
    await detect();
    expect(header(el, 'perform').getAttribute('aria-expanded')).toBe('true');
    expect(header(el, 'recruiting').getAttribute('aria-expanded')).toBe('false');
  });

  it('lets the user fold the group of the active route', async () => {
    const { el, router, detect } = await setup();
    await router.navigateByUrl('/perform/objectives');
    await detect();
    header(el, 'perform').click();
    await detect();
    expect(header(el, 'perform').getAttribute('aria-expanded')).toBe('false');
  });

  it('restores remembered groups for the user', async () => {
    localStorage.setItem('sinhrm.nav.expanded.7', '["services"]');
    const { el } = await setup();
    expect(header(el, 'services').getAttribute('aria-expanded')).toBe('true');
  });
});

describe('ShellLayout user menu in sidebar footer', () => {
  beforeEach(() => localStorage.clear());

  const openMenu = async (el: HTMLElement, detect: () => Promise<void>): Promise<HTMLElement> => {
    (el.querySelector('.sidebar-footer button.user') as HTMLButtonElement).click();
    await detect();
    return document.querySelector('.mat-mdc-menu-panel') as HTMLElement;
  };

  it('renders the user button in the sidebar footer, next to the help link, and no top bar', async () => {
    const { el } = await setup();
    const footer = el.querySelector('.sidebar .sidebar-footer') as HTMLElement;
    expect(footer).toBeTruthy();
    expect(footer.querySelector('a[href="/docs"]')).toBeTruthy();
    expect(footer.querySelector('button.user .name')?.textContent).toContain('U');
    expect(footer.querySelector('button.user .email')?.textContent).toContain('u@example.com');
    expect(el.querySelector('.topbar')).toBeNull();
  });

  it('opens the menu with language, theme, profile, extension and logout items', async () => {
    const { el, detect } = await setup();
    const panel = await openMenu(el, detect);
    expect(panel.querySelector('app-language-switcher')).toBeTruthy();
    expect(panel.querySelector('.theme-toggle')).toBeTruthy();
    expect(panel.querySelector('a[href="/me"]')).toBeTruthy();
    expect(panel.querySelector('a[href="/settings/extension"]')).toBeTruthy();
    expect(panel.querySelector('button.logout')).toBeTruthy();
  });

  it('switches the language from the footer menu', async () => {
    const { el, detect } = await setup();
    const panel = await openMenu(el, detect);
    const ru = Array.from(panel.querySelectorAll('mat-button-toggle button')).find((b) => b.textContent?.trim() === 'ru') as HTMLButtonElement;
    ru.click();
    await detect();
    expect(langUse).toHaveBeenCalledWith('ru');
  });

  it('logs out and goes to /login', async () => {
    const { el, router, detect } = await setup();
    const nav = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const panel = await openMenu(el, detect);
    (panel.querySelector('button.logout') as HTMLButtonElement).click();
    await detect();
    expect(logout).toHaveBeenCalled();
    expect(nav).toHaveBeenCalledWith('/login');
  });
});

describe('ShellLayout module access', () => {
  beforeEach(() => localStorage.clear());

  it('hides items of unavailable modules and a group with none left', async () => {
    const { el } = await setup(['core', 'people', 'time', 'desk']);
    const links = [...el.querySelectorAll('a.nav-link')].map((a) => a.getAttribute('href'));
    expect(links).toContain('/people');
    expect(links).toContain('/time');
    expect(links).not.toContain('/timeoff');
    expect(links).not.toContain('/knowledge');
    expect(header(el, 'recruiting')).toBeNull();
    expect(header(el, 'perform')).toBeNull();
    expect(header(el, 'people')).not.toBeNull();
  });

  it('shows everything when every module is available', async () => {
    const { el } = await setup();
    expect(header(el, 'recruiting')).not.toBeNull();
    expect(el.querySelector('a[href="/candidates"]')).not.toBeNull();
  });
});

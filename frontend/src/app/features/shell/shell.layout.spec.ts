import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { MatTooltip } from '@angular/material/tooltip';
import { Router, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { BreakpointObserver } from '@angular/cdk/layout';
import { of } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { ShellLayout } from './shell.layout';

@Component({ template: '' })
class Blank {}

const USER = { id: 7, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'uk', roles: ['employee'], status: 'active' };

const logout = vi.fn().mockResolvedValue(undefined);
const setActiveRole = vi.fn().mockResolvedValue(undefined);
const langUse = vi.fn().mockResolvedValue(undefined);

async function setup(modules?: string[], narrow = false, user: object = USER): Promise<{ el: HTMLElement; router: Router; http: HttpTestingController; detect: () => Promise<void>; fixture: ComponentFixture<ShellLayout> }> {
  logout.mockClear();
  setActiveRole.mockClear();
  langUse.mockClear();
  TestBed.configureTestingModule({
    imports: [ShellLayout, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter([{ path: '**', component: Blank }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: AuthService, useValue: { user: signal(user), logout, setActiveRole, hasModule: (k: string) => modules === undefined || modules.includes(k) } },
      { provide: BreakpointObserver, useValue: { observe: () => of({ matches: narrow, breakpoints: {} }) } },
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
  return { el: fixture.nativeElement as HTMLElement, router: TestBed.inject(Router), http: TestBed.inject(HttpTestingController), detect, fixture };
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

describe('ShellLayout badges', () => {
  beforeEach(() => localStorage.clear());

  const flushBadges = async (http: HttpTestingController, data: object): Promise<void> => {
    await new Promise((resolve) => setTimeout(resolve)); // the first load starts on a 0 ms timer
    for (const req of http.match('/api/nav/badges')) req.flush({ data });
  };
  const badgeOf = (el: HTMLElement, selector: string): string | undefined => el.querySelector(`${selector} .nav-badge`)?.textContent?.trim();

  it('shows counters next to items and the sum on a collapsed group', async () => {
    const { el, http, detect } = await setup();
    await flushBadges(http, { tasks: 1, timeoff_approvals: 2, my_documents: 3, inbox: 0 });
    await detect();

    expect(badgeOf(el, 'a[href="/tasks"]')).toBe('1');
    expect(badgeOf(el, 'a[href="/timeoff/approvals"]')).toBe('2');
    expect(el.querySelector('a[href="/inbox"] .nav-badge')).toBeNull();
    expect(badgeOf(el, 'button[aria-controls="nav-group-people"]')).toBe('5');
    expect(el.querySelector('button[aria-controls="nav-group-recruiting"] .nav-badge')).toBeNull();

    // An open group shows the counters on its items only.
    header(el, 'people').click();
    await detect();
    expect(el.querySelector('button[aria-controls="nav-group-people"] .nav-badge')).toBeNull();
  });

  it('refreshes after navigation', async () => {
    const { el, router, http, detect } = await setup();
    await flushBadges(http, { tasks: 1 });
    await router.navigateByUrl('/tasks');
    await flushBadges(http, { tasks: 120 });
    await detect();
    expect(badgeOf(el, 'a[href="/tasks"]')).toBe('99+');
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

  it('hides "work as" for a single-role account', async () => {
    const { el, detect } = await setup(undefined, false, { ...USER, assigned_roles: ['employee'], active_role: null });
    const panel = await openMenu(el, detect);
    expect(panel.querySelectorAll('.work-as-item').length).toBe(0);
    expect(el.querySelector('.role-chip')).toBeNull();
  });

  it('lists "all roles" + each assigned role, checks the active one and shows it as a chip', async () => {
    const multi = { ...USER, roles: ['recruiter'], assigned_roles: ['superadmin', 'recruiter'], active_role: 'recruiter' };
    const { el, detect } = await setup(undefined, false, multi);
    expect(el.querySelector('.sidebar-footer .role-chip')?.textContent).toContain('roles.recruiter');
    const panel = await openMenu(el, detect);
    const items = Array.from(panel.querySelectorAll('.work-as-item'));
    expect(items.map((b) => b.textContent?.trim())).toEqual([
      'radio_button_uncheckedshell.menu.allRoles',
      'radio_button_uncheckedroles.superadmin',
      'radio_button_checkedroles.recruiter',
    ]);
    expect(items.map((b) => b.getAttribute('role'))).toEqual(['menuitemradio', 'menuitemradio', 'menuitemradio']);
    expect(items[2].getAttribute('aria-checked')).toBe('true');
  });

  it('switches the role, goes home and says so', async () => {
    const multi = { ...USER, assigned_roles: ['superadmin', 'recruiter'], active_role: null };
    const { el, router, detect } = await setup(undefined, false, multi);
    const nav = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const panel = await openMenu(el, detect);
    (panel.querySelectorAll('.work-as-item')[2] as HTMLButtonElement).click();
    await detect();
    expect(setActiveRole).toHaveBeenCalledWith('recruiter');
    expect(nav).toHaveBeenCalledWith('/');
    expect(document.querySelector('.mat-mdc-snack-bar-label')?.textContent).toContain('shell.menu.workingAs');
  });
});

describe('ShellLayout current page', () => {
  beforeEach(() => localStorage.clear());

  it('marks only the current page link with aria-current="page" (not by colour alone)', async () => {
    const { el, router, detect } = await setup();
    await router.navigateByUrl('/perform/objectives');
    await detect();
    const current = [...el.querySelectorAll('a.nav-link[aria-current="page"]')].map((a) => a.getAttribute('href'));
    expect(current).toEqual(['/perform/objectives']);
    expect(el.querySelector('a[href="/perform/objectives"]')?.classList.contains('active')).toBe(true);

    await router.navigateByUrl('/');
    await detect();
    expect([...el.querySelectorAll('a.nav-link[aria-current="page"]')].map((a) => a.getAttribute('href'))).toEqual(['/']);
  });

  it('keeps every menu icon out of the accessible name', async () => {
    const { el } = await setup();
    const icons = [...el.querySelectorAll('.sidebar-nav a.nav-link mat-icon')];
    expect(icons.length).toBeGreaterThan(10);
    expect(icons.every((i) => i.getAttribute('aria-hidden') === 'true')).toBe(true);
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

describe('ShellLayout mobile drawer', () => {
  beforeEach(() => localStorage.clear());

  it('desktop has no top bar', async () => {
    const { el } = await setup(undefined, false);
    expect(el.querySelector('.topbar')).toBeNull();
    expect(el.querySelector('.sidebar')?.hasAttribute('inert')).toBe(false);
  });

  it('desktop does not move focus into the sidebar on load', async () => {
    const { el } = await setup(undefined, false);
    expect(el.contains(document.activeElement)).toBe(false);
  });

  it('opens from the burger, locks scroll, closes on backdrop and Esc', async () => {
    const { el, detect } = await setup(undefined, true);
    const sidebar = el.querySelector('.sidebar') as HTMLElement;
    expect(el.querySelector('.topbar')).not.toBeNull();
    expect(sidebar.classList.contains('open')).toBe(false);
    expect(sidebar.hasAttribute('inert')).toBe(true);

    (el.querySelector('.burger') as HTMLButtonElement).click();
    await detect();
    expect(sidebar.classList.contains('open')).toBe(true);
    expect(document.body.classList.contains('app-scroll-locked')).toBe(true);
    expect(el.querySelector('#nav-group-people')).not.toBeNull();

    (el.querySelector('.backdrop') as HTMLElement).click();
    await detect();
    expect(sidebar.classList.contains('open')).toBe(false);
    expect(document.body.classList.contains('app-scroll-locked')).toBe(false);

    (el.querySelector('.burger') as HTMLButtonElement).click();
    await detect();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await detect();
    expect(sidebar.classList.contains('open')).toBe(false);
  });

  it('closes on navigation', async () => {
    const { el, router, detect } = await setup(undefined, true);
    (el.querySelector('.burger') as HTMLButtonElement).click();
    await detect();
    await router.navigateByUrl('/tasks');
    await detect();
    expect(el.querySelector('.sidebar')?.classList.contains('open')).toBe(false);
  });
});

describe('ShellLayout collapsible sidebar (icon rail)', () => {
  beforeEach(() => localStorage.clear());

  const toggle = (el: HTMLElement): HTMLButtonElement => el.querySelector('button.rail-toggle') as HTMLButtonElement;
  const isRail = (el: HTMLElement): boolean => el.querySelector('.shell')?.classList.contains('rail') ?? false;

  it('folds to a rail and back, with aria-expanded, a translated label and the choice remembered', async () => {
    const { el, detect } = await setup();
    expect(isRail(el)).toBe(false);
    expect(toggle(el).getAttribute('aria-expanded')).toBe('true');
    expect(toggle(el).getAttribute('aria-label')).toBe('shell.nav.collapseMenu');
    expect(toggle(el).getAttribute('aria-controls')).toBe('app-sidebar');

    toggle(el).click();
    await detect();
    expect(isRail(el)).toBe(true);
    expect(toggle(el).getAttribute('aria-expanded')).toBe('false');
    expect(toggle(el).getAttribute('aria-label')).toBe('shell.nav.expandMenu');
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBe('1');

    toggle(el).click();
    await detect();
    expect(isRail(el)).toBe(false);
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBe('0');
  });

  it('restores the rail after a reload', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el } = await setup();
    expect(isRail(el)).toBe(true);
  });

  it('keeps labels as accessible names and the user menu reachable on the rail', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el } = await setup();
    expect(el.querySelector('a[href="/tasks"] .label')?.textContent).toContain('shell.nav.tasks');
    expect(el.querySelector('.sidebar-footer button.user')?.getAttribute('aria-label')).toBe('shell.menu.open');
  });

  it('shows group icons with the sum of counters; a group click widens the sidebar with that group open', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    localStorage.setItem('sinhrm.nav.expanded.7', '["people"]');
    const { el, http, detect } = await setup();
    await new Promise((resolve) => setTimeout(resolve));
    for (const req of http.match('/api/nav/badges')) req.flush({ data: { timeoff_approvals: 2, my_documents: 3 } });
    await detect();

    // On the rail the items are hidden even for a group opened before, so its header carries the counter.
    expect(header(el, 'people').getAttribute('aria-expanded')).toBe('false');
    expect(header(el, 'people').querySelector('.section-icon')).not.toBeNull();
    expect(el.querySelector('button[aria-controls="nav-group-people"] .nav-badge')?.textContent?.trim()).toBe('5');

    header(el, 'perform').click();
    await detect();
    expect(isRail(el)).toBe(false);
    expect(header(el, 'perform').getAttribute('aria-expanded')).toBe('true');
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBe('0');
  });

  it('marks the group of the current page on the rail', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el, router, detect } = await setup();
    await router.navigateByUrl('/perform/objectives');
    await detect();
    expect(header(el, 'perform').classList.contains('current')).toBe(true);
    expect(header(el, 'people').classList.contains('current')).toBe(false);
  });

  it('shows a name tooltip on the right only while the sidebar is a rail', async () => {
    const { el, detect, fixture } = await setup();
    const tipOf = (href: string): MatTooltip => fixture.debugElement.query(By.css(`a[href="${href}"]`)).injector.get(MatTooltip);
    expect(tipOf('/tasks').disabled).toBe(true);
    toggle(el).click();
    await detect();
    expect(tipOf('/tasks').disabled).toBe(false);
    expect(tipOf('/tasks').message).toBe('shell.nav.tasks');
    expect(tipOf('/tasks').position).toBe('right');
  });

  it('never applies on the mobile drawer: no toggle, no rail', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el } = await setup(undefined, true);
    expect(toggle(el)).toBeNull();
    expect(isRail(el)).toBe(false);
  });
});

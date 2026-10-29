import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, inject, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { MatTooltip } from '@angular/material/tooltip';
import { ActivatedRoute, Router, Routes, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { BreakpointObserver } from '@angular/cdk/layout';
import { of } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { HOVER_QUERY } from './nav-rail';
import { AssistantSettings } from '../assistant/assistant-settings';
import { ShellLayout } from './shell.layout';
import { windowOutletRoute } from '../../core/windows/window-route';
import { WindowNavigation } from '../../core/windows/window-navigation';

@Component({ template: '' })
class Blank {
  readonly route = inject(ActivatedRoute);
}

const WINDOW_TEST_ROUTES: Routes = [
  { path: '', pathMatch: 'full', component: Blank },
  windowOutletRoute('os1', [{ path: 'tasks', component: Blank, data: { windowRoute: 'tasks', module: null } }]),
  windowOutletRoute('os2', [{ path: 'people', component: Blank, data: { windowRoute: 'people', module: 'people' } }]),
  windowOutletRoute('os3', [{ path: 'calendar', component: Blank, data: { windowRoute: 'calendar', module: null } }]),
  windowOutletRoute('os4', [{ path: 'reports', component: Blank, data: { windowRoute: 'reports', module: null } }]),
  { path: 'settings/appearance', component: Blank },
  { path: 'settings/extension', component: Blank },
  { path: 'me', component: Blank },
  { path: '**', component: Blank },
];

const USER = { id: 7, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'uk', roles: ['employee'], status: 'active' };

const logout = vi.fn().mockResolvedValue(undefined);
const setActiveRole = vi.fn().mockResolvedValue(undefined);
const langUse = vi.fn().mockResolvedValue(undefined);

async function setup(modules?: string[], narrow = false, user: object = USER, windows = false): Promise<{ el: HTMLElement; router: Router; http: HttpTestingController; detect: () => Promise<void>; fixture: ComponentFixture<ShellLayout> }> {
  logout.mockClear();
  setActiveRole.mockClear();
  langUse.mockClear();
  TestBed.configureTestingModule({
    imports: [ShellLayout, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter(windows ? WINDOW_TEST_ROUTES : [{ path: '**', component: Blank }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: AuthService, useValue: { user: signal(user), logout, setActiveRole, hasModule: (k: string) => modules === undefined || modules.includes(k) } },
      // A wide screen comes with a mouse (hover), a narrow one is a touch phone.
      { provide: BreakpointObserver, useValue: { observe: (q: string) => of({ matches: q === HOVER_QUERY ? !narrow : narrow, breakpoints: {} }) } },
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

describe('ShellLayout mobile window focus', () => {
  beforeEach(() => localStorage.clear());

  it('marks an inactive expanded window hidden and inert while keeping the other slot active', async () => {
    const { el, router, detect, fixture } = await setup(undefined, true, USER, true);
    await router.navigateByUrl('/');
    await detect();
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    await windows.open('os1', 'tasks');
    await detect();
    await windows.open('os2', 'people');
    await detect();

    const inactive = el.querySelector('article.os-window.window-os1') as HTMLElement;
    expect(inactive.getAttribute('aria-hidden')).toBe('true');
    expect(inactive.hasAttribute('inert')).toBe(true);
    expect(el.querySelector('article.os-window.window-os2')?.getAttribute('aria-hidden')).toBeNull();
    expect(windows.activeSlot()).toBe('os2');

    await windows.focusPrimary();
    await detect();
    expect(el.querySelector('.primary-view')?.hasAttribute('inert')).toBe(false);
    expect(inactive.getAttribute('aria-hidden')).toBe('true');
    expect(windows.activeSlot()).toBeNull();
  });
});

describe('ShellLayout desktop Dock', () => {
  beforeEach(() => localStorage.clear());

  it('uses the audited window launcher for Tasks and restores routed content through the Dock', async () => {
    const { el, router, detect, fixture } = await setup(undefined, false, USER, true);
    await router.navigateByUrl('/');
    await detect();
    const moduleLink = el.querySelector('a[href="/tasks"]') as HTMLAnchorElement;
    expect(moduleLink).not.toBeNull();
    moduleLink.click();
    await detect();
    expect(router.url).toContain('os1:window/tasks');
    expect(fixture.debugElement.injector.get(WindowNavigation).windows().os1.routeId).toBe('tasks');

    const launcher = el.querySelector('.os-dock button[aria-label="shell.nav.tasks"]') as HTMLButtonElement;
    expect(launcher).not.toBeNull();
    launcher.click();
    await detect();
    expect(fixture.debugElement.injector.get(WindowNavigation).windows().os1.routeId).toBe('tasks');
    expect(router.url).toContain('os1:window/tasks');
    expect(el.querySelector('.os-dock button.dock-window')).not.toBeNull();
  });

  it('closes the mobile Modules drawer when launching the already-active window', async () => {
    const { el, router, detect, fixture } = await setup(undefined, true, USER, true);
    await router.navigateByUrl('/');
    await detect();
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    await windows.open('os1', 'tasks');
    await detect();

    (el.querySelector('.modules-toggle') as HTMLButtonElement).click();
    await detect();
    expect(el.querySelector('.modules-toggle')?.getAttribute('aria-expanded')).toBe('true');
    const moduleLink = el.querySelector('a[href="/tasks"]') as HTMLAnchorElement;
    moduleLink.click();
    await detect();

    expect(el.querySelector('.modules-toggle')?.getAttribute('aria-expanded')).toBe('false');
    expect(windows.windows().os1.routeId).toBe('tasks');
    expect(router.url).toContain('os1:window/tasks');
  });

  it('returns Home through the brand while preserving named windows', async () => {
    const { el, router, detect, fixture } = await setup(undefined, false, USER, true);
    await router.navigateByUrl('/');
    await detect();
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    await windows.open('os1', 'tasks');
    await detect();
    const brand = el.querySelector('.topbar-brand') as HTMLAnchorElement;
    expect(brand.getAttribute('href')).toBe('/');

    brand.click();
    await detect();

    expect(router.url).toContain('os1:window/tasks');
    expect(windows.primaryFocused()).toBe(true);
    expect(windows.windows().os1.open).toBe(true);
  });

  it('keeps all minimized pages in the Dock and restores each without closing its route', async () => {
    const { el, router, detect, fixture } = await setup(undefined, false, USER, true);
    await router.navigateByUrl('/');
    await detect();
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    const routes = ['tasks', 'people', 'calendar', 'reports'] as const;
    for (let index = 0; index < routes.length; index++) {
      await windows.open(`os${index + 1}` as 'os1' | 'os2' | 'os3' | 'os4', routes[index]);
    }
    for (const slot of ['os1', 'os2', 'os3', 'os4'] as const) await windows.minimize(slot);
    await detect();

    for (const slot of ['os1', 'os2', 'os3', 'os4'] as const) {
      expect(windows.windows()[slot].minimized).toBe(true);
      expect(el.querySelector(`[data-testid="dock-window-${slot}"]`)).not.toBeNull();
      expect(el.querySelector(`article[data-testid="os-window-${slot}"]`)?.getAttribute('aria-hidden')).toBe('true');
    }

    const restore = el.querySelector('[data-testid="dock-window-os3"]') as HTMLButtonElement;
    restore.click();
    await detect();
    expect(windows.windows().os3.minimized).toBe(false);
    expect(windows.windows().os3.active).toBe(true);
    for (const outlet of ['os1:window/tasks', 'os2:window/people', 'os3:window/calendar', 'os4:window/reports']) {
      expect(router.url).toContain(outlet);
    }
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

  it('does not refetch counters or remount a window for focus, minimize, and restore controls', async () => {
    const { el, router, http, detect, fixture } = await setup(undefined, false, USER, true);
    await flushBadges(http, { tasks: 1 });
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    await windows.open('os1', 'tasks');
    await flushBadges(http, { tasks: 1 });
    const mountedWindowViews = el.querySelectorAll('app-os-window-host router-outlet + *').length;
    expect(mountedWindowViews).toBe(1);

    for (let index = 0; index < 3; index++) {
      await windows.focusPrimary();
      await detect();
      await windows.focus('os1');
      await detect();
      await windows.minimize('os1');
      await detect();
      await windows.restore('os1');
      await detect();
    }
    await new Promise((resolve) => setTimeout(resolve));
    expect(http.match('/api/nav/badges')).toHaveLength(0);
    expect(el.querySelectorAll('app-os-window-host router-outlet + *').length).toBe(mountedWindowViews);
    expect(router.url).toContain('os1:window/tasks');
  });
});

describe('ShellLayout user menu in system bar', () => {
  beforeEach(() => localStorage.clear());

  const openMenu = async (el: HTMLElement, detect: () => Promise<void>): Promise<HTMLElement> => {
    (el.querySelector('.topbar .os-user-launcher') as HTMLButtonElement).click();
    await detect();
    return document.querySelector('.mat-mdc-menu-panel') as HTMLElement;
  };

  it('shows the user menu trigger in the OS bar and keeps help in Modules', async () => {
    const { el } = await setup();
    const footer = el.querySelector('.sidebar .sidebar-footer') as HTMLElement;
    expect(footer).toBeTruthy();
    expect(footer.querySelector('a[href="/docs"]')).toBeTruthy();
    expect(footer.querySelector('button.user')).toBeNull();
    expect(el.querySelector('.topbar .modules-toggle')).not.toBeNull();
    expect(el.querySelector('.topbar .os-user-launcher')?.getAttribute('aria-label')).toBe('shell.menu.open');
  });

  it('opens the menu with language, theme, appearance, profile, extension and logout items', async () => {
    const { el, detect } = await setup();
    const panel = await openMenu(el, detect);
    expect(panel.querySelector('app-language-switcher')).toBeTruthy();
    expect(panel.querySelector('.theme-toggle')).toBeTruthy();
    expect(panel.querySelector('a[href="/settings/appearance"]')?.textContent).toContain('appearance.title');
    expect(panel.querySelector('a[href="/me"]')).toBeTruthy();
    expect(panel.querySelector('a[href="/settings/extension"]')).toBeTruthy();
    expect(panel.querySelector('button.logout')).toBeTruthy();
  });

  it('opens Appearance in the primary view while retaining all four routed windows', async () => {
    const { el, router, detect, fixture } = await setup(undefined, false, USER, true);
    await router.navigateByUrl('/');
    const windows = fixture.debugElement.injector.get(WindowNavigation);
    const routes = ['tasks', 'people', 'calendar', 'reports'] as const;
    for (let index = 0; index < routes.length; index++) {
      await windows.open(`os${index + 1}` as 'os1' | 'os2' | 'os3' | 'os4', routes[index]);
    }
    await detect();
    const slots = ['os1', 'os2', 'os3', 'os4'];
    const before = fixture.debugElement.queryAll(By.directive(Blank))
      .map((debugElement) => debugElement.componentInstance as Blank)
      .filter((view) => view.route.pathFromRoot.some((route) => slots.includes(route.outlet)));
    expect(before).toHaveLength(4);

    const panel = await openMenu(el, detect);
    const appearance = panel.querySelector<HTMLAnchorElement>('a[href="/settings/appearance"]')!;
    appearance.click();
    await detect();

    expect(router.url).toContain('settings/appearance');
    expect(windows.primaryFocused()).toBe(true);
    expect(windows.windows().os1.routeId).toBe('tasks');
    expect(windows.windows().os2.routeId).toBe('people');
    expect(windows.windows().os3.routeId).toBe('calendar');
    expect(windows.windows().os4.routeId).toBe('reports');
    const after = fixture.debugElement.queryAll(By.directive(Blank))
      .map((debugElement) => debugElement.componentInstance as Blank)
      .filter((view) => view.route.pathFromRoot.some((route) => slots.includes(route.outlet)));
    expect(after).toEqual(before);
  });

  it('switches the language from the footer menu', async () => {
    const { el, detect } = await setup();
    const panel = await openMenu(el, detect);
    const ru = Array.from(panel.querySelectorAll('mat-button-toggle button')).find((b) => b.textContent?.trim() === 'ru') as HTMLButtonElement;
    ru.click();
    await detect();
    expect(langUse).toHaveBeenCalledWith('ru');
  });

  it('logs out, drops the saved assistant chat and goes to /login', async () => {
    sessionStorage.setItem('sinhrm.assistant.history.1', '[{"role":"user","content":"hi"}]');
    const { el, router, detect } = await setup();
    const nav = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const panel = await openMenu(el, detect);
    (panel.querySelector('button.logout') as HTMLButtonElement).click();
    await detect();
    expect(logout).toHaveBeenCalled();
    expect(nav).toHaveBeenCalledWith('/login');
    expect(sessionStorage.getItem('sinhrm.assistant.history.1')).toBeNull();
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
    expect(el.querySelector('.topbar .role-chip')?.textContent).toContain('roles.recruiter');
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
    // The toast (core NotifyService) keeps the snack bar's polite live region: screen readers announce it without moving focus.
    expect(document.querySelector('.mat-mdc-snack-bar-container [aria-live="polite"]')).not.toBeNull();
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

    await router.navigateByUrl('/overview');
    await detect();
    expect([...el.querySelectorAll('a.nav-link[aria-current="page"]')].map((a) => a.getAttribute('href'))).toEqual(['/overview']);
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

  it('desktop keeps the top bar and Modules panel inert until opened', async () => {
    const { el } = await setup(undefined, false);
    expect(el.querySelector('.topbar')).not.toBeNull();
    expect(el.querySelector('.topbar-brand')?.getAttribute('aria-label')).toBe('titles.desktop');
    expect(el.querySelector('.sidebar')?.hasAttribute('inert')).toBe(true);
  });

  it('desktop does not move focus into the sidebar on load', async () => {
    const { el } = await setup(undefined, false);
    expect(el.contains(document.activeElement)).toBe(false);
  });

  it('opens from Modules, locks scroll, closes on backdrop and Esc', async () => {
    const { el, detect } = await setup(undefined, true);
    const sidebar = el.querySelector('.sidebar') as HTMLElement;
    expect(el.querySelector('.topbar')).not.toBeNull();
    expect(sidebar.classList.contains('open')).toBe(false);
    expect(sidebar.hasAttribute('inert')).toBe(true);

    (el.querySelector('.modules-toggle') as HTMLButtonElement).click();
    await detect();
    expect(sidebar.classList.contains('open')).toBe(true);
    expect(document.body.classList.contains('app-scroll-locked')).toBe(true);
    expect(el.querySelector('#nav-group-people')).not.toBeNull();

    (el.querySelector('.backdrop') as HTMLElement).click();
    await detect();
    expect(sidebar.classList.contains('open')).toBe(false);
    expect(document.body.classList.contains('app-scroll-locked')).toBe(false);

    (el.querySelector('.modules-toggle') as HTMLButtonElement).click();
    await detect();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await detect();
    expect(sidebar.classList.contains('open')).toBe(false);
  });

  it('closes on navigation', async () => {
    const { el, router, detect } = await setup(undefined, true);
    (el.querySelector('.modules-toggle') as HTMLButtonElement).click();
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
    expect(el.querySelector('.topbar button.user')?.getAttribute('aria-label')).toBe('shell.menu.open');
  });

  it('keeps all permission-aware links visible in Modules regardless of the saved rail preference', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    localStorage.setItem('sinhrm.nav.expanded.7', '["people"]');
    const { el, http, detect } = await setup();
    await new Promise((resolve) => setTimeout(resolve));
    for (const req of http.match('/api/nav/badges')) req.flush({ data: { timeoff_approvals: 2, my_documents: 3 } });
    await detect();

    expect(header(el, 'people').getAttribute('aria-expanded')).toBe('true');
    expect(header(el, 'people').querySelector('.section-icon')).not.toBeNull();
    expect(el.querySelector('a[href="/people"]')).not.toBeNull();
    expect(el.querySelector('a[href="/timeoff/approvals"] .nav-badge')?.textContent?.trim()).toBe('2');

    header(el, 'perform').click();
    await detect();
    expect(header(el, 'perform').getAttribute('aria-expanded')).toBe('true');
    expect(el.querySelector('a[href="/perform/objectives"]')).not.toBeNull();
  });

  it('marks the actual current route link even when a collapsed preference is stored', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el, router, detect } = await setup();
    await router.navigateByUrl('/perform/objectives');
    await detect();
    expect(header(el, 'perform').classList.contains('current')).toBe(true);
    expect(header(el, 'people').classList.contains('current')).toBe(false);
    expect(el.querySelector('a[href="/perform/objectives"]')?.getAttribute('aria-current')).toBe('page');

    toggle(el).click();
    await detect();
    // Group toggles only change disclosure; route state stays on the destination link.
    expect(header(el, 'perform').hasAttribute('aria-current')).toBe(false);
  });

  it('rail tooltips do not repeat the name for screen readers; the user button keeps the name as its description', async () => {
    localStorage.setItem('sinhrm.nav.collapsed', '1');
    const { el, detect } = await setup();
    await detect();
    await detect();
    const tasks = el.querySelector('a[href="/tasks"]') as HTMLElement;
    expect(tasks.hasAttribute('aria-describedby')).toBe(false);
    expect(header(el, 'people').hasAttribute('aria-describedby')).toBe(false);
    const user = el.querySelector('.topbar button.user') as HTMLElement;
    const ids = user.getAttribute('aria-describedby') ?? '';
    expect(ids).not.toBe('');
    expect(document.getElementById(ids)?.textContent).toBe('U');
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
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const { el } = await setup(undefined, true);
    expect(toggle(el)).toBeNull();
    expect(el.querySelector('button.auto-hide')).toBeNull();
    expect(isRail(el)).toBe(false);
    expect(el.querySelector('.shell')?.classList.contains('autohide')).toBe(false);
  });
});

describe('ShellLayout auto-hide sidebar', () => {
  beforeEach(() => localStorage.clear());

  const autoHide = (el: HTMLElement): HTMLButtonElement => el.querySelector('button.auto-hide') as HTMLButtonElement;
  const shell = (el: HTMLElement): DOMTokenList => (el.querySelector('.shell') as HTMLElement).classList;
  const aside = (el: HTMLElement): HTMLElement => el.querySelector('#app-sidebar') as HTMLElement;

  it('a pressed switch next to the collapse button; on: a rail that opens over the content under the pointer', async () => {
    const { el, detect } = await setup();
    expect(autoHide(el).getAttribute('aria-pressed')).toBe('false');
    expect(autoHide(el).getAttribute('aria-label')).toBe('shell.nav.autoHide');

    autoHide(el).click();
    await detect();
    expect(autoHide(el).getAttribute('aria-pressed')).toBe('true');
    expect(localStorage.getItem('sinhrm.nav.autohide')).toBe('1');
    expect([shell(el).contains('autohide'), shell(el).contains('rail'), shell(el).contains('peek')]).toEqual([true, true, false]);
    // The collapse toggle has nothing to do while auto-hide is on.
    expect(el.querySelector('button.rail-toggle:not(.auto-hide)')).toBeNull();

    aside(el).dispatchEvent(new MouseEvent('mouseenter'));
    await detect();
    // Width first (peek), labels after the 180ms width animation (rail off).
    expect([shell(el).contains('autohide'), shell(el).contains('rail'), shell(el).contains('peek')]).toEqual([true, true, true]);
    await new Promise((resolve) => setTimeout(resolve, 220));
    await detect();
    expect([shell(el).contains('autohide'), shell(el).contains('rail'), shell(el).contains('peek')]).toEqual([true, false, true]);
    aside(el).dispatchEvent(new MouseEvent('mouseleave'));
    await new Promise((resolve) => setTimeout(resolve, 300));
    await detect();
    expect([shell(el).contains('rail'), shell(el).contains('peek')]).toEqual([true, false]);
  });

  it('remembers auto-hide after a reload; Esc folds the open sidebar', async () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const { el, detect } = await setup();
    expect(autoHide(el).getAttribute('aria-pressed')).toBe('true');
    aside(el).dispatchEvent(new MouseEvent('mouseenter'));
    await detect();
    expect(shell(el).contains('peek')).toBe(true);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await detect();
    expect(shell(el).contains('peek')).toBe(false);
  });

  it('keyboard focus inside opens and holds it, focus leaving folds it', async () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const { el, detect } = await setup();
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    try {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
      (el.querySelector('a[href="/tasks"]') as HTMLElement).focus();
      await detect();
      expect(shell(el).contains('peek')).toBe(true);
      outside.focus();
      await detect();
      expect(shell(el).contains('peek')).toBe(false);
    } finally {
      outside.remove();
    }
  });
});


describe('ShellLayout mobile assistant access', () => {
  beforeEach(() => localStorage.clear());

  it('opens chat from a translated top-bar button without opening the drawer or changing the animation preference', async () => {
    const { el, detect } = await setup(['assistant'], true);
    const settings = TestBed.inject(AssistantSettings);
    const launcher = el.querySelector('.topbar .assistant-launcher') as HTMLButtonElement;
    expect(launcher?.getAttribute('aria-label')).toBe('shell.menu.assistant');
    expect(settings.chatOpen()).toBe(false);
    launcher.click();
    await detect();
    expect(settings.chatOpen()).toBe(true);
    expect(settings.enabled()).toBe(true);
    expect(el.querySelector('.sidebar')?.classList.contains('open')).toBe(false);
    expect(localStorage.getItem('sinhrm.assistant.enabled')).toBeNull();
  });

  it('hides the mobile launcher when assistant access is unavailable', async () => {
    const { el } = await setup(['core'], true);
    expect(el.querySelector('.assistant-launcher')).toBeNull();
  });
});

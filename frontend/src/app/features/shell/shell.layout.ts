import { Logo } from '../../core/ui/logo';
import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { ActivatedRouteSnapshot, NavigationEnd, Router, RouterOutlet } from '@angular/router';
import { SUPERADMIN_ROLE, UserRole, isAdmin, isHrStaff } from '../../core/auth/auth.model';
import { A11yModule } from '@angular/cdk/a11y';
import { BreakpointObserver } from '@angular/cdk/layout';
import { DOCUMENT } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ThemeService } from '../../core/theme/theme.service';
import { clearAssistantHistory } from '../assistant/assistant-conversation';
import { AssistantSettings } from '../assistant/assistant-settings';
import { AssistantMascot } from '../assistant/mascot/assistant-mascot';
import { LanguageSwitcher } from './language-switcher';
import { NavBadge, NavBadgesService, groupBadgeSum } from './nav-badges';
import { NAV_GROUP_MODULES, NavGroupId, groupForUrl, loadExpanded, saveExpanded } from './nav-groups';
import { NARROW_QUERY } from './shell-breakpoints';
import { NotifyService } from '../../core/ui/notify.service';
import { isWindowControlOnlyNavigation, WindowNavigation } from '../../core/windows/window-navigation';
import { WindowSlot, WINDOW_SLOTS, windowRouteByPath } from '../../core/windows/window-context';
import { OsWindowWorkspace } from './os-window-workspace';
import { DESKTOP_APPS, DesktopAppEntry, appAllowed } from '../desktop/desktop.model';
import { DESKTOP_LAUNCHER } from '../desktop/desktop-launcher';

/** One radio item of "Працювати як": a role, or null = all roles. */
interface RoleChoice {
  role: UserRole | null;
  label: string;
}

/** App frame for signed-in users: sidebar navigation with a pinned footer (help link + user menu). */
@Component({
  selector: 'app-shell-layout',
  imports: [
    Logo,
    RouterOutlet,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    TranslocoPipe,
    LanguageSwitcher,
    NavBadge,
    A11yModule,
    AssistantMascot,
    OsWindowWorkspace,
  ],
  providers: [
    WindowNavigation,
    {
      provide: DESKTOP_LAUNCHER,
      useFactory: () => {
        const router = inject(Router);
        const windows = inject(WindowNavigation);
        const notify = inject(NotifyService);
        return {
          launch: (route: string) => {
            const definition = windowRouteByPath(router.config, route);
            // Only canonical pages absent from the authenticated workspace catalog stay in primary.
            if (!definition) return router.navigateByUrl(route);
            const slot = definition ? windows.slotFor(definition.id) : null;
            if (!slot) {
              notify.show('osWindow.capacity');
              return Promise.resolve(false);
            }
            return windows.open(slot, definition.id);
          },
        };
      },
    },
  ],
  host: { '(document:keydown.escape)': 'onEscape()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './shell.layout.html',
  styleUrl: './shell.layout.scss',
})
export class ShellLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly breakpoints = inject(BreakpointObserver);
  private readonly navBadges = inject(NavBadgesService);
  private readonly assistant = inject(AssistantSettings);
  protected readonly theme = inject(ThemeService);
  protected readonly windowNavigation = inject(WindowNavigation);
  private readonly notify = inject(NotifyService);
  private readonly i18n = inject(TranslocoService);
  private readonly desktopLauncher = inject(DESKTOP_LAUNCHER);

  protected readonly user = this.auth.user;
  protected readonly isSuperadmin = computed(() => this.user()?.roles.includes(SUPERADMIN_ROLE) ?? false);
  /** Dictionaries are managed by superadmin and admin. */
  protected readonly isAdmin = computed(() => isAdmin(this.user()?.roles ?? []));
  /** HR settings pages (People, TimeOff, Desk, Pulse, Workflows, …): superadmin, admin, hr_manager. */
  protected readonly isHr = computed(() => isHrStaff(this.user()?.roles ?? []));
  protected readonly activeRole = computed(() => this.user()?.active_role ?? null);
  /** "Працювати як" items; empty (section hidden) for single-role accounts. */
  protected readonly roleChoices = computed<RoleChoice[]>(() => {
    const assigned = this.user()?.assigned_roles ?? [];
    return assigned.length < 2 ? [] : [{ role: null, label: 'shell.menu.allRoles' }, ...assigned.map((role) => ({ role, label: `roles.${role}` }))];
  });
  protected readonly initial = computed(() => (this.user()?.name ?? '?').charAt(0).toUpperCase());
  protected readonly themeLabel = computed(() => (this.theme.theme() === 'dark' ? 'shell.theme.toLight' : 'shell.theme.toDark'));
  protected readonly themeIcon = computed(() => (this.theme.theme() === 'dark' ? 'light_mode' : 'dark_mode'));
  protected readonly windowSlots = WINDOW_SLOTS;
  protected readonly dockApps = computed(() => {
    const pinned = new Set(['tasks', 'people', 'desk', 'recruiting-reports']);
    const running = new Set(Object.values(this.windowNavigation.windows()).map((view) => view.routeId).filter((id): id is string => id !== null));
    return DESKTOP_APPS.filter((app) => {
      const route = windowRouteByPath(this.router.config, app.route);
      return pinned.has(app.id) && appAllowed(app, this.user()) && route !== undefined && !running.has(route.id);
    });
  });

  protected moduleLinkActive(path: string, exact = false): boolean {
    this.url();
    const definition = windowRouteByPath(this.router.config, path);
    if (definition && Object.values(this.windowNavigation.windows()).some((view) => view.active && view.routeId === definition.id)) return true;
    return this.router.isActive(this.router.parseUrl(path), {
      paths: exact ? 'exact' : 'subset',
      queryParams: 'ignored',
      matrixParams: 'ignored',
      fragment: 'ignored',
    });
  }

  protected navigateModule(event: MouseEvent, path: string): void {
    const anchor = event.currentTarget;
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
      || (anchor instanceof HTMLAnchorElement && anchor.target)) return;
    event.preventDefault();
    void this.desktopLauncher.launch(path);
    // Close on the launch action itself: focusing an already active window can be NavigationSkipped, so no
    // NavigationEnd event is guaranteed to dismiss the mobile Modules drawer.
    this.closeDrawer();
  }

  protected navigateDesktop(event: MouseEvent): void {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    event.preventDefault();
    void this.windowNavigation.navigatePrimary('/');
  }

  /** Core destinations use the primary outlet while preserving live named windows and browser link semantics. */
  protected navigatePrimary(event: MouseEvent, path: string): void {
    const anchor = event.currentTarget;
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
      || (anchor instanceof HTMLAnchorElement && anchor.target)) return;
    event.preventDefault();
    void this.windowNavigation.navigatePrimary(path);
  }

  private readonly url = toSignal(
    this.router.events.pipe(
      filter((e): e is NavigationEnd => e instanceof NavigationEnd),
      map((e) => e.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );
  /** Counters next to menu items (my tasks, approvals, inboxes…). */
  protected readonly badges = this.navBadges.badges;
  /** Groups the user opened by hand (remembered per user in localStorage). */
  private readonly expanded = signal<ReadonlySet<NavGroupId>>(new Set());
  /** Group of the current page — always shown open. */
  protected readonly activeGroup = computed(() => groupForUrl(this.url()));

  /** Below 768px the sidebar is an off-canvas drawer behind a compact top bar. */
  protected readonly narrow = toSignal(this.breakpoints.observe(NARROW_QUERY).pipe(map((state) => state.matches)), { initialValue: false });
  protected readonly drawerOpen = signal(false);
  /** Sum of all counters, shown on the burger button. */
  protected readonly totalBadge = computed(() => Object.values(this.badges()).reduce<number>((a, n) => a + (n ?? 0), 0));
  /** i18n key of the current route title (top bar on narrow screens). */
  protected readonly pageTitleKey = computed(() => {
    this.url();
    let route: ActivatedRouteSnapshot | null = this.router.routerState.snapshot.root;
    let key: string | undefined;
    while (route) {
      key = route.title ?? key;
      route = route.firstChild;
    }
    return key;
  });

  constructor() {
    const body = inject(DOCUMENT).body;
    // Lock page scroll while the drawer is open.
    effect(() => body.classList.toggle('app-scroll-locked', this.drawerOpen()));
    // Any navigation (and leaving the narrow layout) closes the drawer.
    effect(() => {
      if (!this.narrow()) untracked(() => this.drawerOpen.set(false));
    });
    this.router.events
      .pipe(filter((e) => e instanceof NavigationEnd), takeUntilDestroyed())
      .subscribe(() => this.drawerOpen.set(false));
    let previousUrl = this.router.url;
    const contentNavigations = this.router.events.pipe(
      filter((e): e is NavigationEnd => e instanceof NavigationEnd),
      filter((event) => {
        const controlOnly = isWindowControlOnlyNavigation(this.router, previousUrl, event.urlAfterRedirects);
        previousUrl = event.urlAfterRedirects;
        return !controlOnly;
      }),
    );
    const watching = this.navBadges.watch(contentNavigations);
    inject(DestroyRef).onDestroy(() => watching.unsubscribe());
    effect(() => {
      const id = this.user()?.id;
      this.expanded.set(id === undefined ? new Set() : loadExpanded(id));
    });
    // Navigating into a group opens it (and keeps it open afterwards).
    effect(() => {
      const group = this.activeGroup();
      // untracked: only navigation re-runs this, so the user can still fold the current group.
      if (group && !untracked(this.expanded).has(group)) this.setExpanded(group, true);
    });
  }

  /** Module is switched on and allowed for the user (hidden otherwise, docs/modules/modules-access.md). */
  protected can(module: string): boolean {
    return this.auth.hasModule(module);
  }

  /** A group is shown while at least one of its modules is available. */
  protected groupVisible(group: NavGroupId): boolean {
    return NAV_GROUP_MODULES[group].some((m) => this.auth.hasModule(m));
  }

  /** Groups stay fully navigable inside the Modules panel regardless of the legacy sidebar preference. */
  protected isOpen(group: NavGroupId): boolean {
    return this.expanded().has(group);
  }

  /**
   * On the rail the items of a group are hidden, so the group header of the current page says so to screen readers
   * (aria-current="true"; the expanded sidebar marks the item itself with aria-current="page").
   */
  /** A group header whose items are hidden shows the sum of their counters. */
  protected groupBadge(group: NavGroupId): number {
    return this.isOpen(group) ? 0 : groupBadgeSum(this.badges(), group);
  }

  /** Header click: folds/unfolds a group inside the Modules panel. */
  protected toggle(group: NavGroupId): void {
    this.setExpanded(group, !this.expanded().has(group));
  }

  protected focusWindow(slot: WindowSlot): void {
    void this.windowNavigation.focus(slot);
  }

  protected launchDesktopApp(app: DesktopAppEntry): void {
    void this.desktopLauncher.launch(app.route);
  }

  protected onEscape(): void {
    this.closeDrawer();
  }

  private setExpanded(group: NavGroupId, open: boolean): void {
    const next = new Set(this.expanded());
    if (open) next.add(group);
    else next.delete(group);
    this.expanded.set(next);
    const id = this.user()?.id;
    if (id !== undefined) saveExpanded(id, next);
  }

  protected toggleDrawer(): void {
    this.drawerOpen.update((open) => !open);
  }

  protected openDrawer(): void {
    this.drawerOpen.set(true);
  }

  protected closeDrawer(): void {
    this.drawerOpen.set(false);
  }

  /** Opens the chat with «Стік» (keyboard-friendly way to reach him while he is off stage). */
  protected openAssistant(): void {
    this.assistant.openChat();
  }

  /** "Працювати як": the server narrows every check to the role; the SPA restarts from home with the new menu. */
  protected async workAs(role: UserRole | null): Promise<void> {
    if (role === this.activeRole()) return;
    try {
      await this.auth.setActiveRole(role);
    } catch {
      this.notify.show('common.error');
      return;
    }
    await this.router.navigateByUrl('/');
    const name = this.i18n.translate(role === null ? 'shell.menu.allRoles' : `roles.${role}`);
    this.notify.show('shell.menu.workingAs', { params: { role: name } });
  }

  protected async logout(): Promise<void> {
    try {
      await this.auth.logout();
    } finally {
      clearAssistantHistory();
    }
    await this.router.navigateByUrl('/login');
  }
}

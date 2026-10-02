import { Logo } from '../../core/ui/logo';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, afterNextRender, computed, effect, inject, signal, untracked, viewChild } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { ActivatedRouteSnapshot, NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { UserRole, isHrStaff } from '../../core/auth/auth.model';
import { A11yModule, FocusMonitor } from '@angular/cdk/a11y';
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
import { NavRail, RailTip } from './nav-rail';
import { NotifyService } from '../../core/ui/notify.service';

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
    RouterLink,
    RouterLinkActive,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    TranslocoPipe,
    LanguageSwitcher,
    NavBadge,
    A11yModule,
    AssistantMascot,
    RailTip,
  ],
  providers: [NavRail],
  host: { '(document:keydown.escape)': 'onEscape()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './shell.layout.html',
  styleUrl: './shell.layout.scss',
})
export class ShellLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly navBadges = inject(NavBadgesService);
  private readonly assistant = inject(AssistantSettings);
  protected readonly theme = inject(ThemeService);
  private readonly notify = inject(NotifyService);
  private readonly i18n = inject(TranslocoService);

  protected readonly user = this.auth.user;
  protected readonly isSuperadmin = computed(() => this.user()?.roles.includes('superadmin') ?? false);
  /** Dictionaries are managed by superadmin and admin. */
  protected readonly isAdmin = computed(() => this.isSuperadmin() || (this.user()?.roles.includes('admin') ?? false));
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

  private readonly rail = inject(NavRail);
  /** Below 768px the sidebar is an off-canvas drawer behind a compact top bar. */
  protected readonly narrow = this.rail.narrow;
  /** Desktop sidebar folded to an icon-only rail (remembered in this browser). */
  protected readonly collapsed = this.rail.collapsed;
  /** «Auto-hide»: a rail that opens over the content under the pointer / keyboard focus (wide screens with a mouse). */
  protected readonly autoHide = this.rail.autoHide;
  protected readonly autoHideAvailable = this.rail.autoHideAvailable;
  protected readonly peek = this.rail.peek;
  private readonly sidebar = viewChild.required<ElementRef<HTMLElement>>('sidebar');
  protected readonly railToggleLabel = computed(() => (this.collapsed() ? 'shell.nav.expandMenu' : 'shell.nav.collapseMenu'));
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
    effect(() => body.classList.toggle('app-scroll-locked', this.narrow() && this.drawerOpen()));
    // Any navigation (and leaving the narrow layout) closes the drawer.
    effect(() => {
      if (!this.narrow()) untracked(() => this.drawerOpen.set(false));
    });
    this.router.events
      .pipe(filter((e) => e instanceof NavigationEnd), takeUntilDestroyed())
      .subscribe(() => this.drawerOpen.set(false));
    const watching = this.navBadges.watch(this.router.events.pipe(filter((e) => e instanceof NavigationEnd)));
    inject(DestroyRef).onDestroy(() => watching.unsubscribe());
    effect(() => {
      const id = this.user()?.id;
      this.expanded.set(id === undefined ? new Set() : loadExpanded(id));
    });
    // Auto-hide: keyboard focus inside the sidebar keeps it open, focus leaving it folds it (a click does not hold it).
    const focus = inject(FocusMonitor);
    const destroyRef = inject(DestroyRef);
    afterNextRender(() => {
      const el = this.sidebar().nativeElement;
      const sub = focus.monitor(el, true).subscribe((origin) => (origin === null ? this.rail.focusOut() : this.rail.focusIn(origin === 'keyboard')));
      destroyRef.onDestroy(() => {
        sub.unsubscribe();
        focus.stopMonitoring(el);
      });
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

  /** Items of a group are shown: opened by the user, and the sidebar is not a rail (a rail shows group icons only). */
  protected isOpen(group: NavGroupId): boolean {
    return !this.collapsed() && this.expanded().has(group);
  }

  /**
   * On the rail the items of a group are hidden, so the group header of the current page says so to screen readers
   * (aria-current="true"; the expanded sidebar marks the item itself with aria-current="page").
   */
  protected groupCurrent(group: NavGroupId): 'true' | null {
    return this.collapsed() && this.activeGroup() === group ? 'true' : null;
  }

  /** A group header whose items are hidden shows the sum of their counters. */
  protected groupBadge(group: NavGroupId): number {
    return this.isOpen(group) ? 0 : groupBadgeSum(this.badges(), group);
  }

  /** Header click: folds/unfolds the group; on the rail it widens the sidebar with that group open. */
  protected toggle(group: NavGroupId): void {
    if (this.collapsed()) {
      this.rail.expand();
      this.setExpanded(group, true);
      return;
    }
    this.setExpanded(group, !this.expanded().has(group));
  }

  protected toggleRail(): void {
    this.rail.toggle();
  }

  protected toggleAutoHide(): void {
    this.rail.toggleAutoHide();
  }

  protected pointerEnter(): void {
    this.rail.pointerEnter();
  }

  protected pointerLeave(): void {
    this.rail.pointerLeave();
  }

  /** The user menu (an overlay outside the sidebar) keeps the auto-hide sidebar open while it is open. */
  protected holdSidebar(on: boolean): void {
    this.rail.hold(on);
  }

  protected onEscape(): void {
    this.closeDrawer();
    this.rail.escape();
  }

  private setExpanded(group: NavGroupId, open: boolean): void {
    const next = new Set(this.expanded());
    if (open) next.add(group);
    else next.delete(group);
    this.expanded.set(next);
    const id = this.user()?.id;
    if (id !== undefined) saveExpanded(id, next);
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

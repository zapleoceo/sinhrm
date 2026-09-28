import { Logo } from '../../core/ui/logo';
import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { ActivatedRouteSnapshot, NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { isHrStaff } from '../../core/auth/auth.model';
import { A11yModule } from '@angular/cdk/a11y';
import { BreakpointObserver } from '@angular/cdk/layout';
import { DOCUMENT } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ThemeService } from '../../core/theme/theme.service';
import { AssistantSettings } from '../assistant/assistant-settings';
import { AssistantMascot } from '../assistant/mascot/assistant-mascot';
import { LanguageSwitcher } from './language-switcher';
import { NavBadge, NavBadgesService, groupBadgeSum } from './nav-badges';
import { NAV_GROUP_MODULES, NavGroupId, groupForUrl, loadExpanded, saveExpanded } from './nav-groups';

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
  ],
  host: { '(document:keydown.escape)': 'closeDrawer()' },
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

  protected readonly user = this.auth.user;
  protected readonly isSuperadmin = computed(() => this.user()?.roles.includes('superadmin') ?? false);
  /** Dictionaries are managed by superadmin and admin. */
  protected readonly isAdmin = computed(() => this.isSuperadmin() || (this.user()?.roles.includes('admin') ?? false));
  /** HR settings pages (People, TimeOff, Desk, Pulse, Workflows, …): superadmin, admin, hr_manager. */
  protected readonly isHr = computed(() => isHrStaff(this.user()?.roles ?? []));
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

  /** Below 768px the sidebar is an off-canvas drawer behind a compact top bar. */
  protected readonly narrow = toSignal(
    inject(BreakpointObserver).observe('(max-width: 767.98px)').pipe(map((s) => s.matches)),
    { initialValue: false },
  );
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

  protected isOpen(group: NavGroupId): boolean {
    return this.expanded().has(group);
  }

  /** A collapsed group header shows the sum of its items' counters. */
  protected groupBadge(group: NavGroupId): number {
    return groupBadgeSum(this.badges(), group);
  }

  protected toggle(group: NavGroupId): void {
    this.setExpanded(group, !this.expanded().has(group));
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

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }
}

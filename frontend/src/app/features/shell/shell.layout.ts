import { ChangeDetectionStrategy, Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { isHrStaff } from '../../core/auth/auth.model';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ThemeService } from '../../core/theme/theme.service';
import { LanguageSwitcher } from './language-switcher';
import { NAV_GROUP_MODULES, NavGroupId, groupForUrl, loadExpanded, saveExpanded } from './nav-groups';

/** App frame for signed-in users: sidebar navigation with a pinned footer (help link + user menu). */
@Component({
  selector: 'app-shell-layout',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    TranslocoPipe,
    LanguageSwitcher,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './shell.layout.html',
  styleUrl: './shell.layout.scss',
})
export class ShellLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
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
  /** Groups the user opened by hand (remembered per user in localStorage). */
  private readonly expanded = signal<ReadonlySet<NavGroupId>>(new Set());
  /** Group of the current page — always shown open. */
  protected readonly activeGroup = computed(() => groupForUrl(this.url()));

  constructor() {
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

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }
}

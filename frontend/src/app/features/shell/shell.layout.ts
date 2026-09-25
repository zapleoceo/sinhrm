import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ThemeService } from '../../core/theme/theme.service';
import { CommandPaletteService } from '../recruiting/palette/command-palette.service';
import { LanguageSwitcher } from './language-switcher';

/** App frame for signed-in users: sidebar navigation + top bar with the user menu. */
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
    MatTooltipModule,
    TranslocoPipe,
    LanguageSwitcher,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(document:keydown)': 'onKey($event)' },
  templateUrl: './shell.layout.html',
  styleUrl: './shell.layout.scss',
})
export class ShellLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  protected readonly theme = inject(ThemeService);
  protected readonly palette = inject(CommandPaletteService);
  /** Shortcut label: ⌘K on Apple devices, Ctrl+K elsewhere. */
  protected readonly paletteKey = /Mac|iPhone|iPad/.test(globalThis.navigator?.platform ?? '') ? '⌘K' : 'Ctrl+K';

  protected readonly user = this.auth.user;
  protected readonly isSuperadmin = computed(() => this.user()?.roles.includes('superadmin') ?? false);
  /** Dictionaries are managed by superadmin and admin. */
  protected readonly isAdmin = computed(() => this.isSuperadmin() || (this.user()?.roles.includes('admin') ?? false));
  protected readonly initial = computed(() => (this.user()?.name ?? '?').charAt(0).toUpperCase());
  protected readonly themeLabel = computed(() => (this.theme.theme() === 'dark' ? 'shell.theme.toLight' : 'shell.theme.toDark'));
  protected readonly themeIcon = computed(() => (this.theme.theme() === 'dark' ? 'light_mode' : 'dark_mode'));

  /** Cmd/Ctrl+K anywhere toggles the command palette. */
  protected onKey(e: KeyboardEvent): void {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      this.palette.toggle();
    }
  }

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }
}

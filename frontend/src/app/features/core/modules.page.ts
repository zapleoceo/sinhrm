import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { USER_ROLES, UserRole } from '../../core/auth/auth.model';
import { AuthService } from '../../core/auth/auth.service';
import { ModuleSetting, ModulesService } from './modules.service';

/** Editable copy of a row; `confirming` = the "switch off?" question is shown. */
interface Draft {
  enabled: boolean;
  roles: UserRole[];
  confirming: boolean;
}

/**
 * Адміністрування → Модулі (superadmin): switch modules on/off for the whole company and choose which roles see
 * them. Core modules are locked. Switching off asks for confirmation and never deletes data.
 */
@Component({
  selector: 'app-modules-page',
  imports: [
    MatButtonModule,
    MatCheckboxModule,
    MatIconModule,
    MatProgressBarModule,
    MatSlideToggleModule,
    MatTooltipModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ 'modules.title' | transloco }}</h1>
    <p class="muted">{{ 'modules.intro' | transloco }}</p>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="table-wrap">
      <table class="matrix">
        <thead>
          <tr>
            <th scope="col">{{ 'modules.module' | transloco }}</th>
            <th scope="col">{{ 'modules.enabled' | transloco }}</th>
            @for (role of roles; track role) {
              <th scope="col" class="role">{{ 'roles.' + role | transloco }}</th>
            }
            <th scope="col"><span class="visually-hidden">{{ 'modules.actions' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (m of modules(); track m.key) {
            @let d = drafts()[m.key];
            <tr [class.core]="m.core" [class.off]="!d.enabled">
              <th scope="row">
                <span class="name">
                  <mat-icon aria-hidden="true">{{ m.icon }}</mat-icon>
                  {{ m.name_key | transloco }}
                  @if (m.core) {
                    <mat-icon class="lock" [matTooltip]="'modules.coreHint' | transloco" [attr.aria-label]="'modules.coreHint' | transloco">lock</mat-icon>
                  }
                </span>
              </th>
              <td>
                <mat-slide-toggle
                  [checked]="d.enabled"
                  [disabled]="m.core"
                  [attr.aria-label]="('modules.enabled' | transloco) + ': ' + (m.name_key | transloco)"
                  (change)="setEnabled(m.key, $event.checked)"
                />
              </td>
              @for (role of roles; track role) {
                <td class="role">
                  <mat-checkbox
                    [checked]="m.core || role === 'superadmin' || d.roles.includes(role)"
                    [disabled]="m.core || role === 'superadmin'"
                    [attr.aria-label]="(m.name_key | transloco) + ': ' + ('roles.' + role | transloco)"
                    (change)="setRole(m.key, role, $event.checked)"
                  />
                </td>
              }
              <td class="actions">
                @if (!m.core && changed(m)) {
                  @if (d.confirming) {
                    <span class="warn">{{ 'modules.confirmOff' | transloco }}</span>
                    <button mat-flat-button type="button" (click)="save(m, true)" [disabled]="saving()">{{ 'modules.confirmYes' | transloco }}</button>
                    <button mat-button type="button" (click)="reset(m)">{{ 'common.cancel' | transloco }}</button>
                  } @else {
                    <button mat-flat-button type="button" (click)="save(m, false)" [disabled]="saving()">{{ 'common.save' | transloco }}</button>
                    <button mat-button type="button" (click)="reset(m)">{{ 'common.cancel' | transloco }}</button>
                  }
                }
              </td>
            </tr>
          }
        </tbody>
      </table>
    </div>
    <p class="muted note">{{ 'modules.contextualNote' | transloco }}</p>
  `,
  styles: `
    h1 { font: var(--mat-sys-headline-small); margin: 0 0 0.5rem; }
    .table-wrap { overflow-x: auto; }
    .matrix { border-collapse: collapse; width: 100%; }
    th, td { padding: 0.35rem 0.5rem; text-align: left; border-bottom: 1px solid var(--mat-sys-outline-variant); }
    .role { text-align: center; }
    .name { display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 500; }
    .lock { font-size: 18px; width: 18px; height: 18px; opacity: 0.7; }
    tr.off .name { opacity: 0.6; }
    .actions { white-space: nowrap; }
    .actions button { margin-left: 0.25rem; }
    .warn { color: var(--app-danger); margin-right: 0.5rem; }
    .note { margin-top: 1rem; }
    .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  `,
})
export class ModulesPage implements OnInit {
  private readonly api = inject(ModulesService);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly roles = USER_ROLES;
  protected readonly modules = signal<ModuleSetting[]>([]);
  protected readonly drafts = signal<Record<string, Draft>>({});
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);

  ngOnInit(): void {
    this.api.list().subscribe({
      next: (rows) => {
        this.modules.set(rows);
        this.drafts.set(Object.fromEntries(rows.map((m) => [m.key, this.draftOf(m)])));
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.toast('modules.loadError');
      },
    });
  }

  protected changed(m: ModuleSetting): boolean {
    const d = this.drafts()[m.key];
    return d.enabled !== m.enabled || [...d.roles].sort().join() !== [...m.roles].sort().join();
  }

  protected setEnabled(key: string, enabled: boolean): void {
    this.patch(key, { enabled, confirming: false });
  }

  protected setRole(key: string, role: UserRole, on: boolean): void {
    const roles = this.drafts()[key].roles.filter((r) => r !== role);
    this.patch(key, { roles: on ? [...roles, role] : roles });
  }

  protected reset(m: ModuleSetting): void {
    this.patch(m.key, this.draftOf(m));
  }

  /** Switching a module off asks once more ("data is not deleted"); everything else saves at once. */
  protected save(m: ModuleSetting, confirmed: boolean): void {
    const d = this.drafts()[m.key];
    if (m.enabled && !d.enabled && !confirmed) {
      this.patch(m.key, { confirming: true });
      return;
    }
    this.saving.set(true);
    this.api.save(m.key, d.enabled, d.roles).subscribe({
      next: (saved) => {
        this.modules.update((rows) => rows.map((r) => (r.key === saved.key ? saved : r)));
        this.patch(saved.key, this.draftOf(saved));
        this.saving.set(false);
        this.toast('modules.saved');
        // The menu of the current user follows the new settings.
        void this.auth.reload();
      },
      error: () => {
        this.saving.set(false);
        this.toast('modules.saveError');
      },
    });
  }

  private draftOf(m: ModuleSetting): Draft {
    return { enabled: m.enabled, roles: [...m.roles], confirming: false };
  }

  private patch(key: string, change: Partial<Draft>): void {
    this.drafts.update((all) => ({ ...all, [key]: { ...all[key], ...change } }));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}

import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LeaveRequestsStore } from '../../timeoff/leave-requests.store';
import { BalancesPanel } from '../../timeoff/widgets/balances-panel';
import { LeaveRequestForm } from '../../timeoff/widgets/leave-request-form';
import { RequestAction, RequestsList } from '../../timeoff/widgets/requests-list';
import { initials } from '../org-tree';
import { CHANGEABLE_FIELDS, ChangeRequest, Employee, fieldLabelKey } from '../people.model';
import { EmployeeDocumentsTab } from '../../documents/profile/employee-documents.tab';
import { EmployeeRunsTab } from '../../workflows/runs/employee-runs.tab';
import { PerformanceTab } from '../../perform/profile/performance.tab';
import { EmployeeAssetsTab } from '../../assets/employee-assets.tab';
import { AuthService } from '../../../core/auth/auth.service';
import { AuditHistory } from '../../audit/audit-history';
import { AuditLoader } from '../../audit/audit.model';
import { AuditService } from '../../audit/audit.service';
import { canManagePeople } from '../people.access';
import { ChangeRequestDialog } from './change-request.dialog';
import { EmployeeDialog, EmployeeDialogData } from './employee.dialog';
import { ProfileStore, ProfileTab } from './profile.store';
import { TerminateDialog } from './terminate.dialog';

/**
 * Employee profile (/people/:id) and "My profile" (/me). Tabs follow the API's access flags: Overview for everyone,
 * Job and Time off for admins, the employee and managers above, Change requests for admins, the employee and deciders,
 * Documents for admins, the employee and managers, Workflows for admins and managers, Performance for admins,
 * the employee and managers.
 */
@Component({
  selector: 'app-profile-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatIconModule,
    MatProgressBarModule,
    MatTabsModule,
    RouterLink,
    TranslocoPipe,
    BalancesPanel,
    LeaveRequestForm,
    RequestsList,
    EmployeeDocumentsTab,
    EmployeeRunsTab,
    PerformanceTab,
    EmployeeAssetsTab,
    AuditHistory,
  ],
  providers: [ProfileStore, LeaveRequestsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (store.loading() && !store.employee()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.error(); as key) {
      <div class="state">
        <p>{{ key | transloco }}</p>
        <a mat-stroked-button routerLink="/people">{{ 'people.directory.title' | transloco }}</a>
      </div>
    }
    @if (store.employee(); as e) {
      <header class="page-head">
        <div class="who">
          <span class="avatar" aria-hidden="true">{{ initialsOf(e) }}</span>
          <div>
            <h1>{{ e.full_name }}</h1>
            <p class="muted">
              {{ e.position?.name ?? '—' }}@if (e.department) { · {{ e.department.name }}}@if (e.branch) { · {{ e.branch.name }}}
              @if (e.status !== 'active') {
                · <span class="badge">{{ 'people.status.' + e.status | transloco }}</span>
              }
            </p>
          </div>
        </div>
        <div class="actions">
          @if (e.access?.self) {
            <button mat-stroked-button type="button" (click)="requestChange(e)"><mat-icon>edit_note</mat-icon>{{ 'people.changes.new' | transloco }}</button>
          }
          @if (e.access?.manage) {
            <button mat-stroked-button type="button" (click)="edit(e)"><mat-icon>edit</mat-icon>{{ 'people.edit.title' | transloco }}</button>
            @if (e.status !== 'terminated') {
              <button mat-button type="button" class="danger" (click)="terminate(e)">{{ 'people.terminate.action' | transloco }}</button>
            }
          }
        </div>
      </header>

      <mat-tab-group mat-stretch-tabs="false" animationDuration="0ms" [selectedIndex]="initialTab()">
        <mat-tab [label]="'people.tabs.overview' | transloco">
          <dl class="facts">
            <dt>{{ 'people.fields.workEmail' | transloco }}</dt>
            <dd>
              @if (e.work_email) { <a [href]="'mailto:' + e.work_email">{{ e.work_email }}</a> } @else { — }
            </dd>
            <dt>{{ 'people.fields.phone' | transloco }}</dt>
            <dd>
              @if (e.phone) { <a [href]="'tel:' + e.phone">{{ e.phone }}</a> } @else { — }
            </dd>
            <dt>{{ 'people.fields.manager' | transloco }}</dt>
            <dd>
              @if (e.manager) { <a [routerLink]="['/people', e.manager.id]">{{ e.manager.name }}</a> } @else { — }
            </dd>
            @if (e.access?.pii) {
              <dt>{{ 'people.fields.birthDate' | transloco }}</dt>
              <dd>{{ e.birth_date ? (e.birth_date | date: 'dd.MM.yyyy') : '—' }}</dd>
              <dt>{{ 'people.fields.personalEmail' | transloco }}</dt>
              <dd>{{ e.personal_email ?? '—' }}</dd>
              <dt>{{ 'people.fields.address' | transloco }}</dt>
              <dd>{{ e.address ?? '—' }}</dd>
              <dt>{{ 'people.fields.emergencyContact' | transloco }}</dt>
              <dd>{{ e.emergency_contact ?? '—' }}</dd>
              @for (f of customFields(); track f[0]) {
                <dt>{{ f[0] }}</dt>
                <dd>{{ f[1] ?? '—' }}</dd>
              }
            }
          </dl>
          <a mat-button [routerLink]="['/people/org-chart']" [queryParams]="{ root: e.id }">
            <mat-icon>account_tree</mat-icon>{{ 'people.orgChart.showTeam' | transloco }}
          </a>
        </mat-tab>

        @if (e.access?.job) {
          <mat-tab [label]="'people.tabs.job' | transloco">
            <dl class="facts">
              <dt>{{ 'people.fields.hiredAt' | transloco }}</dt>
              <dd>{{ e.hired_at | date: 'dd.MM.yyyy' }}</dd>
              <dt>{{ 'people.fields.employmentType' | transloco }}</dt>
              <dd>{{ e.employment_type ? ('people.employmentType.' + e.employment_type | transloco) : '—' }}</dd>
              <dt>{{ 'people.fields.status' | transloco }}</dt>
              <dd>{{ 'people.status.' + e.status | transloco }}</dd>
              @if (e.fired_at) {
                <dt>{{ 'people.fields.firedAt' | transloco }}</dt>
                <dd>{{ e.fired_at | date: 'dd.MM.yyyy' }}@if (e.termination_reason) { · {{ e.termination_reason }}}</dd>
              }
              <dt>{{ 'people.fields.reports' | transloco }}</dt>
              <dd>{{ e.reports_count ?? 0 }}</dd>
              @if (e.work_schedule?.days?.length) {
                <dt>{{ 'people.fields.schedule' | transloco }}</dt>
                <dd>{{ scheduleText(e) }}</dd>
              }
            </dl>
          </mat-tab>
          <mat-tab [label]="'people.tabs.timeoff' | transloco">
            <div class="tab">
              <app-balances-panel [employeeId]="e.id" [version]="requests.version()" />
              @if (e.access?.decide || e.access?.self) {
                <h3>{{ 'timeoff.newRequest' | transloco }}</h3>
                <app-leave-request-form [employeeId]="e.access?.self ? undefined : e.id" [allowOverride]="!!e.access?.manage" (created)="requests.added($event)" />
              }
              <h3>{{ 'timeoff.requests' | transloco }}</h3>
              <app-requests-list [requests]="requests.items()" (act)="act($event)" />
            </div>
          </mat-tab>
        }

        @if (store.tabs().includes('changes')) {
          <mat-tab [label]="'people.tabs.changes' | transloco">
            <ul class="changes">
              @for (c of store.changes(); track c.id) {
                <li [attr.data-status]="c.status">
                  <div class="main">
                    @for (f of fields; track f) {
                      @if (f in c.changes) {
                        <span><span class="muted">{{ label(f) | transloco }}:</span> {{ c.changes[f] ?? '—' }}</span>
                      }
                    }
                    @if (c.comment) {
                      <span class="muted">«{{ c.comment }}»</span>
                    }
                    <span class="muted small">{{ c.created_at | date: 'dd.MM.yyyy HH:mm' }}@if (c.decided_by) { · {{ c.decided_by.name }}}</span>
                  </div>
                  <span class="status">{{ 'people.changeStatus.' + c.status | transloco }}</span>
                  @if (c.can_decide && c.status === 'pending') {
                    <button mat-stroked-button type="button" (click)="decide(c, true)"><mat-icon>check</mat-icon>{{ 'timeoff.actions.approve' | transloco }}</button>
                    <button mat-button type="button" (click)="decide(c, false)">{{ 'timeoff.actions.reject' | transloco }}</button>
                  }
                </li>
              } @empty {
                <li class="muted">{{ 'people.changes.empty' | transloco }}</li>
              }
            </ul>
          </mat-tab>
        }

        @if (store.tabs().includes('documents')) {
          <mat-tab [label]="'people.tabs.documents' | transloco">
            <ng-template matTabContent>
              <app-employee-documents-tab [employeeId]="e.id" [canManage]="!!e.access?.manage" />
            </ng-template>
          </mat-tab>
        }

        @if (store.tabs().includes('workflows')) {
          <mat-tab [label]="'people.tabs.workflows' | transloco">
            <ng-template matTabContent>
              <app-employee-runs-tab [employeeId]="e.id" [canStart]="!!e.access?.manage" />
            </ng-template>
          </mat-tab>
        }

        @if (store.tabs().includes('performance')) {
          <mat-tab [label]="'people.tabs.performance' | transloco">
            <ng-template matTabContent>
              <app-performance-tab [employeeId]="e.id" [canManage]="!!e.access?.decide" />
            </ng-template>
          </mat-tab>
        }

        @if (store.tabs().includes('assets')) {
          <mat-tab [label]="'people.tabs.assets' | transloco">
            <ng-template matTabContent>
              <app-employee-assets-tab [employeeId]="e.id" />
            </ng-template>
          </mat-tab>
        }

        @if (historyLoader(); as loader) {
          <mat-tab [label]="'audit.history' | transloco">
            <ng-template matTabContent>
              <app-audit-history [loader]="loader" />
            </ng-template>
          </mat-tab>
        }
      </mat-tab-group>
    }
  `,
  styles: `
    .who { display: flex; align-items: center; gap: 1rem; }
    .avatar {
      display: grid; place-items: center; width: 4rem; height: 4rem; border-radius: 50%; font-size: 1.4rem;
      background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container);
    }
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .danger { color: var(--app-danger); }
    .badge { color: var(--app-warning); }
    .facts { display: grid; grid-template-columns: max-content 1fr; gap: 0.5rem 1.5rem; padding: 1rem 0; margin: 0; }
    .facts dt { color: var(--app-muted); }
    .facts dd { margin: 0; }
    .tab { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem 0; }
    .changes { list-style: none; margin: 0; padding: 1rem 0; }
    .changes li { display: flex; gap: 1rem; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .changes li[data-status='pending'] .status { color: var(--app-warning); }
    .changes li[data-status='approved'] .status { color: var(--app-success); }
    .main { flex: 1; display: flex; flex-direction: column; }
    .small { font-size: 0.8rem; }
  `,
})
export class ProfilePage {
  /** Route param :id; absent on /me. */
  readonly id = input<string | undefined>(undefined);
  /** Query param ?tab=documents|workflows|… preselects a tab (e.g. links from tasks). */
  readonly tab = input<string | undefined>(undefined);

  protected readonly store = inject(ProfileStore);
  protected readonly requests = inject(LeaveRequestsStore);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly fields = CHANGEABLE_FIELDS;
  protected readonly label = fieldLabelKey;
  protected readonly initialTab = computed(() => {
    const wanted = this.tab();
    const index = wanted === undefined ? -1 : this.store.tabs().indexOf(wanted as ProfileTab);
    return Math.max(0, index);
  });
  private readonly auth = inject(AuthService);
  private readonly audit = inject(AuditService);
  /** History tab: HR staff only (the API answers 403 to everyone else). */
  protected readonly historyLoader = computed<AuditLoader | null>(() => {
    const id = this.store.employee()?.id;
    if (id === undefined || !canManagePeople(this.auth.user()?.roles ?? [])) return null;
    return (paging) => this.audit.employeeHistory(id, paging);
  });
  protected readonly customFields = computed(() => Object.entries(this.store.employee()?.custom_fields ?? {}));

  constructor() {
    effect(() => {
      const raw = this.id();
      this.store.load(raw === undefined ? 'me' : Number(raw));
    });
    effect(() => {
      const e = this.store.employee();
      if (e?.access?.job) {
        this.requests.setQuery({ employee_id: e.id });
      }
    });
  }

  protected initialsOf(e: Employee): string {
    return initials(e.full_name);
  }

  protected scheduleText(e: Employee): string {
    const days = (e.work_schedule?.days ?? []).map((d) => this.i18n.translate(`people.weekday.${d}`)).join(', ');
    const hours = e.work_schedule?.hours_per_day;
    return hours ? `${days} · ${hours} h` : days;
  }

  protected act(action: RequestAction): void {
    this.requests.act(action, (key) => this.toast(key));
  }

  protected decide(request: ChangeRequest, approve: boolean): void {
    this.store.decide(request, approve, (key) => this.toast(key));
  }

  protected requestChange(e: Employee): void {
    this.dialog
      .open<ChangeRequestDialog, Employee, ChangeRequest>(ChangeRequestDialog, { data: e })
      .afterClosed()
      .subscribe((created) => {
        if (created) {
          this.store.added(created);
          this.toast('people.changes.sent');
        }
      });
  }

  protected edit(e: Employee): void {
    this.dialog
      .open<EmployeeDialog, EmployeeDialogData, Employee>(EmployeeDialog, { data: { employee: e } })
      .afterClosed()
      .subscribe((saved) => saved && this.store.replace(saved));
  }

  protected terminate(e: Employee): void {
    this.dialog
      .open<TerminateDialog, Employee, Employee>(TerminateDialog, { data: e })
      .afterClosed()
      .subscribe((saved) => saved && this.store.replace(saved));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}

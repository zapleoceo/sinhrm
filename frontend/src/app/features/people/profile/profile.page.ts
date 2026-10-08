import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LeaveRequestsStore } from '../../timeoff/leave-requests.store';
import { BalancesPanel } from '../../timeoff/widgets/balances-panel';
import { LeaveRequestForm } from '../../timeoff/widgets/leave-request-form';
import { RequestAction, RequestsList } from '../../timeoff/widgets/requests-list';
import { initials } from '../org-tree';
import { CHANGEABLE_FIELDS, ChangeRequest, Employee, fieldLabelKey } from '../people.model';
import { HiddenChangesLine } from '../hidden-changes';
import { EmployeeDocumentsTab } from '../../documents/profile/employee-documents.tab';
import { EmployeeRunsTab } from '../../workflows/runs/employee-runs.tab';
import { PerformanceTab } from '../../perform/profile/performance.tab';
import { EmployeeAssetsTab } from '../../assets/employee-assets.tab';
import { AuthService } from '../../../core/auth/auth.service';
import { AuditHistory } from '../../audit/audit-history';
import { AuditLoader } from '../../audit/audit.model';
import { AuditService } from '../../audit/audit.service';
import { canManagePeople } from '../people.access';
import { CompensationTab } from './compensation.tab';
import { ChangeRequestDialog } from './change-request.dialog';
import { EmployeeDialog, EmployeeDialogData } from './employee.dialog';
import { ProfileStore, ProfileTab } from './profile.store';
import { RestoreDialog } from './restore.dialog';
import { TerminateDialog } from './terminate.dialog';
import { ConfirmDialog, ConfirmDialogData } from '../../workflows/confirm.dialog';
import { PeopleService, peopleErrorKey } from '../people.service';
import { PrivacyActions } from '../../privacy/privacy-actions';
import { wideDialog } from '../../../core/ui/dialog';
import { NotifyService } from '../../../core/ui/notify.service';

/**
 * Employee profile (/people/:id) and "My profile" (/me). Tabs follow the API's access flags: Overview for everyone,
 * Job and Time off for admins, the employee and managers above, Change requests for admins, the employee and deciders,
 * Documents for admins, the employee and managers, Workflows for admins and managers, Performance for admins,
 * the employee and managers.
 */
@Component({
  selector: 'app-profile-page',
  imports: [
    CompensationTab,
    DatePipe,
    MatButtonModule,
    MatIconModule,
    MatProgressBarModule,
    MatSlideToggleModule,
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
    PrivacyActions,
    HiddenChangesLine,
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
                · <span class="badge app-pill" [attr.data-status]="e.status">{{ 'people.status.' + e.status | transloco }}</span>
              }
            </p>
          </div>
        </div>
        <div class="actions">
          @if (e.access?.self) {
            <mat-slide-toggle [checked]="approvalEmails()" (change)="toggleApprovalEmails($event.checked)">{{ 'people.approvalEmails' | transloco }}</mat-slide-toggle>
            <button mat-stroked-button type="button" (click)="requestChange(e)"><mat-icon>edit_note</mat-icon>{{ 'people.changes.new' | transloco }}</button>
          }
          @if (e.access?.manage) {
            <button mat-stroked-button type="button" (click)="edit(e)"><mat-icon>edit</mat-icon>{{ 'people.edit.title' | transloco }}</button>
            @if (e.status === 'terminated') {
              <button mat-stroked-button type="button" (click)="restore(e)"><mat-icon>person_add</mat-icon>{{ 'people.restore.action' | transloco }}</button>
            }
          }
          @if (e.access?.terminate && e.status !== 'terminated') {
            @if (e.termination_scheduled) {
              <button mat-button type="button" (click)="cancelTermination(e)"><mat-icon>event_busy</mat-icon>{{ 'people.terminate.cancel' | transloco }}</button>
            } @else {
              <button mat-button type="button" class="danger" (click)="terminate(e)">{{ 'people.terminate.action' | transloco }}</button>
            }
          }
          <app-privacy-actions type="employee" [subjectId]="e.id" [name]="e.full_name" [erasable]="e.status === 'terminated'" (erased)="store.load(e.id)" />
        </div>
      </header>
      @if (e.termination_scheduled && e.fired_at) {
        <p class="scheduled app-pill" role="status"><mat-icon aria-hidden="true">event</mat-icon>{{ 'people.terminate.scheduled' | transloco: { date: dateText(e.fired_at) } }}@if (e.handover_to; as h) { · {{ 'people.terminate.handoverScheduled' | transloco: { name: h.full_name } }}}</p>
      }

      <mat-tab-group mat-stretch-tabs="false" animationDuration="0ms" [selectedIndex]="initialTab()">
        <mat-tab [label]="'people.tabs.overview' | transloco">
          <dl class="facts">
            <dt>{{ 'people.fields.workEmail' | transloco }}</dt>
            <dd>
              @if (e.work_email) { <a [href]="'mailto:' + e.work_email">{{ e.work_email }}</a> } @else { — }
            </dd>
            <dt>{{ 'people.fields.phone' | transloco }}</dt>
            <dd>
              @if (e.phone) { <a class="mono" [href]="'tel:' + e.phone">{{ e.phone }}</a> } @else { — }
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
              <dt>{{ 'people.fields.personalPhone' | transloco }}</dt>
              <dd>
                @if (personalPhone(); as phone) { <a class="mono" [href]="'tel:' + phone">{{ phone }}</a> } @else { — }
              </dd>
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
                    <app-hidden-changes [fields]="c.hidden_changes" />
                    @if (c.comment) {
                      <span class="muted">«{{ c.comment }}»</span>
                    }
                    <span class="muted small">{{ c.created_at | date: 'dd.MM.yyyy HH:mm' }}@if (c.decided_by) { · {{ c.decided_by.name }}}</span>
                  </div>
                  <span class="status app-pill">{{ 'people.changeStatus.' + c.status | transloco }}</span>
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

        @if (e.access?.manage || e.access?.self) {
          <mat-tab [label]="'people.tabs.compensation' | transloco">
            <ng-template matTabContent>
              <app-compensation-tab [employeeId]="e.id" [canManage]="!!e.access?.manage" [canAdd]="!!e.access?.manage && !!e.access?.decide" [self]="!!e.access?.self" />
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
    .who { display: flex; align-items: center; gap: 1rem; min-width: 0; }
    .who h1 { overflow-wrap: anywhere; }
    /* Avatar = «station» ring (brief C): line colour ring, initials in the display face. */
    .avatar {
      display: grid; place-items: center; flex: none; width: 4rem; height: 4rem; box-sizing: border-box; border-radius: 50%;
      border: 3px solid var(--mat-sys-primary); background: var(--app-card); color: var(--mat-sys-primary);
      font: 800 1.35rem/1 var(--app-font-display);
    }
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
    .danger { color: var(--app-bad-text); --mat-button-text-label-text-color: var(--app-bad-text); }
    .badge[data-status='on_leave'] { --pill-text: var(--app-info-text); --pill-bg: var(--app-info-bg); --pill-line: transparent; }
    .badge[data-status='terminated']::before { border-style: dashed; }
    .scheduled { --pill-text: var(--app-warn-text); --pill-bg: var(--app-warn-bg); --pill-line: transparent; display: inline-flex; align-items: center; gap: 0.5rem; margin: 0 0 0.5rem; }
    .scheduled::before { display: none; }
    .scheduled mat-icon { font-size: 1.125rem; width: 1.125rem; height: 1.125rem; }
    .facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 0; padding: 0.5rem 0 1rem; margin: 0; max-width: 48rem; }
    .facts dt, .facts dd { padding: 0.6rem 1.5rem 0.6rem 0; border-bottom: var(--app-border-w) solid var(--app-track); }
    .facts dt { color: var(--app-muted); }
    .facts dd { margin: 0; overflow-wrap: anywhere; }
    .facts .mono { font-size: 0.8125rem; }
    .tab { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem 0; }
    .tab h3 { font: var(--mat-sys-title-medium); margin: 0.75rem 0 0.25rem; }
    .changes { list-style: none; margin: 0; padding: 1rem 0; }
    .changes li { display: flex; gap: 1rem; align-items: center; padding: 0.75rem 0; border-bottom: var(--app-border-w) solid var(--app-track); flex-wrap: wrap; }
    .changes li[data-status='pending'] .status { --pill-text: var(--app-warn-text); --pill-bg: var(--app-warn-bg); --pill-line: transparent; }
    .changes li[data-status='pending'] .status::before { border-radius: 1px; rotate: 45deg; background: currentColor; width: 0.45rem; height: 0.45rem; }
    .changes li[data-status='approved'] .status { --pill-text: var(--app-good-text); --pill-bg: var(--app-good-bg); --pill-line: transparent; }
    .changes li[data-status='approved'] .status::before { background: currentColor; }
    .changes li[data-status='rejected'] .status { --pill-text: var(--app-bad-text); --pill-bg: var(--app-bad-bg); --pill-line: transparent; }
    .changes li[data-status='rejected'] .status::before { border-radius: 1px; background: currentColor; }
    .main { flex: 1; display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
    @media (max-width: 600px) {
      .avatar { width: 3.25rem; height: 3.25rem; font-size: 1.1rem; }
      .facts { grid-template-columns: minmax(0, 1fr); }
      .facts dt { padding-bottom: 0; border-bottom: 0; font: var(--mat-sys-label-medium); }
      .facts dd { padding-top: 0.15rem; }
    }
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
  private readonly people = inject(PeopleService);
  private readonly notify = inject(NotifyService);
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
  protected readonly approvalEmails = computed(() => this.auth.user()?.approval_emails ?? true);
  /** History tab: HR staff only (the API answers 403 to everyone else). */
  protected readonly historyLoader = computed<AuditLoader | null>(() => {
    const id = this.store.employee()?.id;
    if (id === undefined || !canManagePeople(this.auth.user()?.roles ?? [])) return null;
    return (paging) => this.audit.employeeHistory(id, paging);
  });
  /** Candidate's phone carried over at hire (HireService): PII tier, shown as its own row, not as a raw custom key. */
  protected readonly personalPhone = computed(() => this.store.employee()?.custom_fields?.['personal_phone'] ?? null);
  protected readonly customFields = computed(() =>
    Object.entries(this.store.employee()?.custom_fields ?? {}).filter(([key]) => key !== 'personal_phone'),
  );

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

  /** YYYY-MM-DD → dd.MM.yyyy (no time zone shift: the date is a calendar day). */
  protected dateText(iso: string | null | undefined): string {
    return iso ? iso.split('-').reverse().join('.') : '';
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
      .open<EmployeeDialog, EmployeeDialogData, Employee>(EmployeeDialog, wideDialog({ employee: e }))
      .afterClosed()
      .subscribe((saved) => saved && this.store.replace(saved));
  }

  protected terminate(e: Employee): void {
    this.dialog
      .open<TerminateDialog, Employee, Employee>(TerminateDialog, { data: e })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          this.store.replace(saved);
          this.toast(saved.termination_scheduled ? 'people.terminate.scheduledDone' : 'people.terminate.done', { date: this.dateText(saved.fired_at) });
        }
      });
  }

  /** Confirm, then cancel the scheduled termination (HR or a manager above). */
  protected cancelTermination(e: Employee): void {
    const t = (key: string): string => this.i18n.translate(key, { name: e.full_name });
    this.dialog
      .open<ConfirmDialog, ConfirmDialogData, { reason: string } | null>(ConfirmDialog, {
        data: { message: t('people.terminate.cancelBody'), confirm: t('people.terminate.cancelConfirm'), cancel: t('people.terminate.keep') },
        ariaLabel: t('people.terminate.cancelTitle'),
      })
      .afterClosed()
      .subscribe((ok) => {
        if (!ok) return;
        this.people.cancelTermination(e.id).subscribe({
          next: (saved) => {
            this.store.replace(saved);
            this.toast('people.terminate.cancelled');
          },
          error: (err: unknown) => this.toast(peopleErrorKey(err)),
        });
      });
  }

  /** HR: restore a terminated employee into the previous or a new position. */
  protected restore(e: Employee): void {
    this.dialog
      .open<RestoreDialog, Employee, Employee>(RestoreDialog, wideDialog(e))
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          this.store.replace(saved);
          this.toast('people.restore.done');
        }
      });
  }

  protected async toggleApprovalEmails(on: boolean): Promise<void> {
    try {
      await this.auth.setApprovalEmails(on);
    } catch {
      this.toast('common.error');
    }
  }

  private toast(key: string, params?: Record<string, string>): void {
    this.notify.show(key, { params });
  }
}

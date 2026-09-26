import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { RUN_STATUSES, RunQuery, RunStatus, WorkflowRun, WorkflowTemplate } from '../workflows.model';
import { WorkflowsService } from '../workflows.service';
import { RunCard, StepAction } from './run-card';
import { RunsStore } from './runs.store';

/** Board of workflow runs (/workflows/runs): admins see all, managers their people; filters by status, template, employee. */
@Component({
  selector: 'app-workflow-runs-page',
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, TranslocoPipe, RunCard],
  providers: [RunsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'workflows.runs.title' | transloco }}</h1>
        <p class="muted">{{ 'workflows.runs.subtitle' | transloco }}</p>
      </div>
    </header>

    <div class="filters">
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'workflows.fields.status' | transloco }}</mat-label>
        <mat-select [value]="status()" (selectionChange)="setStatus($event.value)">
          <mat-option [value]="null">{{ 'common.all' | transloco }}</mat-option>
          @for (s of statuses; track s) {
            <mat-option [value]="s">{{ 'workflows.runStatus.' + s | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      @if (isAdmin()) {
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'workflows.fields.template' | transloco }}</mat-label>
          <mat-select [value]="templateId()" (selectionChange)="setTemplate($event.value)">
            <mat-option [value]="null">{{ 'common.all' | transloco }}</mat-option>
            @for (t of templates(); track t.id) {
              <mat-option [value]="t.id">{{ t.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
      <mat-form-field subscriptSizing="dynamic" class="narrow">
        <mat-label>{{ 'workflows.fields.employeeId' | transloco }}</mat-label>
        <input matInput type="number" min="1" [value]="employeeId() ?? ''" (change)="setEmployee($event)" />
      </mat-form-field>
    </div>

    @if (store.loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.failed()) {
      <div class="state">
        <p>{{ 'common.error' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
      </div>
    }
    <div class="runs">
      @for (r of store.items(); track r.id) {
        <app-run-card [run]="r" [busy]="store.pending()" (action)="act($event)" (cancelRequested)="cancel($event)" />
      } @empty {
        @if (!store.loading() && !store.failed()) {
          <p class="muted state">{{ 'workflows.runs.empty' | transloco }}</p>
        }
      }
    </div>
  `,
  styles: `
    .runs { display: flex; flex-direction: column; gap: 0.5rem; }
    .narrow { width: 10rem; }
  `,
})
export class WorkflowRunsPage implements OnInit {
  protected readonly store = inject(RunsStore);
  private readonly api = inject(WorkflowsService);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly statuses = RUN_STATUSES;
  protected readonly status = signal<RunStatus | null>('running');
  protected readonly templateId = signal<number | null>(null);
  protected readonly employeeId = signal<number | null>(null);
  protected readonly templates = signal<WorkflowTemplate[]>([]);
  protected readonly isAdmin = computed(() => (this.auth.user()?.roles ?? []).some((r) => r === 'superadmin' || r === 'admin'));

  ngOnInit(): void {
    if (this.isAdmin()) {
      this.api.templates().subscribe({ next: (list) => this.templates.set(list), error: () => this.templates.set([]) });
    }
    this.reload();
  }

  protected setStatus(status: RunStatus | null): void {
    this.status.set(status);
    this.reload();
  }

  protected setTemplate(id: number | null): void {
    this.templateId.set(id);
    this.reload();
  }

  protected setEmployee(event: Event): void {
    const n = Math.round(Number((event.target as HTMLInputElement).value));
    this.employeeId.set(Number.isFinite(n) && n > 0 ? n : null);
    this.reload();
  }

  protected act(a: StepAction): void {
    this.store.command(a.run, a.step, a.command, (key) => this.toast(key), a.reason);
  }

  protected cancel(run: WorkflowRun): void {
    this.store.cancel(run, (key) => this.toast(key));
  }

  private reload(): void {
    const query: RunQuery = {};
    const status = this.status();
    const templateId = this.templateId();
    const employeeId = this.employeeId();
    if (status !== null) {
      query.status = status;
    }
    if (templateId !== null) {
      query.template_id = templateId;
    }
    if (employeeId !== null) {
      query.employee_id = employeeId;
    }
    this.store.load(query);
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}

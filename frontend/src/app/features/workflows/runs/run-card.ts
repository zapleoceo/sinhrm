import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ConfirmDialog, ConfirmDialogData } from '../confirm.dialog';
import { RunStep, StepCommand, WorkflowRun, progressPercent, stepResultKey } from '../workflows.model';

export interface StepAction {
  run: WorkflowRun;
  step: RunStep;
  command: StepCommand;
  reason?: string;
}

/** One workflow run: header with progress, expandable steps with complete / skip / retry. */
@Component({
  selector: 'app-run-card',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, MatTooltipModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let r = run();
    <article class="run" [attr.data-status]="r.status">
      <button type="button" class="head" (click)="open.set(!open())" [attr.aria-expanded]="open()">
        <mat-icon>{{ open() ? 'expand_less' : 'expand_more' }}</mat-icon>
        <span class="title">
          <strong>{{ r.template.name }}</strong>
          @if (showEmployee()) {
            <span> · {{ r.employee.full_name }}</span>
          }
          <span class="muted small"> · {{ 'workflows.runs.anchor' | transloco }} {{ r.anchor_date | date: 'dd.MM.yyyy' }}</span>
        </span>
        <span class="status">{{ 'workflows.runStatus.' + r.status | transloco }}</span>
        @if (r.has_failed) {
          <mat-icon class="failed" [matTooltip]="'workflows.runs.hasFailed' | transloco">error</mat-icon>
        }
        <span class="progress">
          <mat-progress-bar mode="determinate" [value]="percent()" />
          <span class="muted small">{{ r.progress.finished }}/{{ r.progress.total }}</span>
        </span>
      </button>
      @if (open()) {
        <div class="body">
          <p class="muted small">
            {{ 'workflows.trigger.' + r.trigger | transloco }}
            @if (r.started_by) { · {{ r.started_by.name }} }
            · {{ r.created_at | date: 'dd.MM.yyyy HH:mm' }}
            @if (r.parent_run_id) { · {{ 'workflows.runs.child' | transloco }} }
            @if (showEmployee()) {
              · <a [routerLink]="['/people', r.employee.id]">{{ 'workflows.runs.openProfile' | transloco }}</a>
            }
          </p>
          <ol class="steps">
            @for (s of r.steps; track s.id) {
              <li [attr.data-status]="s.status">
                <mat-icon class="icon">{{ icon(s) }}</mat-icon>
                <div class="main">
                  <span>{{ s.title }}</span>
                  <span class="muted small">
                    {{ 'workflows.action.' + s.action | transloco }}
                    · {{ 'workflows.assignee.' + s.assignee_rule | transloco }}@if (s.assignee) {: {{ s.assignee.name }}}
                    @if (s.due_at) { · {{ s.due_at | date: 'dd.MM.yyyy' }} }
                    · {{ 'workflows.stepStatus.' + s.status | transloco }}
                    @if (s.waiting && s.status === 'pending') { · {{ 'workflows.step.waiting' | transloco }} }
                    @if (s.attempts > 1) { · {{ 'workflows.step.attempts' | transloco: { n: s.attempts } }} }
                  </span>
                  @if (resultKey(s); as key) {
                    <span class="result small">{{ key | transloco }}</span>
                  }
                </div>
                @if (s.can_complete && s.status === 'pending') {
                  <button mat-stroked-button type="button" [disabled]="busy().has(s.id)" (click)="emit(s, 'complete')">
                    <mat-icon>check</mat-icon>{{ 'workflows.step.complete' | transloco }}
                  </button>
                  <button mat-button type="button" [disabled]="busy().has(s.id)" (click)="skip(s)">{{ 'workflows.step.skip' | transloco }}</button>
                }
                @if (s.can_retry && s.status === 'failed') {
                  <button mat-stroked-button type="button" [disabled]="busy().has(s.id)" (click)="emit(s, 'retry')">
                    <mat-icon>replay</mat-icon>{{ 'workflows.step.retry' | transloco }}
                  </button>
                }
              </li>
            }
          </ol>
          @if (r.can_cancel && r.status === 'running') {
            <button mat-button type="button" class="danger" (click)="cancelRun()">{{ 'workflows.runs.cancel' | transloco }}</button>
          }
        </div>
      }
    </article>
  `,
  styles: `
    .run { border: 1px solid var(--app-border); border-radius: var(--app-radius); background: var(--mat-sys-surface); }
    .head {
      display: flex; align-items: center; gap: 0.75rem; width: 100%; padding: 0.6rem 0.75rem;
      border: 0; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; flex-wrap: wrap;
    }
    .title { flex: 1 1 16rem; min-width: 0; }
    .progress { display: flex; align-items: center; gap: 0.5rem; width: 10rem; }
    .run[data-status='running'] .status { color: var(--app-warning); }
    .run[data-status='completed'] .status { color: var(--app-success); }
    .run[data-status='cancelled'] { opacity: 0.7; }
    .failed { color: var(--app-danger); }
    .body { padding: 0 0.75rem 0.75rem; }
    .steps { list-style: none; margin: 0; padding: 0; }
    .steps li { display: flex; gap: 0.75rem; align-items: center; padding: 0.4rem 0; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .steps li[data-status='done'] .icon { color: var(--app-success); }
    .steps li[data-status='failed'] .icon, .result { color: var(--app-danger); }
    .steps li[data-status='skipped'] { opacity: 0.7; }
    .main { flex: 1 1 14rem; display: flex; flex-direction: column; min-width: 0; }
    .small { font-size: 0.8rem; }
    .danger { color: var(--app-danger); margin-top: 0.5rem; }
  `,
})
export class RunCard {
  readonly run = input.required<WorkflowRun>();
  readonly showEmployee = input(true);
  readonly busy = input<ReadonlySet<number>>(new Set());
  readonly action = output<StepAction>();
  readonly cancelRequested = output<WorkflowRun>();

  private readonly i18n = inject(TranslocoService);
  private readonly dialog = inject(MatDialog);
  protected readonly open = signal(false);
  protected readonly percent = computed(() => progressPercent(this.run()));

  protected icon(step: RunStep): string {
    switch (step.status) {
      case 'done':
        return 'check_circle';
      case 'skipped':
        return 'redo';
      case 'failed':
        return 'error';
      default:
        return step.waiting ? 'hourglass_top' : 'radio_button_unchecked';
    }
  }

  protected resultKey(step: RunStep): string | null {
    return stepResultKey(step.result);
  }

  protected emit(step: RunStep, command: StepCommand, reason?: string): void {
    this.action.emit({ run: this.run(), step, command, reason });
  }

  protected skip(step: RunStep): void {
    this.ask({ message: this.i18n.translate('workflows.step.skipReason'), withReason: true, reasonLabel: this.i18n.translate('workflows.step.skipReason') }, (reason) =>
      this.emit(step, 'skip', reason || undefined),
    );
  }

  protected cancelRun(): void {
    this.ask({ message: this.i18n.translate('workflows.runs.confirmCancel') }, () => this.cancelRequested.emit(this.run()));
  }

  private ask(data: Pick<ConfirmDialogData, 'message' | 'withReason' | 'reasonLabel'>, then: (reason: string) => void): void {
    this.dialog
      .open<ConfirmDialog, ConfirmDialogData, { reason: string } | null>(ConfirmDialog, {
        data: { ...data, confirm: this.i18n.translate('common.confirm'), cancel: this.i18n.translate('common.cancel') },
      })
      .afterClosed()
      .subscribe((r) => {
        if (r) {
          then(r.reason);
        }
      });
  }
}

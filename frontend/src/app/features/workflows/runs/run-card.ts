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
import { RUN_STATUS_TONE, RunStep, StepCommand, WorkflowRun, progressPercent, stepResultKey } from '../workflows.model';

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
        <span class="status app-pill" [attr.data-tone]="statusTone[r.status]">{{ 'workflows.runStatus.' + r.status | transloco }}</span>
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
    .run { border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius); background: var(--app-card); }
    .head {
      display: flex; align-items: center; gap: 0.75rem; width: 100%; min-height: 2.75rem; padding: 0.6rem 0.9rem;
      border: 0; border-radius: inherit; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; flex-wrap: wrap;
    }
    .head:hover { background: var(--app-card-2); }
    .title { flex: 1 1 16rem; min-width: 0; }
    .progress { display: flex; align-items: center; gap: 0.5rem; width: 10rem; }
    .progress .small { font-family: var(--app-font-mono); }
    .run[data-status='cancelled'] .title { color: var(--app-muted); }
    .failed { color: var(--app-danger); }
    .body { padding: 0 0.9rem 0.9rem; }
    /* Steps of a run = stations on one line: icon in a ring, done = success line behind it. */
    .steps { list-style: none; margin: 0; padding: 0; }
    .steps li { position: relative; display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.45rem 0; flex-wrap: wrap; }
    .steps li:not(:last-child)::before {
      content: ''; position: absolute; left: calc(0.875rem - 1px); top: 2.2rem; bottom: -0.45rem; width: 2px; background: var(--app-track);
    }
    .steps li[data-status='done']:not(:last-child)::before { background: var(--app-success); }
    .icon {
      flex: none; width: 1.75rem; height: 1.75rem; box-sizing: border-box; padding: 0.2rem; font-size: 1.1rem; line-height: 1.35rem;
      border-radius: 50%; border: 2px solid var(--station, var(--app-muted)); color: var(--station, var(--app-muted)); background: var(--app-card);
    }
    .steps li[data-status='pending'] { --station: var(--mat-sys-primary); }
    .steps li[data-status='done'] { --station: var(--app-success); }
    .steps li[data-status='failed'] { --station: var(--app-danger); }
    .steps li[data-status='skipped'] .icon { border-style: dashed; }
    .steps li[data-status='skipped'] .main > span:first-child { color: var(--app-muted); }
    .result { color: var(--app-bad-text); }
    .main { flex: 1 1 14rem; display: flex; flex-direction: column; min-width: 0; padding-top: 0.2rem; }
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
  protected readonly statusTone = RUN_STATUS_TONE;
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

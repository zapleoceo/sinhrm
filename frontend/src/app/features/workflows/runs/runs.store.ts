import { Injectable, inject, signal } from '@angular/core';
import { RunQuery, RunStep, StepCommand, WorkflowRun, applyOutcome } from '../workflows.model';
import { WorkflowsService, workflowsErrorKey } from '../workflows.service';

/** Runs list of the board or of one employee, with step commands and cancel applied in place. */
@Injectable()
export class RunsStore {
  private readonly api = inject(WorkflowsService);
  private seq = 0;

  readonly items = signal<WorkflowRun[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<number>>(new Set());
  readonly query = signal<RunQuery>({});

  load(query: RunQuery = this.query()): void {
    const seq = ++this.seq;
    this.query.set(query);
    this.loading.set(true);
    this.failed.set(false);
    this.api.runs(query).subscribe({
      next: (list) => {
        if (seq === this.seq) {
          this.items.set(list);
          this.loading.set(false);
        }
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  command(run: WorkflowRun, step: RunStep, command: StepCommand, onDone: (key: string) => void, reason?: string): void {
    this.mark(step.id, true);
    this.api.stepCommand(run.id, step.id, command, reason).subscribe({
      next: (outcome) => {
        this.items.update((list) => list.map((r) => (r.id === run.id ? applyOutcome(r, outcome) : r)));
        this.mark(step.id, false);
        onDone(`workflows.step.${command}Done`);
        if (command === 'retry' || outcome.run_status !== run.status) {
          this.refresh(run.id);
        }
      },
      error: (e: unknown) => {
        this.mark(step.id, false);
        onDone(workflowsErrorKey(e));
      },
    });
  }

  cancel(run: WorkflowRun, onDone: (key: string) => void): void {
    this.api.cancelRun(run.id).subscribe({
      next: (saved) => {
        this.replace(saved);
        onDone('workflows.runs.cancelled');
      },
      error: (e: unknown) => onDone(workflowsErrorKey(e)),
    });
  }

  start(templateId: number, employeeId: number, anchorDate: string | undefined, onDone: (key: string) => void): void {
    this.api.startRun(templateId, employeeId, anchorDate).subscribe({
      next: (run) => {
        this.items.update((list) => [run, ...list]);
        onDone('workflows.runs.started');
      },
      error: (e: unknown) => onDone(workflowsErrorKey(e)),
    });
  }

  /** Reloads one run (after retry the server may have executed further steps). */
  refresh(id: number): void {
    this.api.run(id).subscribe({ next: (run) => this.replace(run), error: () => undefined });
  }

  private replace(run: WorkflowRun): void {
    this.items.update((list) => list.map((r) => (r.id === run.id ? run : r)));
  }

  private mark(id: number, on: boolean): void {
    this.pending.update((s) => {
      const next = new Set(s);
      if (on) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });
  }
}

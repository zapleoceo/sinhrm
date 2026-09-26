import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ASSIGNEE_RULES, AssigneeRule, EditableStep, WORKFLOW_ACTIONS, WORKFLOW_KINDS, WORKFLOW_TRIGGERS, WorkflowAction, WorkflowKind, WorkflowTrigger } from '../workflows.model';
import { workflowsErrorKey } from '../workflows.service';
import { ConfigChange, StepConfigForm } from './step-config';
import { WorkflowEditorStore } from './workflow-editor.store';

/** Nested server messages of one step's config: steps.2.config.url → { url }. */
export function stepConfigErrors(errors: Record<string, string>, index: number): Record<string, string> {
  const prefix = `steps.${index}.config.`;
  const out: Record<string, string> = {};
  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith(prefix)) {
      out[key.slice(prefix.length)] = message;
    }
  }
  return out;
}

/**
 * Admin → Воркфлоу → template: name/kind/trigger/active, steps (drag to reorder, per-action settings),
 * webhook signing key. Saving sends the whole template (PUT).
 */
@Component({
  selector: 'app-workflow-editor-page',
  imports: [
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    DatePipe,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
    StepConfigForm,
  ],
  providers: [WorkflowEditorStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './workflow-editor.page.html',
  styleUrl: './workflow-editor.page.scss',
})
export class WorkflowEditorPage {
  readonly id = input.required({ transform: numberAttribute });

  protected readonly store = inject(WorkflowEditorStore);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly kinds = WORKFLOW_KINDS;
  protected readonly triggers = WORKFLOW_TRIGGERS;
  protected readonly actions = WORKFLOW_ACTIONS;
  protected readonly rules = ASSIGNEE_RULES;
  protected readonly secret = signal('');

  constructor() {
    effect(() => this.store.load(this.id()));
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected int(event: Event, min: number, max: number): number {
    const n = Math.round(Number(this.val(event)));
    return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : 0;
  }

  protected userId(event: Event): number | null {
    const n = Math.round(Number(this.val(event)));
    return Number.isFinite(n) && n > 0 ? n : null;
  }

  protected setKind(kind: WorkflowKind): void {
    this.store.setHead({ kind });
  }

  protected setTrigger(trigger: WorkflowTrigger): void {
    const h = this.store.head();
    this.store.setHead({ trigger, probation_days: trigger === 'probation_end' ? (h.probation_days ?? 90) : h.probation_days });
  }

  protected setAction(index: number, action: WorkflowAction): void {
    this.store.setAction(index, action);
  }

  protected setRule(index: number, rule: AssigneeRule): void {
    this.store.updateStep(index, { assignee_rule: rule });
  }

  protected setConfig(index: number, change: ConfigChange): void {
    this.store.setConfig(index, change.key, change.value);
  }

  protected configErrors(index: number): Record<string, string> {
    return stepConfigErrors(this.store.errors(), index);
  }

  protected dropStep(event: CdkDragDrop<EditableStep[]>): void {
    this.store.moveStep(event.previousIndex, event.currentIndex, (key) => this.toast(key));
  }

  protected save(): void {
    this.store.save().subscribe({ next: () => this.toast('workflows.editor.saved'), error: (e: unknown) => this.toast(workflowsErrorKey(e)) });
  }

  protected saveSecret(clear: boolean): void {
    const value = clear ? null : this.secret().trim();
    if (value !== null && (value.length < 16 || value.length > 200)) {
      this.toast('workflows.secret.length');
      return;
    }
    this.store.setSecret(value).subscribe({
      next: () => {
        this.secret.set('');
        this.toast(clear ? 'workflows.secret.cleared' : 'workflows.secret.saved');
      },
      error: (e: unknown) => this.toast(workflowsErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}

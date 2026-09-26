import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { DocumentTemplate } from '../../documents/documents.model';
import { DocumentsService } from '../../documents/documents.service';
import {
  EditableStep,
  SaveTemplate,
  StepConfigValue,
  WorkflowAction,
  WorkflowTemplate,
  moveItem,
  newStep,
  switchAction,
  toEditable,
  toSaveBody,
} from '../workflows.model';
import { WorkflowsService, fieldErrors, workflowsErrorKey } from '../workflows.service';

export type TemplateHead = Omit<SaveTemplate, 'steps'>;

/**
 * Workflow template editor state. The whole template is saved with PUT; a drag reorder of an unchanged,
 * fully saved template goes straight to the reorder endpoint.
 */
@Injectable()
export class WorkflowEditorStore {
  private readonly api = inject(WorkflowsService);
  private readonly documents = inject(DocumentsService);

  readonly template = signal<WorkflowTemplate | null>(null);
  readonly head = signal<TemplateHead>({ name: '', kind: 'onboarding', trigger: 'manual', active: false, probation_days: null });
  readonly steps = signal<EditableStep[]>([]);
  readonly dirty = signal(false);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly saving = signal(false);
  /** Server validation messages keyed like steps.0.config.url. */
  readonly errors = signal<Record<string, string>>({});
  /** Other workflow templates (start_workflow) and document templates (create_document). */
  readonly workflowOptions = signal<WorkflowTemplate[]>([]);
  readonly documentOptions = signal<DocumentTemplate[]>([]);
  readonly otherWorkflows = computed(() => this.workflowOptions().filter((t) => t.id !== this.template()?.id));

  load(id: number): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.template(id).subscribe({
      next: (t) => {
        this.apply(t);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
    this.api.templates().subscribe({ next: (list) => this.workflowOptions.set(list), error: () => this.workflowOptions.set([]) });
    this.documents.templates().subscribe({ next: (list) => this.documentOptions.set(list), error: () => this.documentOptions.set([]) });
  }

  setHead(patch: Partial<TemplateHead>): void {
    this.head.update((h) => ({ ...h, ...patch }));
    this.dirty.set(true);
  }

  updateStep(index: number, patch: Partial<Omit<EditableStep, 'key' | 'id' | 'config' | 'action'>>): void {
    this.steps.update((list) => list.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    this.dirty.set(true);
  }

  setAction(index: number, action: WorkflowAction): void {
    this.steps.update((list) => list.map((s, i) => (i === index ? { ...s, action, config: switchAction(s.config, action) } : s)));
    this.dirty.set(true);
  }

  setConfig(index: number, key: string, value: StepConfigValue): void {
    this.steps.update((list) => list.map((s, i) => (i === index ? { ...s, config: { ...s.config, [key]: value } } : s)));
    this.dirty.set(true);
  }

  addStep(): void {
    this.steps.update((list) => [...list, newStep()]);
    this.dirty.set(true);
  }

  removeStep(index: number): void {
    this.steps.update((list) => list.filter((_s, i) => i !== index));
    this.dirty.set(true);
  }

  /** Reorders locally; when nothing else is unsaved the new order is stored at once via the reorder endpoint. */
  moveStep(from: number, to: number, onError: (key: string) => void): void {
    if (from === to) {
      return;
    }
    const before = this.steps();
    const moved = moveItem(before, from, to);
    this.steps.set(moved);
    const t = this.template();
    const ids = moved.map((s) => s.id);
    if (t === null || this.dirty() || ids.some((id) => id === undefined)) {
      this.dirty.set(true);
      return;
    }
    this.api.reorderSteps(t.id, ids as number[]).subscribe({
      next: (saved) => this.apply(saved),
      error: (e: unknown) => {
        this.steps.set(before);
        onError(workflowsErrorKey(e));
      },
    });
  }

  save(): Observable<WorkflowTemplate> {
    const t = this.template();
    if (t === null) {
      throw new Error('Template is not loaded');
    }
    this.saving.set(true);
    this.errors.set({});
    return this.api.updateTemplate(t.id, toSaveBody(this.head(), this.steps())).pipe(
      tap({
        next: (saved) => {
          this.apply(saved);
          this.saving.set(false);
        },
        error: (e: unknown) => {
          this.errors.set(fieldErrors(e));
          this.saving.set(false);
        },
      }),
    );
  }

  setSecret(secret: string | null): Observable<WorkflowTemplate> {
    const t = this.template();
    if (t === null) {
      throw new Error('Template is not loaded');
    }
    return this.api.setWebhookSecret(t.id, secret).pipe(tap((saved) => this.template.set({ ...t, webhook_secret: saved.webhook_secret })));
  }

  /** First validation message of a field (e.g. `steps.0.config.url` or `name`). */
  error(path: string): string | null {
    return this.errors()[path] ?? null;
  }

  private apply(t: WorkflowTemplate): void {
    this.template.set(t);
    this.head.set({ name: t.name, kind: t.kind, trigger: t.trigger, active: t.active, probation_days: t.probation_days });
    this.steps.set([...t.steps].sort((a, b) => a.position - b.position).map(toEditable));
    this.dirty.set(false);
  }
}

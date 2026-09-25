import { moveItemInArray } from '@angular/cdk/drag-drop';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, concatMap, of, tap } from 'rxjs';
import {
  EvaluationDetails,
  NextStepPatterns,
  ScriptContent,
  ScriptDetails,
  ScriptFollowup,
  ScriptObjection,
  ScriptStep,
  ScriptTemplate,
  ScriptVersion,
  emptyContent,
  newId,
} from '../scripts.model';
import { ScriptsService } from '../scripts.service';

type ListKey = 'steps' | 'objections' | 'templates' | 'followups';
type Item<K extends ListKey> = ScriptContent[K][number];

/**
 * The script editor (provided per page). `content` is the working copy: it starts from the draft (or the active
 * version when there is no draft) and is sent whole by "save draft". Publishing saves unsaved edits first.
 */
@Injectable()
export class ScriptEditorStore {
  private readonly api = inject(ScriptsService);

  readonly script = signal<ScriptDetails | null>(null);
  readonly content = signal<ScriptContent>(emptyContent());
  readonly dirty = signal(false);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly saving = signal(false);
  readonly versions = signal<ScriptVersion[]>([]);
  readonly activeVersionId = signal<number | null>(null);
  readonly testResult = signal<EvaluationDetails | null>(null);
  readonly testing = signal(false);

  /** Keys of templates, for the follow-up "template" select. */
  readonly templateKeys = computed(() => this.content().templates.map((t) => t.key).filter((k) => k !== ''));
  readonly hasDraft = computed(() => this.script()?.draft !== null && this.script()?.draft !== undefined);

  load(id: number): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.get(id).subscribe({
      next: (s) => {
        this.script.set(s);
        this.content.set(structuredClone((s.draft ?? s.active_version)?.content ?? emptyContent()));
        this.dirty.set(false);
        this.loading.set(false);
        this.loadVersions();
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  loadVersions(): void {
    const s = this.script();
    if (!s) {
      return;
    }
    this.api.versions(s.id).subscribe({
      next: (r) => {
        this.versions.set(r.versions);
        this.activeVersionId.set(r.activeVersionId);
      },
      error: () => undefined,
    });
  }

  addStep(): void {
    this.add('steps', (list) => ({ id: newId('s', list), title: '', goal: '', sample: '', required: false, weight: 10, keywords: [] }));
  }

  addObjection(): void {
    this.add('objections', (list) => ({ id: newId('o', list), trigger: '', answer: '' }));
  }

  addTemplate(): void {
    this.add('templates', (list) => {
      const id = newId('t', list);
      return { id, key: id, title: '', text: '' };
    });
  }

  addFollowup(): void {
    this.add('followups', (list) => ({ id: newId('f', list), condition: 'no_reply', delay_days: 1, template_key: null }));
  }

  updateStep(index: number, patch: Partial<ScriptStep>): void {
    this.update('steps', index, patch);
  }

  updateObjection(index: number, patch: Partial<ScriptObjection>): void {
    this.update('objections', index, patch);
  }

  updateTemplate(index: number, patch: Partial<ScriptTemplate>): void {
    this.update('templates', index, patch);
  }

  updateFollowup(index: number, patch: Partial<ScriptFollowup>): void {
    this.update('followups', index, patch);
  }

  remove(key: ListKey, index: number): void {
    this.edit((c) => ({ ...c, [key]: c[key].filter((_: unknown, i: number) => i !== index) }));
  }

  /** Drag & drop reorder of steps (order = order of the conversation). */
  moveStep(from: number, to: number): void {
    if (from === to) {
      return;
    }
    this.edit((c) => {
      const steps = [...c.steps];
      moveItemInArray(steps, from, to);
      return { ...c, steps };
    });
  }

  setPatterns(kind: keyof NextStepPatterns, list: string[]): void {
    this.edit((c) => ({ ...c, next_step_patterns: { ...c.next_step_patterns, [kind]: list } }));
  }

  saveDraft(): Observable<ScriptVersion> {
    const s = this.requireScript();
    this.saving.set(true);
    return this.api.saveDraft(s.id, this.content()).pipe(
      tap({
        next: (draft) => {
          this.script.update((cur) => (cur ? { ...cur, draft } : cur));
          this.dirty.set(false);
          this.saving.set(false);
          this.loadVersions();
        },
        error: () => this.saving.set(false),
      }),
    );
  }

  /** Saves unsaved edits, then publishes the draft as the new active version. */
  publish(): Observable<ScriptDetails> {
    const s = this.requireScript();
    const save: Observable<unknown> = this.dirty() || !this.hasDraft() ? this.saveDraft() : of(null);
    return save.pipe(
      concatMap(() => {
        this.saving.set(true);
        return this.api.publish(s.id);
      }),
      tap({ next: (updated) => this.afterVersionChange(updated), error: () => this.saving.set(false) }),
    );
  }

  activate(version: number): Observable<ScriptDetails> {
    const s = this.requireScript();
    return this.api.activate(s.id, version).pipe(tap((updated) => this.afterVersionChange(updated)));
  }

  setArchived(archived: boolean): Observable<ScriptDetails> {
    const s = this.requireScript();
    return this.api.update(s.id, { archived }).pipe(tap((updated) => this.script.set(updated)));
  }

  runTest(text: string, version: 'draft' | 'active'): void {
    const s = this.script();
    if (!s || text.trim() === '') {
      return;
    }
    this.testing.set(true);
    this.api.test(s.id, text, version).subscribe({
      next: (r) => {
        this.testResult.set(r);
        this.testing.set(false);
      },
      error: () => {
        this.testResult.set(null);
        this.testing.set(false);
      },
    });
  }

  private afterVersionChange(updated: ScriptDetails): void {
    this.script.set(updated);
    this.saving.set(false);
    if (!this.dirty()) {
      this.content.set(structuredClone((updated.draft ?? updated.active_version)?.content ?? emptyContent()));
    }
    this.loadVersions();
  }

  private add<K extends ListKey>(key: K, make: (list: ScriptContent[K]) => Item<K>): void {
    this.edit((c) => ({ ...c, [key]: [...c[key], make(c[key])] }));
  }

  private update<K extends ListKey>(key: K, index: number, patch: Partial<Item<K>>): void {
    this.edit((c) => ({ ...c, [key]: c[key].map((item: Item<K>, i: number) => (i === index ? { ...item, ...patch } : item)) }));
  }

  private edit(fn: (c: ScriptContent) => ScriptContent): void {
    this.content.update(fn);
    this.dirty.set(true);
  }

  private requireScript(): ScriptDetails {
    const s = this.script();
    if (!s) {
      throw new Error('no script loaded');
    }
    return s;
  }
}

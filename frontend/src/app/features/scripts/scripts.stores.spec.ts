import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { ScriptEditorStore } from './editor/script-editor.store';
import { EvaluationDetails, ScriptContent, ScriptDetails, ScriptVersion, Task, emptyContent } from './scripts.model';
import { ScriptsService } from './scripts.service';
import { TasksStore } from './tasks/tasks.store';

const version = (n: number, content: ScriptContent, draft = false): ScriptVersion => ({
  id: n * 10,
  script_id: 1,
  version: n,
  is_draft: draft,
  published_at: draft ? null : '2026-09-01T00:00:00Z',
  author: null,
  updated_at: null,
  content,
});
const withSteps = (...titles: string[]): ScriptContent => ({
  ...emptyContent(),
  steps: titles.map((title, i) => ({ id: `s${i + 1}`, title, goal: '', sample: '', required: false, weight: 10, keywords: [] })),
});
const task = (id: number, done = false): Task => ({
  id,
  type: 'followup',
  title: `T${id}`,
  assignee_id: 1,
  candidate: null,
  application_id: null,
  vacancy: null,
  template_key: null,
  due_at: '2026-09-10T10:00:00Z',
  done_at: done ? '2026-09-10T11:00:00Z' : null,
  is_overdue: true,
});

class FakeApi {
  script: ScriptDetails = { id: 1, name: 'S', channel: 'call', archived: false, active_version: version(1, withSteps('A')), draft: null, updated_at: null };
  saved: ScriptContent[] = [];
  calls: string[] = [];
  setDone$: Observable<Task> = of(task(1, true));
  get = () => of(structuredClone(this.script));
  versions = () => of({ versions: [version(1, withSteps('A'))], activeVersionId: 10 });
  saveDraft = (_id: number, content: ScriptContent) => {
    this.saved.push(content);
    this.calls.push('save');
    return of(version(2, content, true));
  };
  publish = () => {
    this.calls.push('publish');
    return of({ ...this.script, active_version: version(2, this.saved.at(-1) ?? emptyContent()), draft: null });
  };
  activate = (_id: number, v: number) => of({ ...this.script, active_version: version(v, withSteps('Old')) });
  test = (): Observable<EvaluationDetails> =>
    of({ engine: 'rules', score: 80, steps: [], next_step: { fixed: true, quote: null, negative_quote: null }, objections: [], recommendations: [] });
  tasks = () => of([task(1), task(2)]);
  setTaskDone = () => this.setDone$;
}

function setup<T>(store: new () => T): { store: T; api: FakeApi } {
  const api = new FakeApi();
  TestBed.configureTestingModule({ providers: [store, { provide: ScriptsService, useValue: api }] });
  return { store: TestBed.inject(store), api };
}

describe('ScriptEditorStore', () => {
  it('edits a working copy of the active version and reorders steps', () => {
    const { store } = setup(ScriptEditorStore);
    store.load(1);
    expect(store.content().steps.map((s) => s.title)).toEqual(['A']);
    expect(store.dirty()).toBe(false);

    store.addStep();
    store.updateStep(1, { title: 'B', keywords: ['b'] });
    store.addStep();
    store.moveStep(2, 0);
    expect(store.content().steps.map((s) => s.id)).toEqual(['s3', 's1', 's2']);
    store.remove('steps', 0);
    expect(store.content().steps.map((s) => s.title)).toEqual(['A', 'B']);
    expect(store.dirty()).toBe(true);

    store.addTemplate();
    store.updateTemplate(0, { key: 'first' });
    store.addFollowup();
    store.updateFollowup(0, { template_key: 'first', delay_days: 2 });
    store.setPatterns('negative', ['подумайте']);
    expect(store.templateKeys()).toEqual(['first']);
    expect(store.content().followups[0]).toEqual({ id: 'f1', condition: 'no_reply', delay_days: 2, template_key: 'first' });
    expect(store.content().next_step_patterns.negative).toEqual(['подумайте']);
  });

  it('publish saves unsaved edits first, then refreshes from the answer', () => {
    const { store, api } = setup(ScriptEditorStore);
    store.load(1);
    store.addObjection();
    store.publish().subscribe();
    expect(api.calls).toEqual(['save', 'publish']);
    expect(store.dirty()).toBe(false);
    expect(store.script()?.active_version?.version).toBe(2);
    expect(store.content().objections.length).toBe(1);
  });

  it('activates an older version and runs a test', () => {
    const { store } = setup(ScriptEditorStore);
    store.load(1);
    store.activate(1).subscribe();
    expect(store.content().steps[0].title).toBe('Old');
    store.runTest('   ', 'draft');
    expect(store.testResult()).toBeNull();
    store.runTest('Hello', 'draft');
    expect(store.testResult()?.score).toBe(80);
  });
});

describe('TasksStore', () => {
  it('loads and marks done optimistically', () => {
    const { store } = setup(TasksStore);
    store.load({ mine: true, due: 'today' });
    expect(store.items().length).toBe(2);
    expect(store.overdue()).toBe(2);
    store.toggleDone(store.items()[0], () => undefined);
    expect(store.items()[0].done_at).not.toBeNull();
    expect(store.pending().size).toBe(0);
    expect(store.overdue()).toBe(1);
  });

  it('rolls back a refused change', () => {
    const { store, api } = setup(TasksStore);
    store.load({});
    api.setDone$ = throwError(() => new HttpErrorResponse({ status: 403 }));
    let key = '';
    store.toggleDone(store.items()[1], (k) => (key = k));
    expect(store.items()[1].done_at).toBeNull();
    expect(key).toBe('scripts.errors.forbidden');
  });
});

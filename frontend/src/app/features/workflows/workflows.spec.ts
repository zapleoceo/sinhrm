import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { stepConfigErrors } from './editor/workflow-editor.page';
import { WorkflowEditorStore } from './editor/workflow-editor.store';
import { RunsStore } from './runs/runs.store';
import {
  RunStep,
  TemplateStep,
  WorkflowRun,
  WorkflowTemplate,
  applyOutcome,
  defaultConfig,
  moveItem,
  newStep,
  progressPercent,
  stepResultKey,
  switchAction,
  toEditable,
  toSaveBody,
} from './workflows.model';
import { WorkflowsService, fieldErrors, workflowsErrorKey } from './workflows.service';

const tStep = (id: number, position: number, extra: Partial<TemplateStep> = {}): TemplateStep => ({
  id,
  position,
  title: `Step ${id}`,
  action: 'create_task',
  offset_days: 0,
  assignee_rule: 'hr_admin',
  assignee_user_id: null,
  config: { title: 'Do it' },
  ...extra,
});
const template = (steps: TemplateStep[] = [tStep(1, 1), tStep(2, 2)]): WorkflowTemplate => ({
  id: 7,
  name: 'Onboarding',
  kind: 'onboarding',
  trigger: 'employee_hired',
  active: true,
  probation_days: null,
  runs_count: 0,
  updated_at: '2026-09-01T10:00:00Z',
  webhook_secret: { is_set: false, masked: null, updated_at: null },
  steps,
});
const rStep = (id: number, extra: Partial<RunStep> = {}): RunStep => ({
  id,
  position: id,
  title: `S${id}`,
  action: 'create_task',
  offset_days: 0,
  assignee_rule: 'employee',
  assignee: null,
  due_at: null,
  status: 'pending',
  waiting: true,
  executed_at: null,
  attempts: 1,
  completed_by: null,
  completed_at: null,
  result: null,
  can_complete: true,
  can_retry: false,
  ...extra,
});
const run = (steps: RunStep[] = [rStep(1), rStep(2)]): WorkflowRun => ({
  id: 3,
  template: { id: 7, name: 'Onboarding' },
  employee: { id: 12, full_name: 'Test Person' },
  anchor_date: '2026-09-01',
  status: 'running',
  trigger: 'manual',
  started_by: null,
  parent_run_id: null,
  depth: 0,
  created_at: '2026-09-01T10:00:00Z',
  completed_at: null,
  progress: { finished: 0, total: steps.length },
  has_failed: false,
  can_cancel: true,
  steps,
});

describe('WorkflowsService', () => {
  let api: WorkflowsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(WorkflowsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads, creates, updates, reorders and deletes templates', () => {
    api.templates().subscribe();
    http.expectOne({ method: 'GET', url: '/api/workflows/templates' }).flush({ data: [] });
    api.template(7).subscribe();
    http.expectOne({ method: 'GET', url: '/api/workflows/templates/7' }).flush({ data: template() });

    const body = { name: 'N', kind: 'custom' as const, trigger: 'manual' as const, steps: [] };
    api.createTemplate(body).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/workflows/templates' }).request.body).toEqual(body);
    api.updateTemplate(7, body).subscribe();
    http.expectOne({ method: 'PUT', url: '/api/workflows/templates/7' }).flush({ data: template() });

    api.reorderSteps(7, [2, 1]).subscribe();
    const reorder = http.expectOne({ method: 'POST', url: '/api/workflows/templates/7/steps/reorder' });
    expect(reorder.request.body).toEqual({ ids: [2, 1] });
    reorder.flush({ data: template() });

    api.deleteTemplate(7).subscribe();
    http.expectOne({ method: 'DELETE', url: '/api/workflows/templates/7' }).flush(null, { status: 204, statusText: 'No Content' });

    api.setWebhookSecret(7, null).subscribe();
    const secret = http.expectOne({ method: 'PUT', url: '/api/workflows/templates/7/webhook-secret' });
    expect(secret.request.body).toEqual({ secret: null });
    secret.flush({ data: template() });
  });

  it('lists runs with filters as string params and runs commands', () => {
    api.runs({ employee_id: 12, status: 'running' }).subscribe();
    http
      .expectOne((r) => r.url === '/api/workflows/runs' && r.params.get('employee_id') === '12' && r.params.get('status') === 'running' && !r.params.has('template_id'))
      .flush({ data: [] });

    api.startRun(7, 12, '2026-09-01').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/workflows/runs' }).request.body).toEqual({ template_id: 7, employee_id: 12, anchor_date: '2026-09-01' });
    api.startRun(7, 12).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/workflows/runs' }).request.body).toEqual({ template_id: 7, employee_id: 12 });

    api.cancelRun(3).subscribe();
    http.expectOne({ method: 'POST', url: '/api/workflows/runs/3/cancel' }).flush({ data: run() });

    api.stepCommand(3, 5, 'skip', 'not needed').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/workflows/runs/3/steps/5/skip' }).request.body).toEqual({ reason: 'not needed' });
    api.stepCommand(3, 5, 'complete').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/workflows/runs/3/steps/5/complete' }).request.body).toEqual({});
    api.stepCommand(3, 5, 'retry').subscribe();
    http.expectOne({ method: 'POST', url: '/api/workflows/runs/3/steps/5/retry' }).flush({ data: {} });
  });

  it('maps errors to i18n keys and field messages', () => {
    expect(workflowsErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'has_runs' } }))).toBe('workflows.errors.has_runs');
    expect(workflowsErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'step_not_open' } }))).toBe('workflows.errors.step_not_open');
    expect(workflowsErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('workflows.errors.forbidden');
    expect(workflowsErrorKey(new HttpErrorResponse({ status: 404 }))).toBe('workflows.errors.not_found');
    expect(workflowsErrorKey(new HttpErrorResponse({ status: 422, error: {} }))).toBe('workflows.errors.validation');
    expect(workflowsErrorKey(new Error('x'))).toBe('common.error');

    const e = new HttpErrorResponse({ status: 422, error: { errors: { 'steps.0.config.url': ['Bad URL'], name: ['Required'] } } });
    expect(fieldErrors(e)).toEqual({ 'steps.0.config.url': 'Bad URL', name: 'Required' });
    expect(fieldErrors(new HttpErrorResponse({ status: 409 }))).toEqual({});
    expect(stepConfigErrors(fieldErrors(e), 0)).toEqual({ url: 'Bad URL' });
    expect(stepConfigErrors(fieldErrors(e), 1)).toEqual({});
  });
});

describe('workflow model helpers', () => {
  it('gives each action its own config defaults', () => {
    expect(defaultConfig('send_email_template')).toEqual({ subject: '', body: '' });
    expect(defaultConfig('webhook')).toEqual({ url: '' });
    expect(defaultConfig('create_document')).toEqual({ document_template_id: null, send: false });
    expect(defaultConfig('add_calendar_event')).toEqual({ title: '', time: '10:00', duration_minutes: 30, online: true });
    expect(defaultConfig('start_workflow')).toEqual({ template_id: null });
  });

  it('keeps shared config values when switching actions and drops foreign keys', () => {
    expect(switchAction({ title: 'Meet', url: 'https://example.test' }, 'create_task')).toEqual({ title: 'Meet' });
    expect(switchAction({ title: 'Form' }, 'request_form')).toEqual({ title: 'Form', url: '' });
    expect(switchAction({ title: 'X' }, 'webhook')).toEqual({ url: '' });
  });

  it('moves steps without mutating the list', () => {
    const list = ['a', 'b', 'c'];
    expect(moveItem(list, 0, 2)).toEqual(['b', 'c', 'a']);
    expect(moveItem(list, 2, 0)).toEqual(['c', 'a', 'b']);
    expect(moveItem(list, 1, 1)).toEqual(list);
    expect(list).toEqual(['a', 'b', 'c']);
  });

  it('builds the save body: ids of saved steps, user id only for specific_user, probation only for probation_end', () => {
    const saved = toEditable(tStep(1, 1, { assignee_rule: 'manager', assignee_user_id: 9 }));
    const fresh = { ...newStep('webhook'), title: '  Ping  ', assignee_rule: 'specific_user' as const, assignee_user_id: 4 };
    const body = toSaveBody({ name: 'N', kind: 'onboarding', trigger: 'manual', active: true, probation_days: 90 }, [saved, fresh]);
    expect(body.probation_days).toBeNull();
    expect(body.steps[0]).toEqual({ id: 1, title: 'Step 1', action: 'create_task', offset_days: 0, assignee_rule: 'manager', assignee_user_id: null, config: { title: 'Do it' } });
    expect(body.steps[1]).toEqual({ title: 'Ping', action: 'webhook', offset_days: 0, assignee_rule: 'specific_user', assignee_user_id: 4, config: { url: '' } });
    expect(toSaveBody({ name: 'N', kind: 'custom', trigger: 'probation_end', probation_days: 60 }, []).probation_days).toBe(60);
  });

  it('translates step result codes', () => {
    expect(stepResultKey(null)).toBeNull();
    expect(stepResultKey({ task_id: 5 })).toBeNull();
    expect(stepResultKey({ reason: 'not_connected' })).toBe('workflows.result.not_connected');
    expect(stepResultKey({ error: 'blocked_host' })).toBe('workflows.result.blocked_host');
    expect(stepResultKey({ error: 'http_502' })).toBe('workflows.result.http');
    expect(stepResultKey({ error: 'exception:RuntimeException' })).toBe('workflows.result.generic');
  });

  it('computes progress and applies a step outcome to the run', () => {
    expect(progressPercent({ progress: { finished: 1, total: 3 } })).toBe(33);
    expect(progressPercent({ progress: { finished: 0, total: 0 } })).toBe(0);
    const next = applyOutcome(run(), { id: 1, run_id: 3, status: 'done', completed_at: '2026-09-02T10:00:00Z', result: null, run_status: 'running' });
    expect(next.steps[0].status).toBe('done');
    expect(next.steps[0].can_complete).toBe(false);
    expect(next.progress).toEqual({ finished: 1, total: 2 });
    const failed = applyOutcome(run(), { id: 2, run_id: 3, status: 'failed', completed_at: null, result: { error: 'x' }, run_status: 'running' });
    expect(failed.has_failed).toBe(true);
  });
});

describe('WorkflowEditorStore', () => {
  let store: WorkflowEditorStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), WorkflowEditorStore] });
    store = TestBed.inject(WorkflowEditorStore);
    http = TestBed.inject(HttpTestingController);
    store.load(7);
    http.expectOne('/api/workflows/templates/7').flush({ data: template([tStep(2, 2), tStep(1, 1)]) });
    http.expectOne('/api/workflows/templates').flush({ data: [template(), { ...template(), id: 8, name: 'Other' }] });
    http.expectOne((r) => r.url === '/api/documents/templates').flush({ data: [] });
  });

  afterEach(() => http.verify());

  it('loads steps ordered by position and excludes itself from start_workflow options', () => {
    expect(store.steps().map((s) => s.id)).toEqual([1, 2]);
    expect(store.otherWorkflows().map((t) => t.id)).toEqual([8]);
    expect(store.dirty()).toBe(false);
  });

  it('reorders a pristine saved template through the reorder endpoint', () => {
    store.moveStep(0, 1, () => undefined);
    const req = http.expectOne({ method: 'POST', url: '/api/workflows/templates/7/steps/reorder' });
    expect(req.request.body).toEqual({ ids: [2, 1] });
    req.flush({ data: template([tStep(2, 1), tStep(1, 2)]) });
    expect(store.steps().map((s) => s.id)).toEqual([2, 1]);
    expect(store.dirty()).toBe(false);
  });

  it('restores the order when the reorder is refused', () => {
    let error = '';
    store.moveStep(0, 1, (key) => (error = key));
    http.expectOne('/api/workflows/templates/7/steps/reorder').flush({ code: 'invalid_order' }, { status: 422, statusText: 'Unprocessable' });
    expect(store.steps().map((s) => s.id)).toEqual([1, 2]);
    expect(error).toBe('workflows.errors.invalid_order');
  });

  it('reorders locally once there are unsaved edits and saves everything with PUT', () => {
    store.addStep();
    store.setAction(2, 'webhook');
    store.setConfig(2, 'url', 'https://example.test/hook');
    store.moveStep(2, 0, () => undefined);
    http.expectNone('/api/workflows/templates/7/steps/reorder');
    expect(store.steps()[0].action).toBe('webhook');

    store.save().subscribe();
    const put = http.expectOne({ method: 'PUT', url: '/api/workflows/templates/7' });
    expect(put.request.body.steps.map((s: { id?: number }) => s.id)).toEqual([undefined, 1, 2]);
    expect(put.request.body.steps[0].config).toEqual({ url: 'https://example.test/hook' });
    put.flush({ data: template() });
    expect(store.dirty()).toBe(false);
  });

  it('keeps server field errors after a failed save', () => {
    store.setHead({ name: '' });
    store.save().subscribe({ error: () => undefined });
    http.expectOne('/api/workflows/templates/7').flush({ errors: { name: ['Required'] } }, { status: 422, statusText: 'Unprocessable' });
    expect(store.error('name')).toBe('Required');
    expect(store.dirty()).toBe(true);
  });
});

describe('RunsStore', () => {
  let store: RunsStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), RunsStore] });
    store = TestBed.inject(RunsStore);
    http = TestBed.inject(HttpTestingController);
    store.load({ employee_id: 12 });
    http.expectOne((r) => r.url === '/api/workflows/runs').flush({ data: [run()] });
  });

  afterEach(() => http.verify());

  it('completes a step in place', () => {
    let msg = '';
    store.command(store.items()[0], store.items()[0].steps[0], 'complete', (key) => (msg = key));
    http
      .expectOne('/api/workflows/runs/3/steps/1/complete')
      .flush({ data: { id: 1, run_id: 3, status: 'done', completed_at: '2026-09-02T00:00:00Z', result: null, run_status: 'running' } });
    expect(store.items()[0].steps[0].status).toBe('done');
    expect(msg).toBe('workflows.step.completeDone');
    expect(store.pending().size).toBe(0);
  });

  it('reports a refused command', () => {
    let msg = '';
    store.command(store.items()[0], store.items()[0].steps[0], 'complete', (key) => (msg = key));
    http.expectOne('/api/workflows/runs/3/steps/1/complete').flush({ code: 'step_not_open' }, { status: 409, statusText: 'Conflict' });
    expect(msg).toBe('workflows.errors.step_not_open');
  });

  it('cancels and starts runs', () => {
    store.cancel(store.items()[0], () => undefined);
    http.expectOne('/api/workflows/runs/3/cancel').flush({ data: { ...run(), status: 'cancelled', can_cancel: false } });
    expect(store.items()[0].status).toBe('cancelled');

    store.start(7, 12, undefined, () => undefined);
    http.expectOne({ method: 'POST', url: '/api/workflows/runs' }).flush({ data: { ...run(), id: 4 } });
    expect(store.items().map((r) => r.id)).toEqual([4, 3]);
  });
});

/** Types of the Workflows API (backend app/Modules/Workflows). */

export type WorkflowKind = 'onboarding' | 'offboarding' | 'custom';
export const WORKFLOW_KINDS: readonly WorkflowKind[] = ['onboarding', 'offboarding', 'custom'];

export type WorkflowTrigger = 'manual' | 'employee_hired' | 'employee_terminated' | 'probation_end';
export const WORKFLOW_TRIGGERS: readonly WorkflowTrigger[] = ['manual', 'employee_hired', 'employee_terminated', 'probation_end'];

export type WorkflowAction =
  | 'create_task'
  | 'request_form'
  | 'send_email_template'
  | 'add_calendar_event'
  | 'create_document'
  | 'upload_document_request'
  | 'webhook'
  | 'start_workflow'
  | 'notify_manager'
  | 'assign_buddy'
  | 'collect_assets';
export const WORKFLOW_ACTIONS: readonly WorkflowAction[] = [
  'create_task',
  'request_form',
  'send_email_template',
  'add_calendar_event',
  'create_document',
  'upload_document_request',
  'webhook',
  'start_workflow',
  'notify_manager',
  'assign_buddy',
  'collect_assets',
];

export type AssigneeRule = 'employee' | 'manager' | 'hr_admin' | 'specific_user';
export const ASSIGNEE_RULES: readonly AssigneeRule[] = ['employee', 'manager', 'hr_admin', 'specific_user'];

export type RunStatus = 'running' | 'completed' | 'cancelled';
export const RUN_STATUSES: readonly RunStatus[] = ['running', 'completed', 'cancelled'];

export type StepStatus = 'pending' | 'done' | 'skipped' | 'failed';

/** Action-specific settings; the server validates them per action and drops unknown keys. */
export type StepConfigValue = string | number | boolean | null;
export type StepConfig = Record<string, StepConfigValue>;

export interface WebhookSecretState {
  is_set: boolean;
  masked: string | null;
  updated_at: string | null;
}

export interface TemplateStep {
  id: number;
  position: number;
  title: string;
  action: WorkflowAction;
  offset_days: number;
  assignee_rule: AssigneeRule;
  assignee_user_id: number | null;
  config: StepConfig;
}

export interface WorkflowTemplate {
  id: number;
  name: string;
  kind: WorkflowKind;
  trigger: WorkflowTrigger;
  active: boolean;
  probation_days: number | null;
  runs_count: number;
  updated_at: string;
  webhook_secret: WebhookSecretState;
  steps: TemplateStep[];
}

/** A step in the editor: `id` is absent for steps not saved yet; `key` tracks rows in the template. */
export interface EditableStep {
  key: string;
  id?: number;
  title: string;
  action: WorkflowAction;
  offset_days: number;
  assignee_rule: AssigneeRule;
  assignee_user_id: number | null;
  config: StepConfig;
}

export interface SaveTemplateStep {
  id?: number;
  title: string;
  action: WorkflowAction;
  offset_days: number;
  assignee_rule: AssigneeRule;
  assignee_user_id?: number | null;
  config: StepConfig;
}

export interface SaveTemplate {
  name: string;
  kind: WorkflowKind;
  trigger: WorkflowTrigger;
  active?: boolean;
  probation_days?: number | null;
  steps: SaveTemplateStep[];
}

export interface PersonRef {
  id: number;
  name: string;
}

export interface RunStep {
  id: number;
  position: number;
  title: string;
  action: WorkflowAction;
  offset_days: number;
  assignee_rule: AssigneeRule;
  assignee: PersonRef | null;
  due_at: string | null;
  status: StepStatus;
  waiting: boolean;
  executed_at: string | null;
  attempts: number;
  completed_by: PersonRef | number | null;
  completed_at: string | null;
  result: Record<string, unknown> | null;
  can_complete: boolean;
  can_retry: boolean;
}

export interface WorkflowRun {
  id: number;
  template: { id: number; name: string };
  employee: { id: number; full_name: string };
  anchor_date: string;
  status: RunStatus;
  trigger: WorkflowTrigger;
  started_by: PersonRef | null;
  parent_run_id: number | null;
  depth: number;
  created_at: string;
  completed_at: string | null;
  progress: { finished: number; total: number };
  has_failed: boolean;
  can_cancel: boolean;
  steps: RunStep[];
}

export interface RunQuery {
  employee_id?: number;
  template_id?: number;
  status?: RunStatus;
}

export interface StepOutcome {
  id: number;
  run_id: number;
  status: StepStatus;
  completed_at: string | null;
  result: Record<string, unknown> | null;
  run_status: RunStatus;
}

export type StepCommand = 'complete' | 'skip' | 'retry';

/** Business error codes with their own message (backend WorkflowException). */
export const WORKFLOW_ERROR_CODES = ['depth_limit', 'run_not_running', 'step_not_open', 'step_not_failed', 'has_runs', 'invalid_order'] as const;

/** Step result codes with their own message; http_<n> and exception:* are grouped. */
export const STEP_RESULT_CODES = [
  'not_connected',
  'send_not_supported',
  'no_manager',
  'no_assignee',
  'missing_secret',
  'blocked_host',
  'blocked_port',
  'invalid_url',
  'unresolved_host',
  'connection_error',
  'depth_limit',
  'template_inactive',
  'template_missing',
  'document_template_missing',
  'template_archived',
  'cancelled',
  'no_assets',
] as const;

/** Default config of a freshly chosen action: only the keys the server knows for it. */
export function defaultConfig(action: WorkflowAction): StepConfig {
  switch (action) {
    case 'create_task':
    case 'assign_buddy':
    case 'collect_assets':
      return { title: '' };
    case 'request_form':
      return { title: '', url: '' };
    case 'send_email_template':
      return { subject: '', body: '' };
    case 'add_calendar_event':
      return { title: '', time: '10:00', duration_minutes: 30, online: true };
    case 'create_document':
      return { document_template_id: null, send: false };
    case 'upload_document_request':
      return { document_name: '' };
    case 'webhook':
      return { url: '' };
    case 'start_workflow':
      return { template_id: null };
    case 'notify_manager':
      return { message: '' };
  }
}

/** Keeps values of keys the new action shares with the old config; fills the rest with defaults. */
export function switchAction(config: StepConfig, action: WorkflowAction): StepConfig {
  const next = defaultConfig(action);
  for (const key of Object.keys(next)) {
    const value = config[key];
    if (value !== undefined && value !== null && value !== '') {
      next[key] = value;
    }
  }
  return next;
}

let keySeq = 0;

export function toEditable(step: TemplateStep): EditableStep {
  return {
    key: `id${step.id}`,
    id: step.id,
    title: step.title,
    action: step.action,
    offset_days: step.offset_days,
    assignee_rule: step.assignee_rule,
    assignee_user_id: step.assignee_user_id,
    config: { ...defaultConfig(step.action), ...step.config },
  };
}

export function newStep(action: WorkflowAction = 'create_task'): EditableStep {
  keySeq += 1;
  return { key: `new${keySeq}`, title: '', action, offset_days: 0, assignee_rule: 'hr_admin', assignee_user_id: null, config: defaultConfig(action) };
}

/** Moves one item (CDK drop indices) without mutating the input. */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
  const copy = [...list];
  if (from === to || from < 0 || from >= copy.length) {
    return copy;
  }
  const [item] = copy.splice(from, 1);
  copy.splice(Math.max(0, Math.min(to, copy.length)), 0, item);
  return copy;
}

/** PUT/POST body from the editor state; the user id is sent only for the specific_user rule. */
export function toSaveBody(head: Omit<SaveTemplate, 'steps'>, steps: readonly EditableStep[]): SaveTemplate {
  return {
    ...head,
    probation_days: head.trigger === 'probation_end' ? (head.probation_days ?? null) : null,
    steps: steps.map((s) => ({
      ...(s.id !== undefined ? { id: s.id } : {}),
      title: s.title.trim(),
      action: s.action,
      offset_days: s.offset_days,
      assignee_rule: s.assignee_rule,
      assignee_user_id: s.assignee_rule === 'specific_user' ? s.assignee_user_id : null,
      config: s.config,
    })),
  };
}

/** i18n key of a step result code (reason / error in the result object), or null when nothing to show. */
export function stepResultKey(result: Record<string, unknown> | null): string | null {
  const raw = result?.['reason'] ?? result?.['error'];
  if (typeof raw !== 'string' || raw === '') {
    return null;
  }
  if ((STEP_RESULT_CODES as readonly string[]).includes(raw)) {
    return `workflows.result.${raw}`;
  }
  if (raw.startsWith('http_')) {
    return 'workflows.result.http';
  }
  return 'workflows.result.generic';
}

/** Progress in percent (0 for an empty run). */
export function progressPercent(run: Pick<WorkflowRun, 'progress'>): number {
  const { finished, total } = run.progress;
  return total > 0 ? Math.round((finished / total) * 100) : 0;
}

/** Applies a step command outcome to a run (status of the step and of the run). */
export function applyOutcome(run: WorkflowRun, outcome: StepOutcome): WorkflowRun {
  const steps = run.steps.map((s) =>
    s.id === outcome.id
      ? {
          ...s,
          status: outcome.status,
          completed_at: outcome.completed_at,
          result: outcome.result,
          waiting: false,
          can_complete: outcome.status === 'pending' && s.can_complete,
          can_retry: outcome.status === 'failed' && s.can_retry,
        }
      : s,
  );
  const finished = steps.filter((s) => s.status === 'done' || s.status === 'skipped').length;
  return {
    ...run,
    steps,
    status: outcome.run_status,
    has_failed: steps.some((s) => s.status === 'failed'),
    can_cancel: outcome.run_status === 'running' && run.can_cancel,
    progress: { finished, total: steps.length },
  };
}

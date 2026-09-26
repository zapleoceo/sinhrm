/** Types of the HiringRequests API (backend app/Modules/HiringRequests, tz2). */

export type HiringStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled' | 'in_progress' | 'closed';
export const HIRING_STATUSES: readonly HiringStatus[] = ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'in_progress', 'closed'];
export type HiringReason = 'new_position' | 'replacement';
export type HiringPriority = 'low' | 'normal' | 'high' | 'urgent';
export const HIRING_PRIORITIES: readonly HiringPriority[] = ['low', 'normal', 'high', 'urgent'];
export type ApprovalStatus = 'waiting' | 'pending' | 'approved' | 'rejected' | 'skipped';
export type RouteStepKind = 'manager' | 'role' | 'user';
export const ROUTE_STEP_KINDS: readonly RouteStepKind[] = ['manager', 'role', 'user'];
export type FormFieldType = 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox';
export const FORM_FIELD_TYPES: readonly FormFieldType[] = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

interface Named {
  id: number;
  name: string;
}

export interface HiringApproval {
  id: number;
  position: number;
  name: string;
  kind: RouteStepKind;
  role: string | null;
  approver: Named | null;
  status: ApprovalStatus;
  sla_days: number | null;
  activated_at: string | null;
  due_at: string | null;
  overdue: boolean;
  decided_by: Named | null;
  decided_at: string | null;
  comment: string | null;
}

export interface HiringProgress {
  vacancy_status: 'open' | 'paused' | 'closed' | null;
  hired: number;
  headcount: number;
  percent: number;
}

export interface HiringRequest {
  id: number;
  title: string;
  status: HiringStatus;
  priority: HiringPriority;
  reason: HiringReason;
  headcount: number;
  branch: Named;
  department: Named | null;
  position: Named | null;
  replaced_employee: { id: number; full_name: string } | null;
  desired_start_date: string | null;
  salary_min: number | null;
  salary_max: number | null;
  currency: string | null;
  requirements: string | null;
  extra: Record<string, string | number | boolean>;
  requester: Named | null;
  recruiter: Named | null;
  vacancy: { id: number; title: string; status: 'open' | 'paused' | 'closed' } | null;
  progress: HiringProgress | null;
  current_step: { position: number; name: string; due_at: string | null; overdue: boolean } | null;
  overdue: boolean;
  approvals: HiringApproval[];
  submitted_at: string | null;
  decided_at: string | null;
  closed_at: string | null;
  created_at: string | null;
  can: { edit: boolean; submit: boolean; decide: boolean; cancel: boolean; manage: boolean };
}

export interface FormField {
  key: string;
  label: string;
  type: FormFieldType;
  required: boolean;
  options?: string[];
}

export interface HiringMeta {
  can_create: boolean;
  can_manage: boolean;
  form_fields: FormField[];
}

export interface SaveHiringRequest {
  title?: string;
  branch_id?: number;
  department_id?: number | null;
  position_id?: number | null;
  headcount?: number;
  reason?: HiringReason;
  replaced_employee_id?: number | null;
  desired_start_date?: string | null;
  salary_min?: number | null;
  salary_max?: number | null;
  currency?: string | null;
  requirements?: string | null;
  priority?: HiringPriority;
  extra?: Record<string, string | number | boolean>;
  submit?: boolean;
}

export interface RouteStep {
  id?: number;
  name: string;
  kind: RouteStepKind;
  role: string | null;
  user_id: number | null;
  sla_days: number | null;
}

export interface HiringSettings {
  form_fields: FormField[];
  creator_user_ids: number[];
  auto_vacancy: boolean;
  users: Named[];
  route: (RouteStep & { position: number; user: Named | null })[];
}

export const HIRING_ERROR_CODES = [
  'invalid_status',
  'replaced_employee_required',
  'required_fields',
  'invalid_field',
  'salary_range',
  'vacancy_taken',
  'invalid_recruiter',
  'invalid_route',
  'no_default_pipeline',
] as const;

/** Colour group of a status chip. */
export function statusTone(status: HiringStatus): 'neutral' | 'info' | 'success' | 'danger' {
  switch (status) {
    case 'pending':
    case 'approved':
      return 'info';
    case 'in_progress':
    case 'closed':
      return 'success';
    case 'rejected':
    case 'cancelled':
      return 'danger';
    default:
      return 'neutral';
  }
}

/** Icon of a route step on the timeline. */
export function stepIcon(step: Pick<HiringApproval, 'status' | 'overdue'>): string {
  if (step.status === 'approved') {
    return 'check_circle';
  }
  if (step.status === 'rejected') {
    return 'cancel';
  }
  if (step.status === 'skipped') {
    return 'redo';
  }
  if (step.status === 'pending') {
    return step.overdue ? 'alarm' : 'hourglass_top';
  }
  return 'radio_button_unchecked';
}

/** "1 000 – 1 500 USD" / "від 1 000" / "" for the salary range. */
export function salaryRange(r: Pick<HiringRequest, 'salary_min' | 'salary_max' | 'currency'>): string {
  const fmt = (n: number): string => n.toLocaleString('uk-UA');
  const cur = r.currency ? ` ${r.currency}` : '';
  if (r.salary_min !== null && r.salary_max !== null) {
    return `${fmt(r.salary_min)} – ${fmt(r.salary_max)}${cur}`;
  }
  if (r.salary_min !== null) {
    return `≥ ${fmt(r.salary_min)}${cur}`;
  }
  if (r.salary_max !== null) {
    return `≤ ${fmt(r.salary_max)}${cur}`;
  }
  return '';
}

/** Keys of required configurable fields still empty (mirrors backend FormFields::missing). */
export function missingFields(fields: readonly FormField[], values: Record<string, unknown>): string[] {
  return fields.filter((f) => f.required && (values[f.key] === undefined || values[f.key] === null || values[f.key] === '' || values[f.key] === false)).map((f) => f.key);
}

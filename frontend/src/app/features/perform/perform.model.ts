/** Types and pure helpers of the Perform API (backend app/Modules/Perform). */

export interface EmployeeRef {
  id: number;
  full_name: string;
}

export interface ListItem {
  id: string;
  text: string;
  done: boolean;
  due_on?: string | null;
}

export type OneOnOneStatus = 'scheduled' | 'completed' | 'cancelled';
export const ONE_ON_ONE_STATUSES: readonly OneOnOneStatus[] = ['scheduled', 'completed', 'cancelled'];

export interface OneOnOne {
  id: number;
  manager: EmployeeRef;
  employee: EmployeeRef;
  scheduled_at: string;
  template_id: number | null;
  status: OneOnOneStatus;
  agenda: ListItem[];
  notes_shared: string | null;
  action_items: ListItem[];
  /** Present only for the meeting's manager. */
  notes_private_manager?: string | null;
  can_manage: boolean;
  can_private_notes: boolean;
}

export interface OneOnOneTemplate {
  id: number;
  name: string;
  agenda: string[];
}

export interface NewOneOnOne {
  employee_id: number;
  scheduled_at: string;
  template_id?: number | null;
}

export type OneOnOnePatch = Partial<Pick<OneOnOne, 'status' | 'notes_shared' | 'notes_private_manager' | 'agenda' | 'action_items' | 'scheduled_at'>>;

export type ObjectiveScope = 'personal' | 'team' | 'branch' | 'company';
export const OBJECTIVE_SCOPES: readonly ObjectiveScope[] = ['personal', 'team', 'branch', 'company'];
export type ObjectiveStatus = 'active' | 'achieved' | 'missed' | 'cancelled';
export const OBJECTIVE_STATUSES: readonly ObjectiveStatus[] = ['active', 'achieved', 'missed', 'cancelled'];
export type Visibility = 'public' | 'team' | 'private';
export const VISIBILITIES: readonly Visibility[] = ['public', 'team', 'private'];

export interface KeyResult {
  id: string;
  title: string;
  start: number;
  target: number;
  current: number;
  unit: string | null;
  weight: number;
}

export interface CheckIn {
  id: number;
  author: { id: number; name: string } | null;
  progress_before: number;
  progress_after: number;
  comment: string | null;
  created_at: string | null;
}

export interface Objective {
  id: number;
  scope: ObjectiveScope;
  owner: EmployeeRef | null;
  period: string;
  title: string;
  description: string | null;
  key_results: KeyResult[];
  progress: number;
  status: ObjectiveStatus;
  parent_objective_id: number | null;
  visibility: Visibility;
  can_edit: boolean;
  checkins?: CheckIn[];
}

export interface SaveObjective {
  scope: ObjectiveScope;
  owner_employee_id?: number | null;
  period: string;
  title: string;
  description?: string | null;
  key_results: Omit<KeyResult, 'id'>[] | KeyResult[];
  status?: ObjectiveStatus;
  parent_objective_id?: number | null;
  visibility: Visibility;
}

export interface ObjectiveNode {
  objective: Objective;
  depth: number;
}

export interface Kpi {
  id: number;
  employee: EmployeeRef;
  metric: string;
  unit: string | null;
  period: string;
  target: number;
  actual: number | null;
  attainment: number | null;
  can_edit: boolean;
}

export type FeedbackType = 'praise' | 'constructive' | 'request';
export const FEEDBACK_TYPES: readonly FeedbackType[] = ['praise', 'constructive', 'request'];
export type FeedbackVisibility = 'private_to_recipient' | 'manager' | 'public';
export const FEEDBACK_VISIBILITIES: readonly FeedbackVisibility[] = ['private_to_recipient', 'manager', 'public'];
export type FeedbackBox = 'received' | 'given' | 'requests' | 'team' | 'public';
export const FEEDBACK_BOXES: readonly FeedbackBox[] = ['received', 'given', 'requests', 'team', 'public'];

export interface Feedback {
  id: number;
  from: EmployeeRef;
  to: EmployeeRef;
  type: FeedbackType;
  text: string;
  visibility: FeedbackVisibility;
  request_id: number | null;
  answered_at: string | null;
  created_at: string | null;
  can_answer: boolean;
}

export interface GiveFeedback {
  to_employee_id?: number | null;
  type: FeedbackType;
  text: string;
  visibility?: FeedbackVisibility;
  request_id?: number | null;
}

export type PlanStatus = 'active' | 'completed' | 'cancelled';
export interface DevelopmentPlan {
  id: number;
  employee: EmployeeRef;
  title: string;
  goals: { id: string; text: string }[];
  actions: ListItem[];
  due_on: string | null;
  status: PlanStatus;
  progress: { done: number; total: number };
  can_edit: boolean;
}

export type ReviewType = 'self' | 'manager' | 'peer' | 'upward';
export const REVIEW_TYPES: readonly ReviewType[] = ['self', 'manager', 'peer', 'upward'];

export interface ScaleLevel {
  value: number;
  label: string;
}
export interface RatingScale {
  id: number;
  name: string;
  levels: ScaleLevel[];
}
export interface Competency {
  id: number;
  name: string;
  description: string | null;
  scale_id: number;
  active: boolean;
}

export type CycleStatus = 'draft' | 'active' | 'closed';
export interface ReviewCycle {
  id: number;
  name: string;
  period_start: string;
  period_end: string;
  participants: { branch_ids: number[]; department_ids: number[] };
  types: ReviewType[];
  competency_ids: number[];
  anonymous: boolean;
  deadlines: Partial<Record<ReviewType, string>>;
  status: CycleStatus;
  progress: { submitted: number; total: number };
  assignments?: { id: number; subject: EmployeeRef; reviewer: EmployeeRef; type: ReviewType; status: 'pending' | 'submitted' }[];
}

export type SaveCycle = Pick<ReviewCycle, 'name' | 'period_start' | 'period_end' | 'participants' | 'types' | 'competency_ids' | 'anonymous' | 'deadlines'>;

export interface Assignment {
  id: number;
  cycle: { id: number; name: string; status: CycleStatus; deadline: string | null };
  subject: EmployeeRef;
  type: ReviewType;
  status: 'pending' | 'submitted';
  submitted_at: string | null;
  competencies?: { id: number; name: string; description: string | null; levels: ScaleLevel[] }[];
  answers?: { competency_id: number; rating: number; comment: string | null }[];
}

export interface ReviewAnswer {
  competency_id: number;
  rating: number;
  comment?: string | null;
}

export interface ReviewResult {
  cycle: { id: number; name: string; status: CycleStatus; anonymous: boolean };
  subject_employee_id: number;
  min_reviewers: number;
  groups: Partial<Record<ReviewType, { reviewers: number | null; suppressed: boolean }>>;
  competencies: { id: number; name: string; max: number; scores: Partial<Record<ReviewType, number | null>>; average: number | null }[];
  comments: { type: ReviewType; competency_id: number; text: string; author: string | null }[];
}

export const PERFORM_ERROR_CODES = [
  'no_employee',
  'self_target',
  'alignment_cycle',
  'request_not_open',
  'cycle_not_draft',
  'cycle_not_active',
  'already_submitted',
  'invalid_answers',
  'in_use',
  'duplicate',
] as const;

/** "2026-Q4" for a date. */
export function quarterOf(date: Date): string {
  return `${date.getFullYear()}-Q${Math.floor(date.getMonth() / 3) + 1}`;
}

/** The current quarter and the neighbours (for the period filter). */
export function quarterOptions(today: Date, back = 3, ahead = 2): string[] {
  const out: string[] = [];
  for (let i = -back; i <= ahead; i++) {
    out.push(quarterOf(new Date(today.getFullYear(), today.getMonth() + i * 3, 1)));
  }
  return out;
}

/** Same formula as the server (ObjectiveProgress): (current − start) / (target − start), clamped 0..1. */
export function keyResultRatio(kr: Pick<KeyResult, 'start' | 'target' | 'current'>): number {
  if (kr.target === kr.start) {
    return kr.current >= kr.target ? 1 : 0;
  }
  return Math.max(0, Math.min(1, (kr.current - kr.start) / (kr.target - kr.start)));
}

/**
 * Alignment tree in display order (depth-first). An objective whose parent is not visible to the user is a root.
 */
export function objectiveTree(list: readonly Objective[]): ObjectiveNode[] {
  const ids = new Set(list.map((o) => o.id));
  const children = new Map<number | null, Objective[]>();
  for (const o of list) {
    const parent = o.parent_objective_id !== null && ids.has(o.parent_objective_id) ? o.parent_objective_id : null;
    children.set(parent, [...(children.get(parent) ?? []), o]);
  }
  const out: ObjectiveNode[] = [];
  const seen = new Set<number>();
  const walk = (parent: number | null, depth: number): void => {
    for (const o of children.get(parent) ?? []) {
      if (!seen.has(o.id)) {
        seen.add(o.id);
        out.push({ objective: o, depth });
        walk(o.id, depth + 1);
      }
    }
  };
  walk(null, 0);
  return out;
}

/** Progress colour band: < 40 danger, < 70 warning, otherwise success. */
export function progressTone(percent: number): 'danger' | 'warning' | 'success' {
  return percent < 40 ? 'danger' : percent < 70 ? 'warning' : 'success';
}

/** Width in % of a score on a 0..max scale (CSS bars). */
export function scoreWidth(score: number | null | undefined, max: number): number {
  return score === null || score === undefined || max <= 0 ? 0 : Math.round((score / max) * 100);
}

export function toggleItem(items: readonly ListItem[], id: string): ListItem[] {
  return items.map((i) => (i.id === id ? { ...i, done: !i.done } : i));
}

/** New item without an id: the server assigns one. */
export function newItem(text: string, due_on: string | null = null): ListItem {
  return { id: '', text: text.trim(), done: false, due_on };
}

/** The item list as the API expects it (empty ids are generated server side). */
export function itemsBody(items: readonly ListItem[]): Partial<ListItem>[] {
  return items.map((i) => ({ ...(i.id ? { id: i.id } : {}), text: i.text, done: i.done, ...(i.due_on !== undefined ? { due_on: i.due_on } : {}) }));
}

/** Upcoming (scheduled, from today) first, earliest first; the rest — latest first. */
export function splitMeetings(list: readonly OneOnOne[], now: Date): { upcoming: OneOnOne[]; past: OneOnOne[] } {
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const upcoming = list.filter((m) => m.status === 'scheduled' && new Date(m.scheduled_at).getTime() >= start);
  const past = list.filter((m) => !upcoming.includes(m));
  upcoming.sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
  past.sort((a, b) => b.scheduled_at.localeCompare(a.scheduled_at));
  return { upcoming, past };
}

/** Parses "1, 2 3" into a list of unique positive ids (participant / audience filters). */
export function parseIds(text: string): number[] {
  return [...new Set(text.split(/[\s,;]+/).map(Number).filter((n) => Number.isInteger(n) && n > 0))];
}

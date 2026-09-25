/** Mirrors backend App\Modules\Scripts (enums, resources). */
export type ScriptChannel = 'call' | 'chat';
export const SCRIPT_CHANNELS: readonly ScriptChannel[] = ['call', 'chat'];
export type FollowupCondition = 'no_reply' | 'link_not_completed' | 'gone_silent';
export const FOLLOWUP_CONDITIONS: readonly FollowupCondition[] = ['no_reply', 'link_not_completed', 'gone_silent'];
export type EvaluationEngine = 'rules' | 'ai';
export type TaskType = 'followup' | 'manual' | 'new_applicant';
export type TaskDue = 'today' | 'overdue';

/** Template variables (backend TemplateVariable); written as {Name} in template texts. */
export const TEMPLATE_VARIABLES = ["Ім'я", 'Рекрутер', 'Вакансія', 'Посилання на вакансію', 'Посилання на співбесіду', 'Адреса'] as const;
export type TemplateVariable = (typeof TEMPLATE_VARIABLES)[number];

export interface ScriptStep {
  id: string;
  title: string;
  goal: string;
  sample: string;
  required: boolean;
  weight: number;
  keywords: string[];
}

export interface ScriptObjection {
  id: string;
  trigger: string;
  answer: string;
}

export interface ScriptTemplate {
  id: string;
  key: string;
  title: string;
  text: string;
}

export interface ScriptFollowup {
  id: string;
  condition: FollowupCondition;
  delay_days: number;
  template_key: string | null;
}

export interface NextStepPatterns {
  positive: string[];
  negative: string[];
}

export interface ScriptContent {
  steps: ScriptStep[];
  objections: ScriptObjection[];
  templates: ScriptTemplate[];
  followups: ScriptFollowup[];
  next_step_patterns: NextStepPatterns;
}

export interface ScriptVersion {
  id: number;
  script_id: number;
  version: number;
  is_draft: boolean;
  published_at: string | null;
  author: { id: number; name: string } | null;
  updated_at: string | null;
  content: ScriptContent;
}

/** List item: versions without content. */
export interface ScriptVersionRef {
  id: number;
  version: number;
  published_at: string | null;
  updated_at: string | null;
}

export interface Script<V = ScriptVersionRef> {
  id: number;
  name: string;
  channel: ScriptChannel;
  archived: boolean;
  active_version: V | null;
  draft: V | null;
  updated_at: string | null;
}

/** GET /api/scripts/{id}: active version and draft with their content. */
export type ScriptDetails = Script<ScriptVersion>;

export interface StepResult {
  id: string;
  title: string;
  required: boolean;
  weight: number;
  done: boolean;
  quote: string | null;
}

export type Recommendation =
  | { type: 'missed_step'; step_id: string; title: string }
  | { type: 'next_step_not_fixed' }
  | { type: 'negative_phrase'; quote: string };

export interface EvaluationDetails {
  engine: EvaluationEngine;
  score: number;
  steps: StepResult[];
  next_step: { fixed: boolean; quote: string | null; negative_quote: string | null };
  objections: { id: string; trigger: string; raised: boolean; quote: string | null }[];
  recommendations: Recommendation[];
}

/** GET /api/touchpoints/{id}/evaluation. */
export interface TouchEvaluation extends EvaluationDetails {
  id: number;
  touchpoint_id: number;
  script: { id: number; name: string | null; version: number; version_id: number } | null;
  created_at: string | null;
}

export interface CandidateTemplate {
  script_id: number;
  script_name: string;
  channel: ScriptChannel;
  key: string;
  title: string;
  text: string;
  missing: string[];
}

export interface Task {
  id: number;
  type: TaskType;
  title: string;
  assignee_id: number;
  candidate: { id: number; name: string } | null;
  application_id: number | null;
  vacancy: { id: number; title: string } | null;
  template_key: string | null;
  due_at: string;
  done_at: string | null;
  is_overdue: boolean;
}

export interface TaskQuery {
  mine?: boolean;
  due?: TaskDue;
  candidate_id?: number;
  done?: boolean;
}

/** Error codes with their own message (backend ScriptException). */
export const SCRIPT_ERROR_CODES = ['no_draft', 'version_not_published', 'nothing_to_evaluate', 'script_archived', 'not_evaluated'] as const;

/** Sample values for the live template preview in the editor. */
export const PREVIEW_VALUES: Record<TemplateVariable, string> = {
  "Ім'я": 'Олена',
  Рекрутер: 'Ірина',
  Вакансія: 'Адміністратор',
  'Посилання на вакансію': 'https://example.test/vacancy',
  'Посилання на співбесіду': 'https://example.test/interview',
  Адреса: 'вул. Прикладна, 1',
};

/** Fills {Variable} tokens; unknown or empty values keep the token (same rule as the backend TemplateRenderer). */
export function renderTemplate(text: string, values: Partial<Record<string, string | null>>): string {
  return text.replace(/\{([^{}\r\n]{1,40})\}/g, (token, name: string) => {
    const value = values[name];
    return (TEMPLATE_VARIABLES as readonly string[]).includes(name) && value && value.trim() ? value.trim() : token;
  });
}

/** Tokens that are not known variables (the backend rejects them on save). */
export function unknownTokens(text: string): string[] {
  const found = [...text.matchAll(/\{([^{}\r\n]{1,40})\}/g)].map((m) => m[1]);
  return [...new Set(found.filter((name) => !(TEMPLATE_VARIABLES as readonly string[]).includes(name)))];
}

export function emptyContent(): ScriptContent {
  return { steps: [], objections: [], templates: [], followups: [], next_step_patterns: { positive: [], negative: [] } };
}

/** Short unique id for a new step/objection/template/follow-up (unique within its list). */
export function newId(prefix: string, taken: readonly { id: string }[]): string {
  let n = taken.length + 1;
  while (taken.some((x) => x.id === `${prefix}${n}`)) {
    n++;
  }
  return `${prefix}${n}`;
}

/** "a, b ,, c" → ["a", "b", "c"]. */
export function splitList(value: string): string[] {
  return value
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '');
}

/** Score colour band: meaning is also given as text (the number), never by colour only. */
export function scoreBand(score: number): 'good' | 'mid' | 'low' {
  return score >= 75 ? 'good' : score >= 50 ? 'mid' : 'low';
}

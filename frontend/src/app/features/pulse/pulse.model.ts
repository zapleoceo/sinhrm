/** Types and pure helpers of the Pulse API (backend app/Modules/Pulse). */

export type SurveyType = 'engagement' | 'lifecycle' | 'enps' | 'mood' | 'custom';
export const SURVEY_TYPES: readonly SurveyType[] = ['engagement', 'lifecycle', 'enps', 'mood', 'custom'];
export type QuestionType = 'scale5' | 'scale10' | 'enps' | 'single' | 'multi' | 'text';
export const QUESTION_TYPES: readonly QuestionType[] = ['scale5', 'scale10', 'enps', 'single', 'multi', 'text'];
export type LifecycleTrigger = 'hire_30' | 'hire_90' | 'exit';
export const LIFECYCLE_TRIGGERS: readonly LifecycleTrigger[] = ['hire_30', 'hire_90', 'exit'];
export type WaveSchedule = 'once' | 'weekly' | 'monthly' | 'quarterly';
export const WAVE_SCHEDULES: readonly WaveSchedule[] = ['once', 'weekly', 'monthly', 'quarterly'];
export type WaveStatus = 'scheduled' | 'open' | 'closed';

export interface Question {
  id: string;
  type: QuestionType;
  text: string;
  options?: string[];
  required?: boolean;
}

export interface Survey {
  id: number;
  title: string;
  type: SurveyType;
  description: string | null;
  questions: Question[];
  lifecycle_trigger: LifecycleTrigger | null;
  active: boolean;
  waves_count: number;
}

export type SaveSurvey = Pick<Survey, 'title' | 'type' | 'description' | 'questions' | 'lifecycle_trigger' | 'active'>;

export interface SurveyTemplate {
  key: string;
  title: string;
  type: SurveyType;
  lifecycle_trigger: LifecycleTrigger | null;
  questions: Question[];
}

export interface Wave {
  id: number;
  survey: { id: number; title: string; type: SurveyType };
  parent_wave_id: number | null;
  schedule: WaveSchedule;
  audience: { branch_ids: number[]; department_ids: number[] };
  anonymous: boolean;
  min_group_size: number;
  starts_at: string;
  ends_at: string;
  status: WaveStatus;
  lifecycle: boolean;
  subject_employee_id: number | null;
  responses_count: number;
}

export interface NewWave {
  starts_at: string;
  ends_at: string;
  schedule: WaveSchedule;
  audience: { branch_ids: number[]; department_ids: number[] };
  anonymous: boolean;
  min_group_size: number;
}

/** What a respondent knows about a wave. */
export interface MyWave {
  id: number;
  title: string;
  type: SurveyType;
  anonymous: boolean;
  ends_at: string;
  responded: boolean;
  description?: string | null;
  questions?: Question[];
}

export type AnswerValue = number | number[] | string;

export interface EnpsStats {
  score: number | null;
  promoters: number;
  passives: number;
  detractors: number;
  total: number;
}

export interface QuestionResult {
  id: string;
  type: QuestionType;
  text: string;
  answered: number | null;
  suppressed?: boolean;
  average?: number | null;
  distribution?: Record<string, number>;
  enps?: EnpsStats;
  options?: { label: string; count: number }[];
  texts?: string[];
}

export interface ResultBlock {
  responses: number | null;
  suppressed: boolean;
  questions: QuestionResult[];
}

/** Coarse participation of a wave that is not closed yet (no scores, no texts). */
export interface ParticipationInfo {
  responded_bucket: string;
  responded_percent: number | null;
}

export interface WaveResults extends ResultBlock {
  wave: { id: number; survey: { id: number; title: string; type: SurveyType }; anonymous: boolean; min_group_size: number; starts_at: string; ends_at: string; status: WaveStatus };
  scope: 'all' | 'department';
  state: WaveStatus;
  participation?: ParticipationInfo;
  segments?: (ResultBlock & { segment: number | null; name: string | null })[];
}

export interface CompareCell {
  id: string;
  current: number | null;
  previous: number | null;
  delta: number | null;
}

/** Comparison; only rows/questions once the wave is closed (while open: participation only). */
export interface WaveCompare {
  scope: 'all' | 'department';
  state: WaveStatus;
  segment?: 'branch' | 'department';
  current?: { id: number; starts_at: string };
  previous?: { id: number; starts_at: string } | null;
  questions: { id: string; type: QuestionType; text: string }[];
  rows?: { segment: number | null; name: string | null; questions: CompareCell[]; hidden_reason?: 'anonymity' | null }[];
  participation?: ParticipationInfo;
}

export interface MoodEntry {
  day: string;
  score: number;
  comment: string | null;
}

export interface MoodToday {
  ask: boolean;
  question: string;
  required: boolean;
  has_employee: boolean;
  today: MoodEntry | null;
}

export interface MoodWeek {
  week_start: string;
  respondents: number | null;
  average: number | null;
  distribution: Record<string, number> | null;
  suppressed: boolean;
}

export interface TeamMood {
  team_size: number | null;
  min_group: number;
  coverage: { answered: number; total: number } | null;
  weeks: MoodWeek[];
  comments: string[];
}

export interface MoodSettings {
  weekdays: number[];
  question: string;
  required: boolean;
  alert_drop: number;
  min_group: number;
}

export const PULSE_ERROR_CODES = [
  'no_employee',
  'wave_not_open',
  'not_in_audience',
  'already_responded',
  'invalid_answers',
  'anonymous_wave',
  'has_responses',
] as const;

/** Mood scale 1..5 as emoji (index = score − 1). */
export const MOODS: readonly string[] = ['😞', '😕', '😐', '🙂', '😄'];

export function moodEmoji(score: number | null | undefined): string {
  return score ? (MOODS[Math.round(score) - 1] ?? '—') : '—';
}

/**
 * eNPS gauge: the score (−100..100) as a 0..180° needle angle for a CSS half-circle.
 */
export function enpsAngle(score: number | null | undefined): number {
  if (score === null || score === undefined) {
    return 90;
  }
  return Math.round(((Math.max(-100, Math.min(100, score)) + 100) / 200) * 180);
}

/** Colour band of an eNPS score: < 0 danger, < 30 warning, otherwise success. */
export function enpsTone(score: number | null | undefined): 'danger' | 'warning' | 'success' | 'none' {
  if (score === null || score === undefined) {
    return 'none';
  }
  return score < 0 ? 'danger' : score < 30 ? 'warning' : 'success';
}

export function deltaTone(delta: number | null): 'up' | 'down' | 'flat' | 'none' {
  if (delta === null) {
    return 'none';
  }
  return delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat';
}

/** Largest value of a distribution (for bar widths), at least 1. */
export function maxOf(values: readonly number[]): number {
  return Math.max(1, ...values);
}

/** Numeric range of a question (for the respond form). */
export function questionRange(type: QuestionType): number[] {
  const [min, max] = type === 'scale5' ? [1, 5] : type === 'scale10' ? [1, 10] : type === 'enps' ? [0, 10] : [0, -1];
  return Array.from({ length: Math.max(0, max - min + 1) }, (_, i) => min + i);
}

/** Next free question id: q1, q2, … */
export function nextQuestionId(questions: readonly Question[]): string {
  let n = questions.length + 1;
  const ids = new Set(questions.map((q) => q.id));
  while (ids.has(`q${n}`)) {
    n++;
  }
  return `q${n}`;
}

/** Required questions without an answer. */
export function missingAnswers(questions: readonly Question[], answers: Readonly<Record<string, AnswerValue | undefined>>): string[] {
  return questions
    .filter((q) => q.required)
    .filter((q) => {
      const a = answers[q.id];
      return a === undefined || a === '' || (Array.isArray(a) && a.length === 0);
    })
    .map((q) => q.id);
}

/** Toggles an option index of a multiple-choice answer. */
export function toggleOption(current: AnswerValue | undefined, index: number): number[] {
  const list = Array.isArray(current) ? current : [];
  return list.includes(index) ? list.filter((i) => i !== index) : [...list, index].sort((a, b) => a - b);
}

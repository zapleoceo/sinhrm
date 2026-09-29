/** Mirrors backend App\Modules\Recruiting\Enums\*. */
export type StageKind = 'attract' | 'select' | 'hire' | 'closed';
export type VacancyStatus = 'open' | 'paused' | 'closed';
export const VACANCY_STATUSES: readonly VacancyStatus[] = ['open', 'paused', 'closed'];
export type ApplicationStatus = 'active' | 'hired' | 'rejected';
export const APPLICATION_STATUSES: readonly ApplicationStatus[] = ['active', 'hired', 'rejected'];
export type Channel = 'call' | 'telegram' | 'whatsapp' | 'viber' | 'email' | 'note' | 'meeting' | 'system';
export const CHANNELS: readonly Channel[] = ['call', 'telegram', 'whatsapp', 'viber', 'email', 'note', 'meeting', 'system'];
/** Channels a user logs by hand (composer). */
export const MANUAL_CHANNELS: readonly Channel[] = ['note', 'call', 'meeting', 'telegram', 'whatsapp', 'viber', 'email'];
export type Direction = 'in' | 'out';
export type CandidateSource =
  | 'manual'
  | 'work_ua'
  | 'robota_ua'
  | 'djinni'
  | 'linkedin'
  | 'dou'
  | 'meta_ads'
  | 'site'
  | 'referral'
  | 'telegram'
  | 'import'
  | 'inbox'
  | 'other';
export const CANDIDATE_SOURCES: readonly CandidateSource[] = [
  'manual',
  'work_ua',
  'robota_ua',
  'djinni',
  'linkedin',
  'dou',
  'meta_ads',
  'site',
  'referral',
  'telegram',
  'import',
  'inbox',
  'other',
];

/** Timeline filter value for stage changes (GET /api/candidates/{id}/timeline?channel=stage). */
export const STAGE_FILTER = 'stage';
export type TimelineFilter = Channel | typeof STAGE_FILTER;

export interface Ref {
  id: number;
  name: string;
}

export interface Stage {
  id: number;
  name: string;
  kind: StageKind;
  position: number;
  is_terminal: boolean;
  is_reject: boolean;
  is_hire: boolean;
}

export interface Pipeline {
  id: number;
  name: string;
  is_default: boolean;
  stages: Stage[];
}

export interface RejectReason {
  id: number;
  name: string;
  active: boolean;
}

export interface Vacancy {
  id: number;
  title: string;
  status: VacancyStatus;
  branch_id: number;
  branch: Ref | null;
  department_id: number | null;
  department: Ref | null;
  position_id: number | null;
  position: Ref | null;
  recruiter_id: number;
  recruiter: Ref | null;
  /** Contextual role: sees and works only this vacancy. */
  hiring_manager_id: number | null;
  hiring_manager: Ref | null;
  pipeline_id: number;
  stages: Stage[];
  description: string | null;
  /** Career page (/jobs): shown publicly with public_description. */
  published: boolean;
  slug: string | null;
  public_description: string | null;
  /** Open AND published on /jobs (backend Vacancy::scopeActive). */
  is_active: boolean;
  category_id: number | null;
  category: Ref | null;
  city_id: number | null;
  city: Ref | null;
  country: string | null;
  employment_type: string | null;
  work_format: string | null;
  experience_level: string | null;
  education_level: string | null;
  /** Internal; shown on /jobs only when salary_visible. */
  salary_min: number | null;
  salary_max: number | null;
  salary_currency: string;
  salary_visible: boolean;
  languages: VacancyLanguage[];
  /** Markdown sections (rendered with raw HTML escaped on the server). */
  requirements: string | null;
  responsibilities: string | null;
  additional_info: string | null;
  external_postings: ExternalPosting[];
  applications_count: number;
  active_applications_count: number;
  opened_at: string | null;
  closed_at: string | null;
  created_at: string | null;
}

export interface SaveVacancy {
  title?: string;
  branch_id?: number;
  position_id?: number | null;
  status?: VacancyStatus;
  description?: string | null;
  published?: boolean;
  public_description?: string | null;
  /** Only recruiting writers may send it (the API answers 422 to a hiring manager). */
  hiring_manager_id?: number | null;
  recruiter_id?: number;
  department_id?: number | null;
  /** Create only: the pipeline is fixed once the vacancy exists. */
  pipeline_id?: number;
  category_id?: number | null;
  city_id?: number | null;
  country?: string | null;
  employment_type?: string | null;
  work_format?: string | null;
  experience_level?: string | null;
  education_level?: string | null;
  salary_min?: number | null;
  salary_max?: number | null;
  salary_currency?: string;
  salary_visible?: boolean;
  languages?: VacancyLanguage[];
  requirements?: string | null;
  responsibilities?: string | null;
  additional_info?: string | null;
  external_postings?: ExternalPosting[];
}

export interface VacancyLanguage {
  lang: string;
  level: string;
}

/** Manual reference to an ad on a job site (no integration: these sites have no employer API). */
export interface ExternalPosting {
  site: string;
  url: string | null;
  date: string | null;
}

/** GET /api/vacancy-options: codes; labels are translated (recruiting.form.options.*) or come from Intl. */
export interface VacancyOptions {
  employment_types: string[];
  work_formats: string[];
  experience_levels: string[];
  education_levels: string[];
  languages: string[];
  language_levels: string[];
  countries: string[];
  currencies: string[];
  external_sites: string[];
}

export interface VacancyTemplate {
  id: number;
  name: string;
  data: SaveVacancy;
  created_at: string | null;
}

export type VacancyTextSection = 'description' | 'requirements' | 'responsibilities' | 'additional_info';

/** POST /api/vacancy-text and GET /api/vacancy-text/{id}: an AI draft of one section. */
export interface VacancyTextDraft {
  status: 'done' | 'deferred' | 'failed';
  request_id: number;
  text: string | null;
  error: string | null;
}

export interface CandidateBrief {
  id: number;
  full_name: string;
  phone: string | null;
  email: string | null;
  telegram_username: string | null;
  source: CandidateSource;
}

/** One stage passed on a vacancy, with time spent there (left_at null = current stage). */
export interface RouteStep {
  stage_change_id: number;
  stage_id: number;
  stage_name: string;
  kind: StageKind;
  entered_at: string;
  left_at: string | null;
  duration_sec: number;
  by: Ref | null;
  reason: string | null;
}

export interface Application {
  id: number;
  candidate_id: number;
  vacancy_id: number;
  stage_id: number;
  status: ApplicationStatus;
  reject_reason_id: number | null;
  reject_reason: string | null;
  rejected_note: string | null;
  stage_entered_at: string | null;
  last_touch_at: string | null;
  is_stale: boolean;
  closed_at: string | null;
  created_at: string | null;
  candidate?: CandidateBrief;
  vacancy?: { id: number; title: string; status: VacancyStatus };
  stage?: Stage;
  stages?: Stage[];
  route?: RouteStep[];
  /** Contextual role: who interviews this candidate for this vacancy (card only). */
  interviewers?: Ref[];
}

/** Acquisition channel (tz3; backend Recruiting AcquisitionChannel). utm_rules/costs — managers only. */
export type ChannelType = 'job_board' | 'ads' | 'referral' | 'social' | 'site' | 'event' | 'agency' | 'other';
export const CHANNEL_TYPES: readonly ChannelType[] = ['job_board', 'ads', 'referral', 'social', 'site', 'event', 'agency', 'other'];

/** HOW the record got into SinHRM — separate from the channel (WHERE FROM). */
export type AddedVia = 'manual' | 'import' | 'mail' | 'extension' | 'webhook' | 'sheets';

export interface UtmRule {
  id: number;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  priority: number;
}

export interface ChannelCost {
  id: number;
  period_start: string;
  period_end: string;
  amount: number;
  currency: string;
  note: string | null;
}

export interface AcquisitionChannel {
  id: number;
  code: string;
  name: string;
  type: ChannelType;
  active: boolean;
  utm_rules?: UtmRule[];
  costs?: ChannelCost[];
}

/** GET /api/vacancies/{id}/sources — the vacancy card block "where applicants came from". */
export interface VacancySourceRow {
  channel_id: number | null;
  name: string | null;
  added_via: AddedVia | null;
  count: number;
  share_pct: number;
}

export interface Candidate extends CandidateBrief {
  /** Personal data erased (Privacy): name is "Видалений кандидат #id", contacts are empty. */
  anonymized_at?: string | null;
  channel_id: number | null;
  channel: { id: number; code: string; name: string; type: ChannelType } | null;
  added_via: AddedVia | null;
  city_id: number | null;
  city: Ref | null;
  utm: Record<string, string>;
  tags: string[];
  owner_id: number | null;
  owner: Ref | null;
  applications: Application[];
  created_at: string | null;
  updated_at: string | null;
}

export interface SaveCandidate {
  full_name?: string;
  phone?: string | null;
  email?: string | null;
  telegram_username?: string | null;
  source?: CandidateSource;
  channel_id?: number | null;
  tags?: string[];
  vacancy_id?: number | null;
}

export interface Touchpoint {
  id: number;
  candidate_id: number | null;
  application_id: number | null;
  channel: Channel;
  direction: Direction;
  author: Ref | null;
  occurred_at: string;
  body: string | null;
  meta: TouchpointMeta;
  via_product: boolean;
  integration_key: string | null;
  /** Script evaluation (timeline only; filled by the Scripts module), null = not evaluated. */
  evaluation?: EvaluationSummary | null;
}

/** Public meta of a touchpoint (backend TouchpointResource::PUBLIC_META). */
export interface TouchpointMeta {
  duration_sec?: number;
  recording_url?: string;
  contact?: string;
  /** e-mail captured by the mail agent */
  subject?: string;
  from?: string;
  parser?: string;
  full_name?: string;
  vacancy_title?: string;
  cv_url?: string;
  /** meeting scheduled in Google Calendar */
  event_id?: string;
  meet_link?: string;
  html_link?: string;
  start?: string;
  end?: string;
  meeting_type?: 'branch' | 'online';
  title?: string;
  /** channels (Channels module): sender name, telephony call status, edited message, recorded in demo mode */
  sender_name?: string;
  call_status?: string;
  edited?: boolean;
  demo?: boolean;
}

/** Short script evaluation on a timeline touchpoint (backend Scripts ScriptEvaluation::summary). */
export interface EvaluationSummary {
  id: number;
  score: number;
  engine: 'rules' | 'ai';
  next_step_fixed: boolean;
  script_version_id: number;
}

export interface StageChangeItem {
  id: number;
  application_id: number;
  vacancy: { id: number; title: string };
  from_stage: Ref | null;
  to_stage: { id: number; name: string; kind: StageKind };
  by: Ref | null;
  reason: string | null;
}

export type TimelineItem =
  | { type: 'touchpoint'; at: string; touchpoint: Touchpoint }
  | { type: 'stage_change'; at: string; stage_change: StageChangeItem };

export interface LogTouch {
  channel: Channel;
  direction?: Direction;
  body?: string;
  occurred_at?: string;
  duration_sec?: number;
  application_id?: number;
}

export interface MoveApplication {
  stage_id: number;
  reason?: string;
  reject_reason_id?: number;
}

export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

/** GET /api/vacancies meta: + active vacancies (open AND published) in the user's scope, for the list header. */
export interface VacancyPageMeta extends PageMeta {
  active_count?: number;
}

export interface Paged<T> {
  data: T[];
  meta: PageMeta;
}

export interface Board {
  vacancy: Vacancy;
  applications: Application[];
}

export interface VacancyQuery {
  q?: string;
  status?: VacancyStatus;
  /** Only open AND published vacancies. */
  active?: boolean;
  branch_id?: number;
  page?: number;
  perPage?: number;
}

export interface CandidateQuery {
  q?: string;
  vacancy_id?: number;
  status?: ApplicationStatus;
  source?: CandidateSource;
  channel_id?: number;
  page?: number;
  perPage?: number;
}

export interface DateRange {
  from: string;
  to: string;
}

export interface TouchesRow {
  author_id: number | null;
  author_name: string | null;
  channel: Channel;
  via_product: boolean;
  count: number;
}

export interface FunnelRow {
  vacancy_id: number;
  vacancy_title: string;
  stage_id: number;
  stage_name: string;
  stage_kind: StageKind;
  position: number;
  count: number;
}

export interface SourcesRow {
  source: CandidateSource;
  candidates: number;
  hired: number;
}

export interface RejectReasonsRow {
  reject_reason_id: number | null;
  name: string | null;
  count: number;
}

export interface Report<Row, Totals> {
  range: DateRange;
  rows: Row[];
  totals: Totals;
}

export type TouchesReport = Report<TouchesRow, { total: number; via_product: number; captured: number }>;
export type FunnelReport = Report<FunnelRow, { total: number }>;
export type SourcesReport = Report<SourcesRow, { candidates: number; hired: number }>;
export type RejectReasonsReport = Report<RejectReasonsRow, { total: number }>;

/** Error codes with their own message (backend RecruitingException). */
export const RECRUITING_ERROR_CODES = [
  'duplicate_candidate',
  'already_applied',
  'stage_not_in_pipeline',
  'board_column_limit',
  'board_column_mismatch',
  'same_stage',
  'reject_reason_required',
  'application_mismatch',
  'no_default_pipeline',
  'vacancy_out_of_scope',
  'already_linked',
  'channel_inactive',
] as const;

/** Body of 409 duplicate_candidate: the existing candidate to open instead. */
export interface DuplicateCandidate {
  code: 'duplicate_candidate';
  existing_id: number;
  matched_by: 'phone' | 'email' | 'telegram';
}

/** AI screening verdict, derived from the score on the server (fit ≥ 70, maybe ≥ 40). */
export type ScreeningVerdict = 'fit' | 'maybe' | 'no';

/** AI screening of an application (tz6). Advisory only: shown as "Оцінка ШІ, рішення за людиною". */
export interface Screening {
  id: number;
  application_id: number;
  vacancy: { id: number; title: string | null };
  status: 'pending' | 'done' | 'failed';
  trigger: 'manual' | 'auto';
  score: number | null;
  verdict: ScreeningVerdict | null;
  summary: string | null;
  strengths: string[];
  gaps: string[];
  questions: string[];
  error: string | null;
  prompt_version: string;
  advisory: true;
  created_at: string | null;
  completed_at: string | null;
}

/** POST /api/candidates/bulk — one result per id (the same rules as the single action). */
export type CandidateBulkAction = 'move' | 'reject' | 'tag' | 'assign';

export interface CandidateBulkBody {
  action: CandidateBulkAction;
  ids: number[];
  vacancy_id?: number;
  stage_id?: number;
  reject_reason_id?: number;
  tag?: string;
  owner_id?: number;
}

export interface BulkResult {
  id: number;
  ok: boolean;
  error: string | null;
}

/** Palette of personal board columns (mapped to theme tokens in the board styles). */
export const PERSONAL_COLORS = ['blue', 'green', 'amber', 'red', 'purple', 'grey'] as const;
export type PersonalColor = (typeof PERSONAL_COLORS)[number];

/** A user's own column on the /candidates board: a personal bucket, never a funnel stage. */
export interface PersonalColumn {
  id: number;
  title: string;
  color: PersonalColor | null;
  position: number;
  hidden: boolean;
}

/** Own columns of one vacancy + where the user filed cards (application → own column). */
export interface PersonalBoard {
  columns: PersonalColumn[];
  cards: { application_id: number; column_id: number }[];
}

export type SavePersonalColumn = Partial<Pick<PersonalColumn, 'title' | 'color' | 'hidden'>>;

/** Mirrors backend App\Modules\Ai\Enums\AiPurpose (switchable purposes). */
export type AiPurpose = 'script_evaluation' | 'mail_classification' | 'candidate_screening';
export const AI_PURPOSES: readonly AiPurpose[] = [
  'script_evaluation',
  'mail_classification',
  'candidate_screening',
];

/** Why AI cannot run for a purpose (null = it can). */
export type AiUnavailable = 'ai_disabled' | 'ai_not_configured' | 'ai_purpose_disabled';

/** GET /api/ai/status (superadmin). */
export interface AiStatus {
  provider: string;
  capability: string;
  capabilities: Partial<Record<AiPurpose | 'test', string>>;
  /** null = the broker picks the model. */
  model: string | null;
  configured: boolean;
  available: boolean;
  purposes: Record<AiPurpose, AiUnavailable | null>;
  /** Active edited prompt version per purpose (null = built-in). */
  prompt_versions?: Partial<Record<AiPurpose, string | null>>;
  auto_screening: boolean;
  usage: {
    requests: number;
    cost_usd: number;
    tokens_in: number;
    tokens_out: number;
    tokens_cached: number;
  };
  limits: { requests: number; cost_usd: number };
}

/** POST /api/ai/test. */
export interface AiTestResult {
  status: 'done' | 'deferred' | 'failed';
  error: string | null;
  reply: string | null;
  request_id: number;
  model: string | null;
  tokens_in: number;
  tokens_out: number;
  tokens_cached: number;
  cost_usd: number;
}

/** Error codes of the Ai module translated under ai.errors.* (ai_provider_http_401 etc. → ai_provider). */
export const AI_ERROR_CODES = [
  'ai_disabled',
  'ai_not_configured',
  'ai_purpose_disabled',
  'ai_budget_exceeded',
  'ai_invalid_output',
  'ai_timeout',
  'ai_provider',
  'insufficient_data',
] as const;
export type AiErrorCode = (typeof AI_ERROR_CODES)[number];

/** Share of a daily limit used, 0..100 (for the usage bars). */
export function usagePercent(used: number, limit: number): number {
  if (limit <= 0) {
    return 0;
  }
  return Math.min(100, Math.round((used / limit) * 100));
}

/** Stats period of the panel (one toggle for all rows). */
export type AiStatsPeriod = 'today' | '7d' | '30d';
export const AI_STATS_PERIODS: readonly AiStatsPeriod[] = ['today', '7d', '30d'];

/** Per-purpose figures of GET /api/ai/stats. */
export interface AiPurposeStats {
  requests: number;
  done: number;
  failed: number;
  pending: number;
  /** error code → count, most frequent first */
  errors: Record<string, number>;
  /** 0..100 of finished requests; null when nothing finished */
  success_pct: number | null;
  avg_latency_s: number | null;
  tokens_in: number;
  tokens_out: number;
  tokens_cached: number;
  cost_usd: number;
  /** requests per hour (today) or per day (7d/30d), oldest first */
  series: number[];
}

/** GET /api/ai/stats?period=… (superadmin). A purpose without requests in the period is null. */
export interface AiStats {
  period: AiStatsPeriod;
  since: string;
  bucket: 'hour' | 'day';
  purposes: Partial<Record<AiPurpose | 'test' | 'prompt_trial', AiPurposeStats | null>>;
}

/** One edited prompt version (ai_prompt_versions). */
export interface AiPromptVersion {
  id: number;
  version: string;
  base_version: string;
  body: string;
  is_active: boolean;
  author: string | null;
  created_at: string | null;
  activated_at: string | null;
}

/** GET /api/ai/prompts/{purpose}: the effective instruction text and its versions. */
export interface AiPromptInfo {
  purpose: AiPurpose;
  builtin_version: string;
  builtin_body: string;
  /** Code-owned OUTPUT line (read-only). */
  output: string;
  /** null = the built-in prompt is used. */
  active_version: string | null;
  version: string;
  body: string;
  capability: string;
  capabilities: string[];
  limits: { min: number; max: number };
  versions: AiPromptVersion[];
}

/** One side of POST /api/ai/prompts/{purpose}/trial. */
export interface AiTrialSide {
  status: 'done' | 'deferred' | 'failed';
  error: string | null;
  version: string;
  data: Record<string, unknown> | null;
  request_id?: number;
  tokens_in?: number;
  tokens_out?: number;
  tokens_cached?: number;
  cost_usd?: number;
}

export interface AiTrialResult {
  draft: AiTrialSide;
  active: AiTrialSide;
}

/** Validation codes of an edited prompt (backend PromptOverrides::problems). */
export const AI_PROMPT_PROBLEMS = [
  'too_short',
  'too_long',
  'missing_role',
  'missing_task',
  'missing_rules',
  'order',
  'output_not_editable',
  'volatile_data',
] as const;

/** Tiny sparkline path (viewBox 0 0 width height) of a series; '' when there is nothing to draw. */
export function sparklinePath(series: readonly number[], width = 60, height = 16): string {
  if (series.length < 2) {
    return '';
  }
  const max = Math.max(...series, 1);
  const step = width / (series.length - 1);
  return series
    .map(
      (v, i) =>
        `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(height - 1 - (v / max) * (height - 2)).toFixed(1)}`,
    )
    .join(' ');
}

/** "ai_timeout: 2, ai_invalid_output: 1" for a tooltip; '' when there are no errors. */
export function errorsTooltip(errors: Record<string, number>): string {
  return Object.entries(errors)
    .map(([code, n]) => `${code}: ${n}`)
    .join(', ');
}

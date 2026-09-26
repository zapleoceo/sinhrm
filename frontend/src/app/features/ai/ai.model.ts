/** Mirrors backend App\Modules\Ai\Enums\AiPurpose (switchable purposes). */
export type AiPurpose = 'script_evaluation' | 'mail_classification' | 'candidate_screening';
export const AI_PURPOSES: readonly AiPurpose[] = ['script_evaluation', 'mail_classification', 'candidate_screening'];

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
  auto_screening: boolean;
  usage: { requests: number; cost_usd: number; tokens_in: number; tokens_out: number; tokens_cached: number };
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
] as const;
export type AiErrorCode = (typeof AI_ERROR_CODES)[number];

/** Share of a daily limit used, 0..100 (for the usage bars). */
export function usagePercent(used: number, limit: number): number {
  if (limit <= 0) {
    return 0;
  }
  return Math.min(100, Math.round((used / limit) * 100));
}

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AI_ERROR_CODES,
  AiPromptInfo,
  AiPurpose,
  AiStats,
  AiStatsPeriod,
  AiStatus,
  AiTestResult,
  AiTrialResult,
} from './ai.model';
import { apiErrorCode, apiErrorStatus } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/ai';

/** Superadmin AI status/usage and the test prompt. */
@Injectable({ providedIn: 'root' })
export class AiService {
  private readonly http = inject(HttpClient);

  status(): Observable<AiStatus> {
    return this.http.get<DataEnvelope<AiStatus>>(`${API}/status`).pipe(unwrapData());
  }

  test(): Observable<AiTestResult> {
    return this.http.post<DataEnvelope<AiTestResult>>(`${API}/test`, {}).pipe(unwrapData());
  }

  stats(period: AiStatsPeriod): Observable<AiStats> {
    return this.http
      .get<DataEnvelope<AiStats>>(`${API}/stats`, { params: { period } })
      .pipe(unwrapData());
  }

  prompt(purpose: AiPurpose): Observable<AiPromptInfo> {
    return this.http
      .get<DataEnvelope<AiPromptInfo>>(`${API}/prompts/${purpose}`)
      .pipe(unwrapData());
  }

  /** Saves the text as a new active version. */
  savePrompt(purpose: AiPurpose, body: string): Observable<AiPromptInfo> {
    return this.http
      .post<DataEnvelope<AiPromptInfo>>(`${API}/prompts/${purpose}`, { body })
      .pipe(unwrapData());
  }

  activatePrompt(purpose: AiPurpose, versionId: number): Observable<AiPromptInfo> {
    return this.http
      .post<DataEnvelope<AiPromptInfo>>(`${API}/prompts/${purpose}/versions/${versionId}/activate`, {})
      .pipe(unwrapData());
  }

  restoreBuiltinPrompt(purpose: AiPurpose): Observable<AiPromptInfo> {
    return this.http
      .post<DataEnvelope<AiPromptInfo>>(`${API}/prompts/${purpose}/builtin`, {})
      .pipe(unwrapData());
  }

  setCapability(purpose: AiPurpose, capability: string): Observable<AiPromptInfo> {
    return this.http
      .put<DataEnvelope<AiPromptInfo>>(`${API}/prompts/${purpose}/capability`, { capability })
      .pipe(unwrapData());
  }

  /** Runs the draft and the active version on the built-in synthetic sample. */
  tryPrompt(purpose: AiPurpose, body: string): Observable<AiTrialResult> {
    return this.http
      .post<DataEnvelope<AiTrialResult>>(`${API}/prompts/${purpose}/trial`, { body })
      .pipe(unwrapData());
  }
}

/** i18n key of an AI error code ("ai_provider_http_401" → ai.errors.ai_provider), null when it is not an AI code. */
export function aiCodeKey(code: string | null | undefined): string | null {
  if (!code) {
    return null;
  }
  const normalized = code.startsWith('ai_provider') ? 'ai_provider' : code;
  return (AI_ERROR_CODES as readonly string[]).includes(normalized)
    ? `ai.errors.${normalized}`
    : null;
}

/** i18n key of a failed AI call (any HTTP error). */
export function aiErrorKey(error: unknown): string {
  return aiCodeKey(apiErrorCode(error)) ?? (apiErrorStatus(error) === 429 ? 'ai.errors.throttled' : 'ai.errors.generic');
}

/** AI refusals of the text generator (vacancy sections) shown as a hint under the section (texts from ai.errors.*). */
const AI_TEXT_ERRORS: readonly string[] = ['ai_disabled', 'ai_not_configured', 'ai_purpose_disabled', 'ai_budget_exceeded', 'ai_timeout', 'ai_invalid_output'];

/**
 * i18n key of a failed AI text generation (vacancy sections): any 429 (endpoint throttle or the AI budget) reads as
 * «throttled»; otherwise the refusal code of the API answer or of the finished request (a plain string code).
 * Unlike aiErrorKey, provider codes are not shown — the hint under a section stays generic.
 */
export function aiTextErrorKey(error: unknown): string {
  if (apiErrorStatus(error) === 429) {
    return 'ai.errors.throttled';
  }
  const code = typeof error === 'string' ? error : apiErrorCode(error);
  return code !== null && AI_TEXT_ERRORS.includes(code) ? `ai.errors.${code}` : 'ai.errors.generic';
}

/** i18n keys of prompt validation errors (422 errors.body = codes), empty when it is not a validation error. */
export function promptProblemKeys(error: unknown): string[] {
  if (error instanceof HttpErrorResponse && error.status === 422) {
    const codes: unknown = (error.error as { errors?: { body?: unknown } } | null)?.errors?.body;
    if (Array.isArray(codes)) {
      return codes
        .filter((c): c is string => typeof c === 'string')
        .map((c) => `ai.prompt.problems.${c}`);
    }
  }
  return [];
}

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
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

const API = '/api/ai';

/** Superadmin AI status/usage and the test prompt. */
@Injectable({ providedIn: 'root' })
export class AiService {
  private readonly http = inject(HttpClient);

  status(): Observable<AiStatus> {
    return this.http.get<{ data: AiStatus }>(`${API}/status`).pipe(map((r) => r.data));
  }

  test(): Observable<AiTestResult> {
    return this.http.post<{ data: AiTestResult }>(`${API}/test`, {}).pipe(map((r) => r.data));
  }

  stats(period: AiStatsPeriod): Observable<AiStats> {
    return this.http
      .get<{ data: AiStats }>(`${API}/stats`, { params: { period } })
      .pipe(map((r) => r.data));
  }

  prompt(purpose: AiPurpose): Observable<AiPromptInfo> {
    return this.http
      .get<{ data: AiPromptInfo }>(`${API}/prompts/${purpose}`)
      .pipe(map((r) => r.data));
  }

  /** Saves the text as a new active version. */
  savePrompt(purpose: AiPurpose, body: string): Observable<AiPromptInfo> {
    return this.http
      .post<{ data: AiPromptInfo }>(`${API}/prompts/${purpose}`, { body })
      .pipe(map((r) => r.data));
  }

  activatePrompt(purpose: AiPurpose, versionId: number): Observable<AiPromptInfo> {
    return this.http
      .post<{ data: AiPromptInfo }>(`${API}/prompts/${purpose}/versions/${versionId}/activate`, {})
      .pipe(map((r) => r.data));
  }

  restoreBuiltinPrompt(purpose: AiPurpose): Observable<AiPromptInfo> {
    return this.http
      .post<{ data: AiPromptInfo }>(`${API}/prompts/${purpose}/builtin`, {})
      .pipe(map((r) => r.data));
  }

  setCapability(purpose: AiPurpose, capability: string): Observable<AiPromptInfo> {
    return this.http
      .put<{ data: AiPromptInfo }>(`${API}/prompts/${purpose}/capability`, { capability })
      .pipe(map((r) => r.data));
  }

  /** Runs the draft and the active version on the built-in synthetic sample. */
  tryPrompt(purpose: AiPurpose, body: string): Observable<AiTrialResult> {
    return this.http
      .post<{ data: AiTrialResult }>(`${API}/prompts/${purpose}/trial`, { body })
      .pipe(map((r) => r.data));
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
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    const key = typeof code === 'string' ? aiCodeKey(code) : null;
    if (key) {
      return key;
    }
    if (error.status === 429) {
      return 'ai.errors.throttled';
    }
  }
  return 'ai.errors.generic';
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

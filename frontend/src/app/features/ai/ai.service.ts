import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { AI_ERROR_CODES, AiStatus, AiTestResult } from './ai.model';

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
}

/** i18n key of an AI error code ("ai_provider_http_401" → ai.errors.ai_provider), null when it is not an AI code. */
export function aiCodeKey(code: string | null | undefined): string | null {
  if (!code) {
    return null;
  }
  const normalized = code.startsWith('ai_provider') ? 'ai_provider' : code;
  return (AI_ERROR_CODES as readonly string[]).includes(normalized) ? `ai.errors.${normalized}` : null;
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

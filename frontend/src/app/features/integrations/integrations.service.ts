import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  AiPolicy,
  CHECK_RESULT_CODES,
  INTEGRATION_ERROR_CODES,
  Integration,
  IntegrationField,
  IntegrationLog,
  IntegrationsList,
  ManualStatus,
  UpdateIntegration,
} from './integrations.model';

const API = '/api/integrations';

@Injectable({ providedIn: 'root' })
export class IntegrationsService {
  private readonly http = inject(HttpClient);

  list(): Observable<IntegrationsList> {
    return this.http.get<IntegrationsList>(API);
  }

  update(key: string, body: UpdateIntegration): Observable<Integration> {
    return this.http.put<{ data: Integration }>(`${API}/${key}`, body).pipe(map((r) => r.data));
  }

  check(key: string): Observable<Integration> {
    return this.http.post<{ data: Integration }>(`${API}/${key}/check`, {}).pipe(map((r) => r.data));
  }

  setStatus(key: string, status: ManualStatus): Observable<Integration> {
    return this.http.post<{ data: Integration }>(`${API}/${key}/status`, { status }).pipe(map((r) => r.data));
  }

  logs(key: string): Observable<IntegrationLog[]> {
    return this.http.get<{ data: IntegrationLog[] }>(`${API}/${key}/logs`).pipe(map((r) => r.data));
  }

  setAiPolicy(enabled: boolean): Observable<AiPolicy> {
    return this.http.put<{ data: AiPolicy }>(`${API}/ai-policy`, { enabled }).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed integrations API call. */
export function integrationErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (INTEGRATION_ERROR_CODES as readonly string[]).includes(code)) {
      return `integrations.errors.${code}`;
    }
  }
  return 'integrations.errors.generic';
}

/** i18n key for last_error ("missing_secret:bot_token" → integrations.check.missing_secret), null if unknown. */
export function checkResultKey(code: string | null): string | null {
  if (!code) {
    return null;
  }
  const normalized = /^http_\d+$/.test(code) ? 'http' : code.split(':')[0];
  return (CHECK_RESULT_CODES as readonly string[]).includes(normalized) ? `integrations.check.${normalized}` : null;
}

/**
 * PUT body from the form state. Settings are sent in full; a secret is sent only when typed (set)
 * or marked for clearing (null). An empty secret input means "keep the stored one".
 */
export function buildUpdate(
  fields: readonly IntegrationField[],
  values: Readonly<Record<string, string>>,
  cleared: ReadonlySet<string>,
): UpdateIntegration {
  const settings: Record<string, string> = {};
  const secrets: Record<string, string | null> = {};
  for (const field of fields) {
    const value = values[field.name] ?? '';
    if (field.type !== 'secret') {
      settings[field.name] = value;
    } else if (cleared.has(field.name)) {
      secrets[field.name] = null;
    } else if (value !== '') {
      secrets[field.name] = value;
    }
  }
  const body: UpdateIntegration = {};
  if (Object.keys(settings).length > 0) {
    body.settings = settings;
  }
  if (Object.keys(secrets).length > 0) {
    body.secrets = secrets;
  }
  return body;
}

import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AiPolicy,
  CHECK_RESULT_CODES,
  INTEGRATION_ERROR_CODES,
  Integration,
  IntegrationField,
  IntegrationLog,
  IntegrationsList,
  EmployeeDirectoryStatus,
  EmployeeDirectorySyntheticPreview,
  ManualStatus,
  UpdateIntegration,
} from './integrations.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/integrations';

@Injectable({ providedIn: 'root' })
export class IntegrationsService {
  private readonly http = inject(HttpClient);

  list(): Observable<IntegrationsList> {
    return this.http.get<IntegrationsList>(API);
  }

  update(key: string, body: UpdateIntegration): Observable<Integration> {
    return this.http.put<DataEnvelope<Integration>>(`${API}/${key}`, body).pipe(unwrapData());
  }

  check(key: string): Observable<Integration> {
    return this.http.post<DataEnvelope<Integration>>(`${API}/${key}/check`, {}).pipe(unwrapData());
  }

  setStatus(key: string, status: ManualStatus): Observable<Integration> {
    return this.http.post<DataEnvelope<Integration>>(`${API}/${key}/status`, { status }).pipe(unwrapData());
  }

  logs(key: string): Observable<IntegrationLog[]> {
    return this.http.get<DataEnvelope<IntegrationLog[]>>(`${API}/${key}/logs`).pipe(unwrapData());
  }

  setAiPolicy(enabled: boolean): Observable<AiPolicy> {
    return this.http.put<DataEnvelope<AiPolicy>>(`${API}/ai-policy`, { enabled }).pipe(unwrapData());
  }

  employeeDirectoryStatus(): Observable<DataEnvelope<EmployeeDirectoryStatus>> {
    return this.http.get<DataEnvelope<EmployeeDirectoryStatus>>(`${API}/itstep-directory/status`);
  }

  employeeDirectorySyntheticPreview(): Observable<EmployeeDirectorySyntheticPreview> {
    return this.http.get<EmployeeDirectorySyntheticPreview>(`${API}/itstep-directory/synthetic-preview`);
  }
}

/** i18n key for a failed integrations API call. */
export function integrationErrorKey(error: unknown): string {
  return apiErrorKey(error, 'integrations', INTEGRATION_ERROR_CODES, { statuses: [], fallback: 'integrations.errors.generic' });
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

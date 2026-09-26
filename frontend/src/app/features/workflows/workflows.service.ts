import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import { RunQuery, SaveTemplate, StepCommand, StepOutcome, WORKFLOW_ERROR_CODES, WorkflowRun, WorkflowTemplate } from './workflows.model';

const API = '/api/workflows';

/** HTTP client of the Workflows API (/api/workflows/templates, /api/workflows/runs). */
@Injectable({ providedIn: 'root' })
export class WorkflowsService {
  private readonly http = inject(HttpClient);

  templates(): Observable<WorkflowTemplate[]> {
    return this.http.get<{ data: WorkflowTemplate[] }>(`${API}/templates`).pipe(map((r) => r.data));
  }

  template(id: number): Observable<WorkflowTemplate> {
    return this.http.get<{ data: WorkflowTemplate }>(`${API}/templates/${id}`).pipe(map((r) => r.data));
  }

  createTemplate(body: SaveTemplate): Observable<WorkflowTemplate> {
    return this.http.post<{ data: WorkflowTemplate }>(`${API}/templates`, body).pipe(map((r) => r.data));
  }

  updateTemplate(id: number, body: SaveTemplate): Observable<WorkflowTemplate> {
    return this.http.put<{ data: WorkflowTemplate }>(`${API}/templates/${id}`, body).pipe(map((r) => r.data));
  }

  reorderSteps(id: number, ids: readonly number[]): Observable<WorkflowTemplate> {
    return this.http.post<{ data: WorkflowTemplate }>(`${API}/templates/${id}/steps/reorder`, { ids }).pipe(map((r) => r.data));
  }

  deleteTemplate(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/templates/${id}`);
  }

  /** null clears the signing key; the key itself is never returned. */
  setWebhookSecret(id: number, secret: string | null): Observable<WorkflowTemplate> {
    return this.http.put<{ data: WorkflowTemplate }>(`${API}/templates/${id}/webhook-secret`, { secret }).pipe(map((r) => r.data));
  }

  runs(query: RunQuery = {}): Observable<WorkflowRun[]> {
    return this.http.get<{ data: WorkflowRun[] }>(`${API}/runs`, { params: toParams({ ...query }) }).pipe(map((r) => r.data));
  }

  run(id: number): Observable<WorkflowRun> {
    return this.http.get<{ data: WorkflowRun }>(`${API}/runs/${id}`).pipe(map((r) => r.data));
  }

  startRun(templateId: number, employeeId: number, anchorDate?: string): Observable<WorkflowRun> {
    const body = { template_id: templateId, employee_id: employeeId, ...(anchorDate ? { anchor_date: anchorDate } : {}) };
    return this.http.post<{ data: WorkflowRun }>(`${API}/runs`, body).pipe(map((r) => r.data));
  }

  cancelRun(id: number): Observable<WorkflowRun> {
    return this.http.post<{ data: WorkflowRun }>(`${API}/runs/${id}/cancel`, {}).pipe(map((r) => r.data));
  }

  stepCommand(runId: number, stepId: number, command: StepCommand, reason?: string): Observable<StepOutcome> {
    const body = command === 'skip' && reason ? { reason } : {};
    return this.http.post<{ data: StepOutcome }>(`${API}/runs/${runId}/steps/${stepId}/${command}`, body).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Workflows API call. */
export function workflowsErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (WORKFLOW_ERROR_CODES as readonly string[]).includes(code)) {
      return `workflows.errors.${code}`;
    }
    if (error.status === 403) {
      return 'workflows.errors.forbidden';
    }
    if (error.status === 404) {
      return 'workflows.errors.not_found';
    }
    if (error.status === 422) {
      return 'workflows.errors.validation';
    }
  }
  return 'common.error';
}

/** First server validation message for a field path (e.g. steps.0.config.url), if any. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof HttpErrorResponse) || error.status !== 422) {
    return {};
  }
  const errors: unknown = (error.error as { errors?: unknown } | null)?.errors;
  if (errors === null || typeof errors !== 'object') {
    return {};
  }
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(errors as Record<string, unknown>)) {
    if (Array.isArray(value) && typeof value[0] === 'string') {
      out[key] = value[0];
    }
  }
  return out;
}

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { toParams } from '../../core/api/http-params';
import { RunQuery, SaveTemplate, StepCommand, StepOutcome, WORKFLOW_ERROR_CODES, WorkflowRun, WorkflowTemplate } from './workflows.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/workflows';

/** HTTP client of the Workflows API (/api/workflows/templates, /api/workflows/runs). */
@Injectable({ providedIn: 'root' })
export class WorkflowsService {
  private readonly http = inject(HttpClient);

  templates(): Observable<WorkflowTemplate[]> {
    return this.http.get<DataEnvelope<WorkflowTemplate[]>>(`${API}/templates`).pipe(unwrapData());
  }

  template(id: number): Observable<WorkflowTemplate> {
    return this.http.get<DataEnvelope<WorkflowTemplate>>(`${API}/templates/${id}`).pipe(unwrapData());
  }

  createTemplate(body: SaveTemplate): Observable<WorkflowTemplate> {
    return this.http.post<DataEnvelope<WorkflowTemplate>>(`${API}/templates`, body).pipe(unwrapData());
  }

  updateTemplate(id: number, body: SaveTemplate): Observable<WorkflowTemplate> {
    return this.http.put<DataEnvelope<WorkflowTemplate>>(`${API}/templates/${id}`, body).pipe(unwrapData());
  }

  reorderSteps(id: number, ids: readonly number[]): Observable<WorkflowTemplate> {
    return this.http.post<DataEnvelope<WorkflowTemplate>>(`${API}/templates/${id}/steps/reorder`, { ids }).pipe(unwrapData());
  }

  deleteTemplate(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/templates/${id}`);
  }

  /** null clears the signing key; the key itself is never returned. */
  setWebhookSecret(id: number, secret: string | null): Observable<WorkflowTemplate> {
    return this.http.put<DataEnvelope<WorkflowTemplate>>(`${API}/templates/${id}/webhook-secret`, { secret }).pipe(unwrapData());
  }

  runs(query: RunQuery = {}): Observable<WorkflowRun[]> {
    return this.http.get<DataEnvelope<WorkflowRun[]>>(`${API}/runs`, { params: toParams({ ...query }) }).pipe(unwrapData());
  }

  run(id: number): Observable<WorkflowRun> {
    return this.http.get<DataEnvelope<WorkflowRun>>(`${API}/runs/${id}`).pipe(unwrapData());
  }

  startRun(templateId: number, employeeId: number, anchorDate?: string): Observable<WorkflowRun> {
    const body = { template_id: templateId, employee_id: employeeId, ...(anchorDate ? { anchor_date: anchorDate } : {}) };
    return this.http.post<DataEnvelope<WorkflowRun>>(`${API}/runs`, body).pipe(unwrapData());
  }

  cancelRun(id: number): Observable<WorkflowRun> {
    return this.http.post<DataEnvelope<WorkflowRun>>(`${API}/runs/${id}/cancel`, {}).pipe(unwrapData());
  }

  stepCommand(runId: number, stepId: number, command: StepCommand, reason?: string): Observable<StepOutcome> {
    const body = command === 'skip' && reason ? { reason } : {};
    return this.http.post<DataEnvelope<StepOutcome>>(`${API}/runs/${runId}/steps/${stepId}/${command}`, body).pipe(unwrapData());
  }
}

/** i18n key for a failed Workflows API call. */
export function workflowsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'workflows', WORKFLOW_ERROR_CODES, { statuses: [403, 404, 422] });
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

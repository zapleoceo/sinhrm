import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import {
  CandidateTemplate,
  EvaluationDetails,
  SCRIPT_ERROR_CODES,
  Script,
  ScriptChannel,
  ScriptContent,
  ScriptDetails,
  ScriptVersion,
  Task,
  TaskQuery,
  TouchEvaluation,
} from './scripts.model';

/** HTTP client of the Scripts API (/api/scripts, /candidates/{id}/templates, /touchpoints/{id}/evaluation, /tasks). */
@Injectable({ providedIn: 'root' })
export class ScriptsService {
  private readonly http = inject(HttpClient);

  list(withArchived = false): Observable<Script[]> {
    return this.http
      .get<{ data: Script[] }>('/api/scripts', { params: toParams({ archived: withArchived ? 1 : undefined }) })
      .pipe(map((r) => r.data));
  }

  get(id: number): Observable<ScriptDetails> {
    return this.http.get<{ data: ScriptDetails }>(`/api/scripts/${id}`).pipe(map((r) => r.data));
  }

  create(name: string, channel: ScriptChannel): Observable<ScriptDetails> {
    return this.http.post<{ data: ScriptDetails }>('/api/scripts', { name, channel }).pipe(map((r) => r.data));
  }

  update(id: number, body: { name?: string; archived?: boolean }): Observable<ScriptDetails> {
    return this.http.patch<{ data: ScriptDetails }>(`/api/scripts/${id}`, body).pipe(map((r) => r.data));
  }

  saveDraft(id: number, content: ScriptContent): Observable<ScriptVersion> {
    return this.http.put<{ data: ScriptVersion }>(`/api/scripts/${id}/draft`, content).pipe(map((r) => r.data));
  }

  publish(id: number): Observable<ScriptDetails> {
    return this.http.post<{ data: ScriptDetails }>(`/api/scripts/${id}/publish`, {}).pipe(map((r) => r.data));
  }

  activate(id: number, version: number): Observable<ScriptDetails> {
    return this.http.post<{ data: ScriptDetails }>(`/api/scripts/${id}/activate/${version}`, {}).pipe(map((r) => r.data));
  }

  versions(id: number): Observable<{ versions: ScriptVersion[]; activeVersionId: number | null }> {
    return this.http
      .get<{ data: ScriptVersion[]; meta: { active_version_id: number | null } }>(`/api/scripts/${id}/versions`)
      .pipe(map((r) => ({ versions: r.data, activeVersionId: r.meta.active_version_id })));
  }

  test(id: number, text: string, version: 'draft' | 'active'): Observable<EvaluationDetails> {
    return this.http.post<{ data: EvaluationDetails }>(`/api/scripts/${id}/test`, { text, version }).pipe(map((r) => r.data));
  }

  candidateTemplates(candidateId: number): Observable<CandidateTemplate[]> {
    return this.http.get<{ data: CandidateTemplate[] }>(`/api/candidates/${candidateId}/templates`).pipe(map((r) => r.data));
  }

  evaluation(touchpointId: number): Observable<TouchEvaluation> {
    return this.http.get<{ data: TouchEvaluation }>(`/api/touchpoints/${touchpointId}/evaluation`).pipe(map((r) => r.data));
  }

  tasks(query: TaskQuery): Observable<Task[]> {
    const params = toParams({
      mine: query.mine ? 1 : undefined,
      due: query.due,
      candidate_id: query.candidate_id,
      done: query.done ? 1 : undefined,
      source: query.source,
      employee_id: query.employee_id,
    });
    return this.http.get<{ data: Task[] }>('/api/tasks', { params }).pipe(map((r) => r.data));
  }

  setTaskDone(id: number, done: boolean): Observable<Task> {
    return this.http.patch<{ data: Task }>(`/api/tasks/${id}`, { done }).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Scripts API call. */
export function scriptsErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (SCRIPT_ERROR_CODES as readonly string[]).includes(code)) {
      return `scripts.errors.${code}`;
    }
    if (error.status === 403) {
      return 'scripts.errors.forbidden';
    }
    if (error.status === 422) {
      return 'scripts.errors.validation';
    }
  }
  return 'scripts.errors.generic';
}

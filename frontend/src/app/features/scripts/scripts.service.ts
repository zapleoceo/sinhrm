import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../../core/api/http-params';
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
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Scripts API (/api/scripts, /candidates/{id}/templates, /touchpoints/{id}/evaluation, /tasks). */
@Injectable({ providedIn: 'root' })
export class ScriptsService {
  private readonly http = inject(HttpClient);

  list(withArchived = false): Observable<Script[]> {
    return this.http
      .get<DataEnvelope<Script[]>>('/api/scripts', { params: toParams({ archived: withArchived ? 1 : undefined }) })
      .pipe(unwrapData());
  }

  get(id: number): Observable<ScriptDetails> {
    return this.http.get<DataEnvelope<ScriptDetails>>(`/api/scripts/${id}`).pipe(unwrapData());
  }

  create(name: string, channel: ScriptChannel): Observable<ScriptDetails> {
    return this.http.post<DataEnvelope<ScriptDetails>>('/api/scripts', { name, channel }).pipe(unwrapData());
  }

  update(id: number, body: { name?: string; archived?: boolean }): Observable<ScriptDetails> {
    return this.http.patch<DataEnvelope<ScriptDetails>>(`/api/scripts/${id}`, body).pipe(unwrapData());
  }

  saveDraft(id: number, content: ScriptContent): Observable<ScriptVersion> {
    return this.http.put<DataEnvelope<ScriptVersion>>(`/api/scripts/${id}/draft`, content).pipe(unwrapData());
  }

  publish(id: number): Observable<ScriptDetails> {
    return this.http.post<DataEnvelope<ScriptDetails>>(`/api/scripts/${id}/publish`, {}).pipe(unwrapData());
  }

  activate(id: number, version: number): Observable<ScriptDetails> {
    return this.http.post<DataEnvelope<ScriptDetails>>(`/api/scripts/${id}/activate/${version}`, {}).pipe(unwrapData());
  }

  versions(id: number): Observable<{ versions: ScriptVersion[]; activeVersionId: number | null }> {
    return this.http
      .get<{ data: ScriptVersion[]; meta: { active_version_id: number | null } }>(`/api/scripts/${id}/versions`)
      .pipe(map((r) => ({ versions: r.data, activeVersionId: r.meta.active_version_id })));
  }

  test(id: number, text: string, version: 'draft' | 'active'): Observable<EvaluationDetails> {
    return this.http.post<DataEnvelope<EvaluationDetails>>(`/api/scripts/${id}/test`, { text, version }).pipe(unwrapData());
  }

  candidateTemplates(candidateId: number): Observable<CandidateTemplate[]> {
    return this.http.get<DataEnvelope<CandidateTemplate[]>>(`/api/candidates/${candidateId}/templates`).pipe(unwrapData());
  }

  evaluation(touchpointId: number): Observable<TouchEvaluation> {
    return this.http.get<DataEnvelope<TouchEvaluation>>(`/api/touchpoints/${touchpointId}/evaluation`).pipe(unwrapData());
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
    return this.http.get<DataEnvelope<Task[]>>('/api/tasks', { params }).pipe(unwrapData());
  }

  setTaskDone(id: number, done: boolean): Observable<Task> {
    return this.http.patch<DataEnvelope<Task>>(`/api/tasks/${id}`, { done }).pipe(unwrapData());
  }
}

/** i18n key for a failed Scripts API call. */
export function scriptsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'scripts', SCRIPT_ERROR_CODES, { statuses: [403, 422], fallback: 'scripts.errors.generic' });
}

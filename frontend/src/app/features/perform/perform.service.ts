import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { toParams } from '../../core/api/http-params';
import {
  Assignment,
  Competency,
  DevelopmentPlan,
  Feedback,
  FeedbackBox,
  GiveFeedback,
  Kpi,
  NewOneOnOne,
  Objective,
  OneOnOne,
  OneOnOnePatch,
  OneOnOneTemplate,
  PERFORM_ERROR_CODES,
  RatingScale,
  ReviewAnswer,
  ReviewCycle,
  ReviewResult,
  ReviewType,
  SaveCycle,
  SaveObjective,
} from './perform.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/perform';

/** HTTP client of the Perform API (/api/perform/*). */
@Injectable({ providedIn: 'root' })
export class PerformService {
  private readonly http = inject(HttpClient);

  oneOnOnes(query: { employee_id?: number; status?: string } = {}): Observable<OneOnOne[]> {
    return this.get<OneOnOne[]>(`${API}/one-on-ones`, query);
  }

  createOneOnOne(body: NewOneOnOne): Observable<OneOnOne> {
    return this.http.post<DataEnvelope<OneOnOne>>(`${API}/one-on-ones`, body).pipe(unwrapData());
  }

  updateOneOnOne(id: number, patch: OneOnOnePatch): Observable<OneOnOne> {
    return this.http.patch<DataEnvelope<OneOnOne>>(`${API}/one-on-ones/${id}`, patch).pipe(unwrapData());
  }

  deleteOneOnOne(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/one-on-ones/${id}`);
  }

  templates(): Observable<OneOnOneTemplate[]> {
    return this.get<OneOnOneTemplate[]>(`${API}/one-on-one-templates`);
  }

  objectives(query: { period?: string; owner_employee_id?: number } = {}): Observable<Objective[]> {
    return this.get<Objective[]>(`${API}/objectives`, query);
  }

  objective(id: number): Observable<Objective> {
    return this.get<Objective>(`${API}/objectives/${id}`);
  }

  saveObjective(body: SaveObjective, id?: number): Observable<Objective> {
    const req = id ? this.http.put<DataEnvelope<Objective>>(`${API}/objectives/${id}`, body) : this.http.post<DataEnvelope<Objective>>(`${API}/objectives`, body);
    return req.pipe(unwrapData());
  }

  checkIn(id: number, values: { id: string; current: number }[], comment: string | null): Observable<Objective> {
    return this.http.post<DataEnvelope<Objective>>(`${API}/objectives/${id}/check-ins`, { key_results: values, comment }).pipe(unwrapData());
  }

  deleteObjective(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/objectives/${id}`);
  }

  kpis(query: { employee_id?: number; period?: string } = {}): Observable<Kpi[]> {
    return this.get<Kpi[]>(`${API}/kpis`, query);
  }

  saveKpi(body: { employee_id: number; metric: string; unit?: string | null; period: string; target: number; actual?: number | null }, id?: number): Observable<Kpi> {
    const req = id ? this.http.put<DataEnvelope<Kpi>>(`${API}/kpis/${id}`, body) : this.http.post<DataEnvelope<Kpi>>(`${API}/kpis`, body);
    return req.pipe(unwrapData());
  }

  feedback(box: FeedbackBox): Observable<Feedback[]> {
    return this.get<Feedback[]>(`${API}/feedback`, { box });
  }

  giveFeedback(body: GiveFeedback): Observable<Feedback> {
    return this.http.post<DataEnvelope<Feedback>>(`${API}/feedback`, body).pipe(unwrapData());
  }

  plans(query: { employee_id?: number } = {}): Observable<DevelopmentPlan[]> {
    return this.get<DevelopmentPlan[]>(`${API}/development-plans`, query);
  }

  savePlan(body: { employee_id: number; title: string; goals: { text: string }[]; actions: { text: string; due_on?: string | null }[]; due_on?: string | null }, id?: number): Observable<DevelopmentPlan> {
    const req = id ? this.http.put<DataEnvelope<DevelopmentPlan>>(`${API}/development-plans/${id}`, body) : this.http.post<DataEnvelope<DevelopmentPlan>>(`${API}/development-plans`, body);
    return req.pipe(unwrapData());
  }

  togglePlanAction(id: number, actionId: string, done: boolean): Observable<DevelopmentPlan> {
    return this.http.patch<DataEnvelope<DevelopmentPlan>>(`${API}/development-plans/${id}/actions/${actionId}`, { done }).pipe(unwrapData());
  }

  myAssignments(): Observable<Assignment[]> {
    return this.get<Assignment[]>(`${API}/review/assignments`);
  }

  assignment(id: number): Observable<Assignment> {
    return this.get<Assignment>(`${API}/review/assignments/${id}`);
  }

  submitReview(id: number, answers: ReviewAnswer[]): Observable<Assignment> {
    return this.http.post<DataEnvelope<Assignment>>(`${API}/review/assignments/${id}/submit`, { answers }).pipe(unwrapData());
  }

  employeeResults(employeeId: number): Observable<ReviewResult[]> {
    return this.get<ReviewResult[]>(`${API}/review/employees/${employeeId}/results`);
  }

  scales(): Observable<RatingScale[]> {
    return this.get<RatingScale[]>(`${API}/review/scales`);
  }

  createScale(body: Omit<RatingScale, 'id'>): Observable<RatingScale> {
    return this.http.post<DataEnvelope<RatingScale>>(`${API}/review/scales`, body).pipe(unwrapData());
  }

  competencies(): Observable<Competency[]> {
    return this.get<Competency[]>(`${API}/review/competencies`);
  }

  createCompetency(body: { name: string; description?: string | null; scale_id: number }): Observable<Competency> {
    return this.http.post<DataEnvelope<Competency>>(`${API}/review/competencies`, body).pipe(unwrapData());
  }

  cycles(): Observable<ReviewCycle[]> {
    return this.get<ReviewCycle[]>(`${API}/review/cycles`);
  }

  cycle(id: number): Observable<ReviewCycle> {
    return this.get<ReviewCycle>(`${API}/review/cycles/${id}`);
  }

  createCycle(body: SaveCycle): Observable<ReviewCycle> {
    return this.http.post<DataEnvelope<ReviewCycle>>(`${API}/review/cycles`, body).pipe(unwrapData());
  }

  cycleCommand(id: number, command: 'activate' | 'close'): Observable<ReviewCycle> {
    return this.http.post<DataEnvelope<ReviewCycle>>(`${API}/review/cycles/${id}/${command}`, {}).pipe(unwrapData());
  }

  addAssignment(id: number, subjectId: number, reviewerId: number, type: ReviewType): Observable<ReviewCycle> {
    const body = { subject_employee_id: subjectId, reviewer_employee_id: reviewerId, type };
    return this.http.post<DataEnvelope<ReviewCycle>>(`${API}/review/cycles/${id}/assignments`, body).pipe(unwrapData());
  }

  private get<T>(url: string, query: Record<string, string | number | undefined> = {}): Observable<T> {
    return this.http.get<DataEnvelope<T>>(url, { params: toParams(query) }).pipe(unwrapData());
  }
}

/** i18n key for a failed Perform API call. */
export function performErrorKey(error: unknown): string {
  return apiErrorKey(error, 'perform', PERFORM_ERROR_CODES, { statuses: [403, 404, 422] });
}

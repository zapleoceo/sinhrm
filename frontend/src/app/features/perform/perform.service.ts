import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
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

const API = '/api/perform';

interface Data<T> {
  data: T;
}

/** HTTP client of the Perform API (/api/perform/*). */
@Injectable({ providedIn: 'root' })
export class PerformService {
  private readonly http = inject(HttpClient);

  oneOnOnes(query: { employee_id?: number; status?: string } = {}): Observable<OneOnOne[]> {
    return this.get<OneOnOne[]>(`${API}/one-on-ones`, query);
  }

  createOneOnOne(body: NewOneOnOne): Observable<OneOnOne> {
    return this.http.post<Data<OneOnOne>>(`${API}/one-on-ones`, body).pipe(map((r) => r.data));
  }

  updateOneOnOne(id: number, patch: OneOnOnePatch): Observable<OneOnOne> {
    return this.http.patch<Data<OneOnOne>>(`${API}/one-on-ones/${id}`, patch).pipe(map((r) => r.data));
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
    const req = id ? this.http.put<Data<Objective>>(`${API}/objectives/${id}`, body) : this.http.post<Data<Objective>>(`${API}/objectives`, body);
    return req.pipe(map((r) => r.data));
  }

  checkIn(id: number, values: { id: string; current: number }[], comment: string | null): Observable<Objective> {
    return this.http.post<Data<Objective>>(`${API}/objectives/${id}/check-ins`, { key_results: values, comment }).pipe(map((r) => r.data));
  }

  deleteObjective(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/objectives/${id}`);
  }

  kpis(query: { employee_id?: number; period?: string } = {}): Observable<Kpi[]> {
    return this.get<Kpi[]>(`${API}/kpis`, query);
  }

  saveKpi(body: { employee_id: number; metric: string; unit?: string | null; period: string; target: number; actual?: number | null }, id?: number): Observable<Kpi> {
    const req = id ? this.http.put<Data<Kpi>>(`${API}/kpis/${id}`, body) : this.http.post<Data<Kpi>>(`${API}/kpis`, body);
    return req.pipe(map((r) => r.data));
  }

  feedback(box: FeedbackBox): Observable<Feedback[]> {
    return this.get<Feedback[]>(`${API}/feedback`, { box });
  }

  giveFeedback(body: GiveFeedback): Observable<Feedback> {
    return this.http.post<Data<Feedback>>(`${API}/feedback`, body).pipe(map((r) => r.data));
  }

  plans(query: { employee_id?: number } = {}): Observable<DevelopmentPlan[]> {
    return this.get<DevelopmentPlan[]>(`${API}/development-plans`, query);
  }

  savePlan(body: { employee_id: number; title: string; goals: { text: string }[]; actions: { text: string; due_on?: string | null }[]; due_on?: string | null }, id?: number): Observable<DevelopmentPlan> {
    const req = id ? this.http.put<Data<DevelopmentPlan>>(`${API}/development-plans/${id}`, body) : this.http.post<Data<DevelopmentPlan>>(`${API}/development-plans`, body);
    return req.pipe(map((r) => r.data));
  }

  togglePlanAction(id: number, actionId: string, done: boolean): Observable<DevelopmentPlan> {
    return this.http.patch<Data<DevelopmentPlan>>(`${API}/development-plans/${id}/actions/${actionId}`, { done }).pipe(map((r) => r.data));
  }

  myAssignments(): Observable<Assignment[]> {
    return this.get<Assignment[]>(`${API}/review/assignments`);
  }

  assignment(id: number): Observable<Assignment> {
    return this.get<Assignment>(`${API}/review/assignments/${id}`);
  }

  submitReview(id: number, answers: ReviewAnswer[]): Observable<Assignment> {
    return this.http.post<Data<Assignment>>(`${API}/review/assignments/${id}/submit`, { answers }).pipe(map((r) => r.data));
  }

  employeeResults(employeeId: number): Observable<ReviewResult[]> {
    return this.get<ReviewResult[]>(`${API}/review/employees/${employeeId}/results`);
  }

  scales(): Observable<RatingScale[]> {
    return this.get<RatingScale[]>(`${API}/review/scales`);
  }

  createScale(body: Omit<RatingScale, 'id'>): Observable<RatingScale> {
    return this.http.post<Data<RatingScale>>(`${API}/review/scales`, body).pipe(map((r) => r.data));
  }

  competencies(): Observable<Competency[]> {
    return this.get<Competency[]>(`${API}/review/competencies`);
  }

  createCompetency(body: { name: string; description?: string | null; scale_id: number }): Observable<Competency> {
    return this.http.post<Data<Competency>>(`${API}/review/competencies`, body).pipe(map((r) => r.data));
  }

  cycles(): Observable<ReviewCycle[]> {
    return this.get<ReviewCycle[]>(`${API}/review/cycles`);
  }

  cycle(id: number): Observable<ReviewCycle> {
    return this.get<ReviewCycle>(`${API}/review/cycles/${id}`);
  }

  createCycle(body: SaveCycle): Observable<ReviewCycle> {
    return this.http.post<Data<ReviewCycle>>(`${API}/review/cycles`, body).pipe(map((r) => r.data));
  }

  cycleCommand(id: number, command: 'activate' | 'close'): Observable<ReviewCycle> {
    return this.http.post<Data<ReviewCycle>>(`${API}/review/cycles/${id}/${command}`, {}).pipe(map((r) => r.data));
  }

  addAssignment(id: number, subjectId: number, reviewerId: number, type: ReviewType): Observable<ReviewCycle> {
    const body = { subject_employee_id: subjectId, reviewer_employee_id: reviewerId, type };
    return this.http.post<Data<ReviewCycle>>(`${API}/review/cycles/${id}/assignments`, body).pipe(map((r) => r.data));
  }

  private get<T>(url: string, query: Record<string, string | number | undefined> = {}): Observable<T> {
    return this.http.get<Data<T>>(url, { params: toParams(query) }).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Perform API call. */
export function performErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (PERFORM_ERROR_CODES as readonly string[]).includes(code)) {
      return `perform.errors.${code}`;
    }
    if (error.status === 403) {
      return 'perform.errors.forbidden';
    }
    if (error.status === 404) {
      return 'perform.errors.not_found';
    }
    if (error.status === 422) {
      return 'perform.errors.validation';
    }
  }
  return 'common.error';
}

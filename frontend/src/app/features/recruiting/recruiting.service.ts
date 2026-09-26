import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  AcquisitionChannel,
  VacancySourceRow,
  Application,
  Board,
  Candidate,
  CandidateQuery,
  DateRange,
  DuplicateCandidate,
  FunnelReport,
  LogTouch,
  MoveApplication,
  Paged,
  Pipeline,
  RECRUITING_ERROR_CODES,
  RejectReason,
  RejectReasonsReport,
  SaveCandidate,
  SaveVacancy,
  Screening,
  SourcesReport,
  TimelineFilter,
  TimelineItem,
  Touchpoint,
  TouchesReport,
  Vacancy,
  VacancyQuery,
} from './recruiting.model';

type Params = Record<string, string | number | boolean | undefined | null>;

/** Query params without empty values (numbers become strings: the API accepts "20"). */
export function toParams(query: Params): HttpParams {
  let params = new HttpParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') {
      params = params.set(key, String(value));
    }
  }
  return params;
}

/** HTTP client of the Recruiting API (/api/vacancies, /candidates, /applications, /inbox, /reports, …). */
@Injectable({ providedIn: 'root' })
export class RecruitingService {
  private readonly http = inject(HttpClient);

  pipelines(): Observable<Pipeline[]> {
    return this.http.get<{ data: Pipeline[] }>('/api/pipelines').pipe(map((r) => r.data));
  }

  rejectReasons(): Observable<RejectReason[]> {
    return this.http.get<{ data: RejectReason[] }>('/api/reject-reasons').pipe(map((r) => r.data));
  }

  vacancies(query: VacancyQuery): Observable<Paged<Vacancy>> {
    return this.http.get<Paged<Vacancy>>('/api/vacancies', { params: toParams({ ...query }) });
  }

  createVacancy(body: SaveVacancy): Observable<Vacancy> {
    return this.http.post<{ data: Vacancy }>('/api/vacancies', body).pipe(map((r) => r.data));
  }

  updateVacancy(id: number, body: SaveVacancy): Observable<Vacancy> {
    return this.http.patch<{ data: Vacancy }>(`/api/vacancies/${id}`, body).pipe(map((r) => r.data));
  }

  board(vacancyId: number): Observable<Board> {
    return this.http.get<{ data: Board }>(`/api/vacancies/${vacancyId}/board`).pipe(map((r) => r.data));
  }

  /** Tz3 vacancy block: applications by channel × how added. */
  vacancySources(vacancyId: number): Observable<VacancySourceRow[]> {
    return this.http.get<{ data: VacancySourceRow[] }>(`/api/vacancies/${vacancyId}/sources`).pipe(map((r) => r.data));
  }

  /** Active acquisition channels (candidate form, filters); managers get rules and costs with all=true. */
  channels(all = false): Observable<AcquisitionChannel[]> {
    return this.http
      .get<{ data: AcquisitionChannel[] }>('/api/acquisition-channels', { params: toParams({ all: all ? 1 : undefined }) })
      .pipe(map((r) => r.data));
  }

  apply(vacancyId: number, candidateId: number): Observable<Application> {
    return this.http
      .post<{ data: Application }>(`/api/vacancies/${vacancyId}/applications`, { candidate_id: candidateId })
      .pipe(map((r) => r.data));
  }

  candidates(query: CandidateQuery): Observable<Paged<Candidate>> {
    return this.http.get<Paged<Candidate>>('/api/candidates', { params: toParams({ ...query }) });
  }

  candidate(id: number): Observable<Candidate> {
    return this.http.get<{ data: Candidate }>(`/api/candidates/${id}`).pipe(map((r) => r.data));
  }

  createCandidate(body: SaveCandidate): Observable<Candidate> {
    return this.http.post<{ data: Candidate }>('/api/candidates', body).pipe(map((r) => r.data));
  }

  updateCandidate(id: number, body: SaveCandidate): Observable<Candidate> {
    return this.http.patch<{ data: Candidate }>(`/api/candidates/${id}`, body).pipe(map((r) => r.data));
  }

  timeline(candidateId: number, filters: readonly TimelineFilter[], page = 1, perPage = 50): Observable<Paged<TimelineItem>> {
    const params = toParams({ channel: filters.length ? filters.join(',') : undefined, page, perPage });
    return this.http.get<Paged<TimelineItem>>(`/api/candidates/${candidateId}/timeline`, { params });
  }

  logTouch(candidateId: number, body: LogTouch): Observable<Touchpoint> {
    return this.http.post<{ data: Touchpoint }>(`/api/candidates/${candidateId}/touchpoints`, body).pipe(map((r) => r.data));
  }

  /** Latest AI screening of each application of the candidate (pending ones are polled once by the API). */
  screenings(candidateId: number): Observable<Screening[]> {
    return this.http.get<{ data: Screening[] }>(`/api/candidates/${candidateId}/screenings`).pipe(map((r) => r.data));
  }

  /** Starts an AI screening (201 done / 202 still running). */
  screen(applicationId: number): Observable<Screening> {
    return this.http.post<{ data: Screening }>(`/api/applications/${applicationId}/screening`, {}).pipe(map((r) => r.data));
  }

  move(applicationId: number, body: MoveApplication): Observable<Application> {
    return this.http.post<{ data: Application }>(`/api/applications/${applicationId}/move`, body).pipe(map((r) => r.data));
  }

  stale(days: number): Observable<Application[]> {
    return this.http.get<{ data: Application[] }>('/api/recruiting/stale', { params: toParams({ days }) }).pipe(map((r) => r.data));
  }

  inbox(page = 1, perPage = 50): Observable<Paged<Touchpoint>> {
    return this.http.get<Paged<Touchpoint>>('/api/inbox', { params: toParams({ page, perPage }) });
  }

  linkInbox(touchpointId: number, candidateId: number): Observable<Touchpoint> {
    return this.http
      .post<{ data: Touchpoint }>(`/api/inbox/${touchpointId}/link`, { candidate_id: candidateId })
      .pipe(map((r) => r.data));
  }

  createFromInbox(touchpointId: number, body: { full_name: string; vacancy_id?: number }): Observable<Candidate> {
    return this.http.post<{ data: Candidate }>(`/api/inbox/${touchpointId}/create-candidate`, body).pipe(map((r) => r.data));
  }

  touchesReport(range: DateRange): Observable<TouchesReport> {
    return this.report<TouchesReport>('touches', { ...range });
  }

  funnelReport(range: DateRange, vacancyId?: number): Observable<FunnelReport> {
    return this.report<FunnelReport>('funnel', { ...range, vacancy_id: vacancyId });
  }

  sourcesReport(range: DateRange): Observable<SourcesReport> {
    return this.report<SourcesReport>('sources', { ...range });
  }

  rejectReasonsReport(range: DateRange): Observable<RejectReasonsReport> {
    return this.report<RejectReasonsReport>('reject-reasons', { ...range });
  }

  private report<T>(name: string, params: Params): Observable<T> {
    return this.http.get<{ data: T }>(`/api/reports/${name}`, { params: toParams(params) }).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Recruiting API call. */
export function recruitingErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as { code?: unknown; restricted?: unknown } | null;
    const code: unknown = body?.code;
    if (code === 'duplicate_candidate' && body?.restricted === true) {
      return 'recruiting.errors.duplicate_restricted';
    }
    if (typeof code === 'string' && (RECRUITING_ERROR_CODES as readonly string[]).includes(code)) {
      return `recruiting.errors.${code}`;
    }
    if (error.status === 403) {
      return 'recruiting.errors.forbidden';
    }
    if (error.status === 422) {
      return 'recruiting.errors.validation';
    }
  }
  return 'recruiting.errors.generic';
}

/** The existing candidate from a 409 duplicate_candidate answer, or null. */
export function duplicateOf(error: unknown): DuplicateCandidate | null {
  if (error instanceof HttpErrorResponse && error.status === 409) {
    const body = error.error as Partial<DuplicateCandidate> | null;
    if (body?.code === 'duplicate_candidate' && typeof body.existing_id === 'number') {
      return body as DuplicateCandidate;
    }
  }
  return null;
}

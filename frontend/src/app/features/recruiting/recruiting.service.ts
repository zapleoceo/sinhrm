import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  AcquisitionChannel,
  VacancySourceRow,
  Application,
  Board,
  BulkResult,
  Candidate,
  CandidateBulkBody,
  CandidateQuery,
  DateRange,
  DuplicateCandidate,
  FunnelReport,
  LogTouch,
  MoveApplication,
  Paged,
  PersonalBoard,
  PersonalColumn,
  Pipeline,
  RECRUITING_ERROR_CODES,
  Ref,
  RejectReason,
  RejectReasonsReport,
  SaveCandidate,
  SavePersonalColumn,
  SaveVacancy,
  Screening,
  SourcesReport,
  TimelineFilter,
  TimelineItem,
  Touchpoint,
  TouchesReport,
  Vacancy,
  VacancyOptions,
  VacancyPageMeta,
  VacancyQuery,
  VacancyTemplate,
  VacancyTextDraft,
  VacancyTextSection,
} from './recruiting.model';
import { apiErrorCode, apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';
import { QueryValue, toParams } from '../../core/api/http-params';

/** Moved to core/api/http-params.ts; re-exported for features that still import it from here (features/people). */
export { toParams };

/** HTTP client of the Recruiting API (/api/vacancies, /candidates, /applications, /inbox, /reports, …). */
@Injectable({ providedIn: 'root' })
export class RecruitingService {
  private readonly http = inject(HttpClient);

  pipelines(): Observable<Pipeline[]> {
    return this.http.get<DataEnvelope<Pipeline[]>>('/api/pipelines').pipe(unwrapData());
  }

  rejectReasons(): Observable<RejectReason[]> {
    return this.http.get<DataEnvelope<RejectReason[]>>('/api/reject-reasons').pipe(unwrapData());
  }

  vacancies(query: VacancyQuery): Observable<Paged<Vacancy, VacancyPageMeta>> {
    return this.http.get<Paged<Vacancy, VacancyPageMeta>>('/api/vacancies', { params: toParams({ ...query, active: query.active ? 1 : undefined }) });
  }

  vacancy(id: number): Observable<Vacancy> {
    return this.http.get<DataEnvelope<Vacancy>>(`/api/vacancies/${id}`).pipe(unwrapData());
  }

  vacancyOptions(): Observable<VacancyOptions> {
    return this.http.get<DataEnvelope<VacancyOptions>>('/api/vacancy-options').pipe(unwrapData());
  }

  vacancyTemplates(): Observable<VacancyTemplate[]> {
    return this.http.get<DataEnvelope<VacancyTemplate[]>>('/api/vacancy-templates').pipe(unwrapData());
  }

  saveVacancyTemplate(name: string, data: SaveVacancy): Observable<VacancyTemplate> {
    return this.http.post<DataEnvelope<VacancyTemplate>>('/api/vacancy-templates', { name, data }).pipe(unwrapData());
  }

  renameVacancyTemplate(id: number, name: string): Observable<VacancyTemplate> {
    return this.http.patch<DataEnvelope<VacancyTemplate>>(`/api/vacancy-templates/${id}`, { name }).pipe(unwrapData());
  }

  deleteVacancyTemplate(id: number): Observable<void> {
    return this.http.delete<void>(`/api/vacancy-templates/${id}`);
  }

  /** «Створити з ШІ»: a draft of one section (done, or deferred → vacancyTextResult). */
  vacancyText(body: {
    section: VacancyTextSection;
    title: string;
    category_id?: number | null;
    branch_id?: number | null;
    employment_type?: string | null;
    experience_level?: string | null;
  }): Observable<VacancyTextDraft> {
    return this.http.post<DataEnvelope<VacancyTextDraft>>('/api/vacancy-text', body).pipe(unwrapData());
  }

  vacancyTextResult(requestId: number): Observable<VacancyTextDraft> {
    return this.http.get<DataEnvelope<VacancyTextDraft>>(`/api/vacancy-text/${requestId}`).pipe(unwrapData());
  }

  createVacancy(body: SaveVacancy): Observable<Vacancy> {
    return this.http.post<DataEnvelope<Vacancy>>('/api/vacancies', body).pipe(unwrapData());
  }

  updateVacancy(id: number, body: SaveVacancy): Observable<Vacancy> {
    return this.http.patch<DataEnvelope<Vacancy>>(`/api/vacancies/${id}`, body).pipe(unwrapData());
  }

  board(vacancyId: number): Observable<Board> {
    return this.http.get<DataEnvelope<Board>>(`/api/vacancies/${vacancyId}/board`).pipe(unwrapData());
  }

  /** Tz3 vacancy block: applications by channel × how added. */
  vacancySources(vacancyId: number): Observable<VacancySourceRow[]> {
    return this.http.get<DataEnvelope<VacancySourceRow[]>>(`/api/vacancies/${vacancyId}/sources`).pipe(unwrapData());
  }

  /** Active acquisition channels (candidate form, filters); managers get rules and costs with all=true. */
  channels(all = false): Observable<AcquisitionChannel[]> {
    return this.http
      .get<DataEnvelope<AcquisitionChannel[]>>('/api/acquisition-channels', { params: toParams({ all: all ? 1 : undefined }) })
      .pipe(unwrapData());
  }

  apply(vacancyId: number, candidateId: number): Observable<Application> {
    return this.http
      .post<DataEnvelope<Application>>(`/api/vacancies/${vacancyId}/applications`, { candidate_id: candidateId })
      .pipe(unwrapData());
  }

  candidates(query: CandidateQuery): Observable<Paged<Candidate>> {
    return this.http.get<Paged<Candidate>>('/api/candidates', { params: toParams({ ...query }) });
  }

  bulkCandidates(body: CandidateBulkBody): Observable<BulkResult[]> {
    return this.http.post<DataEnvelope<BulkResult[]>>('/api/candidates/bulk', body).pipe(unwrapData());
  }

  candidate(id: number): Observable<Candidate> {
    return this.http.get<DataEnvelope<Candidate>>(`/api/candidates/${id}`).pipe(unwrapData());
  }

  createCandidate(body: SaveCandidate): Observable<Candidate> {
    return this.http.post<DataEnvelope<Candidate>>('/api/candidates', body).pipe(unwrapData());
  }

  updateCandidate(id: number, body: SaveCandidate): Observable<Candidate> {
    return this.http.patch<DataEnvelope<Candidate>>(`/api/candidates/${id}`, body).pipe(unwrapData());
  }

  timeline(candidateId: number, filters: readonly TimelineFilter[], page = 1, perPage = 50): Observable<Paged<TimelineItem>> {
    const params = toParams({ channel: filters.length ? filters.join(',') : undefined, page, perPage });
    return this.http.get<Paged<TimelineItem>>(`/api/candidates/${candidateId}/timeline`, { params });
  }

  logTouch(candidateId: number, body: LogTouch): Observable<Touchpoint> {
    return this.http.post<DataEnvelope<Touchpoint>>(`/api/candidates/${candidateId}/touchpoints`, body).pipe(unwrapData());
  }

  /** Latest AI screening of each application of the candidate (pending ones are polled once by the API). */
  screenings(candidateId: number): Observable<Screening[]> {
    return this.http.get<DataEnvelope<Screening[]>>(`/api/candidates/${candidateId}/screenings`).pipe(unwrapData());
  }

  /** Starts an AI screening (201 done / 202 still running). */
  screen(applicationId: number): Observable<Screening> {
    return this.http.post<DataEnvelope<Screening>>(`/api/applications/${applicationId}/screening`, {}).pipe(unwrapData());
  }

  /** People for the hiring-team pickers (writers and hiring managers; at most 50). */
  assignableUsers(q = ''): Observable<Ref[]> {
    return this.http.get<DataEnvelope<Ref[]>>('/api/recruiting/assignable-users', { params: toParams({ q }) }).pipe(unwrapData());
  }

  /** Replaces the interviewers of an application (an empty list removes everyone). */
  setInterviewers(applicationId: number, userIds: readonly number[]): Observable<Application> {
    return this.http.put<DataEnvelope<Application>>(`/api/applications/${applicationId}/interviewers`, { user_ids: userIds }).pipe(unwrapData());
  }

  /** Own columns of the /candidates board for one vacancy (personal view state, never the stage). */
  personalBoard(vacancyId: number): Observable<PersonalBoard> {
    return this.http.get<DataEnvelope<PersonalBoard>>(`/api/vacancies/${vacancyId}/personal-board`).pipe(unwrapData());
  }

  addPersonalColumn(vacancyId: number, body: SavePersonalColumn): Observable<PersonalColumn> {
    return this.http.post<DataEnvelope<PersonalColumn>>(`/api/vacancies/${vacancyId}/personal-board/columns`, body).pipe(unwrapData());
  }

  updatePersonalColumn(columnId: number, body: SavePersonalColumn): Observable<PersonalColumn> {
    return this.http.patch<DataEnvelope<PersonalColumn>>(`/api/personal-board/columns/${columnId}`, body).pipe(unwrapData());
  }

  deletePersonalColumn(columnId: number): Observable<void> {
    return this.http.delete<void>(`/api/personal-board/columns/${columnId}`);
  }

  /** Saves the combined column order; answers with the stored (repaired) layout. */
  savePersonalLayout(vacancyId: number, keys: string[]): Observable<string[]> {
    return this.http
      .put<DataEnvelope<{ layout: string[] }>>(`/api/vacancies/${vacancyId}/personal-board/layout`, { keys })
      .pipe(map((r) => r.data.layout));
  }

  resetPersonalBoard(vacancyId: number): Observable<void> {
    return this.http.delete<void>(`/api/vacancies/${vacancyId}/personal-board`);
  }

  /** Files the card into an own column (null: back to its stage column). The stage is never changed. */
  fileCard(applicationId: number, columnId: number | null): Observable<void> {
    return this.http.put<void>(`/api/applications/${applicationId}/personal-column`, { column_id: columnId });
  }

  move(applicationId: number, body: MoveApplication): Observable<Application> {
    return this.http.post<DataEnvelope<Application>>(`/api/applications/${applicationId}/move`, body).pipe(unwrapData());
  }

  stale(days: number): Observable<Application[]> {
    return this.http.get<DataEnvelope<Application[]>>('/api/recruiting/stale', { params: toParams({ days }) }).pipe(unwrapData());
  }

  inbox(page = 1, perPage = 50): Observable<Paged<Touchpoint>> {
    return this.http.get<Paged<Touchpoint>>('/api/inbox', { params: toParams({ page, perPage }) });
  }

  linkInbox(touchpointId: number, candidateId: number): Observable<Touchpoint> {
    return this.http
      .post<DataEnvelope<Touchpoint>>(`/api/inbox/${touchpointId}/link`, { candidate_id: candidateId })
      .pipe(unwrapData());
  }

  createFromInbox(touchpointId: number, body: { full_name: string; vacancy_id?: number }): Observable<Candidate> {
    return this.http.post<DataEnvelope<Candidate>>(`/api/inbox/${touchpointId}/create-candidate`, body).pipe(unwrapData());
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

  private report<T>(name: string, params: Record<string, QueryValue>): Observable<T> {
    return this.http.get<DataEnvelope<T>>(`/api/reports/${name}`, { params: toParams(params) }).pipe(unwrapData());
  }
}

/** i18n key for a failed Recruiting API call. */
export function recruitingErrorKey(error: unknown): string {
  // A duplicate the user may not open (out of scope) gets its own text without a link to the card.
  if (apiErrorCode(error) === 'duplicate_candidate' && error instanceof HttpErrorResponse && (error.error as { restricted?: unknown }).restricted === true) {
    return 'recruiting.errors.duplicate_restricted';
  }
  return apiErrorKey(error, 'recruiting', RECRUITING_ERROR_CODES, { statuses: [403, 422], fallback: 'recruiting.errors.generic' });
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

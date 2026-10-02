import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { FormField, HIRING_ERROR_CODES, HiringMeta, HiringRequest, HiringSettings, HiringStatus, RouteStep, SaveHiringRequest } from './hiring-requests.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/hiring-requests';

/** HTTP client of the HiringRequests API (/api/hiring-requests/*). */
@Injectable({ providedIn: 'root' })
export class HiringRequestsService {
  private readonly http = inject(HttpClient);

  list(query: { status?: HiringStatus; mine?: boolean } = {}): Observable<HiringRequest[]> {
    return this.http.get<DataEnvelope<HiringRequest[]>>(API, { params: toParams({ status: query.status, mine: query.mine ? 1 : undefined }) }).pipe(unwrapData());
  }

  inbox(): Observable<HiringRequest[]> {
    return this.http.get<DataEnvelope<HiringRequest[]>>(`${API}/inbox`).pipe(unwrapData());
  }

  meta(): Observable<HiringMeta> {
    return this.http.get<DataEnvelope<HiringMeta>>(`${API}/meta`).pipe(unwrapData());
  }

  get(id: number): Observable<HiringRequest> {
    return this.http.get<DataEnvelope<HiringRequest>>(`${API}/${id}`).pipe(unwrapData());
  }

  create(body: SaveHiringRequest): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(API, body).pipe(unwrapData());
  }

  update(id: number, body: SaveHiringRequest): Observable<HiringRequest> {
    return this.http.patch<DataEnvelope<HiringRequest>>(`${API}/${id}`, body).pipe(unwrapData());
  }

  submit(id: number): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/submit`, {}).pipe(unwrapData());
  }

  decide(id: number, approve: boolean, comment: string | null, recruiterId: number | null = null): Observable<HiringRequest> {
    const body = { decision: approve ? 'approve' : 'reject', comment, recruiter_id: recruiterId ?? undefined };
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/decision`, body).pipe(unwrapData());
  }

  cancel(id: number): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/cancel`, {}).pipe(unwrapData());
  }

  close(id: number): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/close`, {}).pipe(unwrapData());
  }

  createVacancy(id: number, recruiterId: number | null): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/vacancy`, { recruiter_id: recruiterId ?? undefined }).pipe(unwrapData());
  }

  linkVacancy(id: number, vacancyId: number): Observable<HiringRequest> {
    return this.http.post<DataEnvelope<HiringRequest>>(`${API}/${id}/link-vacancy`, { vacancy_id: vacancyId }).pipe(unwrapData());
  }

  settings(): Observable<HiringSettings> {
    return this.http.get<DataEnvelope<HiringSettings>>(`${API}/settings`).pipe(unwrapData());
  }

  saveSettings(body: { form_fields?: FormField[]; creator_user_ids?: number[]; auto_vacancy?: boolean; route?: RouteStep[] }): Observable<HiringSettings> {
    return this.http.put<DataEnvelope<HiringSettings>>(`${API}/settings`, body).pipe(unwrapData());
  }
}

export function hiringErrorKey(error: unknown): string {
  return apiErrorKey(error, 'hiring', HIRING_ERROR_CODES);
}

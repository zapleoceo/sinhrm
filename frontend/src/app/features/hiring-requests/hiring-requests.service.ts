import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { FormField, HIRING_ERROR_CODES, HiringMeta, HiringRequest, HiringSettings, HiringStatus, RouteStep, SaveHiringRequest } from './hiring-requests.model';

const API = '/api/hiring-requests';

/** HTTP client of the HiringRequests API (/api/hiring-requests/*). */
@Injectable({ providedIn: 'root' })
export class HiringRequestsService {
  private readonly http = inject(HttpClient);

  list(query: { status?: HiringStatus; mine?: boolean } = {}): Observable<HiringRequest[]> {
    return this.http.get<{ data: HiringRequest[] }>(API, { params: toParams({ status: query.status, mine: query.mine ? 1 : undefined }) }).pipe(map((r) => r.data));
  }

  inbox(): Observable<HiringRequest[]> {
    return this.http.get<{ data: HiringRequest[] }>(`${API}/inbox`).pipe(map((r) => r.data));
  }

  meta(): Observable<HiringMeta> {
    return this.http.get<{ data: HiringMeta }>(`${API}/meta`).pipe(map((r) => r.data));
  }

  get(id: number): Observable<HiringRequest> {
    return this.http.get<{ data: HiringRequest }>(`${API}/${id}`).pipe(map((r) => r.data));
  }

  create(body: SaveHiringRequest): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(API, body).pipe(map((r) => r.data));
  }

  update(id: number, body: SaveHiringRequest): Observable<HiringRequest> {
    return this.http.patch<{ data: HiringRequest }>(`${API}/${id}`, body).pipe(map((r) => r.data));
  }

  submit(id: number): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/submit`, {}).pipe(map((r) => r.data));
  }

  decide(id: number, approve: boolean, comment: string | null, recruiterId: number | null = null): Observable<HiringRequest> {
    const body = { decision: approve ? 'approve' : 'reject', comment, recruiter_id: recruiterId ?? undefined };
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/decision`, body).pipe(map((r) => r.data));
  }

  cancel(id: number): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/cancel`, {}).pipe(map((r) => r.data));
  }

  close(id: number): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/close`, {}).pipe(map((r) => r.data));
  }

  createVacancy(id: number, recruiterId: number | null): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/vacancy`, { recruiter_id: recruiterId ?? undefined }).pipe(map((r) => r.data));
  }

  linkVacancy(id: number, vacancyId: number): Observable<HiringRequest> {
    return this.http.post<{ data: HiringRequest }>(`${API}/${id}/link-vacancy`, { vacancy_id: vacancyId }).pipe(map((r) => r.data));
  }

  settings(): Observable<HiringSettings> {
    return this.http.get<{ data: HiringSettings }>(`${API}/settings`).pipe(map((r) => r.data));
  }

  saveSettings(body: { form_fields?: FormField[]; creator_user_ids?: number[]; auto_vacancy?: boolean; route?: RouteStep[] }): Observable<HiringSettings> {
    return this.http.put<{ data: HiringSettings }>(`${API}/settings`, body).pipe(map((r) => r.data));
  }
}

export function hiringErrorKey(error: unknown): string {
  return apiErrorKey(error, 'hiring', HIRING_ERROR_CODES);
}

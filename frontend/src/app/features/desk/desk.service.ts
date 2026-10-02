import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { CaseStatus, DESK_ERROR_CODES, DeskCase, DeskCategory, NewComment, OpenCase, QueueQuery } from './desk.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Desk API (/api/desk/*). */
@Injectable({ providedIn: 'root' })
export class DeskService {
  private readonly http = inject(HttpClient);

  categories(all = false): Observable<DeskCategory[]> {
    return this.http.get<DataEnvelope<DeskCategory[]>>('/api/desk/categories', { params: toParams({ all: all ? 1 : undefined }) }).pipe(unwrapData());
  }

  saveCategory(id: number | null, body: Partial<Omit<DeskCategory, 'id'>>): Observable<DeskCategory> {
    const call = id === null ? this.http.post<DataEnvelope<DeskCategory>>('/api/desk/categories', body) : this.http.patch<DataEnvelope<DeskCategory>>(`/api/desk/categories/${id}`, body);
    return call.pipe(unwrapData());
  }

  mine(): Observable<DeskCase[]> {
    return this.http.get<DataEnvelope<DeskCase[]>>('/api/desk/cases/mine').pipe(unwrapData());
  }

  queue(query: QueueQuery = {}): Observable<DeskCase[]> {
    const params = toParams({ status: query.status, category_id: query.category_id, open: query.open ? 1 : undefined });
    return this.http.get<DataEnvelope<DeskCase[]>>('/api/desk/cases', { params }).pipe(unwrapData());
  }

  get(id: number): Observable<DeskCase> {
    return this.http.get<DataEnvelope<DeskCase>>(`/api/desk/cases/${id}`).pipe(unwrapData());
  }

  open(body: OpenCase): Observable<DeskCase> {
    return this.http.post<DataEnvelope<DeskCase>>('/api/desk/cases', body).pipe(unwrapData());
  }

  update(id: number, body: { status?: CaseStatus; assignee_id?: number | null; category_id?: number }): Observable<DeskCase> {
    return this.http.patch<DataEnvelope<DeskCase>>(`/api/desk/cases/${id}`, body).pipe(unwrapData());
  }

  comment(id: number, body: NewComment): Observable<DeskCase> {
    return this.http.post<DataEnvelope<DeskCase>>(`/api/desk/cases/${id}/comments`, body).pipe(unwrapData());
  }

  attach(id: number, file: File): Observable<DeskCase> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<DataEnvelope<DeskCase>>(`/api/desk/cases/${id}/attachments`, form).pipe(unwrapData());
  }

  /** Same-origin download link (cookie session); the API answers with Content-Disposition: attachment. */
  attachmentUrl(caseId: number, attachmentId: number): string {
    return `/api/desk/cases/${caseId}/attachments/${attachmentId}`;
  }
}

export function deskErrorKey(error: unknown): string {
  return apiErrorKey(error, 'desk', DESK_ERROR_CODES);
}

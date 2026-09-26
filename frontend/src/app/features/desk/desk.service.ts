import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { CaseStatus, DESK_ERROR_CODES, DeskCase, DeskCategory, NewComment, OpenCase, QueueQuery } from './desk.model';

/** HTTP client of the Desk API (/api/desk/*). */
@Injectable({ providedIn: 'root' })
export class DeskService {
  private readonly http = inject(HttpClient);

  categories(all = false): Observable<DeskCategory[]> {
    return this.http.get<{ data: DeskCategory[] }>('/api/desk/categories', { params: toParams({ all: all ? 1 : undefined }) }).pipe(map((r) => r.data));
  }

  saveCategory(id: number | null, body: Partial<Omit<DeskCategory, 'id'>>): Observable<DeskCategory> {
    const call = id === null ? this.http.post<{ data: DeskCategory }>('/api/desk/categories', body) : this.http.patch<{ data: DeskCategory }>(`/api/desk/categories/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  mine(): Observable<DeskCase[]> {
    return this.http.get<{ data: DeskCase[] }>('/api/desk/cases/mine').pipe(map((r) => r.data));
  }

  queue(query: QueueQuery = {}): Observable<DeskCase[]> {
    const params = toParams({ status: query.status, category_id: query.category_id, open: query.open ? 1 : undefined });
    return this.http.get<{ data: DeskCase[] }>('/api/desk/cases', { params }).pipe(map((r) => r.data));
  }

  get(id: number): Observable<DeskCase> {
    return this.http.get<{ data: DeskCase }>(`/api/desk/cases/${id}`).pipe(map((r) => r.data));
  }

  open(body: OpenCase): Observable<DeskCase> {
    return this.http.post<{ data: DeskCase }>('/api/desk/cases', body).pipe(map((r) => r.data));
  }

  update(id: number, body: { status?: CaseStatus; assignee_id?: number | null; category_id?: number }): Observable<DeskCase> {
    return this.http.patch<{ data: DeskCase }>(`/api/desk/cases/${id}`, body).pipe(map((r) => r.data));
  }

  comment(id: number, body: NewComment): Observable<DeskCase> {
    return this.http.post<{ data: DeskCase }>(`/api/desk/cases/${id}/comments`, body).pipe(map((r) => r.data));
  }

  attach(id: number, file: File): Observable<DeskCase> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ data: DeskCase }>(`/api/desk/cases/${id}/attachments`, form).pipe(map((r) => r.data));
  }

  /** Same-origin download link (cookie session); the API answers with Content-Disposition: attachment. */
  attachmentUrl(caseId: number, attachmentId: number): string {
    return `/api/desk/cases/${caseId}/attachments/${attachmentId}`;
  }
}

export function deskErrorKey(error: unknown): string {
  return apiErrorKey(error, 'desk', DESK_ERROR_CODES);
}

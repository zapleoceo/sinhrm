import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { AnonymousReport, HandledReport, ReportStatus, SAFE_SPEAK_ERROR_CODES, SubmitReport } from './safe-speak.model';

/**
 * HTTP client of Safe Speak. The anonymous side (/api/safe-speak/public/*) runs without a session on the server;
 * the access code is always sent in the request body, never in a URL.
 */
@Injectable({ providedIn: 'root' })
export class SafeSpeakService {
  private readonly http = inject(HttpClient);

  submit(body: SubmitReport): Observable<{ code: string; report: AnonymousReport }> {
    return this.http.post<{ data: { code: string; report: AnonymousReport } }>('/api/safe-speak/public/reports', body).pipe(map((r) => r.data));
  }

  followUp(code: string): Observable<AnonymousReport> {
    return this.http.post<{ data: AnonymousReport }>('/api/safe-speak/public/follow-up', { code }).pipe(map((r) => r.data));
  }

  reply(code: string, body: string): Observable<AnonymousReport> {
    return this.http.post<{ data: AnonymousReport }>('/api/safe-speak/public/reply', { code, body }).pipe(map((r) => r.data));
  }

  isHandler(): Observable<boolean> {
    return this.http.get<{ data: { handler: boolean } }>('/api/safe-speak/me').pipe(map((r) => r.data.handler));
  }

  inbox(status?: ReportStatus): Observable<HandledReport[]> {
    return this.http.get<{ data: HandledReport[] }>('/api/safe-speak/reports', { params: toParams({ status }) }).pipe(map((r) => r.data));
  }

  get(id: number): Observable<HandledReport> {
    return this.http.get<{ data: HandledReport }>(`/api/safe-speak/reports/${id}`).pipe(map((r) => r.data));
  }

  answer(id: number, body: string): Observable<HandledReport> {
    return this.http.post<{ data: HandledReport }>(`/api/safe-speak/reports/${id}/messages`, { body }).pipe(map((r) => r.data));
  }

  setStatus(id: number, status: ReportStatus): Observable<HandledReport> {
    return this.http.patch<{ data: HandledReport }>(`/api/safe-speak/reports/${id}`, { status }).pipe(map((r) => r.data));
  }
}

export function safeSpeakErrorKey(error: unknown): string {
  return apiErrorKey(error, 'safeSpeak', SAFE_SPEAK_ERROR_CODES);
}

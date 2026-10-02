import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { AnonymousReport, HandledReport, ReportStatus, SAFE_SPEAK_ERROR_CODES, SubmitReport } from './safe-speak.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/**
 * HTTP client of Safe Speak. The anonymous side (/api/safe-speak/public/*) runs without a session on the server;
 * the access code is always sent in the request body, never in a URL.
 */
@Injectable({ providedIn: 'root' })
export class SafeSpeakService {
  private readonly http = inject(HttpClient);

  submit(body: SubmitReport): Observable<{ code: string; report: AnonymousReport }> {
    return this.http.post<DataEnvelope<{ code: string; report: AnonymousReport }>>('/api/safe-speak/public/reports', body).pipe(unwrapData());
  }

  followUp(code: string): Observable<AnonymousReport> {
    return this.http.post<DataEnvelope<AnonymousReport>>('/api/safe-speak/public/follow-up', { code }).pipe(unwrapData());
  }

  reply(code: string, body: string): Observable<AnonymousReport> {
    return this.http.post<DataEnvelope<AnonymousReport>>('/api/safe-speak/public/reply', { code, body }).pipe(unwrapData());
  }

  isHandler(): Observable<boolean> {
    return this.http.get<DataEnvelope<{ handler: boolean }>>('/api/safe-speak/me').pipe(map((r) => r.data.handler));
  }

  inbox(status?: ReportStatus): Observable<HandledReport[]> {
    return this.http.get<DataEnvelope<HandledReport[]>>('/api/safe-speak/reports', { params: toParams({ status }) }).pipe(unwrapData());
  }

  get(id: number): Observable<HandledReport> {
    return this.http.get<DataEnvelope<HandledReport>>(`/api/safe-speak/reports/${id}`).pipe(unwrapData());
  }

  answer(id: number, body: string): Observable<HandledReport> {
    return this.http.post<DataEnvelope<HandledReport>>(`/api/safe-speak/reports/${id}/messages`, { body }).pipe(unwrapData());
  }

  setStatus(id: number, status: ReportStatus): Observable<HandledReport> {
    return this.http.patch<DataEnvelope<HandledReport>>(`/api/safe-speak/reports/${id}`, { status }).pipe(unwrapData());
  }
}

export function safeSpeakErrorKey(error: unknown): string {
  return apiErrorKey(error, 'safeSpeak', SAFE_SPEAK_ERROR_CODES);
}

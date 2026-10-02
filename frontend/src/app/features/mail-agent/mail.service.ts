import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AssignSender,
  MAIL_ERROR_CODES,
  MailStatus,
  ProcessedMail,
  SaveSenderRule,
  SenderRule,
  SyncCounts,
  UnknownSender,
} from './mail.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/mail';

/** HTTP of the MailAgent module (superadmin). */
@Injectable({ providedIn: 'root' })
export class MailService {
  private readonly http = inject(HttpClient);

  status(): Observable<MailStatus> {
    return this.http.get<DataEnvelope<MailStatus>>(`${API}/status`).pipe(unwrapData());
  }

  sync(): Observable<SyncCounts> {
    return this.http.post<DataEnvelope<SyncCounts>>(`${API}/sync`, {}).pipe(unwrapData());
  }

  rules(): Observable<SenderRule[]> {
    return this.http.get<DataEnvelope<SenderRule[]>>(`${API}/rules`).pipe(unwrapData());
  }

  createRule(body: SaveSenderRule): Observable<SenderRule> {
    return this.http.post<DataEnvelope<SenderRule>>(`${API}/rules`, body).pipe(unwrapData());
  }

  updateRule(id: number, body: SaveSenderRule): Observable<SenderRule> {
    return this.http.patch<DataEnvelope<SenderRule>>(`${API}/rules/${id}`, body).pipe(unwrapData());
  }

  deleteRule(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/rules/${id}`);
  }

  unknown(): Observable<UnknownSender[]> {
    return this.http.get<DataEnvelope<UnknownSender[]>>(`${API}/unknown-senders`).pipe(unwrapData());
  }

  assign(id: number, body: AssignSender): Observable<SenderRule> {
    return this.http.post<DataEnvelope<SenderRule>>(`${API}/unknown-senders/${id}/assign`, body).pipe(unwrapData());
  }

  dismiss(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/unknown-senders/${id}`);
  }

  messages(): Observable<ProcessedMail[]> {
    return this.http.get<DataEnvelope<ProcessedMail[]>>(`${API}/messages`).pipe(unwrapData());
  }
}

/** i18n key for a failed mail API call. */
export function mailErrorKey(error: unknown): string {
  return apiErrorKey(error, 'mail', MAIL_ERROR_CODES, { statuses: [422], fallback: 'mail.errors.generic' });
}

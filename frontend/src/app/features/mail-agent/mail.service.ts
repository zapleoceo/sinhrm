import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
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

const API = '/api/mail';

/** HTTP of the MailAgent module (superadmin). */
@Injectable({ providedIn: 'root' })
export class MailService {
  private readonly http = inject(HttpClient);

  status(): Observable<MailStatus> {
    return this.http.get<{ data: MailStatus }>(`${API}/status`).pipe(map((r) => r.data));
  }

  sync(): Observable<SyncCounts> {
    return this.http.post<{ data: SyncCounts }>(`${API}/sync`, {}).pipe(map((r) => r.data));
  }

  rules(): Observable<SenderRule[]> {
    return this.http.get<{ data: SenderRule[] }>(`${API}/rules`).pipe(map((r) => r.data));
  }

  createRule(body: SaveSenderRule): Observable<SenderRule> {
    return this.http.post<{ data: SenderRule }>(`${API}/rules`, body).pipe(map((r) => r.data));
  }

  updateRule(id: number, body: SaveSenderRule): Observable<SenderRule> {
    return this.http.patch<{ data: SenderRule }>(`${API}/rules/${id}`, body).pipe(map((r) => r.data));
  }

  deleteRule(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/rules/${id}`);
  }

  unknown(): Observable<UnknownSender[]> {
    return this.http.get<{ data: UnknownSender[] }>(`${API}/unknown-senders`).pipe(map((r) => r.data));
  }

  assign(id: number, body: AssignSender): Observable<SenderRule> {
    return this.http.post<{ data: SenderRule }>(`${API}/unknown-senders/${id}/assign`, body).pipe(map((r) => r.data));
  }

  dismiss(id: number): Observable<void> {
    return this.http.delete<void>(`${API}/unknown-senders/${id}`);
  }

  messages(): Observable<ProcessedMail[]> {
    return this.http.get<{ data: ProcessedMail[] }>(`${API}/messages`).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed mail API call. */
export function mailErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (MAIL_ERROR_CODES as readonly string[]).includes(code)) {
      return `mail.errors.${code}`;
    }
    if (error.status === 422) {
      return 'mail.errors.validation';
    }
  }
  return 'mail.errors.generic';
}

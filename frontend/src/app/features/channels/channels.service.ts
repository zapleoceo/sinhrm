import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, map } from 'rxjs';
import { Touchpoint } from '../recruiting/recruiting.model';
import {
  CHANNEL_ERROR_CODES,
  ChannelAvailability,
  ChannelInfo,
  ChannelMode,
  SendMessage,
  SimulateEvent,
  SimulateResult,
} from './channels.model';

/** HTTP client of the Channels API + the channel availability shared by every candidate card. */
@Injectable({ providedIn: 'root' })
export class ChannelsService {
  private readonly http = inject(HttpClient);
  private availabilityRequested = false;

  /** GET /api/channels, loaded once per session (the card only needs off / demo / live). */
  readonly availability = signal<readonly ChannelAvailability[]>([]);

  ensureAvailability(): void {
    if (this.availabilityRequested) {
      return;
    }
    this.availabilityRequested = true;
    this.http.get<{ data: ChannelAvailability[] }>('/api/channels').subscribe({
      next: (r) => this.availability.set(r.data),
      error: () => {
        this.availabilityRequested = false;
      },
    });
  }

  /** Mode of a timeline channel ("telegram", "call", …); unknown → off. */
  modeOf(channel: string): ChannelMode {
    return this.availability().find((a) => a.channel === channel && a.mode !== 'off')?.mode ?? 'off';
  }

  send(candidateId: number, body: SendMessage): Observable<Touchpoint> {
    return this.http.post<{ data: Touchpoint }>(`/api/candidates/${candidateId}/messages`, body).pipe(map((r) => r.data));
  }

  adminOverview(): Observable<ChannelInfo[]> {
    return this.http.get<{ data: ChannelInfo[] }>('/api/channels/admin').pipe(map((r) => r.data));
  }

  registerWebhook(key: string): Observable<void> {
    return this.http.post<unknown>(`/api/channels/${key}/register-webhook`, {}).pipe(map(() => undefined));
  }

  sendTest(key: string, to: string, text: string): Observable<void> {
    return this.http.post<unknown>(`/api/channels/${key}/test`, { to, text: text || undefined }).pipe(map(() => undefined));
  }

  simulate(key: string, body: SimulateEvent): Observable<SimulateResult> {
    return this.http.post<{ data: SimulateResult }>(`/api/channels/${key}/simulate`, body).pipe(map((r) => r.data));
  }
}

/** Code of a failed Channels API call if it is a known business code, else null. */
export function channelErrorCode(error: unknown): string | null {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (CHANNEL_ERROR_CODES as readonly string[]).includes(code)) {
      return code;
    }
  }
  return null;
}

/** i18n key for a failed Channels API call. */
export function channelErrorKey(error: unknown): string {
  const code = channelErrorCode(error);
  if (code) {
    return `channels.errors.${code}`;
  }
  if (error instanceof HttpErrorResponse && error.status === 403) {
    return 'recruiting.errors.forbidden';
  }
  return 'channels.errors.generic';
}

/** URL to paste in the provider console; telephony adds the shared token as a placeholder (the value is never shown). */
export function webhookUrlForConsole(info: ChannelInfo, tokenPlaceholder: string): string {
  return info.auth === 'query_token' ? `${info.webhook_url}?token=${tokenPlaceholder}` : info.webhook_url;
}

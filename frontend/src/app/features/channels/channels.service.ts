import { HttpClient } from '@angular/common/http';
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
import { apiErrorCode, apiErrorStatus } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

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
    this.http.get<DataEnvelope<ChannelAvailability[]>>('/api/channels').subscribe({
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

  /** Why a channel is off (e-mail: "reconnect_to_send" when Google is connected read-only); null otherwise. */
  reasonOf(channel: string): string | null {
    return this.availability().find((a) => a.channel === channel)?.reason ?? null;
  }

  send(candidateId: number, body: SendMessage): Observable<Touchpoint> {
    return this.http.post<DataEnvelope<Touchpoint>>(`/api/candidates/${candidateId}/messages`, body).pipe(unwrapData());
  }

  adminOverview(): Observable<ChannelInfo[]> {
    return this.http.get<DataEnvelope<ChannelInfo[]>>('/api/channels/admin').pipe(unwrapData());
  }

  registerWebhook(key: string): Observable<void> {
    return this.http.post<unknown>(`/api/channels/${key}/register-webhook`, {}).pipe(map(() => undefined));
  }

  sendTest(key: string, to: string, text: string): Observable<void> {
    return this.http.post<unknown>(`/api/channels/${key}/test`, { to, text: text || undefined }).pipe(map(() => undefined));
  }

  simulate(key: string, body: SimulateEvent): Observable<SimulateResult> {
    return this.http.post<DataEnvelope<SimulateResult>>(`/api/channels/${key}/simulate`, body).pipe(unwrapData());
  }
}

/** Code of a failed Channels API call if it is a known business code, else null. */
export function channelErrorCode(error: unknown): string | null {
  const code = apiErrorCode(error);
  return code !== null && (CHANNEL_ERROR_CODES as readonly string[]).includes(code) ? code : null;
}

/** i18n key for a failed Channels API call. */
export function channelErrorKey(error: unknown): string {
  const code = channelErrorCode(error);
  if (code) {
    return `channels.errors.${code}`;
  }
  // 403 comes from the recruiting card (the candidate is out of the user's scope) — the recruiting text explains it.
  return apiErrorStatus(error) === 403 ? 'recruiting.errors.forbidden' : 'channels.errors.generic';
}

/** URL to paste in the provider console; telephony adds the shared token as a placeholder (the value is never shown). */
export function webhookUrlForConsole(info: ChannelInfo, tokenPlaceholder: string): string {
  return info.auth === 'query_token' ? `${info.webhook_url}?token=${tokenPlaceholder}` : info.webhook_url;
}

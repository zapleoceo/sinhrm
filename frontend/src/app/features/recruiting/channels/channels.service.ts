import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../../core/http/api-error';
import { AcquisitionChannel, ChannelCost, ChannelType, UtmRule } from '../recruiting.model';

export const CHANNEL_ERROR_CODES = ['channel_code_taken', 'channel_inactive', 'empty_utm_rule'] as const;

export type UtmInput = Pick<UtmRule, 'utm_source' | 'utm_medium' | 'utm_campaign'> & { priority?: number };

/** Admin side of the acquisition channels dictionary (/api/acquisition-channels): channels, UTM rules, costs. */
@Injectable({ providedIn: 'root' })
export class ChannelsService {
  private readonly http = inject(HttpClient);

  save(id: number | null, body: Partial<{ code: string; name: string; type: ChannelType; active: boolean }>): Observable<AcquisitionChannel> {
    const call =
      id === null
        ? this.http.post<{ data: AcquisitionChannel }>('/api/acquisition-channels', body)
        : this.http.patch<{ data: AcquisitionChannel }>(`/api/acquisition-channels/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  addRule(channelId: number, rule: UtmInput): Observable<AcquisitionChannel> {
    return this.http.post<{ data: AcquisitionChannel }>(`/api/acquisition-channels/${channelId}/utm-rules`, rule).pipe(map((r) => r.data));
  }

  deleteRule(ruleId: number): Observable<void> {
    return this.http.delete<void>(`/api/acquisition-channels/utm-rules/${ruleId}`);
  }

  addCost(channelId: number, cost: Omit<ChannelCost, 'id' | 'currency' | 'note'> & { currency?: string; note?: string | null }): Observable<AcquisitionChannel> {
    return this.http.post<{ data: AcquisitionChannel }>(`/api/acquisition-channels/${channelId}/costs`, cost).pipe(map((r) => r.data));
  }

  deleteCost(costId: number): Observable<void> {
    return this.http.delete<void>(`/api/acquisition-channels/costs/${costId}`);
  }

  /** "Which channel would this UTM give?" */
  preview(utm: UtmInput): Observable<{ channel_id: number | null; rule_id: number | null }> {
    return this.http.post<{ data: { channel_id: number | null; rule_id: number | null } }>('/api/acquisition-channels/resolve', utm).pipe(map((r) => r.data));
  }
}

export function channelErrorKey(error: unknown): string {
  return apiErrorKey(error, 'recruiting.channels', CHANNEL_ERROR_CODES);
}

/** "utm_source=facebook · utm_medium=paid" for a rule (empty parts skipped). */
export function ruleLabel(rule: Pick<UtmRule, 'utm_source' | 'utm_medium' | 'utm_campaign'>): string {
  return (
    [
      ['utm_source', rule.utm_source],
      ['utm_medium', rule.utm_medium],
      ['utm_campaign', rule.utm_campaign],
    ] as const
  )
    .filter(([, v]) => v !== null && v !== '')
    .map(([k, v]) => `${k}=${v}`)
    .join(' · ');
}

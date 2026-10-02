import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AssistantStatus, McpTokenCreated, McpTokenInfo, QuipsResult, TranscriptionResult, TurnRequest, TurnResult } from './assistant.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/assistant';

/** HTTP client of the «Стік» assistant (docs/modules/assistant.md). */
@Injectable({ providedIn: 'root' })
export class AssistantService {
  private readonly http = inject(HttpClient);

  status(): Observable<AssistantStatus> {
    return this.http.get<DataEnvelope<AssistantStatus>>(`${API}/status`).pipe(unwrapData());
  }

  turn(request: TurnRequest): Observable<TurnResult> {
    return this.http.post<DataEnvelope<TurnResult>>(`${API}/turn`, request).pipe(unwrapData());
  }

  poll(requestId: number): Observable<TurnResult> {
    return this.http.get<DataEnvelope<TurnResult>>(`${API}/turns/${requestId}`).pipe(unwrapData());
  }

  /** Voice dictation: multipart upload (the browser sets the boundary; CSRF comes from the global interceptor). */
  transcribe(audio: Blob, filename: string): Observable<TranscriptionResult> {
    const form = new FormData();
    form.append('audio', audio, filename);
    return this.http.post<DataEnvelope<TranscriptionResult>>(`${API}/transcribe`, form).pipe(unwrapData());
  }

  transcription(requestId: number): Observable<TranscriptionResult> {
    return this.http.get<DataEnvelope<TranscriptionResult>>(`${API}/transcriptions/${requestId}`).pipe(unwrapData());
  }

  /** AI jokes for the mascot (cached on the server for 6 h). */
  quips(situation: string, locale: string): Observable<QuipsResult> {
    return this.http.get<DataEnvelope<QuipsResult>>(`${API}/quips`, { params: { situation, locale } }).pipe(unwrapData());
  }

  mcpToken(): Observable<McpTokenInfo> {
    return this.http.get<DataEnvelope<McpTokenInfo>>(`${API}/mcp-token`).pipe(unwrapData());
  }

  createMcpToken(): Observable<McpTokenCreated> {
    return this.http.post<DataEnvelope<McpTokenCreated>>(`${API}/mcp-token`, {}).pipe(unwrapData());
  }

  revokeMcpToken(): Observable<void> {
    return this.http.delete<void>(`${API}/mcp-token`);
  }
}

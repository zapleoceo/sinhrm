import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { AssistantStatus, McpTokenCreated, McpTokenInfo, QuipsResult, TranscriptionResult, TurnRequest, TurnResult } from './assistant.model';

const API = '/api/assistant';

/** HTTP client of the «Стік» assistant (docs/modules/assistant.md). */
@Injectable({ providedIn: 'root' })
export class AssistantService {
  private readonly http = inject(HttpClient);

  status(): Observable<AssistantStatus> {
    return this.http.get<{ data: AssistantStatus }>(`${API}/status`).pipe(map((r) => r.data));
  }

  turn(request: TurnRequest): Observable<TurnResult> {
    return this.http.post<{ data: TurnResult }>(`${API}/turn`, request).pipe(map((r) => r.data));
  }

  poll(requestId: number): Observable<TurnResult> {
    return this.http.get<{ data: TurnResult }>(`${API}/turns/${requestId}`).pipe(map((r) => r.data));
  }

  /** Voice dictation: multipart upload (the browser sets the boundary; CSRF comes from the global interceptor). */
  transcribe(audio: Blob, filename: string): Observable<TranscriptionResult> {
    const form = new FormData();
    form.append('audio', audio, filename);
    return this.http.post<{ data: TranscriptionResult }>(`${API}/transcribe`, form).pipe(map((r) => r.data));
  }

  transcription(requestId: number): Observable<TranscriptionResult> {
    return this.http.get<{ data: TranscriptionResult }>(`${API}/transcriptions/${requestId}`).pipe(map((r) => r.data));
  }

  /** AI jokes for the mascot (cached on the server for 6 h). */
  quips(situation: string, locale: string): Observable<QuipsResult> {
    return this.http.get<{ data: QuipsResult }>(`${API}/quips`, { params: { situation, locale } }).pipe(map((r) => r.data));
  }

  mcpToken(): Observable<McpTokenInfo> {
    return this.http.get<{ data: McpTokenInfo }>(`${API}/mcp-token`).pipe(map((r) => r.data));
  }

  createMcpToken(): Observable<McpTokenCreated> {
    return this.http.post<{ data: McpTokenCreated }>(`${API}/mcp-token`, {}).pipe(map((r) => r.data));
  }

  revokeMcpToken(): Observable<void> {
    return this.http.delete<void>(`${API}/mcp-token`);
  }
}

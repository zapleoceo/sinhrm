import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  GOOGLE_ERROR_CODES,
  GoogleStatus,
  SaveSheetImport,
  ScheduleMeeting,
  ScheduledMeeting,
  SheetImport,
  SheetImportReport,
  SheetInspection,
  SheetMapping,
} from './google.model';
import { apiErrorKey } from '../../core/api/api-error';

const API = '/api/google';

/** HTTP of the GoogleWorkspace module: connection status, meetings, Google Sheets import. */
@Injectable({ providedIn: 'root' })
export class GoogleService {
  private readonly http = inject(HttpClient);

  /** Superadmin only. */
  status(): Observable<GoogleStatus> {
    return this.http.get<GoogleStatus>(`${API}/status`);
  }

  /** Any user: can the candidate card schedule meetings? */
  calendarConnected(): Observable<boolean> {
    return this.http.get<{ data: { connected: boolean } }>(`${API}/calendar`).pipe(map((r) => r.data.connected));
  }

  scheduleMeeting(candidateId: number, body: ScheduleMeeting): Observable<ScheduledMeeting> {
    return this.http
      .post<{ data: ScheduledMeeting }>(`${API}/candidates/${candidateId}/meetings`, body)
      .pipe(map((r) => r.data));
  }

  inspectSheet(url: string, sheet: string): Observable<SheetInspection> {
    return this.http.post<{ data: SheetInspection }>(`${API}/sheets/inspect`, { url, sheet }).pipe(map((r) => r.data));
  }

  imports(): Observable<SheetImport[]> {
    return this.http.get<{ data: SheetImport[] }>(`${API}/sheets/imports`).pipe(map((r) => r.data));
  }

  saveImport(body: SaveSheetImport): Observable<{ data: SheetImport; report: SheetImportReport }> {
    return this.http.post<{ data: SheetImport; report: SheetImportReport }>(`${API}/sheets/imports`, body);
  }

  updateImport(id: number, body: { auto_sync?: boolean; mapping?: SheetMapping }): Observable<SheetImport> {
    return this.http.patch<{ data: SheetImport }>(`${API}/sheets/imports/${id}`, body).pipe(map((r) => r.data));
  }

  runImport(id: number): Observable<{ data: SheetImport; report: SheetImportReport }> {
    return this.http.post<{ data: SheetImport; report: SheetImportReport }>(`${API}/sheets/imports/${id}/run`, {});
  }
}

/** i18n key for a failed Google call. */
export function googleErrorKey(error: unknown): string {
  return apiErrorKey(error, 'google', GOOGLE_ERROR_CODES, { statuses: [422], fallback: 'google.errors.generic' });
}

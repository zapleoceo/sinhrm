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
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

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
    return this.http.get<DataEnvelope<{ connected: boolean }>>(`${API}/calendar`).pipe(map((r) => r.data.connected));
  }

  scheduleMeeting(candidateId: number, body: ScheduleMeeting): Observable<ScheduledMeeting> {
    return this.http
      .post<DataEnvelope<ScheduledMeeting>>(`${API}/candidates/${candidateId}/meetings`, body)
      .pipe(unwrapData());
  }

  inspectSheet(url: string, sheet: string): Observable<SheetInspection> {
    return this.http.post<DataEnvelope<SheetInspection>>(`${API}/sheets/inspect`, { url, sheet }).pipe(unwrapData());
  }

  imports(): Observable<SheetImport[]> {
    return this.http.get<DataEnvelope<SheetImport[]>>(`${API}/sheets/imports`).pipe(unwrapData());
  }

  saveImport(body: SaveSheetImport): Observable<{ data: SheetImport; report: SheetImportReport }> {
    return this.http.post<{ data: SheetImport; report: SheetImportReport }>(`${API}/sheets/imports`, body);
  }

  updateImport(id: number, body: { auto_sync?: boolean; mapping?: SheetMapping }): Observable<SheetImport> {
    return this.http.patch<DataEnvelope<SheetImport>>(`${API}/sheets/imports/${id}`, body).pipe(unwrapData());
  }

  runImport(id: number): Observable<{ data: SheetImport; report: SheetImportReport }> {
    return this.http.post<{ data: SheetImport; report: SheetImportReport }>(`${API}/sheets/imports/${id}/run`, {});
  }
}

/** i18n key for a failed Google call. */
export function googleErrorKey(error: unknown): string {
  return apiErrorKey(error, 'google', GOOGLE_ERROR_CODES, { statuses: [422], fallback: 'google.errors.generic' });
}

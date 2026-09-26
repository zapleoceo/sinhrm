import { HttpClient, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { UserRole } from '../../core/auth/auth.model';
import { apiErrorKey, saveBlob } from '../../core/http/api-error';

export type DataSubjectType = 'candidate' | 'employee';
export type ExportFormat = 'json' | 'html';

export interface PrivacySettings {
  /** Auto-anonymize rejected candidates after N months; null = off. */
  retention_rejected_months: number | null;
}

/** Mirrors backend gate privacy-manage: export, erase and the retention rule are for superadmin and admin. */
export function canManagePrivacy(roles: readonly UserRole[]): boolean {
  return roles.some((r) => r === 'superadmin' || r === 'admin');
}

/** Error of a blocked request (hired, not_terminated) or by status (403/404/422) → i18n key. */
export function privacyErrorKey(e: unknown): string {
  return apiErrorKey(e, 'privacy', ['hired', 'not_terminated']);
}

/** /api/privacy/* — personal-data rights (Law of Ukraine No. 2297-VI). */
@Injectable({ providedIn: 'root' })
export class PrivacyService {
  private readonly http = inject(HttpClient);

  /** Downloads the export file (JSON or human-readable HTML) through the browser. */
  download(type: DataSubjectType, id: number, format: ExportFormat): Observable<void> {
    return this.http
      .get(`/api/privacy/${type}/${id}/export`, { params: { format }, responseType: 'blob', observe: 'response' })
      .pipe(map((res) => saveBlob(res.body ?? new Blob(), fileName(res, `personal-data-${type}-${id}.${format}`))));
  }

  erase(type: DataSubjectType, id: number, reason: string): Observable<void> {
    return this.http.post<unknown>(`/api/privacy/${type}/${id}/erase`, { reason, confirm: true }).pipe(map(() => undefined));
  }

  settings(): Observable<PrivacySettings> {
    return this.http.get<{ data: PrivacySettings }>('/api/privacy/settings').pipe(map((r) => r.data));
  }

  saveSettings(body: PrivacySettings): Observable<PrivacySettings> {
    return this.http.put<{ data: PrivacySettings }>('/api/privacy/settings', body).pipe(map((r) => r.data));
  }
}

/** File name from Content-Disposition, or the fallback. */
function fileName(res: HttpResponse<Blob>, fallback: string): string {
  return /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? fallback;
}

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import {
  CreateDocument,
  DOCUMENT_ERROR_CODES,
  DocumentQuery,
  DocumentTemplate,
  HrDocument,
  SaveDocumentTemplate,
  TemplatePreview,
  UpdateDocument,
} from './documents.model';

/** HTTP client of the Documents API (/api/documents, /api/documents/templates, /api/me/documents). */
@Injectable({ providedIn: 'root' })
export class DocumentsService {
  private readonly http = inject(HttpClient);

  templates(withArchived = false): Observable<DocumentTemplate[]> {
    return this.http
      .get<{ data: DocumentTemplate[] }>('/api/documents/templates', { params: toParams({ archived: withArchived ? 1 : undefined }) })
      .pipe(map((r) => r.data));
  }

  createTemplate(body: { name: string; body: string; category?: string | null }): Observable<DocumentTemplate> {
    return this.http.post<{ data: DocumentTemplate }>('/api/documents/templates', body).pipe(map((r) => r.data));
  }

  updateTemplate(id: number, body: SaveDocumentTemplate): Observable<DocumentTemplate> {
    return this.http.patch<{ data: DocumentTemplate }>(`/api/documents/templates/${id}`, body).pipe(map((r) => r.data));
  }

  preview(body: string, employeeId?: number): Observable<TemplatePreview> {
    const payload = employeeId === undefined ? { body } : { body, employee_id: employeeId };
    return this.http.post<{ data: TemplatePreview }>('/api/documents/templates/preview', payload).pipe(map((r) => r.data));
  }

  list(query: DocumentQuery = {}): Observable<HrDocument[]> {
    return this.http.get<{ data: HrDocument[] }>('/api/documents', { params: toParams({ ...query }) }).pipe(map((r) => r.data));
  }

  mine(): Observable<HrDocument[]> {
    return this.http.get<{ data: HrDocument[] }>('/api/me/documents').pipe(map((r) => r.data));
  }

  get(id: number): Observable<HrDocument> {
    return this.http.get<{ data: HrDocument }>(`/api/documents/${id}`).pipe(map((r) => r.data));
  }

  /** Same-origin download link (cookie session); the API answers with Content-Disposition: attachment. */
  fileUrl(id: number): string {
    return `/api/documents/${id}/file`;
  }

  create(body: CreateDocument): Observable<HrDocument> {
    return this.http.post<{ data: HrDocument }>('/api/documents', body).pipe(map((r) => r.data));
  }

  update(id: number, body: UpdateDocument): Observable<HrDocument> {
    return this.http.patch<{ data: HrDocument }>(`/api/documents/${id}`, body).pipe(map((r) => r.data));
  }

  upload(id: number, file: File): Observable<HrDocument> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ data: HrDocument }>(`/api/documents/${id}/file`, form).pipe(map((r) => r.data));
  }

  send(id: number): Observable<HrDocument> {
    return this.http.post<{ data: HrDocument }>(`/api/documents/${id}/send`, {}).pipe(map((r) => r.data));
  }

  acknowledge(id: number): Observable<HrDocument> {
    return this.http.post<{ data: HrDocument }>(`/api/documents/${id}/acknowledge`, {}).pipe(map((r) => r.data));
  }

  reject(id: number, reason: string | null): Observable<HrDocument> {
    return this.http.post<{ data: HrDocument }>(`/api/documents/${id}/reject`, reason ? { reason } : {}).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Documents API call. */
export function documentsErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (DOCUMENT_ERROR_CODES as readonly string[]).includes(code)) {
      return `documents.errors.${code}`;
    }
    if (error.status === 403) {
      return 'documents.errors.forbidden';
    }
    if (error.status === 404) {
      return 'documents.errors.not_found';
    }
    if (error.status === 422) {
      return 'documents.errors.validation';
    }
  }
  return 'common.error';
}

/** Unknown {tokens} reported by a 422 unknown_variables answer. */
export function unknownVariables(error: unknown): string[] {
  if (error instanceof HttpErrorResponse) {
    const vars: unknown = (error.error as { variables?: unknown } | null)?.variables;
    if (Array.isArray(vars)) {
      return vars.filter((v): v is string => typeof v === 'string');
    }
  }
  return [];
}

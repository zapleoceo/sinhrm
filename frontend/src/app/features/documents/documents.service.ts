import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { toParams } from '../../core/api/http-params';
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
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Documents API (/api/documents, /api/documents/templates, /api/me/documents). */
@Injectable({ providedIn: 'root' })
export class DocumentsService {
  private readonly http = inject(HttpClient);

  templates(withArchived = false): Observable<DocumentTemplate[]> {
    return this.http
      .get<DataEnvelope<DocumentTemplate[]>>('/api/documents/templates', { params: toParams({ archived: withArchived ? 1 : undefined }) })
      .pipe(unwrapData());
  }

  createTemplate(body: { name: string; body: string; category?: string | null }): Observable<DocumentTemplate> {
    return this.http.post<DataEnvelope<DocumentTemplate>>('/api/documents/templates', body).pipe(unwrapData());
  }

  updateTemplate(id: number, body: SaveDocumentTemplate): Observable<DocumentTemplate> {
    return this.http.patch<DataEnvelope<DocumentTemplate>>(`/api/documents/templates/${id}`, body).pipe(unwrapData());
  }

  preview(body: string, employeeId?: number): Observable<TemplatePreview> {
    const payload = employeeId === undefined ? { body } : { body, employee_id: employeeId };
    return this.http.post<DataEnvelope<TemplatePreview>>('/api/documents/templates/preview', payload).pipe(unwrapData());
  }

  list(query: DocumentQuery = {}): Observable<HrDocument[]> {
    return this.http.get<DataEnvelope<HrDocument[]>>('/api/documents', { params: toParams({ ...query }) }).pipe(unwrapData());
  }

  mine(): Observable<HrDocument[]> {
    return this.http.get<DataEnvelope<HrDocument[]>>('/api/me/documents').pipe(unwrapData());
  }

  get(id: number): Observable<HrDocument> {
    return this.http.get<DataEnvelope<HrDocument>>(`/api/documents/${id}`).pipe(unwrapData());
  }

  /** Same-origin download link (cookie session); the API answers with Content-Disposition: attachment. */
  fileUrl(id: number): string {
    return `/api/documents/${id}/file`;
  }

  create(body: CreateDocument): Observable<HrDocument> {
    return this.http.post<DataEnvelope<HrDocument>>('/api/documents', body).pipe(unwrapData());
  }

  update(id: number, body: UpdateDocument): Observable<HrDocument> {
    return this.http.patch<DataEnvelope<HrDocument>>(`/api/documents/${id}`, body).pipe(unwrapData());
  }

  upload(id: number, file: File): Observable<HrDocument> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<DataEnvelope<HrDocument>>(`/api/documents/${id}/file`, form).pipe(unwrapData());
  }

  send(id: number): Observable<HrDocument> {
    return this.http.post<DataEnvelope<HrDocument>>(`/api/documents/${id}/send`, {}).pipe(unwrapData());
  }

  acknowledge(id: number): Observable<HrDocument> {
    return this.http.post<DataEnvelope<HrDocument>>(`/api/documents/${id}/acknowledge`, {}).pipe(unwrapData());
  }

  reject(id: number, reason: string | null): Observable<HrDocument> {
    return this.http.post<DataEnvelope<HrDocument>>(`/api/documents/${id}/reject`, reason ? { reason } : {}).pipe(unwrapData());
  }
}

/** i18n key for a failed Documents API call. */
export function documentsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'documents', DOCUMENT_ERROR_CODES, { statuses: [403, 404, 422] });
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

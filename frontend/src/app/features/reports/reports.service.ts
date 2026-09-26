import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { BuilderResult, BuilderSpec, CatalogGroup, DatasetInfo, ReportFilter, ReportResult, SavedKind, SavedReport, filterParams } from './reports.model';

/** HTTP client of the Reports API (/api/reports/{catalog,builder,saved}). CSV answers come as Blobs. */
@Injectable({ providedIn: 'root' })
export class ReportsService {
  private readonly http = inject(HttpClient);

  catalog(): Observable<CatalogGroup[]> {
    return this.http.get<{ data: CatalogGroup[] }>('/api/reports/catalog').pipe(map((r) => r.data));
  }

  run(key: string, filters: Partial<Record<ReportFilter, string | number | null>>): Observable<ReportResult> {
    return this.http.get<{ data: ReportResult }>(`/api/reports/catalog/${key}`, { params: filterParams(filters) }).pipe(map((r) => r.data));
  }

  csv(key: string, filters: Partial<Record<ReportFilter, string | number | null>>): Observable<Blob> {
    return this.http.get(`/api/reports/catalog/${key}/csv`, { params: filterParams(filters), responseType: 'blob' });
  }

  datasets(): Observable<DatasetInfo[]> {
    return this.http.get<{ data: DatasetInfo[] }>('/api/reports/builder/datasets').pipe(map((r) => r.data));
  }

  build(spec: BuilderSpec): Observable<BuilderResult> {
    return this.http.post<{ data: BuilderResult }>('/api/reports/builder/run', spec).pipe(map((r) => r.data));
  }

  buildCsv(spec: BuilderSpec): Observable<Blob> {
    return this.http.post('/api/reports/builder/csv', spec, { responseType: 'blob' });
  }

  saved(): Observable<SavedReport[]> {
    return this.http.get<{ data: SavedReport[] }>('/api/reports/saved').pipe(map((r) => r.data));
  }

  save(id: number | null, name: string, kind: SavedKind, definition: SavedReport['definition']): Observable<SavedReport> {
    const body = { name, kind, definition };
    const call = id === null ? this.http.post<{ data: SavedReport }>('/api/reports/saved', body) : this.http.put<{ data: SavedReport }>(`/api/reports/saved/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`/api/reports/saved/${id}`);
  }

  savedCsv(id: number): Observable<Blob> {
    return this.http.get(`/api/reports/saved/${id}/run`, { params: { format: 'csv' }, responseType: 'blob' });
  }
}

export function reportsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'reports', []);
}

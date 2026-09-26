import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { ASSET_ERROR_CODES, Asset, AssetHistory, AssetQuery, AssetStatus, AssetType, SaveAsset } from './assets.model';

/** HTTP client of the Assets API (/api/assets/*). */
@Injectable({ providedIn: 'root' })
export class AssetsService {
  private readonly http = inject(HttpClient);

  types(): Observable<AssetType[]> {
    return this.http.get<{ data: AssetType[] }>('/api/assets/types').pipe(map((r) => r.data));
  }

  createType(name: string): Observable<AssetType> {
    return this.http.post<{ data: AssetType }>('/api/assets/types', { name }).pipe(map((r) => r.data));
  }

  list(query: AssetQuery = {}): Observable<Asset[]> {
    return this.http.get<{ data: Asset[] }>('/api/assets', { params: toParams({ ...query }) }).pipe(map((r) => r.data));
  }

  get(id: number): Observable<Asset> {
    return this.http.get<{ data: Asset }>(`/api/assets/${id}`).pipe(map((r) => r.data));
  }

  save(id: number | null, body: SaveAsset): Observable<Asset> {
    const call = id === null ? this.http.post<{ data: Asset }>('/api/assets', body) : this.http.patch<{ data: Asset }>(`/api/assets/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  assign(id: number, employeeId: number, date?: string, condition?: string): Observable<Asset> {
    return this.http.post<{ data: Asset }>(`/api/assets/${id}/assign`, { employee_id: employeeId, date: date || undefined, condition: condition || undefined }).pipe(map((r) => r.data));
  }

  return(id: number, status: AssetStatus, date?: string, condition?: string): Observable<Asset> {
    return this.http.post<{ data: Asset }>(`/api/assets/${id}/return`, { status, date: date || undefined, condition: condition || undefined }).pipe(map((r) => r.data));
  }

  ofEmployee(employeeId: number): Observable<AssetHistory[]> {
    return this.http.get<{ data: AssetHistory[] }>(`/api/assets/employee/${employeeId}`).pipe(map((r) => r.data));
  }
}

export function assetsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'assets', ASSET_ERROR_CODES);
}

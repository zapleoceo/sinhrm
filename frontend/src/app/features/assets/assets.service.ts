import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { ASSET_ERROR_CODES, Asset, AssetHistory, AssetQuery, AssetStatus, AssetType, SaveAsset } from './assets.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Assets API (/api/assets/*). */
@Injectable({ providedIn: 'root' })
export class AssetsService {
  private readonly http = inject(HttpClient);

  types(): Observable<AssetType[]> {
    return this.http.get<DataEnvelope<AssetType[]>>('/api/assets/types').pipe(unwrapData());
  }

  createType(name: string): Observable<AssetType> {
    return this.http.post<DataEnvelope<AssetType>>('/api/assets/types', { name }).pipe(unwrapData());
  }

  list(query: AssetQuery = {}): Observable<Asset[]> {
    return this.http.get<DataEnvelope<Asset[]>>('/api/assets', { params: toParams({ ...query }) }).pipe(unwrapData());
  }

  get(id: number): Observable<Asset> {
    return this.http.get<DataEnvelope<Asset>>(`/api/assets/${id}`).pipe(unwrapData());
  }

  save(id: number | null, body: SaveAsset): Observable<Asset> {
    const call = id === null ? this.http.post<DataEnvelope<Asset>>('/api/assets', body) : this.http.patch<DataEnvelope<Asset>>(`/api/assets/${id}`, body);
    return call.pipe(unwrapData());
  }

  assign(id: number, employeeId: number, date?: string, condition?: string): Observable<Asset> {
    return this.http.post<DataEnvelope<Asset>>(`/api/assets/${id}/assign`, { employee_id: employeeId, date: date || undefined, condition: condition || undefined }).pipe(unwrapData());
  }

  return(id: number, status: AssetStatus, date?: string, condition?: string): Observable<Asset> {
    return this.http.post<DataEnvelope<Asset>>(`/api/assets/${id}/return`, { status, date: date || undefined, condition: condition || undefined }).pipe(unwrapData());
  }

  ofEmployee(employeeId: number): Observable<AssetHistory[]> {
    return this.http.get<DataEnvelope<AssetHistory[]>>(`/api/assets/employee/${employeeId}`).pipe(unwrapData());
  }
}

export function assetsErrorKey(error: unknown): string {
  return apiErrorKey(error, 'assets', ASSET_ERROR_CODES);
}

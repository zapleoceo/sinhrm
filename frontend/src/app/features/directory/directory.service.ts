import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  DictionaryItem,
  DictionaryPage,
  DictionaryQuery,
  DictionaryType,
  SaveDictionaryItem,
} from './directory.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';
import { toParams } from '../../core/api/http-params';

const API = '/api/directory';

/** Largest page the API allows; used to fetch a whole dictionary for pickers. */
export const MAX_PER_PAGE = 200;

@Injectable({ providedIn: 'root' })
export class DirectoryService {
  private readonly http = inject(HttpClient);

  list(type: DictionaryType, query: DictionaryQuery): Observable<DictionaryPage> {
    return this.http.get<DictionaryPage>(`${API}/${type}`, { params: toParams(query) });
  }

  /** Active items for pickers (e.g. branches of a user), sorted by name on the server. */
  active(type: DictionaryType): Observable<DictionaryItem[]> {
    return this.list(type, { status: 'active', perPage: MAX_PER_PAGE }).pipe(map((page) => page.data));
  }

  create(type: DictionaryType, body: SaveDictionaryItem): Observable<DictionaryItem> {
    return this.http.post<DataEnvelope<DictionaryItem>>(`${API}/${type}`, body).pipe(unwrapData());
  }

  update(type: DictionaryType, id: number, body: SaveDictionaryItem): Observable<DictionaryItem> {
    return this.http.patch<DataEnvelope<DictionaryItem>>(`${API}/${type}/${id}`, body).pipe(unwrapData());
  }
}

/** i18n key for a failed directory API call. */
export function directoryErrorKey(error: unknown): string {
  return apiErrorKey(error, 'directory', [], { statuses: [422], fallback: 'directory.errors.generic' });
}

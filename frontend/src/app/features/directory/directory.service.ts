import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  DictionaryItem,
  DictionaryPage,
  DictionaryQuery,
  DictionaryType,
  SaveDictionaryItem,
} from './directory.model';

const API = '/api/directory';

/** Largest page the API allows; used to fetch a whole dictionary for pickers. */
export const MAX_PER_PAGE = 200;

@Injectable({ providedIn: 'root' })
export class DirectoryService {
  private readonly http = inject(HttpClient);

  list(type: DictionaryType, query: DictionaryQuery): Observable<DictionaryPage> {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }
    return this.http.get<DictionaryPage>(`${API}/${type}`, { params });
  }

  /** Active items for pickers (e.g. branches of a user), sorted by name on the server. */
  active(type: DictionaryType): Observable<DictionaryItem[]> {
    return this.list(type, { status: 'active', perPage: MAX_PER_PAGE }).pipe(map((page) => page.data));
  }

  create(type: DictionaryType, body: SaveDictionaryItem): Observable<DictionaryItem> {
    return this.http.post<{ data: DictionaryItem }>(`${API}/${type}`, body).pipe(map((r) => r.data));
  }

  update(type: DictionaryType, id: number, body: SaveDictionaryItem): Observable<DictionaryItem> {
    return this.http.patch<{ data: DictionaryItem }>(`${API}/${type}/${id}`, body).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed directory API call. */
export function directoryErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 422) {
      return 'directory.errors.validation';
    }
  }
  return 'directory.errors.generic';
}

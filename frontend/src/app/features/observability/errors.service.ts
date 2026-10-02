import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

export type ErrorStatusFilter = 'open' | 'resolved' | 'all';

/** One group of the in-app error log (GET /api/errors). */
export interface ErrorGroup {
  id: number;
  source: 'server' | 'web';
  exception_class: string;
  message: string;
  file: string | null;
  line: number | null;
  route: string | null;
  last_user_id: number | null;
  count: number;
  first_seen_at: string;
  last_seen_at: string;
  resolved: boolean;
  resolved_at: string | null;
}

const API = '/api/errors';

/** Superadmin error log: grouped list and the "resolved" toggle. */
@Injectable({ providedIn: 'root' })
export class ErrorsService {
  private readonly http = inject(HttpClient);

  list(status: ErrorStatusFilter): Observable<ErrorGroup[]> {
    return this.http.get<DataEnvelope<ErrorGroup[]>>(API, { params: { status } }).pipe(unwrapData());
  }

  setResolved(id: number, resolved: boolean): Observable<ErrorGroup> {
    return this.http.patch<DataEnvelope<ErrorGroup>>(`${API}/${id}`, { resolved }).pipe(unwrapData());
  }
}

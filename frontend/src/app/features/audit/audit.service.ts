import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AuditOptions, AuditPage, AuditPaging, AuditQuery } from './audit.model';

const API = '/api/audit';

@Injectable({ providedIn: 'root' })
export class AuditService {
  private readonly http = inject(HttpClient);

  /** Superadmin journal with filters. */
  list(query: AuditQuery): Observable<AuditPage> {
    return this.http.get<AuditPage>(API, { params: toParams(query) });
  }

  /** Filter values (entity types, actions, users) for the journal. */
  options(): Observable<AuditOptions> {
    return this.http.get<AuditOptions>(`${API}/options`);
  }

  employeeHistory(id: number, paging: AuditPaging): Observable<AuditPage> {
    return this.http.get<AuditPage>(`/api/people/${id}/history`, { params: toParams(paging) });
  }

  /** Includes the candidate's applications (stage changes). */
  candidateHistory(id: number, paging: AuditPaging): Observable<AuditPage> {
    return this.http.get<AuditPage>(`/api/candidates/${id}/history`, { params: toParams(paging) });
  }
}

function toParams(query: AuditQuery): HttpParams {
  let params = new HttpParams();
  for (const [key, value] of Object.entries(query) as [string, string | number | undefined][]) {
    if (value !== undefined && value !== null && value !== '') {
      params = params.set(key, String(value));
    }
  }
  return params;
}

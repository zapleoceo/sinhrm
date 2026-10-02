import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AuditOptions, AuditPage, AuditPaging, AuditQuery } from './audit.model';
import { toParams } from '../../core/api/http-params';

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

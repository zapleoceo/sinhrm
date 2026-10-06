import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import {
  BulkEmployeeAction,
  ChangeRequest,
  Compensation,
  EmployeeBulkResult,
  SaveCompensation,
  ChangeRequestStatus,
  ChangeableField,
  Employee,
  HireResult,
  OrgNode,
  PEOPLE_ERROR_CODES,
  Paged,
  PeopleQuery,
  PersonOption,
  PickerScope,
  RestoreEmployee,
  SaveEmployee,
} from './people.model';

/** HTTP client of the People API (/api/people, /api/me/employee, /api/applications/{id}/hire). */
@Injectable({ providedIn: 'root' })
export class PeopleService {
  private readonly http = inject(HttpClient);

  list(query: PeopleQuery): Observable<Paged<Employee>> {
    return this.http.get<Paged<Employee>>('/api/people', { params: toParams({ ...query }) });
  }

  get(id: number): Observable<Employee> {
    return this.http.get<{ data: Employee }>(`/api/people/${id}`).pipe(map((r) => r.data));
  }

  me(): Observable<Employee> {
    return this.http.get<{ data: Employee }>('/api/me/employee').pipe(map((r) => r.data));
  }

  create(body: SaveEmployee): Observable<Employee> {
    return this.http.post<{ data: Employee }>('/api/people', body).pipe(map((r) => r.data));
  }

  bulk(action: Exclude<BulkEmployeeAction, 'export'>, ids: number[], value: number): Observable<EmployeeBulkResult[]> {
    return this.http
      .post<{ data: EmployeeBulkResult[] }>('/api/people/bulk', { action, ids, [`${action}_id`]: value })
      .pipe(map((r) => r.data));
  }

  exportCsv(ids: number[]): Observable<Blob> {
    return this.http.post('/api/people/bulk', { action: 'export', ids }, { responseType: 'blob' });
  }

  compensation(id: number): Observable<Compensation> {
    return this.http.get<{ data: Compensation }>(`/api/people/${id}/compensation`).pipe(map((r) => r.data));
  }

  myCompensation(): Observable<Compensation> {
    return this.http.get<{ data: Compensation }>('/api/me/employee/compensation').pipe(map((r) => r.data));
  }

  addCompensation(id: number, body: SaveCompensation): Observable<Compensation> {
    return this.http.post<{ data: Compensation }>(`/api/people/${id}/compensation`, body).pipe(map((r) => r.data));
  }

  update(id: number, body: SaveEmployee): Observable<Employee> {
    return this.http.patch<{ data: Employee }>(`/api/people/${id}`, body).pipe(map((r) => r.data));
  }

  terminate(id: number, firedAt: string, reason: string | null): Observable<Employee> {
    return this.http
      .post<{ data: Employee }>(`/api/people/${id}/terminate`, { fired_at: firedAt, reason })
      .pipe(map((r) => r.data));
  }

  /** Cancel a scheduled (future) termination before its date. */
  cancelTermination(id: number): Observable<Employee> {
    return this.http.post<{ data: Employee }>(`/api/people/${id}/terminate/cancel`, {}).pipe(map((r) => r.data));
  }

  /** HR: bring a terminated employee back; omitted fields keep their previous values. */
  restore(id: number, body: RestoreEmployee): Observable<Employee> {
    return this.http.post<{ data: Employee }>(`/api/people/${id}/restore`, body).pipe(map((r) => r.data));
  }

  orgChart(query: { branch_id?: number; root_id?: number; mine?: boolean } = {}): Observable<OrgNode[]> {
    const params = toParams({ branch_id: query.branch_id, root_id: query.root_id, mine: query.mine ? 1 : undefined });
    return this.http.get<{ data: OrgNode[] }>('/api/people/org-chart', { params }).pipe(map((r) => r.data));
  }

  changeRequests(query: { status?: ChangeRequestStatus; employee_id?: number; perPage?: number } = {}): Observable<Paged<ChangeRequest>> {
    return this.http.get<Paged<ChangeRequest>>('/api/people/change-requests', { params: toParams({ ...query }) });
  }

  submitChange(changes: Partial<Record<ChangeableField, string | null>>, comment: string | null): Observable<ChangeRequest> {
    return this.http
      .post<{ data: ChangeRequest }>('/api/me/employee/change-requests', { changes, comment })
      .pipe(map((r) => r.data));
  }

  decideChange(id: number, approve: boolean, comment: string | null = null): Observable<ChangeRequest> {
    return this.http
      .post<{ data: ChangeRequest }>(`/api/people/change-requests/${id}/${approve ? 'approve' : 'reject'}`, { comment })
      .pipe(map((r) => r.data));
  }

  /** Person picker: search by name among the people the caller may see (GET /api/people/search, q ≥ 2 chars). */
  searchPeople(q: string, scope: PickerScope = 'employees', options: { limit?: number; includeTerminated?: boolean } = {}): Observable<PersonOption[]> {
    const params = toParams({ q, scope, limit: options.limit, include_terminated: options.includeTerminated ? 1 : undefined });
    return this.http.get<{ data: PersonOption[] }>('/api/people/search', { params }).pipe(map((r) => r.data));
  }

  /** Person picker: id → name for saved values; unknown or hidden ids are simply absent. */
  lookupPeople(ids: readonly number[], scope: PickerScope = 'employees'): Observable<PersonOption[]> {
    let params = toParams({ scope });
    for (const id of ids) {
      params = params.append('ids[]', String(id));
    }
    return this.http.get<{ data: PersonOption[] }>('/api/people/lookup', { params }).pipe(map((r) => r.data));
  }

  /** Recruiting → People: 201 created or 200 when the employee already exists (idempotent). */
  hire(applicationId: number, hiredAt?: string): Observable<HireResult> {
    return this.http
      .post<{ data: Employee; meta: { created: boolean } }>(`/api/applications/${applicationId}/hire`, hiredAt ? { hired_at: hiredAt } : {})
      .pipe(map((r) => ({ employee: r.data, created: r.meta.created })));
  }
}

/** i18n key for an error of the People API: known business codes, 403/404/422, otherwise generic. */
export function peopleErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (PEOPLE_ERROR_CODES as readonly string[]).includes(code)) {
      return `people.errors.${code}`;
    }
    if (error.status === 403) {
      return 'people.errors.forbidden';
    }
    if (error.status === 422) {
      return 'people.errors.validation';
    }
    if (error.status === 404) {
      return 'people.errors.not_found';
    }
  }
  return 'common.error';
}

/** Changed fields only (trimmed, empty → null); an empty object means nothing to send. */
export function diffChanges(
  current: Partial<Record<ChangeableField, string | null>>,
  next: Partial<Record<ChangeableField, string>>,
): Partial<Record<ChangeableField, string | null>> {
  const out: Partial<Record<ChangeableField, string | null>> = {};
  for (const [key, raw] of Object.entries(next) as [ChangeableField, string][]) {
    const value = raw.trim() === '' ? null : raw.trim();
    if (value !== (current[key] ?? null)) {
      out[key] = value;
    }
  }
  return out;
}

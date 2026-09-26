import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import {
  Balance,
  CalendarData,
  Holiday,
  LeavePolicy,
  LeavePreview,
  LeaveRequest,
  LeaveRequestQuery,
  LeaveType,
  NewLeaveRequest,
  Paged,
  TIMEOFF_ERROR_CODES,
} from './timeoff.model';

/** HTTP client of the TimeOff API (/api/timeoff/*). */
@Injectable({ providedIn: 'root' })
export class TimeOffService {
  private readonly http = inject(HttpClient);

  types(all = false): Observable<LeaveType[]> {
    return this.http.get<{ data: LeaveType[] }>('/api/timeoff/types', { params: toParams({ all: all ? 1 : undefined }) }).pipe(map((r) => r.data));
  }

  saveType(id: number | null, body: Partial<LeaveType>): Observable<LeaveType> {
    const call = id === null ? this.http.post<{ data: LeaveType }>('/api/timeoff/types', body) : this.http.patch<{ data: LeaveType }>(`/api/timeoff/types/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  policies(): Observable<LeavePolicy[]> {
    return this.http.get<{ data: LeavePolicy[] }>('/api/timeoff/policies').pipe(map((r) => r.data));
  }

  savePolicy(id: number | null, body: Partial<LeavePolicy>): Observable<LeavePolicy> {
    const call =
      id === null ? this.http.post<{ data: LeavePolicy }>('/api/timeoff/policies', body) : this.http.patch<{ data: LeavePolicy }>(`/api/timeoff/policies/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  holidays(year?: number, branchId?: number): Observable<Holiday[]> {
    return this.http.get<{ data: Holiday[] }>('/api/timeoff/holidays', { params: toParams({ year, branch_id: branchId }) }).pipe(map((r) => r.data));
  }

  saveHoliday(id: number | null, body: Partial<Holiday>): Observable<Holiday> {
    const call = id === null ? this.http.post<{ data: Holiday }>('/api/timeoff/holidays', body) : this.http.patch<{ data: Holiday }>(`/api/timeoff/holidays/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  deleteHoliday(id: number): Observable<void> {
    return this.http.delete<void>(`/api/timeoff/holidays/${id}`);
  }

  /** Own balances, or another employee's (admin / manager above). */
  balances(employeeId?: number): Observable<Balance[]> {
    return this.http.get<{ data: Balance[] }>('/api/timeoff/balances', { params: toParams({ employee_id: employeeId }) }).pipe(map((r) => r.data));
  }

  requests(query: LeaveRequestQuery): Observable<Paged<LeaveRequest>> {
    return this.http.get<Paged<LeaveRequest>>('/api/timeoff/requests', { params: toParams({ ...query }) });
  }

  preview(body: NewLeaveRequest): Observable<LeavePreview> {
    const query = { leave_type_id: body.leave_type_id, starts_on: body.starts_on, ends_on: body.ends_on, half_day: body.half_day, employee_id: body.employee_id };
    return this.http.get<{ data: LeavePreview }>('/api/timeoff/requests/preview', { params: toParams(query) }).pipe(map((r) => r.data));
  }

  create(body: NewLeaveRequest): Observable<LeaveRequest> {
    return this.http.post<{ data: LeaveRequest }>('/api/timeoff/requests', body).pipe(map((r) => r.data));
  }

  decide(id: number, action: 'approve' | 'reject' | 'cancel', comment: string | null = null): Observable<LeaveRequest> {
    return this.http.post<{ data: LeaveRequest }>(`/api/timeoff/requests/${id}/${action}`, action === 'cancel' ? {} : { comment }).pipe(map((r) => r.data));
  }

  approvals(): Observable<LeaveRequest[]> {
    return this.http.get<{ data: LeaveRequest[] }>('/api/timeoff/approvals').pipe(map((r) => r.data));
  }

  calendar(from: string, to: string, branchId?: number): Observable<CalendarData> {
    return this.http.get<{ data: CalendarData }>('/api/timeoff/calendar', { params: toParams({ from, to, branch_id: branchId }) }).pipe(map((r) => r.data));
  }
}

/** i18n key for a TimeOff API error. */
export function timeoffErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (TIMEOFF_ERROR_CODES as readonly string[]).includes(code)) {
      return `timeoff.errors.${code}`;
    }
    if (error.status === 403) {
      return 'timeoff.errors.forbidden';
    }
    if (error.status === 422) {
      return 'timeoff.errors.validation';
    }
  }
  return 'common.error';
}

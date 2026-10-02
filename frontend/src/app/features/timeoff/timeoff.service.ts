import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { toParams } from '../../core/api/http-params';
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
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the TimeOff API (/api/timeoff/*). */
@Injectable({ providedIn: 'root' })
export class TimeOffService {
  private readonly http = inject(HttpClient);

  types(all = false): Observable<LeaveType[]> {
    return this.http.get<DataEnvelope<LeaveType[]>>('/api/timeoff/types', { params: toParams({ all: all ? 1 : undefined }) }).pipe(unwrapData());
  }

  saveType(id: number | null, body: Partial<LeaveType>): Observable<LeaveType> {
    const call = id === null ? this.http.post<DataEnvelope<LeaveType>>('/api/timeoff/types', body) : this.http.patch<DataEnvelope<LeaveType>>(`/api/timeoff/types/${id}`, body);
    return call.pipe(unwrapData());
  }

  policies(): Observable<LeavePolicy[]> {
    return this.http.get<DataEnvelope<LeavePolicy[]>>('/api/timeoff/policies').pipe(unwrapData());
  }

  savePolicy(id: number | null, body: Partial<LeavePolicy>): Observable<LeavePolicy> {
    const call =
      id === null ? this.http.post<DataEnvelope<LeavePolicy>>('/api/timeoff/policies', body) : this.http.patch<DataEnvelope<LeavePolicy>>(`/api/timeoff/policies/${id}`, body);
    return call.pipe(unwrapData());
  }

  holidays(year?: number, branchId?: number): Observable<Holiday[]> {
    return this.http.get<DataEnvelope<Holiday[]>>('/api/timeoff/holidays', { params: toParams({ year, branch_id: branchId }) }).pipe(unwrapData());
  }

  saveHoliday(id: number | null, body: Partial<Holiday>): Observable<Holiday> {
    const call = id === null ? this.http.post<DataEnvelope<Holiday>>('/api/timeoff/holidays', body) : this.http.patch<DataEnvelope<Holiday>>(`/api/timeoff/holidays/${id}`, body);
    return call.pipe(unwrapData());
  }

  deleteHoliday(id: number): Observable<void> {
    return this.http.delete<void>(`/api/timeoff/holidays/${id}`);
  }

  /** Own balances, or another employee's (admin / manager above). */
  balances(employeeId?: number): Observable<Balance[]> {
    return this.http.get<DataEnvelope<Balance[]>>('/api/timeoff/balances', { params: toParams({ employee_id: employeeId }) }).pipe(unwrapData());
  }

  requests(query: LeaveRequestQuery): Observable<Paged<LeaveRequest>> {
    return this.http.get<Paged<LeaveRequest>>('/api/timeoff/requests', { params: toParams({ ...query }) });
  }

  preview(body: NewLeaveRequest): Observable<LeavePreview> {
    const query = { leave_type_id: body.leave_type_id, starts_on: body.starts_on, ends_on: body.ends_on, half_day: body.half_day, employee_id: body.employee_id };
    return this.http.get<DataEnvelope<LeavePreview>>('/api/timeoff/requests/preview', { params: toParams(query) }).pipe(unwrapData());
  }

  create(body: NewLeaveRequest): Observable<LeaveRequest> {
    return this.http.post<DataEnvelope<LeaveRequest>>('/api/timeoff/requests', body).pipe(unwrapData());
  }

  decide(id: number, action: 'approve' | 'reject' | 'cancel', comment: string | null = null): Observable<LeaveRequest> {
    return this.http.post<DataEnvelope<LeaveRequest>>(`/api/timeoff/requests/${id}/${action}`, action === 'cancel' ? {} : { comment }).pipe(unwrapData());
  }

  approvals(): Observable<LeaveRequest[]> {
    return this.http.get<DataEnvelope<LeaveRequest[]>>('/api/timeoff/approvals').pipe(unwrapData());
  }

  calendar(from: string, to: string, branchId?: number): Observable<CalendarData> {
    return this.http.get<DataEnvelope<CalendarData>>('/api/timeoff/calendar', { params: toParams({ from, to, branch_id: branchId }) }).pipe(unwrapData());
  }
}

/** i18n key for a TimeOff API error. */
export function timeoffErrorKey(error: unknown): string {
  return apiErrorKey(error, 'timeoff', TIMEOFF_ERROR_CODES, { statuses: [403, 422] });
}

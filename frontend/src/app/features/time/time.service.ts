import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { TIME_ERROR_CODES, TeamRow, TimeEntry, TimeWeek, TimesheetApproval, WorkScheduleRow } from './time.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Time API (/api/time/*). */
@Injectable({ providedIn: 'root' })
export class TimeService {
  private readonly http = inject(HttpClient);

  week(week: string, employeeId?: number): Observable<TimeWeek> {
    return this.http.get<DataEnvelope<TimeWeek>>('/api/time/week', { params: toParams({ week, employee_id: employeeId }) }).pipe(unwrapData());
  }

  save(week: string, entries: TimeEntry[], employeeId?: number): Observable<TimeWeek> {
    return this.http.put<DataEnvelope<TimeWeek>>('/api/time/week', { week, employee_id: employeeId, entries }).pipe(unwrapData());
  }

  submit(week: string, employeeId?: number): Observable<TimeWeek> {
    return this.http.post<DataEnvelope<TimeWeek>>('/api/time/week/submit', { week, employee_id: employeeId }).pipe(unwrapData());
  }

  decide(timesheetId: number, approve: boolean, comment: string | null): Observable<TimeWeek> {
    return this.http
      .post<DataEnvelope<TimeWeek>>(`/api/time/timesheets/${timesheetId}/decision`, { decision: approve ? 'approve' : 'reject', comment })
      .pipe(unwrapData());
  }

  approvals(): Observable<TimesheetApproval[]> {
    return this.http.get<DataEnvelope<TimesheetApproval[]>>('/api/time/approvals').pipe(unwrapData());
  }

  team(week: string, branchId?: number): Observable<TeamRow[]> {
    return this.http.get<DataEnvelope<TeamRow[]>>('/api/time/team', { params: toParams({ week, branch_id: branchId }) }).pipe(unwrapData());
  }

  schedules(): Observable<WorkScheduleRow[]> {
    return this.http.get<DataEnvelope<WorkScheduleRow[]>>('/api/time/schedules').pipe(unwrapData());
  }

  saveSchedule(branchId: number | null, days: number[], hoursPerDay: number): Observable<WorkScheduleRow> {
    return this.http.put<DataEnvelope<WorkScheduleRow>>('/api/time/schedules', { branch_id: branchId, days, hours_per_day: hoursPerDay }).pipe(unwrapData());
  }

  deleteSchedule(branchId: number): Observable<void> {
    return this.http.delete<void>(`/api/time/schedules/${branchId}`);
  }
}

export function timeErrorKey(error: unknown): string {
  return apiErrorKey(error, 'time', TIME_ERROR_CODES);
}

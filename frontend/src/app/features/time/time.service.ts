import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { TIME_ERROR_CODES, TeamRow, TimeEntry, TimeWeek, TimesheetApproval, WorkScheduleRow } from './time.model';

/** HTTP client of the Time API (/api/time/*). */
@Injectable({ providedIn: 'root' })
export class TimeService {
  private readonly http = inject(HttpClient);

  week(week: string, employeeId?: number): Observable<TimeWeek> {
    return this.http.get<{ data: TimeWeek }>('/api/time/week', { params: toParams({ week, employee_id: employeeId }) }).pipe(map((r) => r.data));
  }

  save(week: string, entries: TimeEntry[], employeeId?: number): Observable<TimeWeek> {
    return this.http.put<{ data: TimeWeek }>('/api/time/week', { week, employee_id: employeeId, entries }).pipe(map((r) => r.data));
  }

  submit(week: string, employeeId?: number): Observable<TimeWeek> {
    return this.http.post<{ data: TimeWeek }>('/api/time/week/submit', { week, employee_id: employeeId }).pipe(map((r) => r.data));
  }

  decide(timesheetId: number, approve: boolean, comment: string | null): Observable<TimeWeek> {
    return this.http
      .post<{ data: TimeWeek }>(`/api/time/timesheets/${timesheetId}/decision`, { decision: approve ? 'approve' : 'reject', comment })
      .pipe(map((r) => r.data));
  }

  approvals(): Observable<TimesheetApproval[]> {
    return this.http.get<{ data: TimesheetApproval[] }>('/api/time/approvals').pipe(map((r) => r.data));
  }

  team(week: string, branchId?: number): Observable<TeamRow[]> {
    return this.http.get<{ data: TeamRow[] }>('/api/time/team', { params: toParams({ week, branch_id: branchId }) }).pipe(map((r) => r.data));
  }

  schedules(): Observable<WorkScheduleRow[]> {
    return this.http.get<{ data: WorkScheduleRow[] }>('/api/time/schedules').pipe(map((r) => r.data));
  }

  saveSchedule(branchId: number | null, days: number[], hoursPerDay: number): Observable<WorkScheduleRow> {
    return this.http.put<{ data: WorkScheduleRow }>('/api/time/schedules', { branch_id: branchId, days, hours_per_day: hoursPerDay }).pipe(map((r) => r.data));
  }

  deleteSchedule(branchId: number): Observable<void> {
    return this.http.delete<void>(`/api/time/schedules/${branchId}`);
  }
}

export function timeErrorKey(error: unknown): string {
  return apiErrorKey(error, 'time', TIME_ERROR_CODES);
}

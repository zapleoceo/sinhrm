/** Types of the TimeOff API (backend app/Modules/TimeOff). */

export type HalfDay = 'none' | 'start' | 'end';
export const HALF_DAYS: readonly HalfDay[] = ['none', 'start', 'end'];

export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export const LEAVE_REQUEST_STATUSES: readonly LeaveRequestStatus[] = ['pending', 'approved', 'rejected', 'cancelled'];

export type AccrualMode = 'yearly_upfront' | 'monthly';
export const ACCRUAL_MODES: readonly AccrualMode[] = ['yearly_upfront', 'monthly'];

export type LeaveUnit = 'days' | 'hours';

export interface LeaveType {
  id: number;
  name: string;
  code: string;
  paid: boolean;
  unit: LeaveUnit;
  color: string;
  requires_approval: boolean;
  tracks_balance: boolean;
  active: boolean;
}

export interface LeavePolicy {
  id: number;
  leave_type_id: number;
  leave_type: { id: number; name: string };
  branch_id: number | null;
  branch: { id: number; name: string } | null;
  accrual_mode: AccrualMode;
  annual_days: number;
  carry_over_max: number | null;
  active: boolean;
}

export interface Holiday {
  id: number;
  date: string;
  name: string;
  branch_id: number | null;
  branch: { id: number; name: string } | null;
}

/** GET /api/timeoff/balances row. balance/available are null for untracked types (sick, unpaid). */
export interface Balance {
  leave_type: { id: number; name: string; code: string; color: string; paid: boolean };
  tracked: boolean;
  balance: number | null;
  pending: number;
  available: number | null;
  used_this_year: number;
  policy: { id: number; accrual_mode: AccrualMode; annual_days: number; carry_over_max: number | null; branch_id: number | null } | null;
}

export interface LeaveRequest {
  id: number;
  employee: { id: number; full_name: string };
  leave_type: { id: number; name: string; color: string };
  starts_on: string;
  ends_on: string;
  half_day: HalfDay;
  days: number;
  comment: string | null;
  status: LeaveRequestStatus;
  balance_override: boolean;
  approver: { id: number; name: string } | null;
  decided_at: string | null;
  decision_comment: string | null;
  can_decide: boolean;
  can_cancel: boolean;
  created_at: string | null;
}

export interface NewLeaveRequest {
  leave_type_id: number;
  starts_on: string;
  ends_on: string;
  half_day: HalfDay;
  comment?: string | null;
  employee_id?: number;
  override_balance?: boolean;
}

export interface LeavePreview {
  days: number;
  holidays: { date: string; name: string }[];
  tracked: boolean;
  available: number | null;
  sufficient: boolean;
  overlap: boolean;
}

export interface Absence {
  id: number;
  employee: { id: number; full_name: string; avatar_url: string | null };
  leave_type: { id: number; name: string; color: string };
  starts_on: string;
  ends_on: string;
  half_day: HalfDay;
  status: 'approved' | 'pending';
}

export interface CalendarData {
  absences: Absence[];
  holidays: { date: string; name: string; branch_id: number | null }[];
}

export interface Paged<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface LeaveRequestQuery {
  employee_id?: number;
  status?: LeaveRequestStatus;
  leave_type_id?: number;
  page?: number;
  perPage?: number;
}

/** Error codes of the TimeOff API; translated as timeoff.errors.<code>. */
export const TIMEOFF_ERROR_CODES = [
  'insufficient_balance',
  'overlap',
  'no_working_days',
  'range_too_long',
  'inactive_type',
  'invalid_status',
  'forbidden',
  'no_employee',
] as const;

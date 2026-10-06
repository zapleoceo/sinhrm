/** Types of the People API (backend app/Modules/People). */

export type EmployeeStatus = 'active' | 'on_leave' | 'terminated';
export const EMPLOYEE_STATUSES: readonly EmployeeStatus[] = ['active', 'on_leave', 'terminated'];

export type EmploymentType = 'full_time' | 'part_time' | 'contractor';
export const EMPLOYMENT_TYPES: readonly EmploymentType[] = ['full_time', 'part_time', 'contractor'];

export type ChangeRequestStatus = 'pending' | 'approved' | 'rejected';

/** Fields an employee may ask to change (backend ChangeableField). */
export type ChangeableField = 'phone' | 'personal_email' | 'address' | 'emergency_contact';
export const CHANGEABLE_FIELDS: readonly ChangeableField[] = ['phone', 'personal_email', 'address', 'emergency_contact'];

/** i18n key of a field label: personal_email → people.fields.personalEmail. */
export function fieldLabelKey(field: string): string {
  return 'people.fields.' + field.replace(/_(\w)/g, (_m, c: string) => c.toUpperCase());
}

export interface Ref {
  id: number;
  name: string;
}

/** What the caller may see/do with this employee (EmployeeResource.access). */
export interface EmployeeAccess {
  job: boolean;
  pii: boolean;
  decide: boolean;
  manage: boolean;
  self: boolean;
  /** Terminate / cancel a scheduled termination: HR or a manager above, never oneself. */
  terminate?: boolean;
}

export interface WorkSchedule {
  days?: number[];
  hours_per_day?: number;
}

/**
 * Employee in tiers: directory fields always; job fields when access.job; PII when access.pii.
 * Hidden tiers are absent from the payload, hence the optional fields.
 */
export interface Employee {
  id: number;
  full_name: string;
  avatar_url: string | null;
  work_email: string | null;
  phone: string | null;
  status: EmployeeStatus;
  branch: Ref | null;
  department: Ref | null;
  position: Ref | null;
  manager: Ref | null;
  access?: EmployeeAccess;
  // job tier
  user_id?: number | null;
  branch_id?: number | null;
  department_id?: number | null;
  position_id?: number | null;
  manager_id?: number | null;
  hired_at?: string;
  fired_at?: string | null;
  /** fired_at is set but the person still works: access lasts until the end of that day (Kyiv). */
  termination_scheduled?: boolean;
  termination_reason?: string | null;
  /** Who takes over the work when the termination applies (optional; only id and name). */
  handover_to?: { id: number; full_name: string } | null;
  /** HR-only, optional (pay-gap report). */
  gender?: string | null;
  employment_type?: EmploymentType;
  work_schedule?: WorkSchedule | null;
  reports_count?: number;
  candidate_id?: number | null;
  // PII tier
  birth_date?: string | null;
  personal_email?: string | null;
  address?: string | null;
  emergency_contact?: string | null;
  custom_fields?: Record<string, string | null>;
}

export interface Paged<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

/** Sortable columns of the directory (backend EmployeeSort); the default order is by name. */
export type PeopleSortKey = 'name' | 'position' | 'department' | 'branch' | 'manager';
export const PEOPLE_SORT_KEYS: readonly PeopleSortKey[] = ['name', 'position', 'department', 'branch', 'manager'];

export interface PeopleQuery {
  q?: string;
  /** Column filters of the table headers: «contains», case-insensitive. */
  name?: string;
  contact?: string;
  manager?: string;
  sort?: PeopleSortKey;
  dir?: 'asc' | 'desc';
  branch_id?: number;
  department_id?: number;
  position_id?: number;
  manager_id?: number;
  status?: EmployeeStatus;
  page?: number;
  perPage?: number;
}

/** POST /api/people/{id}/restore (HR). Omitted = keep the previous value, null = clear. */
export interface RestoreEmployee {
  position_id?: number | null;
  department_id?: number | null;
  branch_id?: number | null;
  manager_id?: number | null;
  hired_at?: string;
}

/** POST/PATCH /api/people (admin). */
export interface SaveEmployee {
  full_name?: string;
  hired_at?: string;
  work_email?: string | null;
  phone?: string | null;
  birth_date?: string | null;
  personal_email?: string | null;
  address?: string | null;
  emergency_contact?: string | null;
  status?: Exclude<EmployeeStatus, 'terminated'>;
  employment_type?: EmploymentType;
  branch_id?: number | null;
  department_id?: number | null;
  position_id?: number | null;
  manager_id?: number | null;
  user_id?: number | null;
  gender?: string | null;
}

export interface OrgNode {
  id: number;
  full_name: string;
  avatar_url: string | null;
  position: Ref | null;
  department: Ref | null;
  branch: Ref | null;
  reports_count: number;
  reports: OrgNode[];
}

export interface ChangeRequest {
  id: number;
  employee: { id: number; full_name: string };
  changes: Partial<Record<ChangeableField, string | null>>;
  status: ChangeRequestStatus;
  comment: string | null;
  requested_by: Ref | null;
  decided_by: Ref | null;
  decided_at: string | null;
  decision_comment: string | null;
  can_decide: boolean;
  created_at: string | null;
}

export interface HireResult {
  employee: Employee;
  created: boolean;
}

/** Error codes the People API returns in {code}; translated as people.errors.<code>. */
export const PEOPLE_ERROR_CODES = [
  'no_employee',
  'manager_cycle',
  'already_terminated',
  'termination_scheduled',
  'termination_not_scheduled',
  'invalid_handover',
  'not_terminated',
  'anonymized',
  'already_decided',
  'not_hired',
  'forbidden',
] as const;

/** POST /api/people/bulk (HR staff). export → CSV. */
export type BulkEmployeeAction = 'department' | 'position' | 'manager' | 'export';

export interface EmployeeBulkResult {
  id: number;
  ok: boolean;
  error: string | null;
}

export const CURRENCIES = ['UAH', 'USD', 'EUR'] as const;
export const PAY_PERIODS = ['month', 'hour'] as const;
export const GENDERS = ['female', 'male'] as const;

export interface CompensationRecord {
  id: number;
  amount: string;
  currency: (typeof CURRENCIES)[number];
  period: (typeof PAY_PERIODS)[number];
  effective_on: string;
  reason: string | null;
  current: boolean;
}

/** GET /api/people/{id}/compensation (HR staff) and /api/me/employee/compensation (own, read-only). */
export interface Compensation {
  current: CompensationRecord | null;
  history: CompensationRecord[];
}

export interface SaveCompensation {
  amount: number;
  currency: string;
  period: string;
  effective_on: string;
  reason: string | null;
}

/** Who the person picker searches: directory employees (default), my managed subtree, or system users (HR only). */
export type PickerScope = 'employees' | 'subordinates' | 'users';

/** One person picker row (GET /api/people/search, /lookup): directory-level fields only. id = user id for 'users'. */
export interface PersonOption {
  id: number;
  full_name: string;
  position: string | null;
  department: string | null;
  avatar_url: string | null;
  /** A terminated employee (only HR, or a manager for their former subordinates, may get one). */
  terminated?: boolean;
  /** fired_at, YYYY-MM-DD; null for working people. */
  terminated_at?: string | null;
}

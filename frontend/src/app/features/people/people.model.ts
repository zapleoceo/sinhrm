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
  termination_reason?: string | null;
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

export interface PeopleQuery {
  q?: string;
  branch_id?: number;
  department_id?: number;
  position_id?: number;
  manager_id?: number;
  status?: EmployeeStatus;
  page?: number;
  perPage?: number;
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
  'already_decided',
  'not_hired',
  'forbidden',
] as const;

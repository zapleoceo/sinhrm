import { UserRole } from '../../core/auth/auth.model';

/** Mirrors backend RecruitingScope::canWrite: viewers are read-only; branch scope is enforced by the API. */
export function canWriteRecruiting(roles: readonly UserRole[]): boolean {
  return roles.some((r) => r === 'superadmin' || r === 'admin' || r === 'recruiter');
}

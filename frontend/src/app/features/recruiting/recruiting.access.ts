import { ADMIN_ROLES, UserRole, isAdmin } from '../../core/auth/auth.model';

/** Mirrors backend RecruitingScope::canWrite roles. */
const WRITE_ROLES: readonly UserRole[] = [...ADMIN_ROLES, 'recruiter'];

/** Mirrors backend RecruitingScope::canWrite: viewers are read-only; branch scope is enforced by the API. */
/** Mirrors backend RecruitingScope::canManage (superadmin/admin): Directories, anyone's vacancy templates. */
export function isRecruitingAdmin(roles: readonly UserRole[]): boolean {
  return isAdmin(roles);
}

export function canWriteRecruiting(roles: readonly UserRole[]): boolean {
  return roles.some((r) => WRITE_ROLES.includes(r));
}

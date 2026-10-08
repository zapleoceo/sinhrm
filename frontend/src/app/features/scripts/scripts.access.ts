import { UserRole, isAdmin } from '../../core/auth/auth.model';

/** Mirrors backend gate scripts-manage: only superadmin/admin edit scripts; everyone else reads. */
export function canManageScripts(roles: readonly UserRole[]): boolean {
  return isAdmin(roles);
}

import { UserRole } from '../../core/auth/auth.model';

/** Mirrors backend gate scripts-manage: only superadmin/admin edit scripts; everyone else reads. */
export function canManageScripts(roles: readonly UserRole[]): boolean {
  return roles.some((r) => r === 'superadmin' || r === 'admin');
}

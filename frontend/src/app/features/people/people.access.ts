import { UserRole } from '../../core/auth/auth.model';

/** Mirrors backend PeopleScope::isAdmin: superadmin and admin manage employees and leave settings (they act as HR). */
export function canManagePeople(roles: readonly UserRole[]): boolean {
  return roles.some((r) => r === 'superadmin' || r === 'admin');
}

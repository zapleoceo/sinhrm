import { UserRole, isHrStaff } from '../../core/auth/auth.model';

/** Mirrors backend PeopleScope::isAdmin: HR staff (superadmin, admin, hr_manager) manage employees and leave settings. */
export function canManagePeople(roles: readonly UserRole[]): boolean {
  return isHrStaff(roles);
}

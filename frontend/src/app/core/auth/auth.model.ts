/** Mirrors backend App\Modules\Auth\Enums\UserRole. */
export type UserRole = 'superadmin' | 'admin' | 'hr_manager' | 'recruiter' | 'employee' | 'viewer';
export const USER_ROLES: readonly UserRole[] = ['superadmin', 'admin', 'hr_manager', 'recruiter', 'employee', 'viewer'];
/** Roles a superadmin can give through an invitation. */
export const INVITABLE_ROLES: readonly UserRole[] = ['admin', 'hr_manager', 'recruiter', 'employee', 'viewer'];
/** Mirrors backend UserRole::hrStaff(): they act as HR (People, TimeOff, Desk, Pulse, Workflows, …) and see every branch. */
export const HR_STAFF_ROLES: readonly UserRole[] = ['superadmin', 'admin', 'hr_manager'];

export function isHrStaff(roles: readonly UserRole[]): boolean {
  return roles.some((r) => HR_STAFF_ROLES.includes(r));
}

/** Mirrors backend App\Modules\Auth\Enums\UserStatus. */
export type UserStatus = 'active' | 'blocked';
export const USER_STATUSES: readonly UserStatus[] = ['active', 'blocked'];

/** Mirrors backend App\Modules\Auth\Enums\AppLocale. */
export type AppLang = 'uk' | 'ru' | 'en';
export const APP_LANGS: readonly AppLang[] = ['uk', 'ru', 'en'];
export const DEFAULT_LANG: AppLang = 'uk';

export function isAppLang(value: unknown): value is AppLang {
  return typeof value === 'string' && (APP_LANGS as readonly string[]).includes(value);
}

/** Response of GET /api/auth/me. */
export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  locale: AppLang;
  roles: UserRole[];
  status: UserStatus;
  /** Module keys the user may open (switched on + role allowed); absent on old API responses = everything. */
  modules?: string[];
}

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

/** Response of GET /api/auth/me (and PUT /api/auth/active-role). */
export interface MeResponse extends Omit<CurrentUser, 'roles' | 'assigned_roles' | 'active_role'> {
  /** Assigned global roles. */
  roles: UserRole[];
  /** "Працювати як": the role the user works in now; null = all roles. Absent on old API responses. */
  active_role?: UserRole | null;
  /** Roles the server authorizes with right now (the active role, or all assigned). */
  effective_roles?: UserRole[];
}

/** The signed-in user as the SPA keeps it: `roles` are the EFFECTIVE roles, so every UI check follows "Працювати як". */
export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  locale: AppLang;
  /** E-mails about approvals and decisions ("Мій профіль"); absent on old API responses = on. */
  approval_emails?: boolean;
  /** Effective roles (what the server authorizes with); use these for every UI permission check. */
  roles: UserRole[];
  /** Roles the account really has — the "Працювати як" choices. */
  assigned_roles?: UserRole[];
  /** "Працювати як" choice; null = all roles. */
  active_role?: UserRole | null;
  status: UserStatus;
  /** Module keys the user may open (switched on + role allowed); absent on old API responses = everything. */
  modules?: string[];
}

/** GET /api/auth/me → SPA state: `roles` become the effective roles, the assigned ones are kept aside. */
export function toCurrentUser(me: MeResponse): CurrentUser {
  return { ...me, roles: me.effective_roles ?? me.roles, assigned_roles: me.roles, active_role: me.active_role ?? null };
}

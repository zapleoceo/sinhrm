import { AppLang, USER_ROLES, UserRole, UserStatus } from '../../core/auth/auth.model';
import { Paged } from '../../core/api/api.model';

/** Item of GET /api/users (backend App\Modules\Users\Http\Resources\UserResource). */
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  roles: UserRole[];
  status: UserStatus;
  /** Branch scope (Directory module); superadmin/admin are not limited by it. */
  branches: UserBranch[];
  locale: AppLang;
  /** Safe Speak: reads and answers anonymous reports (superadmin/admin only). */
  safe_speak_handler: boolean;
  invited_by: number | null;
  last_login_at: string | null;
  created_at: string | null;
}

/** Branch of a user, as returned inside AdminUser. */
export interface UserBranch {
  id: number;
  name: string;
  status: 'active' | 'disabled';
}

/** Sortable columns of GET /api/users (backend App\Modules\Users\Enums\UserSort). */
export const USER_SORT_KEYS = ['name', 'status', 'last_login'] as const;
export type UserSortKey = (typeof USER_SORT_KEYS)[number];

/** GET /api/users query; URL names = API names (users.query.ts). */
export interface UsersQuery {
  /** Name or e-mail contains (the «user» column filter). */
  q?: string;
  role?: UserRole;
  status?: UserStatus;
  /** YYYY-MM-DD, inclusive. */
  last_login_from?: string;
  /** YYYY-MM-DD, inclusive. */
  last_login_to?: string;
  sort?: UserSortKey;
  dir?: 'asc' | 'desc';
  page?: number;
  perPage?: number;
}

export type UsersPage = Paged<AdminUser>;

export interface InviteUser {
  email: string;
  name: string;
  role: UserRole;
}

export interface UpdateUser {
  /** @deprecated single role; prefer `roles` */
  role?: UserRole;
  /** Full set of global roles (at least one). Superadmin is only kept, never given. */
  roles?: UserRole[];
  status?: UserStatus;
  /** Full replacement of the user's branches (active branch ids only). */
  branch_ids?: number[];
  /** Safe Speak handler flag; the API accepts true only for superadmin/admin. */
  safe_speak_handler?: boolean;
}

/** Business error codes returned by the users API ({code}); anything else → "generic". */
export const USER_ERROR_CODES = ['email_taken', 'self_change_forbidden', 'last_superadmin', 'handler_requires_admin', 'superadmin_not_assignable'] as const;

/**
 * Roles to save after the picker closes: the ticked ones plus superadmin if the user has it (it is kept, never
 * given), in the standard order. null = nothing to save (unchanged, or nothing left — a user keeps at least one role).
 */
export function nextRoles(current: readonly UserRole[], ticked: readonly UserRole[]): UserRole[] | null {
  const roles = USER_ROLES.filter((r) => (r === 'superadmin' ? current.includes(r) : ticked.includes(r)));
  const before = USER_ROLES.filter((r) => current.includes(r));
  return roles.length === 0 || roles.join() === before.join() ? null : roles;
}

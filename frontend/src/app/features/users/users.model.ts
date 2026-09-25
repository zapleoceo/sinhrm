import { AppLang, UserRole, UserStatus } from '../../core/auth/auth.model';

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

export interface UsersQuery {
  q?: string;
  role?: UserRole;
  status?: UserStatus;
  page?: number;
  perPage?: number;
}

export interface UsersPage {
  data: AdminUser[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface InviteUser {
  email: string;
  name: string;
  role: UserRole;
}

export interface UpdateUser {
  role?: UserRole;
  status?: UserStatus;
  /** Full replacement of the user's branches (active branch ids only). */
  branch_ids?: number[];
}

/** Business error codes returned by the users API ({code}); anything else → "generic". */
export const USER_ERROR_CODES = ['email_taken', 'self_change_forbidden', 'last_superadmin'] as const;

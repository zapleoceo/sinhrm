import { ParamMap } from '@angular/router';
import { USER_ROLES, USER_STATUSES } from '../../core/auth/auth.model';
import { dateRangeFromParams, intParam, oneOfParam, sortFromParams, textParam } from '../../core/ui/table/table-state';
import { USER_SORT_KEYS, UserSortKey, UsersQuery } from './users.model';

export const USERS_PAGE_SIZE = 20;
/** The API accepts perPage 1..100. */
const MAX_PAGE_SIZE = 100;

/**
 * Users list query from the URL (`/admin/users?role=viewer&sort=last_login&dir=desc&page=2`). URL names = API names.
 * Junk (unknown sort, role or status, bad dates) is dropped here, so the API never answers 422 to an old link.
 */
export function usersQueryFromParams(params: ParamMap): UsersQuery {
  const sort = sortFromParams(params, USER_SORT_KEYS);
  const lastLogin = dateRangeFromParams(params, 'last_login_from', 'last_login_to');
  return {
    q: textParam(params, 'q'),
    role: oneOfParam(params, 'role', USER_ROLES),
    status: oneOfParam(params, 'status', USER_STATUSES),
    last_login_from: lastLogin?.from ?? undefined,
    last_login_to: lastLogin?.to ?? undefined,
    sort: sort ? (sort.key as UserSortKey) : undefined,
    dir: sort ? sort.dir : undefined,
    page: intParam(params, 'page') ?? 1,
    perPage: Math.min(intParam(params, 'perPage') ?? USERS_PAGE_SIZE, MAX_PAGE_SIZE),
  };
}

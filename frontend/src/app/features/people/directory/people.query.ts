import { ParamMap } from '@angular/router';
import { intParam, sortFromParams, textParam } from '../../../core/ui/table/table-state';
import { EMPLOYEE_STATUSES, EmployeeStatus, PEOPLE_SORT_KEYS, PeopleQuery, PeopleSortKey } from '../people.model';

export const PEOPLE_PAGE_SIZE = 50;

/**
 * Directory query from the URL (`/people?position_id=3&sort=manager&dir=desc&page=2`). URL names = API names.
 * Junk (unknown sort or status, non-numeric ids) is dropped here, so the API never answers 422 to an old link.
 */
export function peopleQueryFromParams(params: ParamMap): PeopleQuery {
  const sort = sortFromParams(params, PEOPLE_SORT_KEYS);
  const status = params.get('status');
  return {
    q: textParam(params, 'q'),
    name: textParam(params, 'name'),
    contact: textParam(params, 'contact'),
    manager: textParam(params, 'manager'),
    branch_id: intParam(params, 'branch_id'),
    department_id: intParam(params, 'department_id'),
    position_id: intParam(params, 'position_id'),
    status: (EMPLOYEE_STATUSES as readonly string[]).includes(status ?? '') ? (status as EmployeeStatus) : undefined,
    sort: sort ? (sort.key as PeopleSortKey) : undefined,
    dir: sort ? sort.dir : undefined,
    page: intParam(params, 'page') ?? 1,
    perPage: Math.min(intParam(params, 'perPage') ?? PEOPLE_PAGE_SIZE, 200),
  };
}

/** Same query? (the URL emits again on navigations that do not change it — no second request). */
export function sameQuery(a: PeopleQuery, b: PeopleQuery): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)] as (keyof PeopleQuery)[]);
  return [...keys].every((k) => a[k] === b[k]);
}

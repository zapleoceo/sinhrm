import { ParamMap } from '@angular/router';
import { dateRangeFromParams, intParam, oneOfParam, sortFromParams } from '../../core/ui/table/table-state';
import { AUDIT_ACTIONS, AUDIT_SORT_KEYS, AuditQuery, AuditSortKey } from './audit.model';

export const AUDIT_PAGE_SIZE = 20;
/** The API accepts perPage 1..100. */
const MAX_PAGE_SIZE = 100;
/** Entity types are snake_case codes of at most 48 characters (the API column); anything else is junk. */
const ENTITY_TYPE = /^[a-z][a-z0-9_]{0,47}$/;

/**
 * Audit log query from the URL (`/admin/audit?entity_type=employee&from=2026-09-01&sort=user&dir=asc`). URL names =
 * API names. Junk (unknown sort or action, bad dates, odd entity types) is dropped, so the API never answers 422.
 */
export function auditQueryFromParams(params: ParamMap): AuditQuery {
  const sort = sortFromParams(params, AUDIT_SORT_KEYS);
  const range = dateRangeFromParams(params, 'from', 'to');
  const entity = params.get('entity_type');
  return {
    user_id: intParam(params, 'user_id'),
    entity_type: entity !== null && ENTITY_TYPE.test(entity) ? entity : undefined,
    action: oneOfParam(params, 'action', AUDIT_ACTIONS),
    from: range?.from ?? undefined,
    to: range?.to ?? undefined,
    sort: sort ? (sort.key as AuditSortKey) : undefined,
    dir: sort ? sort.dir : undefined,
    page: intParam(params, 'page') ?? 1,
    perPage: Math.min(intParam(params, 'perPage') ?? AUDIT_PAGE_SIZE, MAX_PAGE_SIZE),
  };
}

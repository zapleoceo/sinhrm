import { Observable } from 'rxjs';

/** Audit log (backend Audit module): who changed what and when. */

export const AUDIT_ACTIONS = [
  'created',
  'updated',
  'deleted',
  'status_changed',
  'role_changed',
  'stage_changed',
  'secret_set',
  'secret_cleared',
  'prompt_activated',
] as const;

export const AUDIT_ENTITY_TYPES = [
  'user',
  'integration',
  'ai_prompt_version',
  'employee',
  'vacancy',
  'candidate',
  'application',
  'leave_request',
  'document',
  'hiring_request',
  'hiring_approval',
  'workflow_template',
] as const;

/** Old/new value of one field; secrets and PII arrive masked as "***". */
export interface AuditChange {
  from: unknown;
  to: unknown;
}

/** Item of GET /api/audit and GET /api/{people|candidates}/{id}/history. */
export interface AuditEntry {
  id: number;
  action: string;
  entity_type: string;
  entity_id: number;
  user: { id: number; name: string } | null;
  changes: Record<string, AuditChange> | null;
  meta: Record<string, unknown> | null;
  created_at: string;
}

export interface AuditPage {
  data: AuditEntry[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface AuditPaging {
  page: number;
  perPage: number;
}

export interface AuditQuery extends Partial<AuditPaging> {
  user_id?: number;
  entity_type?: string;
  action?: string;
  /** YYYY-MM-DD */
  from?: string;
  /** YYYY-MM-DD */
  to?: string;
}

export interface AuditOptions {
  entity_types: string[];
  actions: string[];
  users: { id: number; name: string }[];
}

/** Loads one page of an entity's history; input of the embeddable history component. */
export type AuditLoader = (paging: AuditPaging) => Observable<AuditPage>;

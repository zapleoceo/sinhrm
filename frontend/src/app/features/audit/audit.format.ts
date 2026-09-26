import { AUDIT_ACTIONS, AUDIT_ENTITY_TYPES, AuditEntry } from './audit.model';

/**
 * Router link of the audited entity, or null when it has no page.
 * Applications link to their candidate card when the backend put `candidate_id` in meta.
 */
export function auditEntityLink(entry: Pick<AuditEntry, 'entity_type' | 'entity_id' | 'meta'>): string | null {
  switch (entry.entity_type) {
    case 'employee':
      return `/people/${entry.entity_id}`;
    case 'candidate':
      return `/candidates/${entry.entity_id}`;
    case 'vacancy':
      return `/vacancies/${entry.entity_id}`;
    case 'application': {
      const id = entry.meta?.['candidate_id'];
      const valid = (typeof id === 'number' && Number.isInteger(id)) || (typeof id === 'string' && /^\d+$/.test(id));
      return valid ? `/candidates/${String(id)}` : null;
    }
    case 'user':
      return '/admin/users';
    case 'integration':
      return '/admin/integrations';
    default:
      return null;
  }
}

/** One "field: from → to" line per changed field. */
export function auditChangeLines(changes: AuditEntry['changes']): string[] {
  return Object.entries(changes ?? {}).map(([field, c]) => `${field}: ${auditValue(c.from)} → ${auditValue(c.to)}`);
}

/** Printable value: empty → "—", objects as JSON, the mask "***" as is. */
export function auditValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value as string | number | boolean);
}

/** Precomputed table row (templates do no work). */
export interface AuditRow {
  id: number;
  at: string;
  userName: string | null;
  actionKey: string | null;
  action: string;
  entityKey: string | null;
  entityType: string;
  entityId: number;
  link: string | null;
  lines: string[];
}

export function toAuditRow(entry: AuditEntry): AuditRow {
  return {
    id: entry.id,
    at: entry.created_at,
    userName: entry.user?.name ?? null,
    actionKey: auditActionKey(entry.action),
    action: entry.action,
    entityKey: auditEntityKey(entry.entity_type),
    entityType: entry.entity_type,
    entityId: entry.entity_id,
    link: auditEntityLink(entry),
    lines: auditChangeLines(entry.changes),
  };
}

/** i18n key for an action; unknown values fall back to the raw string (null). */
export function auditActionKey(action: string): string | null {
  return (AUDIT_ACTIONS as readonly string[]).includes(action) ? `audit.actions.${action}` : null;
}

/** i18n key for an entity type; unknown values fall back to the raw string (null). */
export function auditEntityKey(type: string): string | null {
  return (AUDIT_ENTITY_TYPES as readonly string[]).includes(type) ? `audit.entities.${type}` : null;
}

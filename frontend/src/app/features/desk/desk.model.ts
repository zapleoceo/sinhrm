/** Types of the Desk API (backend app/Modules/Desk). */

export type CaseStatus = 'new' | 'in_progress' | 'waiting' | 'resolved' | 'closed';
export const CASE_STATUSES: readonly CaseStatus[] = ['new', 'in_progress', 'waiting', 'resolved', 'closed'];

export interface DeskCategory {
  id: number;
  name: string;
  first_response_hours: number | null;
  resolve_hours: number | null;
  default_assignee_id: number | null;
  active: boolean;
}

export interface CaseSla {
  first_response_due: string | null;
  resolve_due: string | null;
  first_response_breached: boolean;
  resolve_breached: boolean;
}

export interface CaseComment {
  id: number;
  body: string;
  internal: boolean;
  author: { id: number; name: string } | null;
  /** Written by the employee who opened the case. */
  mine: boolean;
  article: { id: number; title: string } | null;
  created_at: string;
}

export interface CaseAttachment {
  id: number;
  filename: string;
  mime: string;
  size: number;
  created_at: string;
}

export interface DeskCase {
  id: number;
  subject: string;
  status: CaseStatus;
  category: { id: number; name: string };
  employee: { id: number; full_name: string };
  assignee: { id: number; name: string } | null;
  created_at: string;
  first_response_at: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  sla: CaseSla;
  can_manage: boolean;
  /** Detail only. Internal notes are not in the payload for the employee. */
  body?: string;
  comments?: CaseComment[];
  attachments?: CaseAttachment[];
}

export interface OpenCase {
  category_id: number;
  subject: string;
  body: string;
}

export interface NewComment {
  body: string;
  internal?: boolean;
  article_id?: number | null;
}

export interface QueueQuery {
  status?: CaseStatus;
  category_id?: number;
  open?: boolean;
}

export const DESK_ERROR_CODES = [
  'no_employee',
  'category_inactive',
  'invalid_assignee',
  'article_not_found',
  'case_closed',
  'invalid_file',
  'file_too_large',
  'too_many_files',
] as const;

/** "breached" when any SLA target is missed, "due" when a target is still running, else "ok". */
export function slaState(c: Pick<DeskCase, 'sla' | 'status'>): 'breached' | 'due' | 'ok' {
  if (c.sla.first_response_breached || c.sla.resolve_breached) {
    return 'breached';
  }
  const open = c.status !== 'resolved' && c.status !== 'closed';
  return open && (c.sla.first_response_due !== null || c.sla.resolve_due !== null) ? 'due' : 'ok';
}

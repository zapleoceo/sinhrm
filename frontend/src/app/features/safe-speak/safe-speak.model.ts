/** Types of the Safe Speak API (backend app/Modules/SafeSpeak). */

export type ReportCategory = 'harassment' | 'discrimination' | 'fraud' | 'safety' | 'ethics' | 'other';
export const REPORT_CATEGORIES: readonly ReportCategory[] = ['harassment', 'discrimination', 'fraud', 'safety', 'ethics', 'other'];

export type ReportStatus = 'new' | 'in_review' | 'closed';
export const REPORT_STATUSES: readonly ReportStatus[] = ['new', 'in_review', 'closed'];

export interface ReportMessage {
  author: 'reporter' | 'handler';
  body: string;
  /** Date only — no time is stored (anonymity). */
  created_on: string;
}

/** What the reporter sees (no id, nothing about the handler). */
export interface AnonymousReport {
  category: ReportCategory;
  subject: string;
  status: ReportStatus;
  created_on: string;
  updated_on: string;
  messages: ReportMessage[];
}

/** Handler inbox item / detail. */
export interface HandledReport extends Omit<AnonymousReport, 'messages'> {
  id: number;
  messages?: ReportMessage[];
  messages_count?: number;
}

export interface SubmitReport {
  category: ReportCategory;
  subject: string;
  body: string;
}

export const SAFE_SPEAK_ERROR_CODES = ['invalid_code', 'too_many_attempts', 'report_closed'] as const;

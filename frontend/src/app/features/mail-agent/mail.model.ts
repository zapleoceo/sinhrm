import { GoogleConnection } from '../google-workspace/google.model';

/** Mirrors backend App\Modules\MailAgent\Enums\SenderKind. */
export type SenderKind = 'job_board' | 'candidate' | 'colleague' | 'newsletter' | 'ignore';
export const SENDER_KINDS: readonly SenderKind[] = ['job_board', 'candidate', 'colleague', 'newsletter', 'ignore'];

/** Mirrors backend App\Modules\MailAgent\Enums\ParserKey. */
export type ParserKey = 'work_ua' | 'robota_ua' | 'djinni' | 'generic';
export const PARSER_KEYS: readonly ParserKey[] = ['work_ua', 'robota_ua', 'djinni', 'generic'];

/** Mirrors backend App\Modules\MailAgent\Enums\MailOutcome. */
export type MailOutcome = 'application' | 'touchpoint' | 'inbox' | 'skipped' | 'unknown' | 'parse_failed';
export const MAIL_OUTCOMES: readonly MailOutcome[] = ['application', 'touchpoint', 'inbox', 'skipped', 'unknown', 'parse_failed'];

export interface MailStatus {
  connection: GoogleConnection;
  last_sync: {
    trigger: 'manual' | 'cron';
    started_at: string;
    finished_at: string | null;
    counts: Partial<Record<string, number>>;
    error: string | null;
  } | null;
  counts: { rules: number; unknown: number; processed_24h: number };
}

/** Counters of POST /api/mail/sync. */
export type SyncCounts = Partial<Record<MailOutcome | 'listed' | 'processed' | 'duplicates' | 'errors' | 'tasks', number>>;

export interface SenderRule {
  id: number;
  pattern: string;
  kind: SenderKind;
  parser: ParserKey | null;
  hits: number;
  last_seen_at: string | null;
  created_at: string | null;
}

export interface SaveSenderRule {
  pattern?: string;
  kind?: SenderKind;
  parser?: ParserKey | null;
}

export interface UnknownSender {
  id: number;
  email: string;
  sample_subject: string | null;
  count: number;
  first_seen_at: string;
  last_seen_at: string;
  suggested_kind: SenderKind | null;
  suggested_parser: ParserKey | null;
}

export interface AssignSender {
  kind: SenderKind;
  parser?: ParserKey | null;
  scope: 'email' | 'domain';
}

export interface ProcessedMail {
  id: number;
  received_at: string;
  sender: string | null;
  subject: string | null;
  kind: SenderKind | null;
  parser: ParserKey | null;
  outcome: MailOutcome;
  error: string | null;
  candidate_id: number | null;
  touchpoint_id: number | null;
}

/** "user@site.ua" or "@site.ua" — same rule as the backend (SenderPattern::REGEX). */
export function isSenderPattern(value: string): boolean {
  return /^(?:[a-z0-9._%+-]+)?@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/.test(value.trim().toLowerCase());
}

export const MAIL_ERROR_CODES = ['duplicate_rule', 'google_gmail_not_connected', 'reconnect_required', 'google_unreachable'] as const;

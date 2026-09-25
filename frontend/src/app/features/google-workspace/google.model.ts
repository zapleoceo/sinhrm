/** Mirrors backend App\Modules\GoogleWorkspace\Enums\GoogleService. */
export type GoogleService = 'gmail' | 'calendar' | 'sheets';
export const GOOGLE_SERVICES: readonly GoogleService[] = ['gmail', 'calendar', 'sheets'];

/** Item of GET /api/google/status (backend ConnectionState). Tokens are never sent to the browser. */
export interface GoogleConnection {
  service: GoogleService;
  integration_key: string;
  status: 'off' | 'demo' | 'connected' | 'error';
  connected: boolean;
  account_email: string | null;
  scopes: string[];
  error: string | null;
  connected_at: string | null;
}

export interface GoogleStatus {
  data: GoogleConnection[];
  meta: { redirect_uri: string; oauth_configured: boolean };
}

/** Browser navigation target (not an XHR): Google consent screen via the API redirect. */
export function connectUrl(services: readonly GoogleService[]): string {
  return `/api/google/connect?services=${services.join(',')}`;
}

// ---- Meetings (Calendar) ----

export type MeetingType = 'branch' | 'online';
export const MEETING_TYPES: readonly MeetingType[] = ['online', 'branch'];
export const MEETING_DURATIONS: readonly number[] = [15, 30, 45, 60, 90];

/** Body of POST /api/google/candidates/{id}/meetings. `start` is ISO 8601 with offset. */
export interface ScheduleMeeting {
  title: string;
  start: string;
  duration_minutes: number;
  type: MeetingType;
  invite_candidate: boolean;
  location?: string | null;
  notes?: string | null;
}

export interface ScheduledMeeting {
  event_id: string;
  meet_link: string | null;
  html_link: string | null;
}

// ---- Sheets import ----

/** Mirrors backend App\Modules\GoogleWorkspace\Enums\SheetField (also the order in the mapping form). */
export type SheetField =
  | 'full_name'
  | 'phone'
  | 'email'
  | 'telegram'
  | 'source'
  | 'utm_source'
  | 'utm_medium'
  | 'utm_campaign'
  | 'utm_content'
  | 'utm_term'
  | 'vacancy'
  | 'created_at';
export const SHEET_FIELDS: readonly SheetField[] = [
  'full_name',
  'phone',
  'email',
  'telegram',
  'source',
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_content',
  'utm_term',
  'vacancy',
  'created_at',
];

/** field → 0-based column index. */
export type SheetMapping = Partial<Record<SheetField, number>>;

export interface SheetInspection {
  spreadsheet_id: string;
  sheet: string;
  headers: string[];
  rows: string[][];
  suggested: SheetMapping;
}

export interface ImportRowError {
  row: number;
  code: string;
}

export interface SheetImportReport {
  created: number;
  matched: number;
  skipped: number;
  applied: number;
  vacancy_unmatched: number;
  errors: ImportRowError[];
  last_row: number;
  rows: number;
}

export interface SheetImport {
  id: number;
  spreadsheet_id: string;
  url: string;
  sheet: string;
  headers: string[];
  mapping: SheetMapping;
  last_row: number;
  auto_sync: boolean;
  last_synced_at: string | null;
  last_report: SheetImportReport | null;
}

export interface SaveSheetImport {
  url: string;
  sheet?: string;
  mapping: SheetMapping;
  auto_sync: boolean;
}

/** Same check as the backend (docs.google.com/spreadsheets/d/<id>). */
export function isSheetUrl(url: string): boolean {
  return /^https:\/\/docs\.google\.com\/spreadsheets\/d\/[A-Za-z0-9_-]{20,128}/.test(url.trim());
}

/** API error codes with their own message (google.errors.*). */
export const GOOGLE_ERROR_CODES = [
  'google_gmail_not_connected',
  'google_calendar_not_connected',
  'google_sheets_not_connected',
  'reconnect_required',
  'google_oauth_not_configured',
  'google_unauthorized',
  'google_forbidden',
  'google_not_found',
  'google_unreachable',
  'invalid_sheet_url',
] as const;

/** Codes of the ?google_error= redirect after the consent flow. */
export const CONNECT_ERROR_CODES = ['consent_denied', 'invalid_state', 'invalid_services', ...GOOGLE_ERROR_CODES] as const;

/** Local "YYYY-MM-DD" + "HH:mm" → ISO 8601 with the browser's offset (e.g. 2026-10-05T10:00:00+03:00). */
export function toIsoWithOffset(date: string, time: string, reference: Date = new Date(`${date}T${time}:00`)): string {
  const offset = -reference.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  const abs = Math.abs(offset);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${date}T${time}:00${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`;
}

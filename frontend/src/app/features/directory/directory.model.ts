/** Mirrors backend App\Modules\Directory\Enums\DictionaryType (URL segment of /api/directory/{type}). */
export type DictionaryType = 'branches' | 'cities' | 'departments' | 'positions';
export const DICTIONARY_TYPES: readonly DictionaryType[] = ['branches', 'cities', 'departments', 'positions'];

/** Mirrors backend App\Modules\Directory\Enums\DirectoryStatus. */
export type DirectoryStatus = 'active' | 'disabled';
export const DIRECTORY_STATUSES: readonly DirectoryStatus[] = ['active', 'disabled'];

/** Item of GET /api/directory/{type} (backend DictionaryItemResource). city/city_id — branches only. */
export interface DictionaryItem {
  id: number;
  external_id: string | null;
  name: string;
  status: DirectoryStatus;
  created_at: string | null;
  updated_at: string | null;
  city_id?: number | null;
  city?: { id: number; name: string } | null;
}

export interface DictionaryQuery {
  q?: string;
  status?: DirectoryStatus;
  page?: number;
  perPage?: number;
}

export interface DictionaryPage {
  data: DictionaryItem[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export interface SaveDictionaryItem {
  name?: string;
  status?: DirectoryStatus;
  city_id?: number | null;
}

export interface ImportCounts {
  created: number;
  updated: number;
  skipped: number;
}

/** Response of POST /api/directory/import: counts per dictionary plus the total. */
export type ImportReport = Record<DictionaryType | 'total', ImportCounts>;

/** Codes with their own message; sintegrum_http_<status> and the rest map to "generic". */
export const DIRECTORY_ERROR_CODES = [
  'integration_not_configured',
  'sintegrum_unauthorized',
  'sintegrum_unreachable',
  'sintegrum_bad_response',
  'sintegrum_timeout',
  'sintegrum_invalid_url',
  'sintegrum_blocked_host',
  'sintegrum_blocked_port',
  'sintegrum_unresolved_host',
] as const;

import { Paged } from '../../core/api/api.model';

/** Mirrors backend App\Modules\Directory\Enums\DictionaryType (URL segment of /api/directory/{type}). */
export type DictionaryType = 'branches' | 'cities' | 'departments' | 'positions' | 'vacancy_categories';
export const DICTIONARY_TYPES: readonly DictionaryType[] = ['branches', 'cities', 'departments', 'positions', 'vacancy_categories'];

/** Mirrors backend App\Modules\Directory\Enums\DirectoryStatus. */
export type DirectoryStatus = 'active' | 'disabled';
export const DIRECTORY_STATUSES: readonly DirectoryStatus[] = ['active', 'disabled'];

/** Item of GET /api/directory/{type} (backend DictionaryItemResource). city/city_id — branches only. */
export interface DictionaryItem {
  id: number;
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

export type DictionaryPage = Paged<DictionaryItem>;

export interface SaveDictionaryItem {
  name?: string;
  status?: DirectoryStatus;
  city_id?: number | null;
}

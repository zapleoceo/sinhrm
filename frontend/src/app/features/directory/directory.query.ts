import { ParamMap } from '@angular/router';
import { intParam, oneOfParam, sortFromParams, textParam } from '../../core/ui/table/table-state';
import { DICTIONARY_SORT_KEYS, DICTIONARY_TYPES, DIRECTORY_STATUSES, DictionaryQuery, DictionarySortKey, DictionaryType } from './directory.model';

export const DIRECTORY_PAGE_SIZE = 50;
/** The API accepts perPage 1..200. */
const MAX_PAGE_SIZE = 200;

/** The tab and its list query, both in the URL. */
export interface DirectoryView {
  type: DictionaryType;
  query: DictionaryQuery;
}

/**
 * Directory view from the URL (`/admin/directory?tab=branches&city_id=2&sort=city&dir=desc`). Junk (unknown tab,
 * sort or status; city on a dictionary without cities) is dropped, so the API never answers 422 to an old link.
 */
export function directoryViewFromParams(params: ParamMap): DirectoryView {
  const type = oneOfParam(params, 'tab', DICTIONARY_TYPES) ?? DICTIONARY_TYPES[0];
  const branches = type === 'branches';
  const sort = sortFromParams(params, branches ? DICTIONARY_SORT_KEYS : DICTIONARY_SORT_KEYS.filter((k) => k !== 'city'));
  return {
    type,
    query: {
      q: textParam(params, 'q'),
      status: oneOfParam(params, 'status', DIRECTORY_STATUSES),
      city_id: branches ? intParam(params, 'city_id') : undefined,
      sort: sort ? (sort.key as DictionarySortKey) : undefined,
      dir: sort ? sort.dir : undefined,
      page: intParam(params, 'page') ?? 1,
      perPage: Math.min(intParam(params, 'perPage') ?? DIRECTORY_PAGE_SIZE, MAX_PAGE_SIZE),
    },
  };
}

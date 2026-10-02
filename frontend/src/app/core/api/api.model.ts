/** Pagination block of a Laravel paginated answer. */
export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

/** One page of a list: `{ data: T[], meta }` (a feature may extend the meta, e.g. VacancyPageMeta). */
export interface Paged<T, M extends PageMeta = PageMeta> {
  data: T[];
  meta: M;
}

/** The API wraps a single resource (and most answers) in `{ data: … }`. */
export interface DataEnvelope<T> {
  data: T;
}

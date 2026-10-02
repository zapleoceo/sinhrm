import { HttpParams } from '@angular/common/http';

/** A query value the API understands; empty ones (undefined, null, '') are not sent. */
export type QueryValue = string | number | boolean | undefined | null;

/** Query params without empty values (numbers and booleans become strings: the API accepts "20", "true"). */
export function toParams<Q extends { [K in keyof Q]: QueryValue }>(query: Q): HttpParams {
  let params = new HttpParams();
  for (const [key, value] of Object.entries(query) as [string, QueryValue][]) {
    if (value !== undefined && value !== null && value !== '') {
      params = params.set(key, String(value));
    }
  }
  return params;
}

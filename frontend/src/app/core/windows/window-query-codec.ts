import { convertToParamMap, ParamMap, Params } from '@angular/router';

const PREFIX = 'wq_';
const QUERY_KEYS = new Set([
  'q', 'name', 'contact', 'manager', 'branch_id', 'department_id', 'position_id', 'status', 'sort', 'dir', 'page', 'perPage',
]);
const MAX_QUERY_KEYS = 16;
const MAX_VALUE_LENGTH = 256;

/** Decode only known, bounded People table keys from the matrix params of a window route. */
export function decodeWindowQuery(params: ParamMap): ParamMap {
  const query: Params = {};
  for (const key of params.keys) {
    if (!key.startsWith(PREFIX)) continue;
    const name = key.slice(PREFIX.length);
    if (!QUERY_KEYS.has(name)) continue;
    const value = params.get(key);
    if (value !== null && value.length <= MAX_VALUE_LENGTH) query[name] = value;
  }
  return convertToParamMap(query);
}

/** Merge canonical table keys or existing matrix keys into the bounded matrix namespace; null removes a key. */
export function encodeWindowQuery(current: ParamMap, patch: Params): Readonly<Record<string, string>> | null {
  const next = new Map<string, string>();
  for (const key of current.keys) {
    const name = key.startsWith(PREFIX) ? key.slice(PREFIX.length) : key;
    const value = current.get(key);
    if (QUERY_KEYS.has(name) && value !== null && value.length <= MAX_VALUE_LENGTH) next.set(name, value);
  }

  for (const [name, value] of Object.entries(patch)) {
    if (!QUERY_KEYS.has(name)) return null;
    if (value === null || value === undefined || value === '') {
      next.delete(name);
      continue;
    }
    if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean') return null;
    const encoded = String(value);
    if (encoded.length > MAX_VALUE_LENGTH) return null;
    next.set(name, encoded);
  }

  if (next.size > MAX_QUERY_KEYS) return null;
  const result: Record<string, string> = {};
  for (const [name, value] of next) result[`${PREFIX}${name}`] = value;
  return result;
}

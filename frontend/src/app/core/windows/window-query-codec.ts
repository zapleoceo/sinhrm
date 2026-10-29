import { convertToParamMap, ParamMap, Params } from '@angular/router';

const PREFIX = 'wq_';
const MAX_QUERY_KEYS = 32;
const MAX_VALUE_LENGTH = 1024;
const MAX_QUERY_LENGTH = 8192;
const KEY_PATTERN = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/;
const CONTROL_KEYS = new Set(['osActive', 'osMin', 'osMax', 'osPrimary', 'id']);

type QueryValue = string | string[];

/** Decode bounded per-window matrix values. OS controls and unprefixed route params never become page query state. */
export function decodeWindowQuery(params: ParamMap): ParamMap {
  const query: Params = {};
  let totalLength = 0;
  for (const key of params.keys) {
    if (!key.startsWith(PREFIX)) continue;
    const name = key.slice(PREFIX.length);
    const values = params.getAll(key);
    if (!validName(name) || values.length === 0 || values.some((value) => value.length > MAX_VALUE_LENGTH)) continue;
    totalLength += values.reduce((length, value) => length + value.length, name.length);
    if (Object.keys(query).length >= MAX_QUERY_KEYS || totalLength > MAX_QUERY_LENGTH) continue;
    query[name] = values.length === 1 ? values[0] : values;
  }
  return convertToParamMap(query);
}

/** Merge canonical route query keys into a bounded matrix namespace; null removes only that route key. */
export function encodeWindowQuery(current: ParamMap, patch: Params): Readonly<Record<string, QueryValue>> | null {
  const next = new Map<string, QueryValue>();
  for (const key of current.keys) {
    const name = key.startsWith(PREFIX) ? key.slice(PREFIX.length) : key;
    const values = current.getAll(key);
    if (validName(name) && values.length > 0 && values.every((value) => value.length <= MAX_VALUE_LENGTH)) {
      next.set(name, values.length === 1 ? values[0] : values);
    }
  }

  for (const [name, value] of Object.entries(patch)) {
    if (!validName(name)) return null;
    if (value === null || value === undefined || value === '') {
      next.delete(name);
      continue;
    }
    const values = Array.isArray(value) ? value : [value];
    if (values.length === 0 || values.some((item) => !['string', 'number', 'boolean'].includes(typeof item))) return null;
    const encoded = values.map(String);
    if (encoded.some((item) => item.length > MAX_VALUE_LENGTH)) return null;
    next.set(name, encoded.length === 1 ? encoded[0] : encoded);
  }

  if (next.size > MAX_QUERY_KEYS) return null;
  let totalLength = 0;
  const result: Record<string, QueryValue> = {};
  for (const [name, value] of next) {
    const values = Array.isArray(value) ? value : [value];
    totalLength += name.length + values.reduce((length, item) => length + item.length, 0);
    if (totalLength > MAX_QUERY_LENGTH) return null;
    result[`${PREFIX}${name}`] = value;
  }
  return result;
}

function validName(name: string): boolean {
  return KEY_PATTERN.test(name) && !name.startsWith(PREFIX) && !CONTROL_KEYS.has(name);
}

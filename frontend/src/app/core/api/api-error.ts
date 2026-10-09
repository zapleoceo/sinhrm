import { HttpErrorResponse } from '@angular/common/http';

/** HTTP statuses with a common meaning → the last part of `<prefix>.errors.<name>`. */
const API_ERROR_STATUSES = { 403: 'forbidden', 404: 'not_found', 422: 'validation', 429: 'rate_limited' } as const;

export type ApiErrorStatus = keyof typeof API_ERROR_STATUSES;

const ALL_STATUSES: readonly ApiErrorStatus[] = [403, 404, 422, 429];

export interface ApiErrorKeyOptions {
  /** Statuses that get their own `<prefix>.errors.<name>` key (default: 403, 404, 422, 429); others → `fallback`. */
  readonly statuses?: readonly ApiErrorStatus[];
  /** Key when neither a known code nor a listed status matched (default `common.error`). */
  readonly fallback?: string;
}

/** Business code of a failed API call ({code} of the backend exceptions), null when there is none. */
export function apiErrorCode(error: unknown): string | null {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    return typeof code === 'string' ? code : null;
  }
  return null;
}

/** HTTP status of a failed API call, null for anything that is not an HTTP error. */
export function apiErrorStatus(error: unknown): number | null {
  return error instanceof HttpErrorResponse ? error.status : null;
}

/**
 * i18n key of a failed API call for a feature: `<prefix>.errors.<code>` for a known business code, otherwise by
 * status — forbidden (403), not_found (404), validation (422), rate_limited (429), limited to `options.statuses`;
 * anything else → `options.fallback` (`common.error` by default).
 */
export function apiErrorKey(error: unknown, prefix: string, codes: readonly string[], options: ApiErrorKeyOptions = {}): string {
  const code = apiErrorCode(error);
  if (code !== null && codes.includes(code)) {
    return `${prefix}.errors.${code}`;
  }
  const status = apiErrorStatus(error);
  const statuses = options.statuses ?? ALL_STATUSES;
  if (status !== null && (statuses as readonly number[]).includes(status)) {
    return `${prefix}.errors.${API_ERROR_STATUSES[status as ApiErrorStatus]}`;
  }
  return options.fallback ?? 'common.error';
}

import { HttpErrorResponse } from '@angular/common/http';

/**
 * i18n key of a failed API call for a feature: `<prefix>.errors.<code>` for a known business code ({code} of the
 * backend exceptions), otherwise by status — forbidden (403), not_found (404), validation (422), rate_limited (429);
 * anything else → `common.error`.
 */
export function apiErrorKey(error: unknown, prefix: string, codes: readonly string[]): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && codes.includes(code)) {
      return `${prefix}.errors.${code}`;
    }
    const byStatus: Record<number, string> = { 403: 'forbidden', 404: 'not_found', 422: 'validation', 429: 'rate_limited' };
    const status = byStatus[error.status];
    if (status !== undefined) {
      return `${prefix}.errors.${status}`;
    }
  }
  return 'common.error';
}

/** Triggers a browser download of a Blob answer (CSV exports) under the given file name. */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

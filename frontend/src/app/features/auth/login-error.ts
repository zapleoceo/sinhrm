/** Reason codes of /login?error=<code> (backend App\Modules\Auth\Enums\LoginDenial). */
export const LOGIN_ERROR_CODES = ['not_invited', 'blocked', 'email_unverified', 'oauth_failed'] as const;
export type LoginErrorCode = (typeof LOGIN_ERROR_CODES)[number];

/** Maps ?error=<code> to an i18n key; unknown codes get a generic message, no code → no message. */
export function loginErrorKey(code: string | null | undefined): string | null {
  if (!code) {
    return null;
  }
  const known = (LOGIN_ERROR_CODES as readonly string[]).includes(code);
  return `login.errors.${known ? code : 'unknown'}`;
}

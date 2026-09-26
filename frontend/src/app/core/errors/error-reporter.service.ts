import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

export const CLIENT_ERRORS_URL = '/api/errors/client';
/** Same error again within this window is not re-sent (a render loop fires the same exception many times). */
const REPEAT_WINDOW_MS = 60_000;
/** Hard cap per page load; the server also rate-limits (10/min per user). */
const MAX_PER_PAGE = 20;
const MAX_MESSAGE = 1000;

export interface ClientError {
  kind: string;
  message: string;
  location: string | null;
}

/**
 * Sends client errors (JS exceptions, 5xx answers) to the in-app error log. Only for a signed-in user (the endpoint
 * needs auth), deduplicated, capped, fire-and-forget: reporting can never throw or show anything to the user.
 * No stack, no request/response bodies — only the kind, message, first code location and the SPA path.
 */
@Injectable({ providedIn: 'root' })
export class ErrorReporter {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly lastSent = new Map<string, number>();
  private sent = 0;

  report(error: ClientError): void {
    try {
      if (!this.auth.user() || this.sent >= MAX_PER_PAGE) {
        return;
      }
      const key = `${error.kind}|${error.location ?? ''}|${error.message}`;
      const now = Date.now();
      const last = this.lastSent.get(key);
      if (last !== undefined && now - last < REPEAT_WINDOW_MS) {
        return;
      }
      this.lastSent.set(key, now);
      this.sent++;
      this.http
        .post(CLIENT_ERRORS_URL, {
          kind: error.kind.slice(0, 120),
          message: error.message.slice(0, MAX_MESSAGE) || '(empty)',
          location: error.location?.slice(0, 300) ?? null,
          route: location.pathname.slice(0, 255),
        })
        .subscribe({ error: () => undefined });
    } catch {
      // never let error reporting break the page
    }
  }
}

/** Error name + message + the first "file:line:col" of the stack (bundle chunk names, no user data). */
export function describeError(error: unknown): ClientError {
  const e = (error as { rejection?: unknown })?.rejection ?? error;
  if (e instanceof Error) {
    const frame = /([\w.-]+\.(?:m?js|ts)):(\d+):(\d+)/.exec(e.stack ?? '');
    return {
      kind: /^[A-Za-z0-9_. :-]+$/.test(e.name) ? e.name : 'Error',
      message: e.message,
      location: frame ? `${frame[1]}:${frame[2]}:${frame[3]}` : null,
    };
  }
  return { kind: 'Error', message: typeof e === 'string' ? e : 'Non-Error thrown', location: null };
}

/** "/api/people/42/documents?x=1" → "/api/people/{id}/documents": one group per endpoint, no ids or query. */
export function normalizeApiPath(url: string): string {
  const path = url.split(/[?#]/)[0].replace(/^https?:\/\/[^/]+/, '');
  return path.replace(/\/\d+(?=\/|$)/g, '/{id}').replace(/\/[0-9a-f-]{32,36}(?=\/|$)/gi, '/{id}');
}

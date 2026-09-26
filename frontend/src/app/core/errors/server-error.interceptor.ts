import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { CLIENT_ERRORS_URL, ErrorReporter, normalizeApiPath } from './error-reporter.service';

/**
 * A 5xx seen by the SPA goes to the in-app error log (also the ones the API never saw: Vercel timeouts, 502).
 * The error itself is passed on unchanged. The report endpoint's own failures are never reported (no loop).
 */
export const serverErrorInterceptor: HttpInterceptorFn = (req, next) => {
  if (req.url.startsWith(CLIENT_ERRORS_URL)) {
    return next(req);
  }
  const reporter = inject(ErrorReporter);
  return next(req).pipe(
    catchError((e: unknown) => {
      if (e instanceof HttpErrorResponse && e.status >= 500) {
        const path = normalizeApiPath(req.url);
        reporter.report({ kind: `HTTP ${e.status}`, message: `${req.method} ${path}`, location: path });
      }
      return throwError(() => e);
    }),
  );
};

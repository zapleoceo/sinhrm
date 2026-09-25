import { HttpClient, HttpErrorResponse, HttpInterceptorFn, HttpRequest, HttpXsrfTokenExtractor } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, shareReplay, switchMap, throwError } from 'rxjs';

export const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';
export const XSRF_HEADER = 'X-XSRF-TOKEN';
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
/** Laravel: CSRF token mismatch / expired session. */
const CSRF_MISMATCH = 419;

/** Fetches the Sanctum XSRF-TOKEN cookie once per page load (again after a 419). */
@Injectable({ providedIn: 'root' })
export class CsrfTokenService {
  private readonly http = inject(HttpClient);
  private ready$: Observable<void> | null = null;

  ensure(): Observable<void> {
    this.ready$ ??= this.http.get(CSRF_COOKIE_URL, { observe: 'response' }).pipe(
      map(() => undefined),
      catchError((e: unknown) => {
        this.ready$ = null;
        return throwError(() => e);
      }),
      shareReplay(1),
    );
    return this.ready$;
  }

  reset(): void {
    this.ready$ = null;
  }
}

function isMutating(req: HttpRequest<unknown>): boolean {
  return !SAFE_METHODS.has(req.method) && req.url.startsWith('/') && !req.url.startsWith('//');
}

/**
 * Sanctum SPA auth needs the XSRF-TOKEN cookie before the first POST/PATCH/DELETE.
 * The header is set here because Angular's own XSRF interceptor runs before this one
 * and would not see a cookie that arrived during this request.
 */
export const csrfInterceptor: HttpInterceptorFn = (req, next) => {
  if (!isMutating(req)) {
    return next(req);
  }
  const csrf = inject(CsrfTokenService);
  const tokens = inject(HttpXsrfTokenExtractor);
  const send = () => {
    const token = tokens.getToken();
    return next(token ? req.clone({ setHeaders: { [XSRF_HEADER]: token } }) : req);
  };

  return csrf.ensure().pipe(
    switchMap(send),
    catchError((e: unknown) => {
      if (e instanceof HttpErrorResponse && e.status === CSRF_MISMATCH) {
        csrf.reset();
        return csrf.ensure().pipe(switchMap(send));
      }
      return throwError(() => e);
    }),
  );
};

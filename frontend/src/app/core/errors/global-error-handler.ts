import { ErrorHandler, Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ErrorReporter, describeError } from './error-reporter.service';

/**
 * Angular's ErrorHandler plus a report to the in-app error log. provideBrowserGlobalErrorListeners() routes
 * window "error" / "unhandledrejection" here as well. HTTP errors are skipped: 5xx are reported by the interceptor,
 * 4xx are expected answers.
 */
@Injectable()
export class GlobalErrorHandler extends ErrorHandler {
  private readonly reporter = inject(ErrorReporter);

  override handleError(error: unknown): void {
    super.handleError(error);
    const inner = (error as { rejection?: unknown })?.rejection ?? error;
    if (!(inner instanceof HttpErrorResponse)) {
      this.reporter.report(describeError(error));
    }
  }
}

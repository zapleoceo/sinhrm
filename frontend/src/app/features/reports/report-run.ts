import { inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { saveBlob } from '../../core/http/api-error';
import { NotifyService } from '../../core/ui/notify.service';
import { LatestRequest } from '../../core/ui/table/latest-request';
import { reportsErrorKey } from './reports.service';

/**
 * Run state of a report page (a catalog report or the builder): the last result, loading, and errors as a
 * notification. A newer run cancels the one still in flight. Create it in an injection context (a page field).
 */
export class ReportRun<R> {
  private readonly notify = inject(NotifyService);
  private readonly request = new LatestRequest();
  readonly result = signal<R | null>(null);
  readonly loading = signal(false);

  run(request: Observable<R>): void {
    this.loading.set(true);
    this.request.run(request, {
      next: (res) => {
        this.result.set(res);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.notify.show(reportsErrorKey(e));
      },
    });
  }

  /** CSV of the report → a file `name`; an error is a notification. */
  download(request: Observable<Blob>, name: string): void {
    request.subscribe({ next: (blob) => saveBlob(blob, name), error: (e: unknown) => this.notify.show(reportsErrorKey(e)) });
  }
}

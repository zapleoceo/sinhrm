import { Injectable, inject, signal } from '@angular/core';
import { LeaveRequest, LeaveRequestQuery } from './timeoff.model';
import { TimeOffService, timeoffErrorKey } from './timeoff.service';
import { RequestAction } from './widgets/requests-list';

/**
 * A list of leave requests (own, one employee's, or everything in scope) with approve/reject/cancel.
 * `version` changes after every successful action so balance panels can reload.
 */
@Injectable()
export class LeaveRequestsStore {
  private readonly api = inject(TimeOffService);
  private seq = 0;

  readonly query = signal<LeaveRequestQuery>({ perPage: 50 });
  readonly items = signal<LeaveRequest[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly version = signal(0);

  setQuery(query: LeaveRequestQuery): void {
    this.query.set({ perPage: 50, ...query });
    this.load();
  }

  load(): void {
    const seq = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    this.api.requests(this.query()).subscribe({
      next: (page) => {
        if (seq !== this.seq) {
          return;
        }
        this.items.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  /** Applies an action; the row is replaced by the server's answer. Errors go to `onError` as i18n keys. */
  act({ request, action }: RequestAction, onError: (key: string) => void): void {
    this.api.decide(request.id, action).subscribe({
      next: (updated) => {
        this.items.update((list) => list.map((r) => (r.id === updated.id ? updated : r)));
        this.version.update((v) => v + 1);
      },
      error: (e: unknown) => onError(timeoffErrorKey(e)),
    });
  }

  /** A request was created elsewhere (the form): show it on top and reload balances. */
  added(request: LeaveRequest): void {
    this.items.update((list) => [request, ...list]);
    this.total.update((n) => n + 1);
    this.version.update((v) => v + 1);
  }
}

import { Injectable, inject, signal } from '@angular/core';
import { PagedList } from '../../core/ui/table/paged-list';
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
  /** A newer query cancels the request still in flight. */
  private readonly list = new PagedList<LeaveRequest>();

  readonly query = signal<LeaveRequestQuery>({ perPage: 50 });
  readonly items = this.list.items;
  readonly total = this.list.total;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;
  readonly version = signal(0);

  setQuery(query: LeaveRequestQuery): void {
    this.query.set({ perPage: 50, ...query });
    this.load();
  }

  load(): void {
    this.list.load(this.api.requests(this.query()));
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

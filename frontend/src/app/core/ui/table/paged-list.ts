import { signal } from '@angular/core';
import { Observable } from 'rxjs';
import { LatestRequest } from './latest-request';

/** What a list endpoint answers: a Laravel page `{ data, meta.total }` or a plain array (everything at once). */
export type ListAnswer<R> = readonly R[] | { data: R[]; meta: { total: number } };

export interface PagedListOptions<A> {
  /** Extra use of the whole answer (e.g. a counter in `meta`); runs before `loading` goes off. */
  next?: (answer: A) => void;
  /** After `failed` is set (e.g. a toast); the rows already shown stay. */
  error?: (e: unknown) => void;
}

/**
 * State of one list in a store or page: `items`, `total`, `loading`, `failed`. `load()` cancels the request still in
 * flight (LatestRequest), so an old answer never lands over a newer query. Create it in an injection context
 * (a field of a store or component); the request in flight is cancelled with its owner.
 */
export class PagedList<T> {
  readonly items = signal<T[]>([]);
  /** `meta.total` of a page; for a plain array — its length. */
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);
  private readonly request = new LatestRequest();

  load<A extends ListAnswer<T>>(request: Observable<A>, options?: PagedListOptions<A>): void;
  load<R, A extends ListAnswer<R>>(request: Observable<A>, options: PagedListOptions<A> & { map: (row: R) => T }): void;
  load<R, A extends ListAnswer<R>>(request: Observable<A>, options: PagedListOptions<A> & { map?: (row: R) => T } = {}): void {
    this.loading.set(true);
    this.failed.set(false);
    this.request.run(request, {
      next: (answer) => {
        const rows: readonly R[] = isPage(answer) ? answer.data : answer;
        this.items.set((options.map ? rows.map(options.map) : rows) as T[]);
        this.total.set(isPage(answer) ? answer.meta.total : rows.length);
        options.next?.(answer);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.failed.set(true);
        this.loading.set(false);
        options.error?.(e);
      },
    });
  }
}

function isPage<R>(answer: ListAnswer<R>): answer is { data: R[]; meta: { total: number } } {
  return !Array.isArray(answer);
}

import { DestroyRef, inject } from '@angular/core';
import { Observable, Observer, Subscription } from 'rxjs';

/**
 * «Only the latest request counts» for a list store: a new `run()` cancels the previous HTTP request (unsubscribe
 * aborts it in HttpClient), so a slow old answer can neither overwrite newer rows nor keep the connection busy.
 * Create it in an injection context (a store field); the request in flight is cancelled with the store.
 */
export class LatestRequest {
  private current: Subscription | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.cancel());
  }

  run<T>(request: Observable<T>, observer: Partial<Observer<T>>): void {
    this.cancel();
    this.current = request.subscribe(observer);
  }

  cancel(): void {
    this.current?.unsubscribe();
    this.current = null;
  }
}

import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, NavigationStart, ParamMap, Params, Router } from '@angular/router';
import { filter } from 'rxjs';

/** Pause after the last keystroke of a live filter before the URL (and so a server request) follows it. */
export const LIVE_FILTER_DEBOUNCE_MS = 250;

export interface LiveEditOptions {
  /** Delay before the queued params go to the URL (0 = right after the edit, e.g. a choice in a list). */
  delay?: number;
  /** Replace the history entry instead of adding one: every edit after the first of one open filter. */
  replace?: boolean;
}

/**
 * Table state in the URL query (provide per page: `providers: [TableUrlState]`).
 * The page writes sort / filters / page with `update()`, and reads them back in `watch()` — on the first load,
 * after a click, and on «back»/«forward» — so a copied link opens the same view. Any change except paging
 * returns to page 1.
 *
 * Live filters (typing in a header filter): the header wraps its output in `live()`, so the page's own
 * `update()` call is queued and merged instead of navigating at once — one URL change (and one request of a
 * server table) after the user pauses, and one history entry per filter session, not per letter.
 */
@Injectable()
export class TableUrlState {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  private liveEdit: LiveEditOptions | null = null;
  private pending: { params: Params; paging: boolean; replace: boolean } | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // Leaving the page drops a queued edit: it must not navigate back to the old route afterwards.
    this.destroyRef.onDestroy(() => this.drop());
    // Any other navigation while an edit waits («back», a link to another page) wins: the stale edit is dropped.
    // Our own writes clear the queue before they navigate, so they never drop anything.
    this.router.events
      .pipe(
        filter((e) => e instanceof NavigationStart),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(() => this.drop());
  }

  /** Calls `apply` with the parsed query now and after every URL change, until the page is destroyed. */
  watch<Q>(parse: (params: ParamMap) => Q, apply: (query: Q) => void): void {
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => apply(parse(params)));
  }

  /**
   * Merges params into the URL (null removes one). `paging: true` keeps the page, otherwise it goes back to 1.
   * Inside `live()` the params are queued (see there); outside, a queued live edit goes along in the same
   * navigation, so nothing typed is lost and the URL changes once.
   */
  update(params: Params, opts: { paging?: boolean } = {}): void {
    const live = this.liveEdit;
    const queued = this.pending;
    this.pending = {
      params: { ...queued?.params, ...params },
      paging: (queued?.paging ?? true) && !!opts.paging,
      // Replace only when every queued edit asked for it: the first edit of a filter session adds an entry.
      replace: live ? (queued?.replace ?? true) && !!live.replace : false,
    };
    const delay = live?.delay ?? 0;
    if (live && delay > 0) {
      this.schedule(delay);
    } else {
      this.flush();
    }
  }

  /**
   * Runs `emit` (a header's filter output) as a live edit: `update()` calls made by the page inside it wait
   * `delay` ms (default LIVE_FILTER_DEBOUNCE_MS) for the next edit, then go to the URL together.
   */
  live(emit: () => void, opts: LiveEditOptions = {}): void {
    const previous = this.liveEdit;
    this.liveEdit = { delay: opts.delay ?? LIVE_FILTER_DEBOUNCE_MS, replace: opts.replace };
    try {
      emit();
    } finally {
      this.liveEdit = previous;
    }
  }

  /** Writes a queued live edit now (Enter / Esc / closing the filter). No-op when nothing waits. */
  flush(): void {
    this.clearTimer();
    const p = this.pending;
    if (!p) return;
    this.pending = null;
    const queryParams = p.paging ? p.params : { ...p.params, page: null };
    void this.router.navigate([], { relativeTo: this.route, queryParams, queryParamsHandling: 'merge', replaceUrl: p.replace });
  }

  private schedule(delay: number): void {
    this.clearTimer();
    this.timer = setTimeout(() => this.flush(), delay);
  }

  private drop(): void {
    this.clearTimer();
    this.pending = null;
  }

  private clearTimer(): void {
    if (this.timer !== null) clearTimeout(this.timer);
    this.timer = null;
  }
}

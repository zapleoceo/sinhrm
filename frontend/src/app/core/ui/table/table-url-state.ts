import { DestroyRef, Injectable, Signal, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, NavigationStart, ParamMap, Params, Router } from '@angular/router';
import { filter } from 'rxjs';
import { FilterValue, TableSort, filterToParam, sortToParams } from './table-state';

/** Pause after the last keystroke of a live filter before the URL (and so a server request) follows it. */
export const LIVE_FILTER_DEBOUNCE_MS = 250;

interface Write {
  params: Params;
  paging: boolean;
  replace: boolean;
}

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
 * Writes never overlap: a flush while the previous navigation is still running waits for it, so a session that
 * asked for one history entry gets exactly one (two overlapping navigations would let the second, «replace», one
 * overwrite the entry before the filter). `writing()` shows what is on its way meanwhile (ClientTable keeps the
 * typed rows on screen with it).
 */
@Injectable()
export class TableUrlState {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  private liveEdit: LiveEditOptions | null = null;
  /** Queued edit (waits for the pause or for the running navigation). */
  private readonly pending = signal<Write | null>(null);
  /** Edit whose navigation runs now (until the router settles it). */
  private readonly inFlight = signal<Write | null>(null);
  private timer: ReturnType<typeof setTimeout> | null = null;
  /** A flush came while a navigation ran: the queued edit goes as soon as that navigation settles. */
  private flushAfter = false;
  private destroyed = false;

  /**
   * Params on their way to the URL — queued or being navigated — merged (the queued ones win); null when nothing
   * waits. Dropped edits («back», another link) leave it at once.
   */
  readonly writing: Signal<Params | null> = computed(() => {
    const [running, queued] = [this.inFlight(), this.pending()];
    return running || queued ? { ...running?.params, ...queued?.params } : null;
  });

  constructor() {
    // Leaving the page drops a queued edit: it must not navigate back to the old route afterwards.
    this.destroyRef.onDestroy(() => {
      this.destroyed = true;
      this.drop();
    });
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
    const queued = this.pending();
    this.pending.set({
      params: { ...queued?.params, ...params },
      paging: (queued?.paging ?? true) && !!opts.paging,
      // Replace only when every queued edit asked for it: the first edit of a filter session adds an entry.
      replace: live ? (queued?.replace ?? true) && !!live.replace : false,
    });
    const delay = live?.delay ?? 0;
    if (live && delay > 0) {
      this.schedule(delay);
    } else {
      this.flush();
    }
  }

  /** Paginator event (`mat-paginator` `(page)`) → `page`/`perPage`; filters and sort stay. */
  setPage(e: { pageIndex: number; pageSize: number }): void {
    this.update({ page: e.pageIndex + 1, perPage: e.pageSize }, { paging: true });
  }

  /** Header sort → `sort`/`dir` (null = the API order); the page goes back to 1. */
  setSort(sort: TableSort | null): void {
    this.update(sortToParams(sort));
  }

  /** Header filter (text / chosen value) → one param; cleared → removed. The page goes back to 1. */
  setFilter(name: string, value: FilterValue): void {
    this.update({ [name]: filterToParam(value) });
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

  /**
   * Writes a queued live edit now (Enter / Esc / closing the filter). No-op when nothing waits. While the previous
   * write still navigates, the edit stays queued and goes right after it (see the class note).
   */
  flush(): void {
    this.clearTimer();
    const p = this.pending();
    if (!p || this.destroyed) return;
    if (this.inFlight()) {
      this.flushAfter = true;
      return;
    }
    this.flushAfter = false;
    this.pending.set(null);
    this.inFlight.set(p);
    const queryParams = p.paging ? p.params : { ...p.params, page: null };
    this.router
      .navigate([], { relativeTo: this.route, queryParams, queryParamsHandling: 'merge', replaceUrl: p.replace })
      .then(
        (done) => this.settled(p, done !== false),
        () => this.settled(p, false),
      );
  }

  /**
   * The router settled a write. An edit queued meanwhile goes now if a flush asked for it (otherwise its pause
   * runs on). Not done (a guard said no): that edit was typed over params that never reached the URL, so it takes
   * them along and keeps the failed write's history mode — still one entry for the session. A write superseded by
   * another navigation («back») finds nothing queued: NavigationStart dropped it.
   */
  private settled(write: Write, done: boolean): void {
    if (this.inFlight() !== write) return;
    this.inFlight.set(null);
    const next = this.pending();
    if (!next) return;
    if (!done) {
      this.pending.set({ params: { ...write.params, ...next.params }, paging: write.paging && next.paging, replace: write.replace && next.replace });
    }
    if (this.flushAfter) this.flush();
  }

  private schedule(delay: number): void {
    this.clearTimer();
    this.timer = setTimeout(() => this.flush(), delay);
  }

  private drop(): void {
    this.clearTimer();
    this.flushAfter = false;
    this.pending.set(null);
  }

  private clearTimer(): void {
    if (this.timer !== null) clearTimeout(this.timer);
    this.timer = null;
  }
}

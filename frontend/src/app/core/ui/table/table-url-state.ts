import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, ParamMap, Params, Router } from '@angular/router';

/**
 * Table state in the URL query (provide per page: `providers: [TableUrlState]`).
 * The page writes sort / filters / page with `update()`, and reads them back in `watch()` — on the first load,
 * after a click, and on «back»/«forward» — so a copied link opens the same view. Any change except paging
 * returns to page 1.
 */
@Injectable()
export class TableUrlState {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  /** Calls `apply` with the parsed query now and after every URL change, until the page is destroyed. */
  watch<Q>(parse: (params: ParamMap) => Q, apply: (query: Q) => void): void {
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => apply(parse(params)));
  }

  /** Merges params into the URL (null removes one). `paging: true` keeps the page, otherwise it goes back to 1. */
  update(params: Params, opts: { paging?: boolean } = {}): void {
    const queryParams = opts.paging ? params : { ...params, page: null };
    void this.router.navigate([], { relativeTo: this.route, queryParams, queryParamsHandling: 'merge' });
  }
}

import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, NavigationStart, ParamMap, Params, Router } from '@angular/router';
import { map } from 'rxjs';
import { TableQueryAdapter } from '../ui/table/table-query-adapter';
import { WindowNavigation } from './window-navigation';
import { WINDOW_CONTEXT } from './window-context';
import { decodeWindowQuery } from './window-query-codec';

/** Primary delegates unchanged; audited windows read/write their own bounded matrix-param namespace. */
@Injectable()
export class WindowQueryState implements TableQueryAdapter {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly context = inject(WINDOW_CONTEXT, { optional: true });
  private readonly navigation = inject(WindowNavigation, { optional: true });

  watch<Query>(parse: (params: ParamMap) => Query, apply: (query: Query) => void): void {
    const params = this.context
      ? this.route.paramMap.pipe(map(decodeWindowQuery))
      : this.route.queryParamMap;
    params.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((value) => apply(parse(value)));
  }

  write(params: Params, replaceUrl: boolean): Promise<boolean> {
    if (this.context && this.navigation) return this.navigation.updateWindowQuery(this.context.slot, params, replaceUrl);
    return this.router.navigate([], {
      relativeTo: this.route,
      queryParams: params,
      queryParamsHandling: 'merge',
      replaceUrl,
    });
  }

  shouldDropPendingEdit(navigation: NavigationStart): boolean {
    return this.context && this.navigation
      ? navigation.navigationTrigger === 'popstate' || this.navigation.shouldDropWindowTableEdit(this.context.slot, navigation.url)
      : true;
  }
}

import { DestroyRef } from '@angular/core';
import { InjectionToken } from '@angular/core';
import { NavigationStart, ParamMap, Params } from '@angular/router';

export interface TableQueryAdapter {
  watch<Query>(parse: (params: ParamMap) => Query, apply: (query: Query) => void, consumerDestroyRef?: DestroyRef): void;
  write(params: Params, replaceUrl: boolean): Promise<boolean>;
  shouldDropPendingEdit(navigation: NavigationStart): boolean;
}

/** Optional opt-in URL state adapter; without it TableUrlState keeps its original primary-query behavior. */
export const TABLE_QUERY_ADAPTER = new InjectionToken<TableQueryAdapter>('Table URL query adapter');

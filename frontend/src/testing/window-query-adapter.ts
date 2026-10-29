import { BehaviorSubject } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DestroyRef } from '@angular/core';
import { NavigationStart, ParamMap, Params, convertToParamMap } from '@angular/router';
import { TableQueryAdapter } from '../app/core/ui/table/table-query-adapter';

/** A decoded window query stream for feature tests; RouterHarness tests cover the production outlet adapter. */
export function windowQueryAdapter(initial: Params): { adapter: TableQueryAdapter; set: (params: Params) => void } {
  const changes = new BehaviorSubject<ParamMap>(convertToParamMap(initial));
  return {
    adapter: {
      watch: <Query>(parse: (params: ParamMap) => Query, apply: (query: Query) => void, consumerDestroyRef?: DestroyRef): void => {
        const params = consumerDestroyRef ? changes.pipe(takeUntilDestroyed(consumerDestroyRef)) : changes;
        params.subscribe((value) => apply(parse(value)));
      },
      write: async (): Promise<boolean> => false,
      shouldDropPendingEdit: (navigation: NavigationStart): boolean => navigation.navigationTrigger === 'popstate',
    },
    set: (params) => changes.next(convertToParamMap(params)),
  };
}

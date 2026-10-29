import { DestroyRef, Signal, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, ParamMap } from '@angular/router';
import { TABLE_QUERY_ADAPTER } from '../ui/table/table-query-adapter';

/** Route query state for legacy page readers: primary URLs stay query based; OS windows use their matrix adapter. */
export function windowAwareQueryParamMap(): Signal<ParamMap> {
  const route = inject(ActivatedRoute);
  const adapter = inject(TABLE_QUERY_ADAPTER, { optional: true });
  const destroyRef = inject(DestroyRef);
  if (!adapter) return toSignal(route.queryParamMap, { initialValue: route.snapshot.queryParamMap });
  const params = signal(route.snapshot.paramMap);
  adapter.watch((value) => value, (value) => params.set(value), destroyRef);
  return params.asReadonly();
}

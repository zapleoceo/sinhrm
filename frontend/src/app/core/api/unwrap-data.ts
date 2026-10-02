import { OperatorFunction, map } from 'rxjs';
import { DataEnvelope } from './api.model';

/** `http.get<DataEnvelope<T>>(…).pipe(unwrapData())` → Observable<T>: drops the `{ data: … }` wrapper of the answer. */
export function unwrapData<T>(): OperatorFunction<DataEnvelope<T>, T> {
  return map((r) => r.data);
}

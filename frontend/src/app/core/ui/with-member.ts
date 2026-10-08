/**
 * Immutable set update for signals: a NEW set with `value` added (`on`) or removed, so `signal.update()` notifies.
 * Used for "ids with a request in flight" (`pending().has(id)` disables a row), open tree nodes, cleared fields.
 */
export function withMember<T>(set: ReadonlySet<T>, value: T, on: boolean): Set<T> {
  const next = new Set(set);
  if (on) {
    next.add(value);
  } else {
    next.delete(value);
  }
  return next;
}

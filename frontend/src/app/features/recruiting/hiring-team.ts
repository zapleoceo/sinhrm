import { Ref } from './recruiting.model';

/**
 * The picker list plus the people already assigned (they may fall outside the first 50 matches, or be blocked since),
 * without duplicates — so a select always shows the current value.
 */
export function withCurrent(list: readonly Ref[], ...current: (Ref | null | undefined)[]): Ref[] {
  const out = [...list];
  for (const c of current) {
    if (c && !out.some((u) => u.id === c.id)) {
      out.push(c);
    }
  }
  return out;
}

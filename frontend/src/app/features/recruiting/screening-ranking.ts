/** Advisory scores only: preserve the default order for ties and unscored cards. */
export function rankByScreening<T extends { screening_score?: number | null }>(items: readonly T[]): T[] {
  return [...items].sort((left, right) => {
    const a = left.screening_score;
    const b = right.screening_score;
    if (a == null) return b == null ? 0 : 1;
    if (b == null) return -1;
    return b - a;
  });
}

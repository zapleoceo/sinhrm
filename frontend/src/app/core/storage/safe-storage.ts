/**
 * localStorage that never throws (private mode, blocked site data, SSR/tests without storage).
 * Used only for per-browser conveniences: theme and language of a guest.
 */
export const safeStorage = {
  get(key: string): string | null {
    try {
      return globalThis.localStorage?.getItem(key) ?? null;
    } catch {
      return null;
    }
  },
  set(key: string, value: string): void {
    try {
      globalThis.localStorage?.setItem(key, value);
    } catch {
      // storage unavailable — the preference just is not remembered
    }
  },
};

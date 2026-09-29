/** Pure joke helpers (no Angular): shared by the framework-free mascot brain/engine and the jokes service. */

/** What made him fall (engine) → which joke pool fits. */
export type FallCause = 'throw' | 'drop' | 'slip' | 'trip' | 'faint';
export type JokeSituation = 'fall' | 'thrown' | 'slip';

export const JOKE_SITUATIONS: readonly JokeSituation[] = ['fall', 'thrown', 'slip'];
/** Built-in jokes per situation in every language (assistant.jokes.<situation>.<n>). */
export const JOKE_FALLBACK_COUNT = 6;
/** Jokes not repeated within the last N shown. */
export const JOKE_MEMORY = 5;

/** An empty pool ("none": still generating on the server) is asked again after this long… */
export const JOKE_RETRY_MS = 60_000;

/** …at most this many times per pool and session. */
export const JOKE_MAX_TRIES = 4;

/** A joke to show: AI text as is, or an i18n key of a built-in one. */
export type Joke = { text: string } | { key: string };

export function situationFor(cause: FallCause): JokeSituation {
  switch (cause) {
    case 'throw':
      return 'thrown';
    case 'slip':
      return 'slip';
    default:
      return 'fall';
  }
}

/** Random item not among the `recent` ones (when possible); recent keeps the last `memory` picks. */
export function pickNoRepeat<T>(items: readonly T[], recent: T[], rng: () => number, memory = JOKE_MEMORY): T | null {
  if (items.length === 0) {
    return null;
  }
  const window = recent.slice(-Math.min(memory, items.length - 1));
  const pool = items.filter((i) => !window.includes(i));
  const from = pool.length > 0 ? pool : items;
  const choice = from[Math.min(from.length - 1, Math.floor(rng() * from.length))];
  recent.push(choice);
  if (recent.length > memory) {
    recent.splice(0, recent.length - memory);
  }
  return choice;
}

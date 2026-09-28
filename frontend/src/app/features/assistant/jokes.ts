import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AssistantService } from './assistant.service';

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

/**
 * Jokes «Стік» tells after getting up: AI-generated pools prefetched in the background (GET /api/assistant/quips,
 * once per session per situation and UI language), built-in i18n jokes when the pool is empty or AI is off.
 */
@Injectable({ providedIn: 'root' })
export class AssistantJokes {
  private readonly api = inject(AssistantService);
  private readonly pools = new Map<string, readonly string[]>();
  private readonly inflight = new Set<string>();
  /** Attempts per pool: an empty answer ("none" = the server is still generating) may be retried later. */
  private readonly tries = new Map<string, { count: number; at: number }>();
  private readonly recent = new Map<JokeSituation, string[]>();

  /** Loads every situation's pool for the language (each at most once per session). Never throws. */
  async prefetch(locale: string): Promise<void> {
    await Promise.all(JOKE_SITUATIONS.map((s) => this.load(s, locale)));
  }

  /** One joke for the situation: from the AI pool if there is one, otherwise a built-in one. */
  pick(situation: JokeSituation, locale: string, rng: () => number): Joke {
    const recent = this.recentFor(situation);
    const pool = this.pools.get(`${locale}:${situation}`) ?? [];
    const text = pickNoRepeat(pool, recent, rng);
    if (text !== null) {
      return { text };
    }
    // No AI pool yet: ask again in the background (the server collects a slow generation on the next call).
    void this.load(situation, locale);
    const keys = Array.from({ length: JOKE_FALLBACK_COUNT }, (_, i) => `assistant.jokes.${situation}.${i}`);
    return { key: pickNoRepeat(keys, recent, rng) ?? keys[0] };
  }

  private async load(situation: JokeSituation, locale: string): Promise<void> {
    const id = `${locale}:${situation}`;
    const tried = this.tries.get(id);
    const now = Date.now();
    if (
      (this.pools.get(id)?.length ?? 0) > 0 ||
      this.inflight.has(id) ||
      (tried !== undefined && (tried.count >= JOKE_MAX_TRIES || now - tried.at < JOKE_RETRY_MS))
    ) {
      return;
    }
    this.tries.set(id, { count: (tried?.count ?? 0) + 1, at: now });
    this.inflight.add(id);
    try {
      const res = await firstValueFrom(this.api.quips(situation, locale));
      const jokes = res.source === 'ai' ? res.jokes.filter((j) => typeof j === 'string' && j.trim().length > 0).map((j) => j.trim().slice(0, 160)) : [];
      this.pools.set(id, jokes);
    } catch {
      // Throttled (429), AI off or offline → built-in jokes.
      this.pools.set(id, []);
    } finally {
      this.inflight.delete(id);
    }
  }

  private recentFor(situation: JokeSituation): string[] {
    let list = this.recent.get(situation);
    if (!list) {
      list = [];
      this.recent.set(situation, list);
    }
    return list;
  }
}

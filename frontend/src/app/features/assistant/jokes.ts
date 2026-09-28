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
  private readonly requested = new Set<string>();
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
    const keys = Array.from({ length: JOKE_FALLBACK_COUNT }, (_, i) => `assistant.jokes.${situation}.${i}`);
    return { key: pickNoRepeat(keys, recent, rng) ?? keys[0] };
  }

  private async load(situation: JokeSituation, locale: string): Promise<void> {
    const id = `${locale}:${situation}`;
    if (this.requested.has(id)) {
      return;
    }
    this.requested.add(id);
    try {
      const res = await firstValueFrom(this.api.quips(situation, locale));
      const jokes = res.source === 'ai' ? res.jokes.filter((j) => typeof j === 'string' && j.trim().length > 0).map((j) => j.trim().slice(0, 160)) : [];
      this.pools.set(id, jokes);
    } catch {
      // Throttled (429), AI off or offline → built-in jokes.
      this.pools.set(id, []);
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

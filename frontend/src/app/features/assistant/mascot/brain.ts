import { AssistantMood } from '../assistant.model';
import { pickGreeting, pickQuip } from '../quips';
import type { EngineEvent } from './mascot-engine';
import { ACTIVITIES, isActivity } from './activities';
import { MOVES, isMove } from './moves';
import { FallCause, Joke, JokeSituation, situationFor } from '../joke-situations';
import { ActionName, ROUTE_ACTIONS, ENTRANCES, EXITS, EntranceAction, ExitAction, GestureName, IDLE_WEIGHTS } from './animations';

export type BrainState =
  | 'offstage'
  | 'entering'
  | 'idle'
  | 'asleep'
  | 'waking'
  | 'exiting'
  | 'dragged'
  | 'airborne'
  | 'recovering'
  | 'fallen'
  | 'docked'
  | 'static'
  | 'orb'
  | 'curling'
  | 'unfolding';

export type BrainCommand =
  | { type: 'play'; action: ActionName; side?: 'left' | 'right'; targetX?: number; blend?: number; variant?: 'full' | 'sit'; fromX?: number }
  | { type: 'gesture'; name: GestureName | null }
  /** A line in the bubble: an i18n key, or ready text (AI joke) when `text` is set. */
  | { type: 'say'; key: string | null; text?: string }
  | { type: 'visible'; value: boolean };

/** Timer source (fake timers in tests). */
export interface Scheduler {
  setTimeout(fn: () => void, ms: number): unknown;
  clearTimeout(handle: unknown): void;
  now(): number;
}

export interface BrainOptions {
  rng?: () => number;
  scheduler?: Scheduler;
  /** The user is typing somewhere — do not pop up now. */
  isTyping?: () => boolean;
  /** Viewport width (for where he lands). */
  width?: () => number;
  /** A joke for the situation after getting up (AI pool or a built-in one). */
  joke?: (situation: JokeSituation) => Joke | null;
  /** Is it OK to joke now (the chat is not answering, nobody is typing)? */
  canJoke?: () => boolean;
  /** Chance to tell a joke after getting up (0..1). */
  jokeChance?: number;
  /** The conversation or voice dictation is in progress — he must stay awake. */
  isBusy?: () => boolean;
  /** Local hour 0–23 (time-of-day flavour; nothing is sent anywhere). */
  hour?: () => number;
  /** A windy day (kite flying). */
  windy?: () => boolean;
}

/** How many recent picks (idles, activities, moves) are not repeated. */
export const RECENT_MEMORY = 6;
/** Weight of wandering somewhere with a locomotion move among the idle choices. */
export const WANDER_WEIGHT = 3;
export const MOVE_ENTRANCE_CHANCE = 0.35;
export const MOVE_EXIT_CHANCE = 0.3;

/** A windy day, decided from the local date only. */
export function windyToday(date = new Date()): boolean {
  const k = date.getFullYear() * 372 + date.getMonth() * 31 + date.getDate();
  return ((k * 2654435761) >>> 0) % 3 === 0;
}

export const JOKE_CHANCE = 0.7;

/** Timings in ms. */
export const TIMING = {
  firstAppearance: 4000,
  minGap: 180_000,
  maxGap: 480_000,
  stay: 12_000,
  hoverGrace: 4000,
  sleepAfter: 90_000,
  routeCooldown: 120_000,
  routeChance: 0.35,
  routeDelay: 1500,
  typingRetry: 5000,
  quipShown: 6500,
  talk: 4000,
  jolt: 2500,
} as const;

const LITE_ENTRANCES: readonly EntranceAction[] = ['enter-walk', 'enter-peek', 'enter-sneak'];

const defaultScheduler: Scheduler = {
  setTimeout: (fn, ms) => globalThis.setTimeout(fn, ms),
  clearTimeout: (h) => globalThis.clearTimeout(h as ReturnType<typeof setTimeout>),
  now: () => Date.now(),
};

/** Random item, never the same as the previous one when there is a choice. */
export function pickOne<T>(list: readonly T[], avoid: T | null, rng: () => number): T {
  const pool = list.length > 1 && avoid !== null ? list.filter((x) => x !== avoid) : list;
  return pool[Math.min(pool.length - 1, Math.floor(rng() * pool.length))];
}

/** Weighted random key, never the previous one when there is a choice. */
export function pickWeighted<T extends string>(weights: Readonly<Record<T, number>>, avoid: T | null, rng: () => number): T {
  const all = Object.keys(weights) as T[];
  const others = all.filter((k) => k !== avoid);
  // The only option is the one to avoid: take it anyway.
  const keys = others.length > 0 ? others : all;
  const total = keys.reduce((a, k) => a + weights[k], 0);
  let r = rng() * total;
  for (const k of keys) {
    r -= weights[k];
    if (r < 0) {
      return k;
    }
  }
  return keys[keys.length - 1];
}

type TimerName = 'appear' | 'stay' | 'sleep' | 'say' | 'talk' | 'getup' | 'fidget';

const DOCKED_FIDGET_MIN = 18_000;
const DOCKED_FIDGET_MAX = 40_000;

/** Throws within this window make him sulk. */
export const ANNOY_WINDOW_MS = 20_000;
export const ANNOY_THROWS = 3;
export const OUCH_COUNT = 5;
/** Impact (px/s) that deserves an «ouch» and a dizzy spell. */
export const OUCH_IMPACT = 800;
export const DIZZY_IMPACT = 1500;

/**
 * Behaviour of «Стік» (framework-free): when he appears and how, what he does meanwhile, when he leaves,
 * sleeping, dragging, the chat seat and the on/off circle. Emits commands; the component feeds back clip/physics events.
 */
export class MascotBrain {
  private current: BrainState = 'offstage';
  private enabled = true;
  private reduced = false;
  private chatOpen = false;
  private url = '/';
  private lastAppearance = -Infinity;
  private lastJolt = -Infinity;
  private pendingQuip: string | null = null;
  private lastEntrance: EntranceAction | null = null;
  private lastExit: ExitAction | null = null;
  private lastFidget: keyof typeof IDLE_WEIGHTS | null = null;
  private lastWasBreath = false;
  private hovered = false;
  private lastTargetX: number | null = null;
  private lite = false;
  private beforeSleep: BrainState = 'idle';
  private throws: number[] = [];
  private annoyed = false;
  private hardFall = false;
  private readonly timers = new Map<TimerName, unknown>();
  private readonly rng: () => number;
  private readonly scheduler: Scheduler;
  private readonly isTyping: () => boolean;
  private readonly width: () => number;
  private readonly joke: (situation: JokeSituation) => Joke | null;
  private readonly canJoke: () => boolean;
  private readonly jokeChance: number;
  private readonly isBusy: () => boolean;
  private readonly hour: () => number;
  private readonly windy: () => boolean;
  /** Last picks (idle fidgets, activities, moves): none of them repeats within RECENT_MEMORY. */
  private recent: string[] = [];
  /** Why the current fall happened (null — not falling). */
  private fallSituation: JokeSituation | null = null;

  constructor(
    private readonly emit: (command: BrainCommand) => void,
    options: BrainOptions = {},
  ) {
    // Late-bound so a test can stub Math.random after construction.
    this.rng = options.rng ?? (() => Math.random());
    this.scheduler = options.scheduler ?? defaultScheduler;
    this.isTyping = options.isTyping ?? (() => false);
    this.width = options.width ?? (() => 1280);
    this.joke = options.joke ?? (() => null);
    this.canJoke = options.canJoke ?? (() => true);
    this.jokeChance = options.jokeChance ?? JOKE_CHANCE;
    this.isBusy = options.isBusy ?? (() => false);
    this.hour = options.hour ?? (() => new Date().getHours());
    this.windy = options.windy ?? (() => windyToday());
  }

  get state(): BrainState {
    return this.current;
  }

  /** On screen (the loop must run). */
  get visible(): boolean {
    return this.current !== 'offstage';
  }

  get canDrag(): boolean {
    return ['entering', 'idle', 'asleep', 'waking', 'docked', 'static', 'recovering', 'fallen', 'airborne'].includes(this.current);
  }

  start(enabled: boolean, reducedMotion: boolean): void {
    this.enabled = enabled;
    this.reduced = reducedMotion;
    if (!enabled) {
      this.toOrb(true);
    } else if (reducedMotion) {
      this.toStatic();
    } else {
      this.current = 'offstage';
      this.schedule('appear', TIMING.firstAppearance, () => this.appear('greeting'));
    }
    this.resetSleep();
  }

  destroy(): void {
    for (const name of [...this.timers.keys()]) {
      this.clear(name);
    }
  }

  setEnabled(on: boolean): void {
    if (on === this.enabled) {
      return;
    }
    this.enabled = on;
    this.clear('appear');
    this.clear('stay');
    this.emit({ type: 'say', key: null });
    this.emit({ type: 'gesture', name: null });
    if (!on) {
      if (this.reduced || this.current === 'offstage') {
        this.toOrb(!this.reduced);
      } else {
        this.current = 'curling';
        this.play('curl');
      }
      return;
    }
    if (this.reduced) {
      if (this.chatOpen) {
        this.toDocked(0);
      } else {
        this.toStatic();
      }
      return;
    }
    this.current = 'unfolding';
    this.pendingQuip = pickGreeting(this.rng);
    this.emit({ type: 'visible', value: true });
    this.play('unfold');
  }

  /** Lite animation: only the simple walking entrances. */
  setLite(on: boolean): void {
    this.lite = on;
  }

  setReducedMotion(on: boolean): void {
    if (on === this.reduced) {
      return;
    }
    this.reduced = on;
    this.clear('appear');
    this.clear('stay');
    if (!this.enabled) {
      this.toOrb(false);
    } else if (this.chatOpen) {
      this.toDocked(on ? 0 : undefined);
    } else if (on) {
      this.toStatic();
    } else {
      this.goOffstage();
    }
  }

  routeChanged(url: string): void {
    this.url = url;
    const now = this.scheduler.now();
    if (
      this.current === 'offstage' &&
      this.enabled &&
      !this.chatOpen &&
      !this.reduced &&
      now - this.lastAppearance >= TIMING.routeCooldown &&
      this.rng() < TIMING.routeChance
    ) {
      this.schedule('appear', TIMING.routeDelay, () => this.appear('quip'));
    }
  }

  userActivity(): void {
    this.resetSleep();
  }

  pointerNear(): void {
    if (this.current === 'asleep') {
      this.current = 'waking';
      this.play('wake');
    }
    this.resetSleep();
  }

  hover(on: boolean): void {
    this.hovered = on;
  }

  scrollJolt(speed: number): void {
    const now = this.scheduler.now();
    if (this.current === 'idle' && Math.abs(speed) > TIMING.jolt && now - this.lastJolt > 4000) {
      this.lastJolt = now;
      this.play('hold-on');
    }
  }

  chatOpened(): void {
    this.chatOpen = true;
    this.clear('appear');
    this.clear('stay');
    this.emit({ type: 'say', key: null });
    if (!this.enabled) {
      this.toOrb(false);
    } else if (['fallen', 'recovering', 'airborne', 'dragged', 'entering'].includes(this.current)) {
      // Busy entering / falling / getting up / being held: he heads for the panel once he is on his feet.
      return;
    } else if (['idle', 'asleep', 'waking', 'static'].includes(this.current) && !this.reduced) {
      this.routeToSeat();
    } else {
      this.toDocked(this.reduced ? 0 : undefined);
    }
  }

  chatClosed(): void {
    this.chatOpen = false;
    this.emit({ type: 'gesture', name: null });
    if (!this.enabled) {
      this.toOrb(false);
    } else if (this.reduced) {
      this.toStatic();
    } else if (this.current === 'docked' || this.current === 'asleep' || this.current === 'waking') {
      this.exit();
    }
  }

  mood(mood: AssistantMood, talkMs: number = TIMING.talk): void {
    if (this.current === 'offstage') {
      return;
    }
    this.clear('talk');
    if (mood !== 'idle') {
      // Something is happening in the chat: wake up if asleep, and the inactivity clock starts over.
      if (this.current === 'asleep') {
        this.current = 'waking';
        this.play('wake');
      }
      this.resetSleep();
    }
    const map: Record<AssistantMood, GestureName | null> = { idle: null, think: 'think', talk: 'talk', celebrate: 'celebrate', shrug: 'shrug', point: 'point', listen: 'listen' };
    this.emit({ type: 'gesture', name: map[mood] });
    if (mood === 'talk') {
      this.schedule('talk', talkMs, () => this.emit({ type: 'gesture', name: null }));
    }
  }

  dragStart(): void {
    this.clear('fidget');
    this.current = 'dragged';
    this.clear('stay');
    this.clear('getup');
    this.emit({ type: 'say', key: null });
  }

  /** Released: he flies as a ragdoll (the engine reports `fell`, `landed`, `rested`). */
  dragEnd(): void {
    if (this.reduced) {
      // Reduced motion: the engine eases him to standing where he was dropped; no fall, no throw counting.
      this.current = 'static';
      return;
    }
    const now = this.scheduler.now();
    this.throws = [...this.throws.filter((t) => now - t < ANNOY_WINDOW_MS), now];
    this.current = 'airborne';
  }

  /** The ragdoll took over (throw, slip, trip, faint): no exits or fidgets until he is up again. */
  fell(cause: FallCause = 'trip'): void {
    this.clear('fidget');
    if (this.reduced) {
      return;
    }
    this.fallSituation = situationFor(cause);
    this.current = 'fallen';
    this.clear('stay');
    this.clear('getup');
    this.emit({ type: 'gesture', name: null });
  }

  landed(speed: number): void {
    if ((this.current === 'fallen' || this.current === 'airborne') && speed > OUCH_IMPACT) {
      const key = `assistant.ouch.${Math.floor(this.rng() * OUCH_COUNT)}`;
      this.emit({ type: 'say', key });
      this.schedule('say', 2500, () => this.emit({ type: 'say', key: null }));
    }
    // Entrances landing on their feet (jump, drop, rope, gopher): the engine plays 'land', clipDone continues.
  }

  /** Lying still: after a pause (longer the harder the fall) he gets up — or sits up to sulk if thrown too often. */
  rested(impact: number): void {
    if (this.current !== 'fallen' && this.current !== 'airborne') {
      return;
    }
    this.current = 'fallen';
    const now = this.scheduler.now();
    this.annoyed = this.throws.filter((t) => now - t < ANNOY_WINDOW_MS).length >= ANNOY_THROWS;
    this.hardFall = impact > DIZZY_IMPACT;
    const delay = 350 + Math.min(1800, impact * 0.7);
    this.schedule('getup', delay, () => {
      if (this.current === 'fallen') {
        this.current = 'recovering';
        this.play('getup', { variant: this.annoyed ? 'sit' : 'full' });
      }
    });
  }

  offscreen(): void {
    if (this.current === 'exiting' || this.current === 'airborne') {
      this.goOffstage();
    }
  }

  clipDone(action: ActionName): void {
    switch (this.current) {
      case 'entering':
        if (action === 'enter-peek') {
          if (this.rng() < 0.4) {
            this.current = 'exiting';
            this.play('exit-peek');
          } else {
            this.play('peek-out', { targetX: this.randomX() });
          }
        } else {
          this.toIdle();
        }
        break;
      case 'idle':
        this.nextIdle();
        break;
      case 'recovering':
        this.recover(action);
        break;
      case 'exiting':
        if ((EXITS as readonly string[]).includes(action) || action === 'exit-peek' || isMove(action)) {
          this.goOffstage();
        }
        break;
      case 'unfolding':
        this.toIdle();
        break;
      case 'curling':
        this.toOrb(false);
        break;
      case 'orb':
        if (action === 'orb-pop') {
          this.play('orb', { blend: 0 });
        }
        break;
      case 'waking':
        this.restoreAfterSleep();
        break;
      case 'docked':
        if ((ROUTE_ACTIONS as readonly string[]).includes(action) || action === 'act-fishing') {
          this.play('docked', { blend: 0.12 });
          this.scheduleDockedFidget();
        }
        break;
      default:
        break;
    }
  }

  /* ───────────── internals ───────────── */

  private appear(reason: 'greeting' | 'quip'): void {
    if (!this.enabled || this.chatOpen || this.reduced || this.current !== 'offstage') {
      return;
    }
    if (this.isTyping()) {
      this.schedule('appear', TIMING.typingRetry, () => this.appear(reason));
      return;
    }
    this.pendingQuip = reason === 'greeting' ? pickGreeting(this.rng) : pickQuip(this.url, this.rng);
    this.current = 'entering';
    this.lastAppearance = this.scheduler.now();
    this.emit({ type: 'visible', value: true });
    const side: 'left' | 'right' = this.rng() < 0.5 ? 'left' : 'right';
    if (this.rng() < MOVE_ENTRANCE_CHANCE) {
      // In on a skateboard, a unicycle, crawling, hand over hand along the top edge…
      const move = this.pickFresh(this.moveChoices('entrance')) as ActionName;
      this.play(move, { fromX: side === 'left' ? -60 : this.width() + 60, targetX: this.randomX() });
      return;
    }
    const entrance = pickOne(this.lite ? LITE_ENTRANCES : ENTRANCES, this.lastEntrance, this.rng);
    this.lastEntrance = entrance;
    this.play(entrance, { side, targetX: this.randomX() });
  }

  /**
   * getup → (sulk → stand-up) → (dizzy) → rub-head → dust → idle (or a hop back onto the open chat).
   * The joke comes the moment he is back on his feet, not after the head rub and dust-off.
   */
  private recover(action: ActionName): void {
    switch (action) {
      case 'getup':
        if (this.annoyed) {
          this.play('sulk');
        } else {
          this.tellJoke();
          this.play(this.hardFall ? 'dizzy' : 'rub-head');
        }
        break;
      case 'sulk':
        this.annoyed = false;
        this.throws = [];
        this.play('stand-up');
        break;
      case 'stand-up':
        // Up again after sulking: a (grumpy) joke.
        this.tellJoke();
        this.play('rub-head');
        break;
      case 'dizzy':
        this.play('rub-head');
        break;
      case 'rub-head':
        this.play('dust');
        break;
      default:
        this.toIdle();
        break;
    }
  }

  /** Standing again after a fall: sometimes a joke about it (never while the chat answers or the user types). */
  private tellJoke(): void {
    const situation = this.fallSituation;
    this.fallSituation = null;
    if (!situation || this.reduced || this.rng() >= this.jokeChance || !this.canJoke()) {
      return;
    }
    const joke = this.joke(situation);
    if (!joke) {
      return;
    }
    this.emit('text' in joke ? { type: 'say', key: null, text: joke.text } : { type: 'say', key: joke.key });
    this.schedule('say', TIMING.quipShown, () => this.emit({ type: 'say', key: null }));
  }

  private toIdle(): void {
    if (this.chatOpen) {
      // Standing on the floor with the chat open: find a way up onto the panel.
      this.routeToSeat();
      return;
    }
    this.current = 'idle';
    this.lastWasBreath = true;
    this.play('idle-breathe');
    if (this.pendingQuip) {
      const key = this.pendingQuip;
      this.pendingQuip = null;
      this.emit({ type: 'say', key });
      this.schedule('say', TIMING.quipShown, () => this.emit({ type: 'say', key: null }));
    }
    if (!this.reduced) {
      this.schedule('stay', TIMING.stay, () => this.stayOver());
    }
    this.resetSleep();
  }

  private stayOver(): void {
    if (this.current !== 'idle') {
      return;
    }
    if (this.hovered) {
      this.schedule('stay', TIMING.hoverGrace, () => this.stayOver());
      return;
    }
    this.exit();
  }

  /** Idle choices with their weights now: fidgets, activities (lite / windy / time of day) and wandering. */
  idleChoices(): Record<string, number> {
    const hour = this.hour();
    const morning = hour >= 6 && hour < 11;
    const late = hour >= 21 || hour < 4;
    const out: Record<string, number> = {};
    for (const [k, w] of Object.entries(IDLE_WEIGHTS) as [string, number][]) {
      out[k] = late && (k === 'idle-yawn' || k === 'idle-stretch') ? w * 3 : w;
    }
    for (const [k, info] of Object.entries(ACTIVITIES)) {
      if (info.docked || (this.lite && !info.lite) || (info.windy && !this.windy())) {
        continue;
      }
      out[k] = info.weight * (morning && info.morning ? info.morning : 1);
    }
    out['wander'] = WANDER_WEIGHT;
    return out;
  }

  /** Weighted pick that skips the recent ones (falls back to all when everything is recent). */
  private pickFresh(weights: Record<string, number>): string {
    const fresh: Record<string, number> = {};
    for (const [k, w] of Object.entries(weights)) {
      if (!this.recent.includes(k)) {
        fresh[k] = w;
      }
    }
    const pool = Object.keys(fresh).length > 0 ? fresh : weights;
    const pick = pickWeighted(pool, null, this.rng);
    this.recent = [...this.recent, pick].slice(-RECENT_MEMORY);
    return pick;
  }

  /** Locomotion moves allowed for a purpose (lite mode keeps the cheaper ones). */
  private moveChoices(kind: 'entrance' | 'exit' | 'wander'): Record<string, number> {
    const out: Record<string, number> = {};
    for (const [k, info] of Object.entries(MOVES)) {
      if (info[kind] && (!this.lite || info.lite)) {
        out[k] = info.weight;
      }
    }
    return out;
  }

  private nextIdle(): void {
    if (this.lastWasBreath) {
      const pick = this.pickFresh(this.idleChoices());
      this.lastWasBreath = false;
      if (pick === 'wander') {
        // Off somewhere else along the bottom edge — running, cartwheeling, on a skateboard…
        const move = this.pickFresh(this.moveChoices('wander')) as ActionName;
        this.play(move, { targetX: this.wanderX() });
        return;
      }
      if (!isActivity(pick)) {
        this.lastFidget = pick as keyof typeof IDLE_WEIGHTS;
      }
      this.play(pick as ActionName);
    } else {
      this.lastWasBreath = true;
      this.play('idle-breathe');
    }
  }

  private exit(): void {
    this.clear('fidget');
    this.clear('stay');
    this.emit({ type: 'say', key: null });
    this.emit({ type: 'gesture', name: null });
    this.current = 'exiting';
    if (!this.reduced && this.rng() < MOVE_EXIT_CHANCE) {
      const move = this.pickFresh(this.moveChoices('exit')) as ActionName;
      this.play(move, { targetX: this.lastX() < this.width() / 2 ? -90 : this.width() + 90 });
      return;
    }
    const exit = pickOne(EXITS, this.lastExit, this.rng);
    this.lastExit = exit;
    this.play(exit);
  }

  private goOffstage(): void {
    if (this.chatOpen && this.enabled) {
      // The chat is open (he was peeking or leaving when it opened): come and sit on it instead.
      this.toDocked();
      return;
    }
    this.current = 'offstage';
    this.emit({ type: 'say', key: null });
    this.emit({ type: 'visible', value: false });
    if (this.enabled && !this.chatOpen && !this.reduced) {
      const gap = TIMING.minGap + this.rng() * (TIMING.maxGap - TIMING.minGap);
      this.schedule('appear', gap, () => this.appear('quip'));
    }
  }

  /** Gets onto the chat panel by one of the routes (the engine picks it by geometry). */
  private routeToSeat(): void {
    this.clear('fidget');
    this.current = 'docked';
    this.emit({ type: 'visible', value: true });
    this.play('seat-route');
    this.resetSleep();
  }

  private toDocked(blend?: number): void {
    this.current = 'docked';
    this.emit({ type: 'visible', value: true });
    this.play('docked', blend === undefined ? {} : { blend });
    this.resetSleep();
    this.scheduleDockedFidget();
  }

  /** Sitting on the chat panel he now and then goes fishing off its edge (not while the chat is busy). */
  private scheduleDockedFidget(): void {
    if (this.reduced) {
      return;
    }
    this.schedule('fidget', DOCKED_FIDGET_MIN + this.rng() * (DOCKED_FIDGET_MAX - DOCKED_FIDGET_MIN), () => {
      if (this.current === 'docked' && this.chatOpen && !this.isBusy()) {
        this.play('act-fishing');
      } else if (this.current === 'docked') {
        this.scheduleDockedFidget();
      }
    });
  }

  /** A floor x at least 150 px away from where he is. */
  private wanderX(): number {
    const w = this.width();
    const here = this.lastX();
    for (let i = 0; i < 6; i++) {
      const x = Math.round(60 + this.rng() * (w - 120));
      if (Math.abs(x - here) >= 150) {
        return x;
      }
    }
    return here < w / 2 ? Math.min(w - 60, here + 200) : Math.max(60, here - 200);
  }

  /** Roughly where he is (the last x the brain sent him to). */
  private lastX(): number {
    return this.lastTargetX ?? this.width() / 2;
  }

  private toStatic(): void {
    this.current = 'static';
    this.emit({ type: 'visible', value: true });
    this.play('static', { targetX: this.width() - 90, blend: 0 });
  }

  private toOrb(pop: boolean): void {
    this.clear('appear');
    this.clear('stay');
    this.current = 'orb';
    this.emit({ type: 'visible', value: true });
    this.play(pop ? 'orb-pop' : 'orb', this.reduced ? { blend: 0 } : {});
  }

  private resetSleep(): void {
    this.schedule('sleep', TIMING.sleepAfter, () => {
      if (this.isBusy()) {
        // Never doze off while an answer is pending, tools run, a confirmation waits or the mic records.
        this.resetSleep();
        return;
      }
      if (this.current === 'idle' || this.current === 'docked' || this.current === 'static') {
        this.beforeSleep = this.current;
        this.clear('stay');
        this.current = 'asleep';
        this.emit({ type: 'gesture', name: null });
        this.play('sleep');
      }
    });
  }

  private restoreAfterSleep(): void {
    if (this.beforeSleep === 'docked' && this.chatOpen) {
      this.toDocked();
    } else if (this.beforeSleep === 'static' || this.reduced) {
      this.toStatic();
    } else {
      this.toIdle();
    }
  }

  private randomX(): number {
    const w = this.width();
    return Math.round(w * (0.22 + this.rng() * 0.5));
  }

  private play(action: ActionName, extra: { side?: 'left' | 'right'; targetX?: number; blend?: number; variant?: 'full' | 'sit'; fromX?: number } = {}): void {
    if (extra.targetX !== undefined) {
      this.lastTargetX = extra.targetX;
    }
    this.emit({ type: 'play', action, ...extra });
  }

  private schedule(name: TimerName, ms: number, fn: () => void): void {
    this.clear(name);
    this.timers.set(
      name,
      this.scheduler.setTimeout(() => {
        this.timers.delete(name);
        fn();
      }, ms),
    );
  }

  private clear(name: TimerName): void {
    const handle = this.timers.get(name);
    if (handle !== undefined) {
      this.scheduler.clearTimeout(handle);
      this.timers.delete(name);
    }
  }
}

/** Feeds an engine event to the brain. */
export function dispatchEngineEvent(brain: MascotBrain, e: EngineEvent): void {
  switch (e.type) {
    case 'clipDone':
      brain.clipDone(e.action);
      break;
    case 'landed':
      brain.landed(e.speed);
      break;
    case 'fell':
      brain.fell(e.cause);
      break;
    case 'rested':
      brain.rested(e.impact);
      break;
    case 'offscreen':
      brain.offscreen();
      break;
  }
}

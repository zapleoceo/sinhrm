import { Vec } from './skeleton';

/** Little drawn extras around «Стік». Pure data + pure update; the renderer draws them from a pool. */
export type EffectKind = 'puff' | 'spark' | 'streak' | 'drop' | 'bang' | 'zz' | 'star' | 'ring' | 'doodle' | 'rope' | 'banana';

export type DoodleShape = 'heart' | 'star' | 'spiral' | 'smile';
export const DOODLE_SHAPES: readonly DoodleShape[] = ['heart', 'star', 'spiral', 'smile'];

export interface Effect {
  kind: EffectKind;
  /** World position; for head-attached effects — offset from the head centre. */
  x: number;
  y: number;
  vx: number;
  vy: number;
  age: number;
  life: number;
  size: number;
  rot: number;
  attached: boolean;
  /** Doodle shape index / rope length. */
  data: number;
  /** Rope pendulum angle (from straight down) and angular velocity. */
  angle: number;
  av: number;
}

/** At most this many effects alive (pooled SVG elements in the renderer). */
export const MAX_EFFECTS = 16;

function make(kind: EffectKind, x: number, y: number, life: number, extra: Partial<Effect> = {}): Effect {
  return { kind, x, y, vx: 0, vy: 0, age: 0, life, size: 1, rot: 0, attached: false, data: 0, angle: 0, av: 0, ...extra };
}

/** Landing dust: a few circles puffing out sideways along the ground; bigger impact → more and wider. */
export function spawnDust(x: number, y: number, strength: number, rng: () => number): Effect[] {
  const s = Math.min(1, Math.max(0.15, strength));
  const n = Math.round(2 + s * 3);
  const out: Effect[] = [];
  for (let i = 0; i < n; i++) {
    const dir = i % 2 === 0 ? 1 : -1;
    out.push(
      make('puff', x + dir * (4 + rng() * 6), y - 2 - rng() * 3, 0.45 + rng() * 0.35, {
        vx: dir * (40 + rng() * 140 * s),
        vy: -(10 + rng() * 50 * s),
        size: 2.5 + rng() * 3.5 * s,
      }),
    );
  }
  return out;
}

export function spawnSparkles(x: number, y: number, rng: () => number): Effect[] {
  return Array.from({ length: 5 }, (_, i) => {
    const a = (i / 5) * Math.PI * 2 + rng() * 0.5;
    const speed = 70 + rng() * 60;
    return make('spark', x, y, 0.7 + rng() * 0.3, { vx: Math.cos(a) * speed, vy: Math.sin(a) * speed - 40, size: 3 + rng() * 2, rot: rng() * Math.PI });
  });
}

/** Speed line left behind a fast move (drawn along the velocity). */
export function spawnStreak(x: number, y: number, vx: number, vy: number, rng: () => number): Effect {
  return make('streak', x + (rng() - 0.5) * 30, y + (rng() - 0.5) * 50, 0.22, { vx, vy, size: 0.6 + rng() * 0.6 });
}

export function spawnSweat(): Effect {
  return make('drop', 11, -8, 1.1, { attached: true, vy: 14 });
}

export function spawnBang(): Effect {
  return make('bang', 6, -26, 0.9, { attached: true });
}

export function spawnZ(x: number, y: number, rng: () => number): Effect {
  return make('zz', x, y, 2.4, { vx: 8 + rng() * 6, vy: -18, size: 0.8, rot: rng() * Math.PI * 2 });
}

/** Dizzy stars orbiting above the head. */
export function spawnStars(): Effect[] {
  return [0, 1, 2].map((i) => make('star', 0, -16, 1.8, { attached: true, rot: (i / 3) * Math.PI * 2, size: 3 }));
}

/** Ripple when he knocks on the screen glass. */
export function spawnRing(x: number, y: number): Effect {
  return make('ring', x, y, 0.5, { size: 3 });
}

export function spawnDoodle(x: number, y: number, shape: DoodleShape, size: number): Effect {
  return make('doodle', x, y, 4.2, { data: DOODLE_SHAPES.indexOf(shape), size });
}

/** A released vine that keeps swinging and is pulled up out of view. */
export function spawnRope(ax: number, ay: number, length: number, angle: number, av: number): Effect {
  return make('rope', ax, ay, 1.4, { data: length, angle, av });
}

/** Banana-peel doodle on the floor (the slip gag). */
export function spawnBanana(x: number, y: number): Effect {
  return make('banana', x, y, 3.2, { size: 1 });
}

/** Seconds a doodle spends being drawn (the hand follows the same curve). */
export const DOODLE_DRAW = 2.4;

/** Point of a doodle curve at u ∈ [0, 1], unit size, centred on the first point. */
export function doodlePoint(shape: DoodleShape, u: number): Vec {
  const t = Math.min(1, Math.max(0, u));
  switch (shape) {
    case 'heart': {
      const a = t * Math.PI * 2;
      const x = 16 * Math.pow(Math.sin(a), 3);
      const y = -(13 * Math.cos(a) - 5 * Math.cos(2 * a) - 2 * Math.cos(3 * a) - Math.cos(4 * a));
      return { x: x / 17, y: (y + 5) / 17 };
    }
    case 'star': {
      const k = t * 5;
      const i = Math.floor(k) % 5;
      const f = k - Math.floor(k);
      const vertex = (n: number): Vec => {
        const a = -Math.PI / 2 + ((n * 2) % 5) * ((Math.PI * 2) / 5);
        return { x: Math.cos(a), y: Math.sin(a) + 1 };
      };
      const p = vertex(i);
      const q = vertex(i + 1);
      return { x: p.x + (q.x - p.x) * f, y: p.y + (q.y - p.y) * f };
    }
    case 'spiral': {
      const a = t * Math.PI * 5;
      const r = 0.1 + t * 0.9;
      return { x: Math.cos(a) * r - 0.1, y: Math.sin(a) * r };
    }
    case 'smile': {
      if (t < 0.7) {
        const a = (t / 0.7) * Math.PI * 2;
        return { x: Math.sin(a), y: 1 - Math.cos(a) };
      }
      const s = (t - 0.7) / 0.3;
      const a = Math.PI * (0.25 + 0.5 * s);
      return { x: -Math.cos(a) * 0.55, y: 1 + Math.sin(a) * 0.45 };
    }
  }
}

/** Ages, moves and drops expired effects. Pure. */
const NO_EFFECTS: Effect[] = Object.freeze([]) as unknown as Effect[];

export function stepEffects(list: readonly Effect[], dt: number): Effect[] {
  if (list.length === 0) {
    // Nothing alive: no new array every step.
    return NO_EFFECTS;
  }
  const out: Effect[] = [];
  for (const e of list) {
    const age = e.age + dt;
    if (age >= e.life) {
      continue;
    }
    const n: Effect = { ...e, age };
    switch (e.kind) {
      case 'puff':
        n.vx = e.vx * Math.exp(-5 * dt);
        n.vy = e.vy * Math.exp(-4 * dt) - 6 * dt;
        break;
      case 'spark':
        n.vx = e.vx * Math.exp(-3 * dt);
        n.vy = e.vy * Math.exp(-3 * dt) + 60 * dt;
        n.rot = e.rot + dt * 4;
        break;
      case 'drop':
        n.vy = e.vy + 80 * dt;
        break;
      case 'zz':
        n.vx = 10 * Math.sin(age * 3 + e.rot);
        n.size = 0.8 + age * 0.35;
        break;
      case 'star':
        n.rot = e.rot + dt * 5.5;
        break;
      case 'ring':
        n.size = e.size + dt * 38;
        break;
      case 'rope': {
        const alpha = -(2600 / Math.max(80, e.data)) * Math.sin(e.angle);
        n.av = (e.av + alpha * dt) * Math.exp(-1.2 * dt);
        n.angle = e.angle + n.av * dt;
        n.data = e.data * Math.exp(-2.2 * dt);
        break;
      }
      default:
        break;
    }
    if (e.kind !== 'rope') {
      n.x = e.x + n.vx * dt;
      n.y = e.y + n.vy * dt;
    }
    out.push(n);
  }
  return out.length > MAX_EFFECTS ? out.slice(out.length - MAX_EFFECTS) : out;
}

/** Remaining opacity factor of an effect (1 → 0 over its life, with a short fade-in). */
export function effectAlpha(e: Effect): number {
  const p = e.age / e.life;
  const fadeIn = Math.min(1, e.age / 0.08);
  return Math.max(0, fadeIn * (1 - p * p));
}

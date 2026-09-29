import type { FallCause } from '../joke-situations';
import { DOODLE_DRAW, DOODLE_SHAPES, DoodleShape, doodlePoint } from './effects';
import { Expression } from './face';
import { GRAVITY, launchSpeed } from './physics';
import { BONES, Pose, STAND, STAND_HIP, Vec, armTo, forwardKinematics, legTo, lerpPose } from './skeleton';

/* ───────────────────────── types ───────────────────────── */

export type EntranceAction =
  | 'enter-peek'
  | 'enter-climb'
  | 'enter-jump'
  | 'enter-drop'
  | 'enter-walk'
  | 'enter-sneak'
  | 'enter-rope'
  | 'enter-gopher'
  | 'enter-slide';
export type IdleAction =
  | 'idle-breathe'
  | 'idle-scratch'
  | 'idle-stretch'
  | 'idle-sit'
  | 'idle-watch'
  | 'idle-yawn'
  | 'idle-juggle'
  | 'idle-read'
  | 'idle-doodle'
  | 'idle-balance'
  | 'idle-pushup'
  | 'idle-knock'
  | 'idle-slip'
  | 'idle-trip'
  | 'idle-faint';
export type ExitAction = 'exit-run' | 'exit-jump' | 'exit-slide' | 'exit-wave' | 'exit-peek';
export type OtherAction =
  | 'peek-out'
  | 'sleep'
  | 'wake'
  | 'dragged'
  | 'airborne'
  | 'land'
  | 'dizzy'
  | 'dust'
  | 'docked'
  | 'static'
  | 'hold-on'
  | 'curl'
  | 'orb'
  | 'orb-pop'
  | 'unfold'
  | 'seat-route';
/** Recovery after a real fall; built by getup.ts from the current ragdoll pose. */
export type GetUpAction = 'getup' | 'sulk' | 'stand-up' | 'rub-head';
export const GETUP_ACTIONS: readonly GetUpAction[] = ['getup', 'sulk', 'stand-up', 'rub-head'];
/** Ways onto the chat panel's top edge (seat-routes.ts). */
export type RouteAction = 'route-hop' | 'route-climb' | 'route-ladder' | 'route-rope' | 'route-trampoline' | 'route-balloon' | 'route-vault' | 'route-stairs';
export const ROUTE_ACTIONS: readonly RouteAction[] = ['route-hop', 'route-climb', 'route-ladder', 'route-rope', 'route-trampoline', 'route-balloon', 'route-vault', 'route-stairs'];
/** Ways of getting around (moves.ts). */
export type MoveAction =
  | 'move-run'
  | 'move-sprint'
  | 'move-skip'
  | 'move-sneak'
  | 'move-moonwalk'
  | 'move-crawl'
  | 'move-cartwheel'
  | 'move-roll'
  | 'move-backflip'
  | 'move-kneeslide'
  | 'move-handwalk'
  | 'move-skateboard'
  | 'move-unicycle'
  | 'move-scooter'
  | 'move-pogo'
  | 'move-monkeybars'
  | 'move-wallflip';
/** Idle activities with props (activities.ts). */
export type ActivityAction =
  | 'act-yoyo'
  | 'act-keepyuppy'
  | 'act-rope'
  | 'act-plane'
  | 'act-bubbles'
  | 'act-coffee'
  | 'act-newspaper'
  | 'act-laptop'
  | 'act-dance'
  | 'act-meditate'
  | 'act-headstand'
  | 'act-magic'
  | 'act-fishing'
  | 'act-kite'
  | 'act-selfie'
  | 'act-wave'
  | 'act-glassdoodle';
export type ActionName = EntranceAction | IdleAction | ExitAction | OtherAction | GetUpAction | RouteAction | MoveAction | ActivityAction;

export const ENTRANCES: readonly EntranceAction[] = [
  'enter-peek',
  'enter-climb',
  'enter-jump',
  'enter-drop',
  'enter-walk',
  'enter-sneak',
  'enter-rope',
  'enter-gopher',
  'enter-slide',
];
export const EXITS: readonly ExitAction[] = ['exit-run', 'exit-jump', 'exit-slide', 'exit-wave'];
/** Idle fidgets with their weights (breathing sits between them). */
export const IDLE_WEIGHTS: Readonly<Record<Exclude<IdleAction, 'idle-breathe'>, number>> = {
  'idle-scratch': 3,
  'idle-stretch': 2,
  'idle-sit': 2,
  'idle-watch': 2,
  'idle-yawn': 1.5,
  'idle-juggle': 2,
  'idle-read': 2,
  'idle-doodle': 2,
  'idle-balance': 1.5,
  'idle-pushup': 1,
  'idle-knock': 1.5,
  'idle-slip': 0.7,
  'idle-trip': 0.6,
  'idle-faint': 0.4,
};

/** Where he lives: the viewport, the ground (bottom edge), the chat seat and the corner of the "off" circle. */
export interface Stage {
  width: number;
  height: number;
  /** y of the surface his feet stand on (bottom edge of the viewport). */
  ground: number;
  /** Top edge of the chat panel where he sits (null — chat closed). */
  seat: Vec | null;
  /** Centre of the circle he curls into when switched off. */
  corner: Vec;
  /** The chat panel's box (read once when it opens and on resize); null/absent — derived from the seat. */
  panel?: PanelRect | null;
}

export interface PanelRect {
  left: number;
  top: number;
  right: number;
  bottom: number;
}

/** A drawn prop (ladder, rope, trampoline…) as an SVG path in world coordinates, drawn on stroke by stroke. */
export interface InkProp {
  d: string;
  /** 0..1 — how much of the stroke is drawn (draw-on effect). */
  draw: number;
  alpha: number;
}

export interface PropsFrame {
  /** Vine anchor at the top edge (drawn to his hands). */
  rope: Vec | null;
  book: { x: number; y: number; page: number } | null;
  balls: Vec[] | null;
  /** Route props (ladder, rope, trampoline, balloon, pole, stairs). */
  ink?: InkProp[] | null;
}

export interface ClipFrame {
  pose: Pose;
  expr?: Partial<Expression>;
  /** Where the eyes look (world direction), overriding the cursor. */
  eyeDir?: Vec;
  props?: Partial<PropsFrame>;
  /** 0 — stick figure, 1 — curled into the circle. */
  morph?: number;
  /** Extra scale of the whole drawing (orb pop). */
  scale?: number;
}

export type ClipEventType = 'launch' | 'dust' | 'sparkle' | 'sweat' | 'bang' | 'ring' | 'doodle' | 'stars' | 'rope' | 'hop' | 'ragdoll' | 'banana';
export type EventAnchor = 'feet' | 'hip' | 'head' | 'rHand';

export interface ClipEvent {
  t: number;
  type: ClipEventType;
  vx?: number;
  vy?: number;
  spin?: number;
  walls?: boolean;
  floor?: boolean;
  at?: EventAnchor;
  strength?: number;
  shape?: DoodleShape;
  size?: number;
  point?: Vec;
  rope?: { ax: number; ay: number; len: number; angle: number; av: number };
  /** ragdoll: what made him fall (picks the joke after getting up). */
  cause?: FallCause;
}

/** Live inputs of a clip: velocity of the physics body, pointer position. */
export interface ClipEnv {
  vx: number;
  vy: number;
  spin: number;
  pointer: Vec | null;
}

export interface Clip {
  action: ActionName;
  /** Seconds; Infinity loops until the brain plays something else. */
  duration: number;
  sample(t: number, env: ClipEnv): ClipFrame;
  events: ClipEvent[];
  /** Blend-in time from the previous pose. */
  blend: number;
  /** Root comes from the physics body (limbs from the clip). */
  physics: boolean;
  /** Limbs follow the clip exactly (no spring lag) — big precise moves like getting up off the floor. */
  stiff?: boolean;
  /** Repeating effect while the clip runs (zZ while asleep). */
  ambient?: { type: 'zz'; every: number };
}

export interface ClipContext {
  stage: Stage;
  from: Pose;
  side: 'left' | 'right';
  targetX: number;
  rng: () => number;
  /** Live (smoothed) seat and panel for routes that re-target when the panel moves. */
  seatNow?: () => Vec;
  panelNow?: () => PanelRect;
}

/* ───────────────────────── helpers ───────────────────────── */

const PI = Math.PI;

export const ease = {
  linear: (t: number): number => t,
  in: (t: number): number => t * t * t,
  out: (t: number): number => 1 - Math.pow(1 - t, 3),
  inOut: (t: number): number => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
  back: (t: number): number => {
    const c1 = 1.70158;
    const c3 = c1 + 1;
    return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
  },
};

export function clamp01(t: number): number {
  return t < 0 ? 0 : t > 1 ? 1 : t;
}

/** Progress of t inside [a, b] clamped to 0..1. */
export function segment(t: number, a: number, b: number): number {
  return b <= a ? (t >= b ? 1 : 0) : clamp01((t - a) / (b - a));
}

function mix(a: number, b: number, t: number): number {
  return a + (b - a) * t;
}

function mixVec(a: Vec, b: Vec, t: number): Vec {
  return { x: mix(a.x, b.x, t), y: mix(a.y, b.y, t) };
}

function mod1(x: number): number {
  return x - Math.floor(x);
}

export function pose(over: Partial<Pose>): Pose {
  return { ...STAND, ...over };
}

export function standHip(stage: Stage): number {
  return stage.ground - STAND_HIP;
}

export function standAt(stage: Stage, x: number, facing: 1 | -1, over: Partial<Pose> = {}): Pose {
  return pose({ x, y: standHip(stage), facing, ...over });
}

function facingInto(side: 'left' | 'right'): 1 | -1 {
  return side === 'left' ? 1 : -1;
}

function offX(stage: Stage, side: 'left' | 'right', margin = 80): number {
  return side === 'left' ? -margin : stage.width + margin;
}

/** Rotates a world delta into the body frame of `p` (x forward). */
function toBody(p: Pose, dx: number, dy: number): Vec {
  const c = Math.cos(-p.rot);
  const s = Math.sin(-p.rot);
  return { x: (dx * c - dy * s) * p.facing, y: dx * s + dy * c };
}

/** Puts a hand on a world point (IK), mutating the pose. */
export function reach(p: Pose, arm: 'l' | 'r', target: Vec, bend: 1 | -1 = -1): void {
  // Shoulder straight from the spine angle (no full FK solve, no pose copy).
  const f = p.turn ?? p.facing;
  const bx = f * Math.sin(p.torso) * BONES.shoulderAt;
  const by = -Math.cos(p.torso) * BONES.shoulderAt;
  const c = Math.cos(p.rot);
  const s = Math.sin(p.rot);
  const d = toBody(p, target.x - (p.x + bx * c - by * s), target.y - (p.y + bx * s + by * c));
  // The spine carries the arms: solve relative to the unrotated torso.
  const a = armTo(d.x, d.y, p.torso, bend);
  if (arm === 'l') {
    p.lShoulder = a.shoulder;
    p.lElbow = a.elbow;
  } else {
    p.rShoulder = a.shoulder;
    p.rElbow = a.elbow;
  }
}

/** Puts a foot on a world point (IK), mutating the pose. */
export function plant(p: Pose, leg: 'l' | 'r', target: Vec): void {
  const d = toBody(p, target.x - p.x, target.y - p.y);
  const s = legTo(d.x, d.y);
  if (leg === 'l') {
    p.lHip = s.hip;
    p.lKnee = s.knee;
  } else {
    p.rHip = s.hip;
    p.rKnee = s.knee;
  }
}

export interface Key {
  t: number;
  p: Pose;
  e?: (u: number) => number;
}

/** Keyframed pose track (each key's easing shapes the segment that ends at it). */
export function keyed(keys: Key[]): (t: number) => Pose {
  return (t: number): Pose => {
    if (t <= keys[0].t) {
      return keys[0].p;
    }
    for (let i = 1; i < keys.length; i++) {
      const k = keys[i];
      if (t <= k.t) {
        const prev = keys[i - 1];
        const u = (t - prev.t) / Math.max(1e-6, k.t - prev.t);
        return lerpPose(prev.p, k.p, (k.e ?? ease.inOut)(u));
      }
    }
    return keys[keys.length - 1].p;
  };
}

function breath(t: number, rate = 1): number {
  return Math.sin((t * 2 * PI * rate) / 3.4);
}

/** Quiet standing with breathing — the base of most idles. */
export function breathing(stage: Stage, x: number, facing: 1 | -1, t: number): Pose {
  const b = breath(t);
  return standAt(stage, x, facing, {
    y: standHip(stage) + b * 0.6,
    torso: 0.04 + b * 0.015,
    head: 0.05 * Math.sin(t * 0.6),
    lShoulder: -0.2 + b * 0.03,
    rShoulder: 0.2 - b * 0.03,
  });
}

/** Sitting on an edge (hip on the surface), legs dangling over it. */
export function sitting(x: number, surface: number, facing: 1 | -1, t: number): Pose {
  const swing = Math.sin(t * 2.2) * 0.35;
  const p = pose({
    x,
    y: surface - 3 + breath(t) * 0.4,
    facing,
    torso: 0.14 + breath(t) * 0.02,
    head: 0.04 * Math.sin(t * 0.7),
    lHip: 1.45,
    lKnee: 1.45 + swing,
    rHip: 1.6,
    rKnee: 1.45 - swing,
  });
  reach(p, 'l', { x: x - facing * 11, y: surface }, 1);
  reach(p, 'r', { x: x - facing * 5, y: surface }, 1);
  return p;
}

/* ───────────────────────── gait (walk / run / sneak) with foot IK ───────────────────────── */

export interface Gait {
  stride: number;
  stance: number;
  lift: number;
  bob: number;
  armSwing: number;
  elbow: number;
  lean: number;
  hipDrop: number;
}

export const WALK: Gait = { stride: 50, stance: 0.56, lift: 7, bob: 1.6, armSwing: 0.55, elbow: 0.45, lean: 0.06, hipDrop: 1 };
export const RUN: Gait = { stride: 104, stance: 0.36, lift: 16, bob: 4, armSwing: 1.1, elbow: 1.5, lean: 0.3, hipDrop: 4 };
export const SNEAK: Gait = { stride: 44, stance: 0.5, lift: 14, bob: 2.5, armSwing: 0.12, elbow: 1.9, lean: 0.34, hipDrop: 3 };

/** Foot offset (forward x relative to the hip, lift above the ground) at cycle phase u. */
export function gaitFoot(u: number, g: Gait): { x: number; lift: number } {
  const half = (g.stride * g.stance) / 2;
  if (u < g.stance) {
    // Stance: the foot is planted in the world while the hip passes over it.
    return { x: half - (u / g.stance) * 2 * half, lift: 0 };
  }
  const w = (u - g.stance) / (1 - g.stance);
  return { x: -half + ease.inOut(w) * 2 * half, lift: g.lift * Math.sin(PI * w) };
}

/** Pose of the gait after travelling `s` px, hip at x. Feet are placed by IK so they do not slide. */
export function gaitPose(stage: Stage, x: number, s: number, facing: 1 | -1, g: Gait): Pose {
  const uA = mod1(s / g.stride);
  const uB = mod1(s / g.stride + 0.5);
  const half = (g.stride * g.stance) / 2;
  const hipY = standHip(stage) + g.hipDrop - g.bob * Math.cos(4 * PI * (uA - g.stance / 2));
  const a = gaitFoot(uA, g);
  const b = gaitFoot(uB, g);
  const la = legTo(a.x, stage.ground - a.lift - hipY);
  const lb = legTo(b.x, stage.ground - b.lift - hipY);
  return pose({
    x,
    y: hipY,
    facing,
    torso: g.lean + 0.02 * Math.sin(uA * 4 * PI),
    head: -g.lean * 0.55,
    lHip: la.hip,
    lKnee: la.knee,
    rHip: lb.hip,
    rKnee: lb.knee,
    lShoulder: -g.armSwing * (a.x / half) * 0.8,
    rShoulder: -g.armSwing * (b.x / half) * 0.8,
    lElbow: g.elbow + 0.15 * Math.max(0, a.x / half),
    rElbow: g.elbow + 0.15 * Math.max(0, b.x / half),
  });
}

interface TravelOptions {
  speed: number;
  gait: Gait;
  /** 0..1 stop-and-go modulation (cartoon sneak). */
  pause?: number;
  delay?: number;
  prelude?: (t: number) => Pose;
  expr?: Partial<Expression>;
  eyeDir?: Vec;
}

function travel(action: ActionName, stage: Stage, x0: number, x1: number, o: TravelOptions, blend = 0.25): Clip {
  const dir: 1 | -1 = x1 >= x0 ? 1 : -1;
  const dist = Math.abs(x1 - x0);
  const delay = o.delay ?? 0;
  const pause = o.pause ?? 0;
  const omega = (2 * PI) / 1.1;
  const walkTime = dist / o.speed;
  return {
    action,
    duration: delay + walkTime,
    blend,
    physics: false,
    events: [],
    sample(t: number): ClipFrame {
      if (t < delay && o.prelude) {
        return { pose: o.prelude(t), expr: o.expr };
      }
      const tw = t - delay;
      const raw = o.speed * (tw - (pause * Math.sin(omega * tw)) / omega);
      const s = Math.min(dist, Math.max(0, raw));
      return { pose: gaitPose(stage, x0 + dir * s, s, dir, o.gait), expr: o.expr, eyeDir: o.eyeDir };
    },
  };
}

/* ───────────────────────── entrances ───────────────────────── */

function enterWalk(c: ClipContext): Clip {
  return travel('enter-walk', c.stage, offX(c.stage, c.side), c.targetX, { speed: 95, gait: WALK, expr: { mouth: 'smile' } }, 0);
}

function enterSneak(c: ClipContext): Clip {
  const f = facingInto(c.side);
  return travel(
    'enter-sneak',
    c.stage,
    offX(c.stage, c.side),
    c.targetX,
    { speed: 48, gait: SNEAK, pause: 0.85, expr: { mouth: 'flat', brows: 0.55 }, eyeDir: { x: -f * 0.6, y: 0.1 } },
    0,
  );
}

function enterPeek(c: ClipContext): Clip {
  const f = facingInto(c.side);
  const edge = c.side === 'left' ? 0 : c.stage.width;
  const peekX = edge - f * 36;
  const hideX = edge - f * 90;
  const lean = { torso: 0.95, head: -0.4, lHip: 0.1, lKnee: 0.3, rHip: -0.1, rKnee: 0.2 };
  const hidden = standAt(c.stage, hideX, f, lean);
  const peek = standAt(c.stage, peekX, f, lean);
  const look = (head: number): Pose => standAt(c.stage, peekX, f, { ...lean, head });
  const sheepish = standAt(c.stage, peekX - f * 4, f, { ...lean, torso: 0.85, head: 0.3 });
  const track = keyed([
    { t: 0, p: hidden },
    { t: 0.55, p: peek, e: ease.out },
    { t: 1.0, p: look(-0.1) },
    { t: 1.45, p: look(-0.55) },
    { t: 1.7, p: look(-0.2) },
    { t: 1.9, p: hidden, e: ease.in },
    { t: 2.8, p: hidden },
    { t: 3.5, p: sheepish, e: ease.out },
    { t: 4.6, p: sheepish },
  ]);
  return {
    action: 'enter-peek',
    duration: 4.6,
    blend: 0,
    physics: false,
    events: [{ t: 1.68, type: 'bang' }],
    sample(t: number): ClipFrame {
      const p = track(t);
      // The near hand grips the screen edge while peeking.
      const j = forwardKinematics(p);
      reach(p, 'r', { x: edge + f * 3, y: p.y + j.shoulder.y + 6 }, 1);
      const caught = t > 1.65 && t < 2.9;
      const shy = t >= 2.9;
      if (shy && t > 3.7) {
        p.lShoulder = 2.4;
        p.lElbow = 0.5 + 0.4 * Math.sin(t * 14);
      }
      return {
        pose: p,
        expr: caught ? { eyes: 'wide', brows: 1, mouth: 'o' } : shy ? { eyes: 'dot', brows: 0.8, mouth: 'wobbly' } : { brows: 0.3, mouth: 'flat' },
        eyeDir: t < 1.65 ? { x: f * Math.sin(t * 2.6), y: -0.2 } : undefined,
      };
    },
  };
}

function peekOut(c: ClipContext): Clip {
  return travel('peek-out', c.stage, c.from.x, c.targetX, { speed: 90, gait: WALK, expr: { mouth: 'smile', brows: 0.3 } });
}

function enterClimb(c: ClipContext): Clip {
  const { stage } = c;
  const g = stage.ground;
  const x = c.targetX;
  const f: 1 | -1 = c.rng() < 0.5 ? 1 : -1;
  const handL = { x: x + f * 3, y: g };
  const handR = { x: x + f * 12, y: g };
  const finalPose = standAt(stage, x + f * 14, f);
  const phase = (t: number): ClipFrame => {
    if (t < 1.3) {
      const u = ease.inOut(segment(t, 0.3, 1.3));
      const hipY = g + 64 - u * 56;
      const p = pose({ x, y: hipY, facing: f, torso: 0.1 + 0.1 * u, head: -0.3 * (1 - u), lHip: 0.25 * Math.sin(t * 9), lKnee: 0.6, rHip: -0.25 * Math.sin(t * 9), rKnee: 0.8 });
      const arrive = ease.out(segment(t, 0, 0.3));
      reach(p, 'l', mixVec({ x: x + f * 4, y: hipY - 30 }, handL, arrive), 1);
      reach(p, 'r', mixVec({ x: x + f * 10, y: hipY - 30 }, handR, arrive), 1);
      return { pose: p, expr: { brows: -0.5 * u, mouth: u > 0.3 ? 'wobbly' : 'flat' } };
    }
    const u = ease.inOut(segment(t, 1.3, 1.95));
    const hipY = g + 8 - u * 26;
    const p = pose({ x: x + f * 6 * u, y: hipY, facing: f, torso: 0.2 + 0.45 * u, head: -0.2 * u, lHip: -0.3, lKnee: 0.5 });
    const footFrom = { x: x + f * 2, y: hipY + 40 };
    const footTo = { x: x + f * 18, y: g };
    const foot = mixVec(footFrom, footTo, u);
    foot.y -= Math.sin(PI * u) * 18;
    plant(p, 'r', foot);
    reach(p, 'l', handL, 1);
    reach(p, 'r', handR, 1);
    return { pose: p, expr: { brows: -0.6, mouth: 'wobbly' } };
  };
  const end = phase(1.95).pose;
  return {
    action: 'enter-climb',
    duration: 2.7,
    blend: 0,
    physics: false,
    events: [{ t: 1.95, type: 'dust', at: 'feet', strength: 0.3 }],
    sample(t: number): ClipFrame {
      if (t < 1.95) {
        return phase(t);
      }
      return { pose: lerpPose(end, finalPose, ease.inOut(segment(t, 1.95, 2.7))), expr: { mouth: 'smile', brows: 0.2 } };
    },
  };
}

/** Launch that lands the hip at (targetX, groundHip) from (x0, y0) reaching `apex` px above the ground hip. */
export function ballistic(x0: number, y0: number, targetX: number, groundHip: number, apex: number): { vx: number; vy: number; time: number } {
  const apexY = Math.min(groundHip, y0) - apex;
  const vy = -Math.sqrt(2 * GRAVITY * Math.max(1, y0 - apexY));
  const time = (-vy + Math.sqrt(vy * vy + 2 * GRAVITY * (groundHip - y0))) / GRAVITY;
  return { vx: (targetX - x0) / time, vy, time };
}

function enterJump(c: ClipContext): Clip {
  const { stage } = c;
  const f: 1 | -1 = c.rng() < 0.5 ? 1 : -1;
  const x0 = c.targetX - f * 70;
  const y0 = stage.ground + 70;
  const shot = ballistic(x0, y0, c.targetX, standHip(stage), Math.min(0.35 * stage.height, 230));
  const flip = c.rng() < 0.5 ? (f * 2 * PI) / (shot.time * 0.95) : 0;
  const start = pose({ x: x0, y: y0, facing: f, lShoulder: 2.8, rShoulder: 2.9, lElbow: 0.2, rElbow: 0.2, lHip: 0.6, lKnee: 1.2, rHip: 0.4, rKnee: 1 });
  return {
    action: 'enter-jump',
    duration: 0.02,
    blend: 0,
    physics: false,
    events: [{ t: 0.01, type: 'launch', vx: shot.vx, vy: shot.vy, spin: flip, walls: true, floor: true }],
    sample: (): ClipFrame => ({ pose: start, expr: { eyes: 'happy', mouth: 'grin' } }),
  };
}

function enterDrop(c: ClipContext): Clip {
  const x = c.targetX;
  const f: 1 | -1 = c.rng() < 0.5 ? 1 : -1;
  const hangY = BONES.shoulderAt + BONES.upperArm + BONES.foreArm - 2;
  return {
    action: 'enter-drop',
    duration: 2.0,
    blend: 0,
    physics: false,
    events: [{ t: 1.98, type: 'launch', vx: 0, vy: 0, spin: 0, walls: true, floor: true }],
    sample(t: number): ClipFrame {
      const fall = ease.in(segment(t, 0, 0.26));
      const since = Math.max(0, t - 0.26);
      const swing = 0.4 * Math.cos(6 * since) * Math.exp(-1.4 * since);
      const kick = t > 0.9 ? 0.3 * Math.sin(t * 11) : 0;
      const letGo = t > 1.55;
      const p = pose({
        x,
        y: mix(-90, hangY, fall),
        facing: f,
        torso: 0.03 + swing * 0.2,
        head: t > 0.8 ? 0.5 : -0.2,
        lShoulder: PI - 0.08,
        lElbow: 0.05,
        rShoulder: letGo ? 1.9 + 0.3 * Math.sin(t * 16) : PI - 0.12,
        rElbow: letGo ? 0.6 : 0.08,
        lHip: swing + kick,
        lKnee: 0.4 + Math.abs(kick),
        rHip: swing * 0.8 - kick,
        rKnee: 0.5,
        squash: t < 0.3 ? 1 + 0.14 * fall : 1,
      });
      const worried = t > 0.8;
      return {
        pose: p,
        expr: worried ? { eyes: 'wide', brows: 1, mouth: 'wobbly' } : { eyes: 'wide', brows: 0.8, mouth: 'o' },
        eyeDir: worried ? { x: 0, y: 1 } : undefined,
      };
    },
  };
}

function enterRope(c: ClipContext): Clip {
  const { stage } = c;
  const f = facingInto(c.side);
  const hangHip = standHip(stage);
  const reachLen = BONES.shoulderAt + BONES.upperArm + BONES.foreArm;
  const rope = Math.max(90, Math.min(320, hangHip - reachLen - 30, stage.ground * 0.42));
  const len = rope + reachLen;
  const theta0 = 1.05;
  const ax = c.side === 'left' ? len * Math.sin(theta0) - 50 : stage.width - (len * Math.sin(theta0) - 50);
  const omega = Math.sqrt(GRAVITY / len);
  const release = Math.acos(-0.35) / omega;
  const angle = (t: number): number => -f * theta0 * Math.cos(omega * t);
  const angVel = (t: number): number => f * theta0 * omega * Math.sin(omega * t);
  const th = angle(release);
  const av = angVel(release);
  return {
    action: 'enter-rope',
    duration: release,
    blend: 0,
    physics: false,
    events: [
      {
        t: release - 0.001,
        type: 'launch',
        vx: len * Math.cos(th) * av,
        vy: -len * Math.sin(th) * av,
        spin: av * 0.6,
        walls: true,
        floor: true,
      },
      { t: release - 0.001, type: 'rope', rope: { ax, ay: 0, len: rope, angle: th, av: av * 0.8 } },
    ],
    sample(t: number): ClipFrame {
      const a = angle(t);
      const w = angVel(t) / (theta0 * omega);
      const p = pose({
        x: ax + len * Math.sin(a),
        y: len * Math.cos(a),
        rot: -a,
        facing: f,
        torso: 0,
        head: -0.15,
        lShoulder: PI - 0.05,
        lElbow: 0.02,
        rShoulder: PI + 0.05,
        rElbow: 0.02,
        lHip: 0.5 * w * f + 0.2,
        lKnee: 0.5,
        rHip: 0.3 * w * f - 0.1,
        rKnee: 0.9,
      });
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin', brows: 0.3 }, props: { rope: { x: ax, y: 0 } } };
    },
  };
}

function enterGopher(c: ClipContext): Clip {
  const { stage } = c;
  const g = stage.ground;
  const x = c.targetX;
  const f0: 1 | -1 = c.rng() < 0.5 ? 1 : -1;
  const hop = launchSpeed(80);
  return {
    action: 'enter-gopher',
    duration: 2.3,
    blend: 0,
    physics: false,
    events: [
      { t: 0.32, type: 'dust', at: 'hip', strength: 0.35, point: { x, y: g } },
      { t: 2.28, type: 'launch', vx: f0 * 50, vy: hop, spin: 0, walls: true, floor: true },
    ],
    sample(t: number): ClipFrame {
      const up = ease.back(segment(t, 0, 0.35));
      const facing: 1 | -1 = t > 1.05 && t < 1.7 ? (-f0 as 1 | -1) : f0;
      const crouch = segment(t, 1.9, 2.25);
      const p = pose({ x, y: mix(g + 70, g + 12, up) + crouch * 6, facing, torso: 0.05, head: -0.15 * Math.sin(t * 3), lHip: 0.9, lKnee: 1.6, rHip: 0.8, rKnee: 1.5 });
      reach(p, 'l', { x: x - 16, y: g }, 1);
      reach(p, 'r', { x: x + 16, y: g }, 1);
      return { pose: p, expr: t < 0.9 ? { eyes: 'wide', brows: 0.7, mouth: 'o' } : { eyes: 'dot', brows: 0.2, mouth: 'smile' }, eyeDir: { x: facing * 0.8, y: 0 } };
    },
  };
}

function enterSlide(c: ClipContext): Clip {
  const { stage } = c;
  const f = facingInto(c.side);
  const x0 = offX(stage, c.side, 110);
  const x1 = c.targetX;
  const g = stage.ground;
  const slideT = 1.6;
  const tau = 0.45;
  const norm = 1 - Math.exp(-slideT / tau);
  const lying = (x: number, t: number): Pose =>
    pose({ x, y: g - 6, rot: f * (PI / 2), facing: f, torso: 0, head: -0.5, lShoulder: PI - 0.1, rShoulder: PI + 0.08, lElbow: 0.1, rElbow: 0.1, lHip: 0.1 * Math.sin(t * 10), lKnee: 0.3, rHip: -0.1 * Math.sin(t * 10), rKnee: 0.5 });
  const plankUp = pose({ x: x1, y: g - 18, rot: f * 1.1, facing: f, torso: 0, head: -0.4, lShoulder: 1.3, rShoulder: 1.4, lElbow: 0.1, rElbow: 0.1, lHip: 0, lKnee: 0.1, rHip: 0, rKnee: 0.1 });
  const kneel = pose({ x: x1, y: g - 26, rot: f * 0.2, facing: f, torso: 0.5, head: -0.3, lShoulder: 0.8, rShoulder: 0.9, lElbow: 0.4, rElbow: 0.4, lHip: 1.4, lKnee: 2.3, rHip: 1.0, rKnee: 2.0 });
  const standUp = standAt(stage, x1, f, { head: 0.1 });
  const getUp = keyed([
    { t: slideT, p: lying(x1, slideT) },
    { t: slideT + 0.3, p: plankUp },
    { t: slideT + 0.6, p: kneel },
    { t: slideT + 1.05, p: standUp, e: ease.back },
  ]);
  return {
    action: 'enter-slide',
    duration: slideT + 1.05,
    blend: 0,
    physics: false,
    events: [0.35, 0.7, 1.05, 1.4].map((t) => ({ t, type: 'dust' as const, at: 'hip' as const, strength: 0.25 })),
    sample(t: number): ClipFrame {
      if (t < slideT) {
        const u = (1 - Math.exp(-t / tau)) / norm;
        return { pose: lying(mix(x0, x1, u), t), expr: { eyes: 'happy', mouth: 'grin', brows: 0.4 } };
      }
      return { pose: getUp(t), expr: { mouth: 'smile', brows: 0.2 } };
    },
  };
}

/* ───────────────────────── exits ───────────────────────── */

function nearestSide(stage: Stage, x: number): 'left' | 'right' {
  return x < stage.width / 2 ? 'left' : 'right';
}

function exitRun(c: ClipContext): Clip {
  const side = nearestSide(c.stage, c.from.x);
  const f: 1 | -1 = side === 'left' ? -1 : 1;
  const x0 = c.from.x;
  const anticipation = standAt(c.stage, x0, f, { y: standHip(c.stage) + 7, torso: 0.45, head: -0.3, lHip: 0.7, lKnee: 1.2, rHip: 0.2, rKnee: 0.9, lShoulder: 0.7, rShoulder: -0.8, lElbow: 1.4, rElbow: 1.4 });
  const start = standAt(c.stage, x0, f);
  return travel('exit-run', c.stage, x0, offX(c.stage, side, 90), {
    speed: 270,
    gait: RUN,
    delay: 0.45,
    prelude: (t) => lerpPose(start, anticipation, ease.out(segment(t, 0, 0.3))),
    expr: { brows: -0.3, mouth: 'grin' },
  });
}

function exitJump(c: ClipContext): Clip {
  const x0 = c.from.x;
  const side = nearestSide(c.stage, x0);
  const f: 1 | -1 = side === 'left' ? -1 : 1;
  const crouch = standAt(c.stage, x0, f, { y: standHip(c.stage) + 9, torso: 0.35, lHip: 0.8, lKnee: 1.5, rHip: 0.7, rKnee: 1.4, lShoulder: -0.9, rShoulder: -0.7, lElbow: 0.3, rElbow: 0.3 });
  const start = standAt(c.stage, x0, f);
  return {
    action: 'exit-jump',
    duration: 0.42,
    blend: 0.2,
    physics: false,
    events: [{ t: 0.4, type: 'launch', vx: f * 90, vy: launchSpeed(120), spin: f * 3, walls: false, floor: false }],
    sample: (t: number): ClipFrame => ({ pose: lerpPose(start, crouch, ease.out(segment(t, 0, 0.35))), expr: { brows: -0.2, mouth: 'grin' } }),
  };
}

function exitSlide(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const g = c.stage.ground;
  const start = standAt(c.stage, x, f);
  return {
    action: 'exit-slide',
    duration: 1.7,
    blend: 0.2,
    physics: false,
    events: [],
    sample(t: number): ClipFrame {
      const sit = sitting(x, g, f, t);
      if (t < 0.55) {
        return { pose: lerpPose(start, sit, ease.inOut(segment(t, 0, 0.55))), expr: { mouth: 'smile' } };
      }
      const u = ease.in(segment(t, 0.75, 1.7));
      const p = { ...sit, y: sit.y + u * 150, lShoulder: 2.8, rShoulder: 2.9, lElbow: 0.3, rElbow: 0.3 };
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin', brows: 0.5 } };
    },
  };
}

function exitWave(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const base = standAt(c.stage, x, f);
  return {
    action: 'exit-wave',
    duration: 2.3,
    blend: 0.2,
    physics: false,
    events: [],
    sample(t: number): ClipFrame {
      const p = breathing(c.stage, x, f, t);
      const up = ease.out(segment(t, 0, 0.3)) * (1 - segment(t, 1.5, 1.7));
      p.rShoulder = mix(base.rShoulder, 2.7, up);
      p.rElbow = mix(base.rElbow, 0.5 + 0.45 * Math.sin(t * 13), up);
      p.head = 0.15;
      const duck = ease.in(segment(t, 1.7, 2.3));
      const crouch = segment(t, 1.5, 1.75);
      p.y += crouch * 10 + duck * 140;
      p.lHip += crouch * 0.6;
      p.lKnee += crouch * 1.1;
      p.rHip += crouch * 0.5;
      p.rKnee += crouch * 1;
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin', brows: 0.3 } };
    },
  };
}

function exitPeek(c: ClipContext): Clip {
  const f = c.from.facing;
  const hide = { ...c.from, x: c.from.x - f * 70 };
  const lean = { ...c.from, x: c.from.x + f * 4, torso: c.from.torso + 0.1 };
  const track = keyed([
    { t: 0, p: c.from },
    { t: 0.18, p: lean, e: ease.out },
    { t: 0.6, p: hide, e: ease.in },
  ]);
  return { action: 'exit-peek', duration: 0.6, blend: 0.1, physics: false, events: [], sample: (t) => ({ pose: track(t), expr: { mouth: 'grin', eyes: 'happy' } }) };
}

/* ───────────────────────── idles ───────────────────────── */

export function simple(action: ActionName, duration: number, sample: (t: number) => ClipFrame, events: ClipEvent[] = [], blend = 0.3): Clip {
  return { action, duration, blend, physics: false, events, sample };
}

function idleBreathe(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-breathe', 2.5 + c.rng() * 3, (t) => ({ pose: breathing(c.stage, x, f, t) }));
}

function idleScratch(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-scratch', 2.4, (t) => {
    const p = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0, 0.35)) * (1 - ease.inOut(segment(t, 1.9, 2.3)));
    p.rShoulder = mix(p.rShoulder, 2.75, w);
    p.rElbow = mix(p.rElbow, 2.35 + 0.18 * Math.sin(t * 38), w);
    p.head = mix(p.head, 0.22, w);
    return { pose: p, expr: { brows: 0.7 * w, mouth: w > 0.5 ? 'flat' : 'smile' }, eyeDir: { x: f * 0.3, y: -0.9 } };
  });
}

function idleStretch(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-stretch', 2.8, (t) => {
    const p = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0, 0.7)) * (1 - ease.inOut(segment(t, 2.0, 2.8)));
    p.lShoulder = mix(p.lShoulder, PI - 0.25, w);
    p.rShoulder = mix(p.rShoulder, PI + 0.2, w);
    p.lElbow = mix(p.lElbow, 0.1, w);
    p.rElbow = mix(p.rElbow, 0.1, w);
    p.torso = mix(p.torso, -0.18, w);
    p.head = mix(p.head, -0.3, w);
    p.y -= 3 * w;
    return { pose: p, expr: w > 0.6 ? { eyes: 'closed', mouth: 'o', brows: 0.4 } : {} };
  });
}

function idleSit(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const start = breathing(c.stage, x, f, 0);
  return simple('idle-sit', 6.5, (t) => {
    const sit = sitting(x, c.stage.ground, f, t);
    const w = ease.inOut(segment(t, 0, 0.6)) * (1 - ease.inOut(segment(t, 5.8, 6.5)));
    return { pose: lerpPose(start, sit, w), expr: { mouth: 'smile', brows: 0.1 } };
  });
}

function idleWatch(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-watch', 2.8, (t) => {
    const p = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0, 0.4)) * (1 - ease.inOut(segment(t, 2.2, 2.8)));
    p.lShoulder = mix(p.lShoulder, 0.95, w);
    p.lElbow = mix(p.lElbow, 1.75, w);
    p.head = mix(p.head, 0.4, w);
    const glance = t > 1.5 && t < 2.2;
    return { pose: p, expr: glance ? { brows: -0.3, mouth: 'flat' } : { brows: 0.2 }, eyeDir: glance ? undefined : { x: f * 0.6, y: 0.8 } };
  });
}

function idleYawn(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-yawn', 2.6, (t) => {
    const p = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0.1, 0.8)) * (1 - ease.inOut(segment(t, 1.8, 2.5)));
    p.head = mix(p.head, -0.35, w);
    p.lShoulder = mix(p.lShoulder, -0.9, w);
    p.rShoulder = mix(p.rShoulder, 0.9, w);
    p.lElbow = mix(p.lElbow, 1.2, w);
    p.rElbow = mix(p.rElbow, 1.2, w);
    return { pose: p, expr: { eyes: w > 0.3 ? 'closed' : 'dot', mouth: 'open', talk: w, brows: 0.3 * w } };
  });
}

function idleJuggle(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const hipY = standHip(c.stage);
  const period = 0.42;
  const handA = { x: x + f * 22, y: hipY - 22 };
  const handB = { x: x + f * 10, y: hipY - 20 };
  const height = 44;
  return simple('idle-juggle', 5.2, (t) => {
    const p = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0, 0.4)) * (1 - ease.inOut(segment(t, 4.7, 5.2)));
    const balls: Vec[] = [0, 1, 2].map((i) => {
      const ph = (t / period + (i * 2) / 3) % 2;
      if (ph < 1) {
        return { x: mix(handA.x, handB.x, ph), y: handA.y - height * 4 * ph * (1 - ph) - 3 };
      }
      const q = ph - 1;
      return { x: mix(handB.x, handA.x, q), y: handB.y - height * 0.3 * 4 * q * (1 - q) - 3 };
    });
    const dip = (s: number): number => 3 * Math.max(0, Math.sin((t / period) * PI * 2 + s));
    reach(p, 'r', { x: handA.x, y: handA.y + dip(0) }, 1);
    reach(p, 'l', { x: handB.x, y: handB.y + dip(PI) }, 1);
    const rest = breathing(c.stage, x, f, t);
    const blended = lerpPose(rest, p, w);
    return { pose: blended, expr: { mouth: 'o', brows: 0.35 }, eyeDir: { x: f * 0.4, y: -0.8 }, props: { balls: w > 0.95 ? balls : null } };
  });
}

function idleRead(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const hipY = standHip(c.stage);
  const book = { x: x + f * 17, y: hipY - 30 };
  return simple('idle-read', 5.4, (t) => {
    const rest = breathing(c.stage, x, f, t);
    const p = { ...rest, head: 0.3 };
    reach(p, 'r', { x: book.x + f * 3, y: book.y + 3 }, 1);
    reach(p, 'l', { x: book.x - f * 3, y: book.y + 3 }, 1);
    const w = ease.inOut(segment(t, 0, 0.45)) * (1 - ease.inOut(segment(t, 4.9, 5.4)));
    const laugh = t > 3.4 && t < 4.4;
    if (laugh) {
      p.torso += 0.03 * Math.sin(t * 30);
    }
    return {
      pose: lerpPose(rest, p, w),
      expr: laugh ? { eyes: 'happy', mouth: 'grin' } : { mouth: 'smile', brows: 0.2 },
      eyeDir: { x: f * 0.7, y: 0.6 },
      props: { book: w > 0.9 ? { x: book.x, y: book.y, page: segment(t, 2.3, 2.8) } : null },
    };
  });
}

function idleDoodle(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const hipY = standHip(c.stage);
  const shape = DOODLE_SHAPES[Math.floor(c.rng() * DOODLE_SHAPES.length)];
  const size = 13;
  const origin = { x: x + f * 34, y: hipY - 40 };
  const start = 0.5;
  return simple(
    'idle-doodle',
    start + DOODLE_DRAW + 1.2,
    (t) => {
      const rest = breathing(c.stage, x, f, t);
      const p = { ...rest, torso: 0.12, head: 0.1 };
      const u = segment(t, start, start + DOODLE_DRAW);
      const d = doodlePoint(shape, u);
      reach(p, 'r', { x: origin.x + d.x * size, y: origin.y + d.y * size }, -1);
      const w = ease.inOut(segment(t, 0, start)) * (1 - ease.inOut(segment(t, start + DOODLE_DRAW + 0.4, start + DOODLE_DRAW + 1.2)));
      const done = t > start + DOODLE_DRAW;
      return { pose: lerpPose(rest, p, w), expr: done ? { eyes: 'happy', mouth: 'grin' } : { mouth: 'flat', brows: 0.3 }, eyeDir: { x: f * 0.9, y: -0.1 } };
    },
    [{ t: start, type: 'doodle', shape, size, point: origin }],
  );
}

function idleBalance(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple('idle-balance', 4.2, (t) => {
    const rest = breathing(c.stage, x, f, t);
    const w = ease.inOut(segment(t, 0, 0.5)) * (1 - ease.inOut(segment(t, 3.6, 4.2)));
    const wob = Math.sin(t * 5.3) * 0.07 + Math.sin(t * 8.9) * 0.04;
    const p = { ...rest };
    p.torso = rest.torso + wob * w;
    p.lHip = mix(rest.lHip, 0.95, w);
    p.lKnee = mix(rest.lKnee, 1.7, w);
    p.lShoulder = mix(rest.lShoulder, -1.45 - wob * 2, w);
    p.rShoulder = mix(rest.rShoulder, 1.5 - wob * 2, w);
    p.lElbow = mix(rest.lElbow, 0.1, w);
    p.rElbow = mix(rest.rElbow, 0.1, w);
    p.x = x + wob * 12 * w;
    plant(p, 'r', { x: x + f * 2, y: c.stage.ground });
    return { pose: p, expr: { mouth: 'wobbly', brows: 0.5 * w } };
  });
}

function idlePushup(c: ClipContext): Clip {
  const f = c.from.facing;
  const g = c.stage.ground;
  const footX = c.from.x - f * 30;
  const rest = breathing(c.stage, c.from.x, f, 0);
  const plank = (alpha: number): Pose => {
    const bodyLen = BONES.thigh + BONES.shin;
    const p = pose({
      x: footX + f * bodyLen * Math.cos(alpha),
      y: g - bodyLen * Math.sin(alpha) - 2,
      rot: f * (PI / 2 - alpha),
      facing: f,
      torso: 0,
      head: -0.35,
      lHip: 0,
      lKnee: 0.05,
      rHip: 0.05,
      rKnee: 0.05,
    });
    const j = forwardKinematics(p);
    const sx = p.x + j.shoulder.x;
    reach(p, 'l', { x: sx - f * 1, y: g }, -1);
    reach(p, 'r', { x: sx + f * 3, y: g }, -1);
    return p;
  };
  const up = 0.47;
  const down = 0.18;
  return simple(
    'idle-pushup',
    5.2,
    (t) => {
      const into = ease.inOut(segment(t, 0, 0.7));
      const out = ease.inOut(segment(t, 4.4, 5.2));
      const reps = segment(t, 0.8, 4.3);
      const alpha = mix(up, down, (1 - Math.cos(reps * PI * 2 * 3)) / 2);
      const p = plank(alpha);
      const tired = t > 3.2;
      const blended = out > 0 ? lerpPose(p, rest, out) : lerpPose(rest, p, into);
      return { pose: blended, expr: tired ? { mouth: 'wobbly', brows: 0.8 } : { mouth: 'flat', brows: -0.6 } };
    },
    [{ t: 3.4, type: 'sweat' }],
    0.35,
  );
}

function idleKnock(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple(
    'idle-knock',
    3.2,
    (t) => {
      const p = breathing(c.stage, x, f, t);
      const w = ease.inOut(segment(t, 0, 0.5)) * (1 - ease.inOut(segment(t, 2.4, 3.2)));
      const tap = [0.8, 1.1, 1.4].reduce((acc, k) => acc + Math.exp(-Math.pow((t - k) * 18, 2)), 0);
      p.rShoulder = mix(p.rShoulder, 1.55 + tap * 0.12, w);
      p.rElbow = mix(p.rElbow, 1.35 - tap * 0.5, w);
      p.head = mix(p.head, 0.25, w);
      p.torso = mix(p.torso, 0.1, w);
      return { pose: p, expr: t > 1.6 ? { eyes: 'wide', brows: 0.8, mouth: 'o' } : { brows: 0.6, mouth: 'smile' } };
    },
    [0.8, 1.1, 1.4].map((t) => ({ t, type: 'ring' as const, at: 'rHand' as const })),
  );
}

/** Walks a few steps, then something goes wrong: the ragdoll takes over (a real fall). */
function mishap(action: ActionName, c: ClipContext, kind: 'slip' | 'trip'): Clip {
  const x0 = c.from.x;
  const dir: 1 | -1 = x0 < c.stage.width / 2 ? 1 : -1;
  const dist = kind === 'slip' ? 46 : 58;
  const speed = kind === 'slip' ? 70 : 85;
  const walk = travel(action, c.stage, x0, x0 + dir * dist, { speed, gait: WALK, expr: { mouth: 'smile' } });
  const fallAt = walk.duration;
  const events: ClipEvent[] =
    kind === 'slip'
      ? [
          { t: 0.05, type: 'banana', point: { x: x0 + dir * (dist + 8), y: c.stage.ground } },
          // Feet shoot forward, he tips backwards onto his back.
          { t: fallAt, type: 'ragdoll', vx: dir * 140, vy: -620, spin: -dir * 9, cause: 'slip' },
        ]
      : [{ t: fallAt, type: 'ragdoll', vx: dir * 330, vy: -160, spin: dir * 6.5, cause: 'trip' }];
  return {
    ...walk,
    duration: fallAt + 0.2,
    events,
    sample(t: number, env: ClipEnv): ClipFrame {
      const f = walk.sample(Math.min(t, fallAt), env);
      return t > fallAt - 0.15 ? { ...f, expr: { eyes: 'wide', brows: 1, mouth: 'o' } } : f;
    },
  };
}

function idleFaint(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const side = c.rng() < 0.5 ? 1 : -1;
  return simple(
    'idle-faint',
    1.9,
    (t) => {
      const p = breathing(c.stage, x, f, t);
      const sway = Math.sin(t * 4) * 0.08 * Math.min(1, t);
      p.torso += sway;
      p.head += sway * 2;
      p.lKnee += 0.15 * Math.min(1, t);
      p.rKnee += 0.15 * Math.min(1, t);
      p.lShoulder = -0.1;
      p.rShoulder = 0.1;
      return { pose: p, expr: t > 0.6 ? { eyes: 'spiral', mouth: 'wobbly', brows: 0.6 } : { eyes: 'sleepy', mouth: 'flat' } };
    },
    [{ t: 1.8, type: 'ragdoll', vx: side * 40, vy: 0, spin: side * f * 2.6, cause: 'faint' }],
    0.25,
  );
}

/* ───────────────────────── states & reactions ───────────────────────── */

function sleepClip(c: ClipContext, docked: boolean): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const surface = docked && c.stage.seat ? c.stage.seat.y : c.stage.ground;
  const start = { ...c.from };
  return {
    action: 'sleep',
    duration: Infinity,
    blend: 0.4,
    physics: false,
    ambient: { type: 'zz', every: 1.5 },
    events: [],
    sample(t: number): ClipFrame {
      const sit = sitting(x, surface, f, t * 0.15);
      const b = Math.sin(t * 1.4);
      sit.torso = 0.38 + b * 0.03;
      sit.head = 0.55 + b * 0.04;
      sit.lShoulder = 0.1;
      sit.rShoulder = 0.25;
      sit.lElbow = 0.6;
      sit.rElbow = 0.7;
      const w = ease.inOut(segment(t, 0, 1));
      return { pose: lerpPose(start, sit, w), expr: { eyes: t > 0.8 ? 'closed' : 'sleepy', mouth: 'o', brows: -0.1 } };
    },
  };
}

function wakeClip(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const docked = c.stage.seat !== null && Math.abs(c.from.y - (c.stage.seat.y - 3)) < 8;
  return simple(
    'wake',
    1.3,
    (t) => {
      const base = docked && c.stage.seat ? sitting(x, c.stage.seat.y, f, t) : breathing(c.stage, x, f, t);
      const jolt = Math.sin(segment(t, 0, 0.35) * PI);
      base.y -= jolt * 18;
      base.lShoulder = mix(base.lShoulder, 2.3, jolt);
      base.rShoulder = mix(base.rShoulder, 2.5, jolt);
      return { pose: base, expr: t < 0.9 ? { eyes: 'wide', brows: 1, mouth: 'o' } : { brows: 0.3, mouth: 'flat' } };
    },
    [{ t: 0.02, type: 'bang' }],
    0.08,
  );
}

function draggedClip(c: ClipContext): Clip {
  const f = c.from.facing;
  return {
    action: 'dragged',
    duration: Infinity,
    blend: 0.12,
    physics: false,
    events: [],
    sample(t: number, env: ClipEnv): ClipFrame {
      const p = pose({
        x: env.pointer?.x ?? c.from.x,
        y: env.pointer?.y ?? c.from.y,
        facing: f,
        rot: Math.max(-0.6, Math.min(0.6, -env.vx * 0.0005)),
        torso: -0.05,
        head: -0.2,
        lShoulder: 2.4 + 0.5 * Math.sin(t * 17),
        rShoulder: 2.6 + 0.5 * Math.sin(t * 17 + 1.8),
        lElbow: 0.6 + 0.4 * Math.sin(t * 21),
        rElbow: 0.5 + 0.4 * Math.sin(t * 19),
        lHip: 0.6 * Math.sin(t * 14),
        lKnee: 0.6 + 0.5 * Math.sin(t * 14 + 1),
        rHip: -0.6 * Math.sin(t * 14),
        rKnee: 0.6 + 0.5 * Math.sin(t * 14 + 2),
      });
      return { pose: p, expr: { eyes: 'wide', brows: 1, mouth: Math.sin(t * 6) > 0 ? 'o' : 'wobbly' } };
    },
  };
}

/** Limbs while the physics body flies: arms up when rising, flailing when falling, tucked when spinning. */
export function airborneClip(from: Pose): Clip {
  return {
    action: 'airborne',
    duration: Infinity,
    blend: 0.12,
    physics: true,
    events: [],
    sample(t: number, env: ClipEnv): ClipFrame {
      const tuck = Math.min(1, Math.abs(env.spin) / 5);
      const rising = env.vy < 0;
      const flail = Math.sin(t * 16);
      const p = pose({
        x: 0,
        y: 0,
        facing: from.facing,
        torso: mix(0, 0.8, tuck),
        head: rising ? -0.25 : 0.1,
        lShoulder: rising ? 2.7 : 1.9 + 0.5 * flail,
        rShoulder: rising ? 2.9 : 2.1 - 0.5 * flail,
        lElbow: rising ? 0.3 : 0.6,
        rElbow: rising ? 0.3 : 0.5,
        lHip: mix(0.35 + 0.25 * Math.sin(t * 12), 1.6, tuck),
        lKnee: mix(0.5, 2.3, tuck),
        rHip: mix(-0.2 - 0.25 * Math.sin(t * 12), 1.4, tuck),
        rKnee: mix(0.7, 2.2, tuck),
        squash: 1 + Math.min(0.12, Math.abs(env.vy) / 5000),
      });
      return { pose: p, expr: rising ? { eyes: 'happy', mouth: 'grin', brows: 0.3 } : { eyes: 'wide', mouth: 'o', brows: 0.9 } };
    },
  };
}

function landClip(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  const crouch = standAt(c.stage, x, f, { y: standHip(c.stage) + 10, torso: 0.3, lHip: 0.8, lKnee: 1.5, rHip: 0.6, rKnee: 1.3, lShoulder: -0.8, rShoulder: 0.9, lElbow: 0.5, rElbow: 0.5 });
  const stand = standAt(c.stage, x, f);
  const track = keyed([
    { t: 0, p: crouch },
    { t: 0.12, p: crouch },
    { t: 0.55, p: stand, e: ease.back },
  ]);
  return simple('land', 0.6, (t) => ({ pose: track(t), expr: { mouth: 'grin', brows: 0.2 } }), [], 0.06);
}

function dizzyClip(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple(
    'dizzy',
    1.9,
    (t) => {
      const p = breathing(c.stage, x, f, t);
      const s = Math.sin(t * 5);
      const k = Math.cos(t * 5);
      p.torso += 0.12 * s;
      p.head += 0.25 * k;
      p.x += 3 * s;
      p.lShoulder = -0.5 + 0.3 * k;
      p.rShoulder = 0.6 + 0.3 * s;
      p.lKnee += 0.2;
      p.rKnee += 0.2;
      return { pose: p, expr: { eyes: 'spiral', mouth: 'wobbly', brows: 0.6 } };
    },
    [{ t: 0.02, type: 'stars' }],
    0.25,
  );
}

function dustClip(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple(
    'dust',
    2.0,
    (t) => {
      const p = breathing(c.stage, x, f, t);
      const pat = t < 1.3;
      if (pat) {
        const k = Math.sin(t * 16);
        p.torso = 0.25;
        p.head = 0.45;
        p.rShoulder = 0.35 + 0.25 * k;
        p.rElbow = 1.2 + 0.4 * k;
        p.lShoulder = 0.2 - 0.2 * k;
        p.lElbow = 1.0;
      } else {
        p.head = 0.05;
      }
      return { pose: p, expr: pat ? { mouth: 'flat', brows: -0.2 } : { mouth: 'flat', brows: -0.55, eyes: 'dot' }, eyeDir: pat ? { x: f * 0.3, y: 1 } : undefined };
    },
    [0.3, 0.7, 1.05].map((t) => ({ t, type: 'dust' as const, at: 'rHand' as const, strength: 0.2 })),
    0.2,
  );
}

function dockedClip(c: ClipContext): Clip {
  const seat = c.stage.seat ?? { x: c.stage.width - 120, y: c.stage.ground - 300 };
  const f: 1 | -1 = -1;
  return {
    action: 'docked',
    duration: Infinity,
    blend: 0.5,
    physics: false,
    events: [],
    sample: (t: number): ClipFrame => ({ pose: sitting(seat.x, seat.y, f, t) }),
  };
}

function staticClip(c: ClipContext): Clip {
  const p = standAt(c.stage, c.targetX, -1);
  return { action: 'static', duration: Infinity, blend: 0, physics: false, events: [], sample: () => ({ pose: p }) };
}

function holdOnClip(c: ClipContext): Clip {
  const x = c.from.x;
  const f = c.from.facing;
  return simple(
    'hold-on',
    1.6,
    (t) => {
      const p = breathing(c.stage, x, f, t);
      const d = Math.exp(-2.2 * t);
      const w = Math.sin(t * 13) * d;
      p.torso += 0.25 * w;
      p.lShoulder = -1.4 + 0.5 * w;
      p.rShoulder = 1.5 - 0.5 * w;
      p.lElbow = 0.3;
      p.rElbow = 0.3;
      p.lKnee += 0.35;
      p.rKnee += 0.35;
      p.y += 5 * d;
      return { pose: p, expr: { eyes: 'wide', mouth: 'wobbly', brows: 0.9 } };
    },
    [],
    0.1,
  );
}

/* ───────────────────────── on/off morph ───────────────────────── */

/** Pose whose (collapsed) head sits on a point — the circle's position. */
function orbPose(center: Vec, over: Partial<Pose> = {}): Pose {
  const p = pose({ facing: -1, ...over, x: 0, y: 0 });
  const j = forwardKinematics(p);
  return { ...p, x: center.x - j.head.x, y: center.y - j.head.y };
}

const TUCK: Partial<Pose> = { torso: 0.9, head: 0.4, lShoulder: 0.4, rShoulder: 0.6, lElbow: 2.4, rElbow: 2.4, lHip: 2.0, lKnee: 2.6, rHip: 1.8, rKnee: 2.5 };

function curlClip(c: ClipContext): Clip {
  const corner = c.stage.corner;
  const start = { ...c.from };
  const f: 1 | -1 = corner.x >= start.x ? 1 : -1;
  const hop = { ...start, facing: f, y: start.y + 8, torso: 0.35, lHip: 0.8, lKnee: 1.4, rHip: 0.7, rKnee: 1.3 };
  const dist = Math.abs(corner.x - start.x);
  const flight = 0.5 + Math.min(0.5, dist / 1600);
  return {
    action: 'curl',
    duration: 0.25 + flight + 0.35,
    blend: 0.15,
    physics: false,
    events: [],
    sample(t: number): ClipFrame {
      if (t < 0.25) {
        return { pose: lerpPose(start, hop, ease.out(segment(t, 0, 0.25))), expr: { brows: 0.4, mouth: 'smile' } };
      }
      const u = segment(t, 0.25, 0.25 + flight);
      const target = orbPose(corner, { ...TUCK, facing: f });
      const p = lerpPose(hop, target, ease.inOut(u));
      p.y -= Math.sin(PI * u) * Math.min(160, 60 + dist * 0.15);
      p.rot = f * 2 * PI * ease.inOut(u);
      const morph = ease.inOut(segment(t, 0.25 + flight * 0.4, 0.25 + flight + 0.3));
      return { pose: p, morph, expr: { eyes: 'happy', mouth: 'grin' } };
    },
  };
}

function orbClip(c: ClipContext): Clip {
  const center = c.stage.seat ? { x: c.stage.seat.x, y: c.stage.seat.y - 16 } : c.stage.corner;
  // Perfectly still: the loop stops and only the eyes are redrawn on pointer moves.
  const still = orbPose(center, TUCK);
  return {
    action: 'orb',
    duration: Infinity,
    blend: 0.45,
    physics: false,
    events: [],
    sample: (): ClipFrame => ({ pose: still, morph: 1 }),
  };
}

function orbPopClip(c: ClipContext): Clip {
  const center = c.stage.corner;
  return simple(
    'orb-pop',
    0.5,
    (t) => ({ pose: orbPose(center, TUCK), morph: 1, scale: ease.back(segment(t, 0, 0.5)) }),
    [],
    0,
  );
}

function unfoldClip(c: ClipContext): Clip {
  const center = c.stage.corner;
  const orb = orbPose(center, TUCK);
  const x = Math.min(c.stage.width - 60, center.x - 40);
  const stretch = standAt(c.stage, x, -1, { lShoulder: PI - 0.2, rShoulder: PI + 0.2, lElbow: 0.1, rElbow: 0.1, torso: -0.12, head: -0.25, y: standHip(c.stage) - 3 });
  const stand = standAt(c.stage, x, -1);
  return simple(
    'unfold',
    1.9,
    (t) => {
      const squish = Math.sin(segment(t, 0, 0.45) * PI * 2) * (1 - segment(t, 0, 0.45));
      if (t < 0.45) {
        return { pose: { ...orb, squash: 1 - 0.18 * squish }, morph: 1, scale: 1 + 0.1 * squish, expr: { eyes: 'wide', brows: 0.6 } };
      }
      const u = segment(t, 0.45, 1.2);
      const p = lerpPose(orb, stretch, ease.out(u));
      p.y -= Math.sin(PI * u) * 40;
      const settle = lerpPose(stretch, stand, ease.inOut(segment(t, 1.35, 1.9)));
      const morph = 1 - ease.out(segment(t, 0.45, 1.0));
      return { pose: t < 1.35 ? p : settle, morph, scale: 1 + 0.12 * Math.sin(segment(t, 0.45, 0.7) * PI), expr: t > 1.1 && t < 1.5 ? { eyes: 'closed', mouth: 'o' } : { eyes: 'happy', mouth: 'grin' } };
    },
    [{ t: 0.45, type: 'sparkle', at: 'head' }],
    0.1,
  );
}

/* ───────────────────────── factory ───────────────────────── */

export function createClip(action: Exclude<ActionName, GetUpAction | RouteAction | MoveAction | ActivityAction>, c: ClipContext): Clip {
  switch (action) {
    case 'idle-slip':
      return mishap('idle-slip', c, 'slip');
    case 'idle-trip':
      return mishap('idle-trip', c, 'trip');
    case 'idle-faint':
      return idleFaint(c);
    case 'enter-walk':
      return enterWalk(c);
    case 'enter-sneak':
      return enterSneak(c);
    case 'enter-peek':
      return enterPeek(c);
    case 'enter-climb':
      return enterClimb(c);
    case 'enter-jump':
      return enterJump(c);
    case 'enter-drop':
      return enterDrop(c);
    case 'enter-rope':
      return enterRope(c);
    case 'enter-gopher':
      return enterGopher(c);
    case 'enter-slide':
      return enterSlide(c);
    case 'peek-out':
      return peekOut(c);
    case 'exit-run':
      return exitRun(c);
    case 'exit-jump':
      return exitJump(c);
    case 'exit-slide':
      return exitSlide(c);
    case 'exit-wave':
      return exitWave(c);
    case 'exit-peek':
      return exitPeek(c);
    case 'idle-breathe':
      return idleBreathe(c);
    case 'idle-scratch':
      return idleScratch(c);
    case 'idle-stretch':
      return idleStretch(c);
    case 'idle-sit':
      return idleSit(c);
    case 'idle-watch':
      return idleWatch(c);
    case 'idle-yawn':
      return idleYawn(c);
    case 'idle-juggle':
      return idleJuggle(c);
    case 'idle-read':
      return idleRead(c);
    case 'idle-doodle':
      return idleDoodle(c);
    case 'idle-balance':
      return idleBalance(c);
    case 'idle-pushup':
      return idlePushup(c);
    case 'idle-knock':
      return idleKnock(c);
    case 'sleep':
      return sleepClip(c, c.stage.seat !== null && Math.abs(c.from.y - (c.stage.seat.y - 3)) < 12);
    case 'wake':
      return wakeClip(c);
    case 'dragged':
      return draggedClip(c);
    case 'airborne':
      return airborneClip(c.from);
    case 'land':
      return landClip(c);
    case 'dizzy':
      return dizzyClip(c);
    case 'dust':
      return dustClip(c);
    case 'docked':
      return dockedClip(c);
    case 'static':
      return staticClip(c);
    case 'hold-on':
      return holdOnClip(c);
    case 'curl':
      return curlClip(c);
    case 'orb':
      return orbClip(c);
    case 'orb-pop':
      return orbPopClip(c);
    case 'unfold':
      return unfoldClip(c);
    case 'seat-route':
      // Resolved by the engine into a concrete route; without a seat it is simply the docked pose.
      return dockedClip(c);
  }
}

/* ───────────────────────── gestures (upper-body overlays) ───────────────────────── */

export type GestureName = 'think' | 'talk' | 'celebrate' | 'shrug' | 'point' | 'listen';

export interface GestureFrame {
  pose: Partial<Pose>;
  expr?: Partial<Expression>;
  eyeDir?: Vec;
  /** Show the animated "…" thought dots. */
  thinking?: boolean;
  /** Draw sound waves at the ear (voice dictation). */
  listening?: boolean;
}

export interface Gesture {
  name: GestureName;
  duration: number;
  sample(t: number, base: Pose): GestureFrame;
  events: ClipEvent[];
}

export function createGesture(name: GestureName, target: Vec | null): Gesture {
  switch (name) {
    case 'think':
      return {
        name,
        duration: Infinity,
        events: [],
        sample: (t, base) => {
          // Hand on the chin (IK), the other arm folded under the elbow. Long waits cycle through little thinking
          // beats every few seconds: tapping the chin, looking up and around, a quick "hmm" nod.
          const beat = Math.floor(t / 4) % 3;
          const local = t % 4;
          const tap = beat === 0 ? 1.6 * Math.max(0, Math.sin(local * 9)) * Math.min(1, local) : 0.6 * Math.sin(t * 3);
          const chin = armTo(7, -9 + tap, base.torso, -1);
          const hold = armTo(10, 8, base.torso, -1);
          const look = beat === 1 ? { x: base.facing * 0.8 * Math.cos(local * 1.4), y: -0.9 } : { x: base.facing * 0.5, y: -0.85 };
          const nod = beat === 2 ? 0.12 * Math.sin(Math.min(1, local / 1.2) * Math.PI * 2) : 0;
          return {
            pose: { rShoulder: chin.shoulder, rElbow: chin.elbow, lShoulder: hold.shoulder, lElbow: hold.elbow, head: -0.18 + 0.04 * Math.sin(t * 1.3) + nod },
            expr: { mouth: 'flat', brows: beat === 2 && local < 1.2 ? 0.2 : 0.55 },
            eyeDir: look,
            thinking: true,
          };
        },
      };
    case 'talk':
      return {
        name,
        duration: Infinity,
        events: [],
        sample: (t) => ({
          pose: {
            rShoulder: 0.65 + 0.3 * Math.sin(t * 2.9),
            rElbow: 1.25 + 0.4 * Math.sin(t * 4.3),
            lShoulder: 0.1 + 0.15 * Math.sin(t * 2.1 + 1),
            lElbow: 0.6 + 0.2 * Math.sin(t * 3.1),
            head: 0.06 * Math.sin(t * 5),
          },
          expr: { mouth: 'open', talk: 0.35 + 0.65 * Math.abs(Math.sin(t * 13) * Math.sin(t * 5.3)), brows: 0.2 + 0.25 * Math.sin(t * 1.7) },
        }),
      };
    case 'celebrate':
      return {
        name,
        duration: 1.6,
        events: [
          { t: 0.02, type: 'sparkle', at: 'head' },
          { t: 0.05, type: 'hop' },
        ],
        sample: (t) => ({
          pose: { lShoulder: 3.45 + 0.15 * Math.sin(t * 14), rShoulder: 2.55 - 0.15 * Math.sin(t * 14), lElbow: 0.2, rElbow: 0.25, head: -0.2 },
          expr: { eyes: 'happy', mouth: 'grin', brows: 0.5 },
        }),
      };
    case 'shrug':
      return {
        name,
        duration: 1.8,
        events: [{ t: 0.25, type: 'sweat' }],
        sample: () => ({
          pose: { lShoulder: -0.3, lElbow: 1.95, rShoulder: 0.3, rElbow: 1.95, head: 0.28, torso: -0.04 },
          expr: { eyes: 'dot', brows: 1, mouth: 'wobbly' },
        }),
      };
    case 'listen':
      return {
        name,
        duration: Infinity,
        events: [],
        sample: (t, base) => {
          // Hand cupped at the ear, leaning toward the chat.
          const ear = armTo(-7, -17, base.torso + 0.18, -1);
          const toward = target ? Math.sign((target.x - base.x) * base.facing) || 1 : 1;
          return {
            pose: { rShoulder: ear.shoulder, rElbow: ear.elbow, torso: base.torso + 0.18 * toward, head: 0.12 * toward + 0.03 * Math.sin(t * 2.4), lShoulder: -0.1, lElbow: 0.4 },
            expr: { eyes: 'wide', brows: 0.6, mouth: 'o' },
            eyeDir: { x: base.facing * toward * 0.7, y: 0.2 },
            listening: true,
          };
        },
      };
    case 'point':
      return {
        name,
        duration: Infinity,
        events: [],
        sample: (t, base) => {
          const j = forwardKinematics({ ...base, rot: 0, squash: 1 });
          const tx = target ? (target.x - (base.x + j.shoulder.x)) * base.facing : 1;
          const ty = target ? target.y - (base.y + j.shoulder.y) : 0.3;
          const aim = Math.atan2(tx, ty) - base.torso;
          return {
            pose: { rShoulder: aim + 0.05 * Math.sin(t * 6), rElbow: 0.05, head: 0.15 },
            expr: { mouth: 'smile', brows: 0.35 },
            eyeDir: { x: tx === 0 ? 0 : Math.sign(tx) * base.facing * 0.8, y: 0.5 },
          };
        },
      };
  }
}

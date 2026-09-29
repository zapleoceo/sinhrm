import {
  Clip,
  ClipContext,
  ClipEvent,
  ClipFrame,
  InkProp,
  PanelRect,
  RUN,
  RouteAction,
  Stage,
  WALK,
  ease,
  gaitPose,
  plant,
  pose,
  reach,
  sitting,
  standHip,
} from './animations';
import { pickWeighted } from './brain';
import { BONES, Pose, Vec, blendTravel, forwardKinematics, lerpPose, unwrapToward } from './skeleton';

/**
 * Ways «Стік» gets onto the chat panel's top edge: pure functions of time + IK, props drawn in ink.
 * The engine picks one by geometry (chooseRoute), re-targets live when the panel moves (seatNow/panelNow) and
 * fades the props out when the route ends or is aborted.
 */

export type RouteSide = 'left' | 'right';

export interface RouteGeometry {
  /** Horizontal distance from him to the seat. */
  dx: number;
  /** Height of the panel top above the floor. */
  height: number;
  /** Free room beside the panel on the side he approaches. */
  room: number;
  /** y of the panel top (small — the panel reaches the top of the viewport). */
  panelTop: number;
  /** Horizontal distance from the approach edge of the panel to the seat. */
  seatInset: number;
}

export const ROUTE_MIN = 1.5;
export const ROUTE_MAX = 4;
/** Believable hand-over-hand climbing speed (px/s): a tall climb takes longer instead of getting faster. */
export const MAX_CLIMB_SPEED = 230;
/** Climbs taller than this (px) are not offered — they would take well over 4 s. */
const MAX_CLIMB_HEIGHT = 700;
/** Longest a route may take in total (a long climb from far away is not offered beyond this). */
export const ROUTE_LIMIT = 5.8;

/** Rough duration of a climbing route (walk to the panel + capped-speed climb + getting on top). */
function climbTime(g: RouteGeometry): number {
  const dist = Math.max(0, g.dx - g.seatInset);
  // Same speeds as walkPhase: short walks still take up to ~1.1 s.
  const walk = dist / Math.min(460, Math.max(130, dist / 1.1));
  // … plus walking along the top to the seat.
  const top = Math.max(0, g.seatInset - 16);
  return 0.25 + walk + 0.25 + Math.max(0, g.height - 60) / MAX_CLIMB_SPEED + 0.9 + (top < 4 ? 0 : Math.max(0.3, top / Math.min(TOP_WALK_MAX, Math.max(150, top / 0.9))));
}
const STEP_WIDTH = 22;
/** Room his body needs beyond the outermost prop/foot on the approach side (px). */
const BODY_MARGIN = 20;
/** Head and raised hands above the hip (px) — how much headroom a pose needs. */
const HEADROOM = 85;
/** The balloon lifts him off the floor first, so dangling feet never dip below it. */
const LIFT = 8;
/** Fastest trot along the panel top to the seat (px/s). */
const TOP_WALK_MAX = 300;

/** How many steps the stairs route draws for a panel top `height` px above the floor (shared by selection and route). */
export function stairSteps(height: number): number {
  return Math.max(2, Math.min(9, Math.ceil(height / 30)));
}

/** Room beside the panel each route needs on the approach side (px); it must stay inside the viewport. */
export function routeRoom(route: RouteAction, g: RouteGeometry): number {
  switch (route) {
    case 'route-hop':
      return 0;
    case 'route-climb':
      return 13 + BODY_MARGIN;
    case 'route-ladder':
      return 58 + BODY_MARGIN;
    case 'route-stairs':
      return STEP_WIDTH * stairSteps(g.height) + 30 + BODY_MARGIN;
    case 'route-trampoline':
      return 78 + BODY_MARGIN;
    case 'route-rope':
      // The back swing goes out beyond the rope by ~0.35 of the rope-to-seat distance.
      return 56 + 0.35 * (g.seatInset + 56) + BODY_MARGIN;
    case 'route-balloon':
      return 30 + 25 + BODY_MARGIN;
    case 'route-vault': {
      const pole = Math.min(260, g.height + 40) - 30;
      return 34 + Math.max(40, 0.8 * pole) + BODY_MARGIN;
    }
  }
}
const BLEND_IN = 0.25;
/** Cross-fade at every phase seam (s). */
const CROSS = 0.14;
const LAND = 0.45;
const CLIMB_STEP = 18;
const TRAMP_GRAVITY = 1800;

/** The panel's box: measured, or derived from the seat when only the seat is known. */
export function panelOf(stage: Stage): PanelRect {
  if (stage.panel) {
    return stage.panel;
  }
  const seat = stage.seat ?? { x: stage.width - 120, y: stage.ground - 300 };
  return { left: seat.x - 250, top: seat.y, right: Math.min(stage.width - 16, seat.x + 140), bottom: stage.ground };
}

/** Which side of the panel he goes up: the one he is on, or the roomier one when he stands under it. */
export function approachSide(x: number, panel: PanelRect, width: number): RouteSide {
  const roomL = panel.left;
  const roomR = width - panel.right;
  let side: RouteSide = x < panel.left ? 'left' : x > panel.right ? 'right' : roomL >= roomR ? 'left' : 'right';
  if ((side === 'left' ? roomL : roomR) < 24) {
    side = side === 'left' ? 'right' : 'left';
  }
  return side;
}

export function routeGeometry(stage: Stage, from: Pose): RouteGeometry {
  const panel = panelOf(stage);
  const seat = stage.seat ?? { x: (panel.left + panel.right) / 2, y: panel.top };
  const side = approachSide(from.x, panel, stage.width);
  return {
    dx: Math.abs(seat.x - from.x),
    height: stage.ground - panel.top,
    room: side === 'left' ? panel.left : stage.width - panel.right,
    panelTop: panel.top,
    seatInset: Math.abs(seat.x - (side === 'left' ? panel.left : panel.right)),
  };
}

/** Route weights allowed by the geometry (lite mode: only the simple ones). */
export function allowedRoutes(g: RouteGeometry, lite: boolean): Partial<Record<RouteAction, number>> {
  const out: Partial<Record<RouteAction, number>> = {};
  // Every route keeps him, his hands/feet and its props inside the viewport: it needs its room beside the panel.
  const fits = (r: RouteAction): boolean => g.room >= routeRoom(r, g);
  if (g.dx < 300 && g.height < 280) {
    out['route-hop'] = 1.2;
  }
  if (g.height < 420 && fits('route-stairs')) {
    out['route-stairs'] = 1.5;
  }
  if (g.height <= MAX_CLIMB_HEIGHT && climbTime(g) <= ROUTE_LIMIT && fits('route-climb')) {
    out['route-climb'] = 1.5;
  }
  if (!lite) {
    if (g.height >= 140 && g.height <= MAX_CLIMB_HEIGHT && climbTime(g) + 0.7 <= ROUTE_LIMIT && fits('route-ladder')) {
      out['route-ladder'] = 1;
    }
    // The last bounce peaks 40 px above the seat: headroom above the panel top.
    if (g.height >= 110 && g.height <= 560 && g.panelTop >= 40 + HEADROOM && fits('route-trampoline')) {
      out['route-trampoline'] = 1;
    }
    if (g.height >= 180 && g.height <= MAX_CLIMB_HEIGHT && climbTime(g) <= ROUTE_LIMIT && g.panelTop >= 90 && fits('route-rope')) {
      out['route-rope'] = 1;
    }
    // He floats ~72 px above the seat with the balloon above his raised hand.
    if (g.height >= 140 && g.panelTop >= 72 + HEADROOM + 40 && fits('route-balloon')) {
      out['route-balloon'] = 1;
    }
    if (g.height < 300 && g.dx >= 160 && fits('route-vault')) {
      out['route-vault'] = 0.8;
    }
  }
  if (Object.keys(out).length === 0) {
    // Nothing fits (e.g. the mobile bottom sheet spans the full width): a hop straight up onto the seat.
    out['route-hop'] = 1;
  }
  return out;
}

/** Weighted random route, never the same as the previous one when there is a choice. */
export function chooseRoute(g: RouteGeometry, rng: () => number, last: RouteAction | null, lite: boolean): RouteAction {
  const weights = allowedRoutes(g, lite) as Record<RouteAction, number>;
  return pickWeighted(weights, last, rng);
}

/* ───────────── building blocks ───────────── */

interface Phase {
  dur: number;
  /** Stretched when the route must fit ROUTE_MIN..ROUTE_MAX. */
  flexible: boolean;
  /** Never squeezed below this (speed cap), even if the route then runs longer than ROUTE_MAX. */
  minDur?: number;
  at(u: number): ClipFrame;
}

interface Ctx {
  stage: Stage;
  from: Pose;
  g: number;
  seat: () => Vec;
  panel: () => PanelRect;
  side: RouteSide;
  /** +1 / -1: direction pointing away from the panel on the approach side. */
  out: 1 | -1;
  /** Facing toward the panel. */
  toward: 1 | -1;
  lite: boolean;
}

function edgeX(c: Ctx): number {
  const p = c.panel();
  return c.side === 'left' ? p.left : p.right;
}

function seated(c: Ctx): Pose {
  const s = c.seat();
  return sitting(s.x, s.y, -1, 0);
}

function ink(d: string, draw: number, lite: boolean, alpha = 1): InkProp {
  return { d, draw: lite ? 1 : Math.max(0, Math.min(1, draw)), alpha };
}

const n = (v: number): string => (Math.round(v * 10) / 10).toString();

/** Walk (or run) along the floor from x0 to x1. */
function walkPhase(c: Ctx, x0: number, x1: number, targetDur = 1.1): Phase {
  const dist = Math.abs(x1 - x0);
  // Walk (≥ 130 px/s) or run (≤ 460 px/s); never squeezed further — fast legs would blur into jumps.
  const speed = Math.min(460, Math.max(130, dist / targetDur));
  const dur = dist < 2 ? 0.01 : Math.max(0.25, dist / speed);
  const gait = speed > 170 ? RUN : WALK;
  const dir: 1 | -1 = x1 >= x0 ? 1 : -1;
  return {
    dur,
    flexible: false,
    at: (u) => ({ pose: gaitPose(c.stage, x0 + (x1 - x0) * u, dist * u, dist < 2 ? c.toward : dir, gait), expr: { mouth: 'smile' } }),
  };
}

/** Standing still for a moment (drawing a prop, looking up). */
function holdPhase(dur: number, at: (u: number) => ClipFrame): Phase {
  return { dur, flexible: false, at };
}

/**
 * Climbing a line from a floor point to a top point (a wall edge or a ladder), hands and feet gripping
 * quantized "rungs" on it hand-over-hand (IK). `n` points away from the surface (the side his body is on).
 */
function climbPose(c: Ctx, bottom: Vec, top: Vec, nrm: Vec, d: number, facing: 1 | -1): Pose {
  const len = Math.hypot(top.x - bottom.x, top.y - bottom.y) || 1;
  const ux = (top.x - bottom.x) / len;
  const uy = (top.y - bottom.y) / len;
  const at = (s: number, off: number): Vec => ({ x: bottom.x + ux * s + nrm.x * off, y: bottom.y + uy * s + nrm.y * off });
  const hip = at(d, 11);
  const p = pose({ x: hip.x, y: hip.y, facing, torso: 0.18, head: -0.35 });
  const grip = (s: number, phase: number): number => {
    const k = (s + phase) / CLIMB_STEP;
    const base = Math.floor(k);
    const frac = k - base;
    return (base + ease.inOut(Math.max(0, (frac - 0.6) / 0.4))) * CLIMB_STEP - phase;
  };
  const clampS = (s: number): number => Math.max(0, Math.min(len, s));
  reach(p, 'l', at(clampS(grip(d + 44, 0)), 1), -1);
  reach(p, 'r', at(clampS(grip(d + 44, CLIMB_STEP / 2)), 1), -1);
  plant(p, 'l', at(clampS(grip(d - 30, CLIMB_STEP / 2)), 2));
  plant(p, 'r', at(clampS(grip(d - 30, 0)), 2));
  return p;
}

function climbPhase(c: Ctx, bottom: () => Vec, top: () => Vec, nrm: Vec, facing: 1 | -1, fromD: number, toD: () => number, speed = 95): Phase {
  const dist = Math.max(10, toD() - fromD);
  return {
    dur: dist / speed,
    flexible: true,
    minDur: dist / MAX_CLIMB_SPEED,
    at: (u) => ({ pose: climbPose(c, bottom(), top(), nrm, fromD + (toD() - fromD) * u, facing), expr: { brows: -0.4, mouth: 'wobbly' } }),
  };
}

/** From the pose at the end of the previous phase onto the seat, in a short arc. */
function landPhase(c: Ctx, from: () => Pose, arc = 16, dur = LAND): Phase {
  return {
    dur,
    flexible: false,
    at: (u) => {
      const a = from();
      const b = seated(c);
      const p = lerpPose(unwrapToward(a, b), b, ease.inOut(u));
      p.y -= arc * 4 * u * (1 - u);
      return { pose: p, expr: u > 0.7 ? { mouth: 'smile', eyes: 'happy' } : { mouth: 'o', brows: 0.3 } };
    },
  };
}

/** Standing on the panel top at x (the top edge works as a floor). */
function standOnTop(c: Ctx, x: number, facing: 1 | -1): Pose {
  return gaitPose({ ...c.stage, ground: c.panel().top }, x, 0, facing, WALK);
}

/**
 * Arrived at the panel's edge: stand up on top, walk along it to the seat (the seat can be far from the edge on a
 * wide panel), then sit. `from` is the pose where the previous phase ended; `props` keeps its props drawn.
 */
function topWalk(c: Ctx, from: () => Pose, props?: () => InkProp[]): Phase[] {
  const x0 = (): number => edgeX(c) - c.out * 16;
  const withProps = (f: ClipFrame): ClipFrame => (props ? { ...f, props: { ink: props() } } : f);
  const standUp: Phase = {
    dur: Math.max(0.3, blendTravel(from(), standOnTop(c, x0(), c.toward)) / 300),
    flexible: false,
    at: (u) => {
      const a = from();
      const b = standOnTop(c, x0(), c.toward);
      return withProps({ pose: lerpPose(unwrapToward(a, b), b, ease.inOut(u)), expr: { mouth: 'smile' } });
    },
  };
  const dist = Math.abs(c.seat().x - x0());
  const topStage = (): Stage => ({ ...c.stage, ground: c.panel().top });
  const dir: 1 | -1 = c.seat().x >= x0() ? 1 : -1;
  const speed = Math.min(TOP_WALK_MAX, Math.max(150, dist / 0.9));
  const walk: Phase = {
    dur: dist < 4 ? 0.05 : Math.max(0.3, dist / speed),
    flexible: false,
    at: (u) => {
      const xa = x0();
      const xb = c.seat().x;
      return withProps({ pose: gaitPose(topStage(), xa + (xb - xa) * u, Math.abs(xb - xa) * u, dir, speed > 170 ? RUN : WALK), expr: { mouth: 'smile' } });
    },
  };
  const sit = landPhase(c, () => walk.at(1).pose, 4, 0.4);
  return [standUp, walk, props ? { ...sit, at: (u) => withProps(sit.at(u)) } : sit];
}

/** `pose` shifted by `k` × (from − to) in every channel (angles unwrapped): a fading seam offset. */
function offsetPose(pose: Pose, from: Pose, to: Pose, k: number): Pose {
  const a = unwrapToward(from, to);
  const out = { ...pose };
  const rec = out as unknown as Record<string, number>;
  const ra = a as unknown as Record<string, number>;
  const rt = to as unknown as Record<string, number>;
  for (const key of ['x', 'y', 'rot', 'torso', 'head', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow', 'lHip', 'lKnee', 'rHip', 'rKnee', 'squash']) {
    rec[key] = rec[key] + (ra[key] - rt[key]) * k;
  }
  if (from.facing !== to.facing) {
    out.turn = pose.facing * (1 - 2 * k);
  }
  return out;
}

/** Runs phases in order, starting with a blend from the exact current pose. */
function build(action: RouteAction, c: Ctx, phases: Phase[], events: (starts: number[]) => ClipEvent[] = () => []): Clip {
  // Fit the total duration into ROUTE_MIN..ROUTE_MAX by stretching the flexible phases.
  const fixed = phases.filter((p) => !p.flexible).reduce((a, p) => a + p.dur, 0) + BLEND_IN;
  const flex = phases.filter((p) => p.flexible).reduce((a, p) => a + p.dur, 0);
  let k = 1;
  if (flex > 0) {
    if (fixed + flex > ROUTE_MAX) {
      k = Math.max(0.3, (ROUTE_MAX - fixed) / flex);
    } else if (fixed + flex < ROUTE_MIN) {
      k = (ROUTE_MIN - fixed) / flex;
    }
  }
  const durs = phases.map((p) => (p.flexible ? Math.max(p.minDur ?? 0, p.dur * k) : p.dur));
  const starts: number[] = [];
  let t0 = BLEND_IN;
  for (const d of durs) {
    starts.push(t0);
    t0 += d;
  }
  const duration = t0;
  const first = phases[0];
  const crossDur = phases.map(() => -1);
  const seams: ({ end: Pose; start: Pose } | undefined)[] = phases.map(() => undefined);
  return {
    action,
    duration,
    blend: 0,
    physics: false,
    stiff: true,
    events: events(starts),
    sample(t: number): ClipFrame {
      if (t < BLEND_IN) {
        const target = first.at(0);
        const u = ease.inOut(t / BLEND_IN);
        return { ...target, pose: lerpPose(unwrapToward(c.from, target.pose), target.pose, u) };
      }
      for (let i = phases.length - 1; i >= 0; i--) {
        if (t >= starts[i] || i === 0) {
          const u = durs[i] <= 0 ? 1 : Math.min(1, (t - starts[i]) / durs[i]);
          const frame = phases[i].at(u);
          const since = t - starts[i];
          if (i > 0) {
            // Cross-fade from where the previous phase ended (longer when limbs have far to go): seams never jump.
            let cross = crossDur[i];
            if (cross < 0 || since < cross) {
              // The seam poses are read once per phase (while inside the fade window), not every frame.
              const seam = (seams[i] ??= { end: phases[i - 1].at(1).pose, start: phases[i].at(0).pose });
              if (cross < 0) {
                const gap = blendTravel(seam.end, seam.start);
                cross = gap < 2 ? 0 : Math.min(durs[i] * 0.8, Math.max(CROSS, gap / 420));
                crossDur[i] = cross;
              }
              if (since < cross) {
                // Fade out the seam's gap as an offset on top of the new phase: its own motion (speed, arc) is kept
                // — lerping from a still pose into a fast-moving phase would make it catch up at double speed.
                return { ...frame, pose: offsetPose(frame.pose, seam.end, seam.start, 1 - ease.inOut(since / cross)) };
              }
            }
          }
          return frame;
        }
      }
      return phases[phases.length - 1].at(1);
    },
  };
}

/** End pose of a phase list (for the landing phase that follows it). */
const endOf = (p: Phase) => (): Pose => p.at(1).pose;

/* ───────────── the routes ───────────── */

function hopRoute(c: Ctx): Clip {
  const start = standingAt(c, c.from.x, c.toward);
  const crouch = { ...start, y: start.y + 9, torso: 0.4, lHip: 0.9, lKnee: 1.5, rHip: 0.8, rKnee: 1.4, lShoulder: -0.8, rShoulder: -0.7, lElbow: 0.4, rElbow: 0.4 };
  const air = { ...crouch, lShoulder: 2.6, rShoulder: 2.8, lElbow: 0.3, rElbow: 0.3, lHip: 0.5, lKnee: 0.9, rHip: 0.3, rKnee: 0.8, torso: 0.1 };
  const dist = Math.hypot(c.seat().x - start.x, c.seat().y - start.y);
  // Longer hops (the fallback on a full-width bottom sheet can be far and high) take proportionally longer.
  const flight = Math.min(1.6, 0.35 + dist / 750);
  return build('route-hop', c, [
    holdPhase(0.22, (u) => ({ pose: lerpPose(start, crouch, ease.out(u)), expr: { brows: -0.2, mouth: 'flat' } })),
    {
      dur: flight,
      flexible: true,
      at: (u) => {
        const end = seated(c);
        // Keep the head and raised arms inside the viewport at the top of the arc.
        const room = (start.y + end.y) / 2 - HEADROOM;
        const apex = Math.max(0, Math.min(50 + Math.max(0, start.y - end.y) * 0.25, room));
        const p = u < 0.5 ? lerpPose(crouch, air, ease.inOut(u * 2)) : lerpPose(air, end, ease.inOut((u - 0.5) * 2));
        p.x = start.x + (end.x - start.x) * u;
        p.y = start.y + 9 * (1 - u) * (1 - u) + (end.y - start.y) * u - apex * 4 * u * (1 - u);
        return { pose: p, expr: { eyes: 'happy', mouth: 'grin' } };
      },
    },
  ]);
}

function standingAt(c: Ctx, x: number, facing: 1 | -1): Pose {
  return gaitPose(c.stage, x, 0, facing, WALK);
}

function climbRoute(c: Ctx): Clip {
  const spot = (): number => edgeX(c) + c.out * 13;
  const bottom = (): Vec => ({ x: edgeX(c), y: c.g });
  const top = (): Vec => ({ x: edgeX(c), y: c.panel().top });
  const nrm = { x: c.out, y: 0 };
  const walk = walkPhase(c, c.from.x, spot());
  const standD = BONES.thigh + BONES.shin;
  const climbTo = (): number => c.g - c.panel().top - 18;
  const climb = climbPhase(c, bottom, top, nrm, c.toward, standD, climbTo);
  const toClimb = holdPhase(0.25, (u) => ({ pose: lerpPose(walk.at(1).pose, climbPose(c, bottom(), top(), nrm, standD, c.toward), ease.inOut(u)), expr: { brows: 0.3 } }));
  // Pull over the top: chest over the edge, a knee up, then sit.
  const kneelPose = (): Pose => {
    const kneel = pose({ x: edgeX(c) - c.out * 14, y: c.panel().top - 20, facing: c.toward, torso: 0.5, head: -0.2, lHip: 1.5, lKnee: 2.2, rHip: 0.6, rKnee: 1.4 });
    reach(kneel, 'l', { x: edgeX(c) - c.out * 26, y: c.panel().top }, 1);
    reach(kneel, 'r', { x: edgeX(c) - c.out * 20, y: c.panel().top }, 1);
    return kneel;
  };
  const overDur = Math.max(0.4, blendTravel(climbPose(c, bottom(), top(), nrm, climbTo(), c.toward), kneelPose()) / 280);
  const over = holdPhase(overDur, (u) => {
    const a = climbPose(c, bottom(), top(), nrm, climbTo(), c.toward);
    const kneel = kneelPose();
    return { pose: lerpPose(unwrapToward(a, kneel), kneel, ease.inOut(u)), expr: { brows: -0.6, mouth: 'wobbly' } };
  });
  return build('route-climb', c, [walk, toClimb, climb, over, ...topWalk(c, endOf(over))]);
}

function ladderRoute(c: Ctx): Clip {
  const baseX = (): number => edgeX(c) + c.out * 46;
  const bottom = (): Vec => ({ x: baseX(), y: c.g });
  const top = (): Vec => ({ x: edgeX(c) + c.out * 4, y: c.panel().top });
  const spot = (): number => baseX() + c.out * 12;
  const walk = walkPhase(c, c.from.x, spot());
  const drawDur = c.lite ? 0.2 : 0.7;
  const ladder = (draw: number): InkProp => {
    const b = bottom();
    const t = top();
    const len = Math.hypot(t.x - b.x, t.y - b.y) || 1;
    const px = ((-(t.y - b.y) / len) * 7) | 0;
    const py = (((t.x - b.x) / len) * 7) | 0;
    let d = `M ${n(b.x - px)} ${n(b.y - py)} L ${n(t.x - px)} ${n(t.y - py)} M ${n(b.x + px)} ${n(b.y + py)} L ${n(t.x + px)} ${n(t.y + py)}`;
    for (let s = 14; s < len - 4; s += 16) {
      const x = b.x + ((t.x - b.x) * s) / len;
      const y = b.y + ((t.y - b.y) * s) / len;
      d += ` M ${n(x - px)} ${n(y - py)} L ${n(x + px)} ${n(y + py)}`;
    }
    return ink(d, draw, c.lite);
  };
  const drawPhase = holdPhase(drawDur, (u) => {
    const p = standingAt(c, spot(), c.toward);
    const b = bottom();
    const t = top();
    reach(p, 'r', { x: b.x + (t.x - b.x) * u * 0.5, y: b.y + (t.y - b.y) * u * 0.5 - 10 }, -1);
    return { pose: p, expr: { mouth: 'flat', brows: 0.3 }, props: { ink: [ladder(u)] } };
  });
  const nrm = { x: c.out * 0.2, y: 0 };
  const standD = BONES.thigh + BONES.shin;
  const toD = (): number => Math.hypot(top().x - bottom().x, top().y - bottom().y) - 16;
  const climbRaw = climbPhase(c, bottom, top, nrm, c.toward, standD, toD);
  const climb: Phase = { ...climbRaw, at: (u) => ({ ...climbRaw.at(u), props: { ink: [ladder(1)] } }) };
  const toClimb = holdPhase(0.25, (u) => ({ pose: lerpPose(drawPhase.at(1).pose, climbPose(c, bottom(), top(), nrm, standD, c.toward), ease.inOut(u)), props: { ink: [ladder(1)] } }));
  return build('route-ladder', c, [walk, drawPhase, toClimb, climb, ...topWalk(c, endOf(climbRaw), () => [ladder(1)])]);
}

function ropeRoute(c: Ctx): Clip {
  const ropeX = (): number => Math.max(12, Math.min(c.stage.width - 12, edgeX(c) + c.out * 56));
  const hangEnd = c.g - 44 - 40;
  const walk = walkPhase(c, c.from.x, ropeX());
  const ropeInk = (endY: number, hand: Vec | null): InkProp => {
    const x = ropeX();
    const e = hand ?? { x, y: endY };
    const mid = { x: (x + e.x) / 2 + 4, y: e.y / 2 };
    return ink(`M ${n(x)} 0 Q ${n(mid.x)} ${n(mid.y)} ${n(e.x)} ${n(e.y)}`, 1, c.lite);
  };
  // The rope drops from the top edge while he walks under it.
  const walkRope: Phase = { ...walk, at: (u) => ({ ...walk.at(u), props: { ink: [ropeInk(hangEnd * ease.out(Math.min(1, u * 1.6)), null)] } }) };
  const handsOn = (p: Pose): Vec => {
    const j = forwardKinematics(p);
    return { x: p.x + (j.lHand.x + j.rHand.x) / 2, y: p.y + (j.lHand.y + j.rHand.y) / 2 };
  };
  const hangPose = (hipY: number, t: number): Pose => {
    const p = pose({ x: ropeX(), y: hipY, facing: c.toward, torso: 0, head: -0.2, lHip: 0.3 + 0.2 * Math.sin(t * 6), lKnee: 0.8, rHip: 0.1, rKnee: 1.2 });
    const k = (Math.sin(t * 7) + 1) / 2;
    reach(p, 'l', { x: ropeX(), y: hipY - 58 + k * 8 }, -1);
    reach(p, 'r', { x: ropeX(), y: hipY - 50 - k * 8 }, -1);
    return p;
  };
  const topHip = (): number => Math.max(90, c.panel().top - 6);
  const grab = holdPhase(0.3, (u) => {
    const p = lerpPose(walk.at(1).pose, hangPose(standHip(c.stage) - 4, 0), ease.inOut(u));
    return { pose: p, expr: { brows: 0.4 }, props: { ink: [ropeInk(hangEnd, handsOn(p))] } };
  });
  const climbDist = (): number => standHip(c.stage) - 4 - topHip();
  const climb: Phase = {
    dur: Math.max(0.5, climbDist() / 120),
    flexible: true,
    at: (u) => {
      const p = hangPose(standHip(c.stage) - 4 - climbDist() * ease.inOut(u), u * 4);
      return { pose: p, expr: { brows: -0.4, mouth: 'wobbly' }, props: { ink: [ropeInk(hangEnd, handsOn(p))] } };
    },
  };
  // Pendulum from the anchor at the top edge: a back swing, then forward over the seat, let go.
  const swing: Phase = {
    dur: 0.9,
    flexible: false,
    at: (u) => {
      const R = topHip();
      // Swing just past the edge onto the top (a wide panel's seat can be far; he walks the rest).
      const D = edgeX(c) - c.out * 30 - ropeX();
      const target = Math.asin(Math.max(-0.95, Math.min(0.95, D / R)));
      const theta = u < 0.35 ? -0.35 * target * Math.sin((u / 0.35) * (Math.PI / 2)) : -0.35 * target + 1.35 * target * ease.inOut((u - 0.35) / 0.65);
      const base = hangPose(R, 0);
      const p = { ...base, x: ropeX() + R * Math.sin(theta), y: R * Math.cos(theta), rot: -theta };
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: [ropeInk(hangEnd, handsOn(p))] } };
    },
  };
  const land = landPhase(c, endOf(swing), 10, 0.55);
  const landInk: Phase = { ...land, at: (u) => ({ ...land.at(u), props: { ink: [ropeInk(hangEnd, null)] } }) };
  void landInk;
  return build('route-rope', c, [walkRope, grab, climb, swing, ...topWalk(c, endOf(swing), () => [ropeInk(hangEnd, null)])]);
}

function trampolineRoute(c: Ctx): Clip {
  const tx = (): number => edgeX(c) + c.out * 48;
  const walk = walkPhase(c, c.from.x, tx());
  const drawDur = c.lite ? 0.15 : 0.5;
  let dip = 0;
  const tramp = (draw: number): InkProp => {
    const x = tx();
    const y = c.g - 10;
    const d = `M ${n(x - 22)} ${n(c.g)} L ${n(x - 18)} ${n(y)} M ${n(x + 22)} ${n(c.g)} L ${n(x + 18)} ${n(y)} M ${n(x - 20)} ${n(y)} Q ${n(x)} ${n(y + dip)} ${n(x + 20)} ${n(y)}`;
    return ink(d, draw, c.lite);
  };
  const drawPhase = holdPhase(drawDur, (u) => {
    const p = standingAt(c, tx() + c.out * 30, c.toward);
    reach(p, 'r', { x: tx() + c.out * 10, y: c.g - 12 }, 1);
    return { pose: p, expr: { mouth: 'flat' }, props: { ink: [tramp(u)] } };
  });
  const surfaceHip = c.g - 10 - (BONES.thigh + BONES.shin) + 2;
  const air = (x: number, y: number, rise: boolean): Pose =>
    pose({ x, y, facing: c.toward, torso: 0.05, head: rise ? -0.2 : 0.1, lShoulder: 2.5, rShoulder: 2.7, lElbow: 0.3, rElbow: 0.3, lHip: 0.1, lKnee: 0.2, rHip: -0.1, rKnee: 0.25 });
  const bounce = (apex: number): Phase => {
    const dur = 2 * Math.sqrt((2 * apex) / TRAMP_GRAVITY);
    return {
      dur,
      flexible: false,
      at: (u) => {
        const h = apex * 4 * u * (1 - u);
        dip = u < 0.12 ? 10 * (1 - u / 0.12) : u > 0.88 ? 10 * ((u - 0.88) / 0.12) : 0;
        const p = air(tx(), surfaceHip - h + dip, u < 0.5);
        p.squash = u < 0.12 || u > 0.88 ? 0.85 : 1 + 0.08 * Math.sin(Math.PI * u);
        return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: [tramp(1)] } };
      },
    };
  };
  const hopOn = holdPhase(0.3, (u) => {
    const a = drawPhase.at(1).pose;
    const b = air(tx(), surfaceHip, true);
    const p = lerpPose(a, b, ease.inOut(u));
    p.y -= 20 * 4 * u * (1 - u);
    return { pose: p, props: { ink: [tramp(1)] } };
  });
  // The last bounce is a real ballistic arc: up past the panel top, down onto it just inside the edge.
  const landX = (): number => edgeX(c) - c.out * 16;
  const landPose = (): Pose => standOnTop(c, landX(), c.toward);
  const arc = (): { v0: number; T: number } => {
    const rise = surfaceHip - landPose().y;
    const apexH = Math.max(60, rise + 40);
    const v0 = Math.sqrt(2 * TRAMP_GRAVITY * apexH);
    return { v0, T: v0 / TRAMP_GRAVITY + Math.sqrt((2 * (apexH - rise)) / TRAMP_GRAVITY) };
  };
  const finalArc: Phase = {
    dur: arc().T,
    flexible: false,
    at: (u) => {
      dip = u < 0.12 ? 10 * (1 - u / 0.12) : 0;
      const end = landPose();
      if (u >= 1) {
        return { pose: end, props: { ink: [tramp(1)] } };
      }
      const { v0, T } = arc();
      const tau = u * T;
      const x = tx() + (end.x - tx()) * u;
      const y = surfaceHip - v0 * tau + 0.5 * TRAMP_GRAVITY * tau * tau;
      const p = u < 0.7 ? air(x, y, u < 0.55) : lerpPose(air(x, y, false), { ...end, x, y }, ease.inOut((u - 0.7) / 0.3));
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: [tramp(1)] } };
    },
  };
  return build('route-trampoline', c, [walk, drawPhase, hopOn, bounce(40), bounce(85), finalArc, ...topWalk(c, endOf(finalArc), () => [tramp(1)])]);
}

function balloonRoute(c: Ctx): Clip {
  // Too close to the viewport edge for the raised arm and the balloon: a few steps inward first.
  const sx = Math.min(c.stage.width - 45, Math.max(45, c.from.x));
  const stepIn = walkPhase(c, c.from.x, sx, 0.5);
  const start = standingAt(c, sx, c.toward);
  const floatTo = (): Vec => ({ x: c.seat().x, y: c.seat().y - 72 });
  const via = (): Vec => ({ x: edgeX(c) + c.out * 30, y: c.panel().top - 80 });
  const balloon = (hand: Vec, r: number): InkProp => {
    const bx = hand.x + 2;
    const by = hand.y - 22 - r;
    return ink(
      `M ${n(hand.x)} ${n(hand.y)} Q ${n(hand.x + 5)} ${n(hand.y - 11)} ${n(bx)} ${n(by + r)} M ${n(bx - r)} ${n(by)} a ${n(r)} ${n(r * 1.15)} 0 1 0 ${n(2 * r)} 0 a ${n(r)} ${n(r * 1.15)} 0 1 0 ${n(-2 * r)} 0`,
      1,
      c.lite,
    );
  };
  const handOf = (p: Pose): Vec => {
    const j = forwardKinematics(p);
    return { x: p.x + j.rHand.x, y: p.y + j.rHand.y };
  };
  const holdUp = (p: Pose): Pose => {
    const q = { ...p };
    const j = forwardKinematics(q);
    reach(q, 'r', { x: q.x + j.shoulder.x + c.toward * 6, y: q.y + j.shoulder.y - 30 }, -1);
    return q;
  };
  const inflate = holdPhase(0.8, (u) => {
    const p = holdUp(start);
    return { pose: p, expr: { mouth: 'o', brows: 0.3 }, props: { ink: [balloon(handOf(p), 3 + 11 * ease.out(u))] } };
  });
  const bez = (a: Vec, b: Vec, cc: Vec, u: number): Vec => ({
    x: (1 - u) * (1 - u) * a.x + 2 * (1 - u) * u * cc.x + u * u * b.x,
    y: (1 - u) * (1 - u) * a.y + 2 * (1 - u) * u * cc.y + u * u * b.y,
  });
  const floatPose = (x: number, y: number, t: number): Pose =>
    holdUp(pose({ x, y, facing: c.toward, torso: 0.04, head: -0.25, lShoulder: -0.2, lElbow: 0.3, lHip: 0.25 + 0.15 * Math.sin(t * 3), lKnee: 0.5, rHip: -0.05 + 0.15 * Math.sin(t * 3 + 1), rKnee: 0.7 }));
  const dist = Math.hypot(floatTo().x - start.x, floatTo().y - start.y);
  const float: Phase = {
    dur: Math.max(0.9, Math.min(2.4, dist / 110)),
    flexible: true,
    at: (u) => {
      const e = ease.inOut(u);
      const q = bez({ x: start.x, y: start.y - LIFT }, floatTo(), via(), e);
      const sway = 7 * Math.sin(u * 9) * Math.sin(Math.PI * u);
      const p = floatPose(q.x + sway, q.y, u * 3);
      return { pose: p, expr: { eyes: 'happy', mouth: 'smile' }, props: { ink: [balloon(handOf(p), 14)] } };
    },
  };
  const liftOff = holdPhase(0.2, (u) => {
    const p = lerpPose(inflate.at(1).pose, floatPose(start.x, start.y - LIFT, 0), u);
    return { pose: p, props: { ink: [balloon(handOf(p), 14)] } };
  });
  const pin = holdPhase(0.35, (u) => {
    const base = floatPose(floatTo().x, floatTo().y, 3);
    const hand = handOf(base);
    const p = { ...base };
    reach(p, 'l', { x: hand.x + 2 - 4 * (1 - u), y: hand.y - 30 * u }, -1);
    return { pose: p, expr: { mouth: 'flat', brows: 0.5 }, props: { ink: [balloon(hand, 14)] } };
  });
  const drop: Phase = {
    dur: 0.4,
    flexible: false,
    at: (u) => {
      const a = pin.at(1).pose;
      const b = seated(c);
      const p = lerpPose(unwrapToward(a, b), b, ease.inOut(u));
      p.y = a.y + (b.y - a.y) * ease.in(u);
      return { pose: p, expr: { mouth: 'o', eyes: 'wide', brows: 1 } };
    },
  };
  return build('route-balloon', c, [stepIn, inflate, liftOff, float, pin, drop], (s) => [
    { t: s[5], type: 'bang' },
    { t: s[5], type: 'sparkle', at: 'head' },
  ]);
}

function vaultRoute(c: Ctx): Clip {
  const plantX = (): number => edgeX(c) + c.out * 34;
  const runFrom = c.from.x;
  const runTo = (): number => plantX() + c.out * 40;
  const run = walkPhase(c, runFrom, runTo(), 1.0);
  const poleLen = (): number => Math.min(260, c.g - c.panel().top + 40);
  const poleInk = (a: Vec, b: Vec, alpha = 1): InkProp => ink(`M ${n(a.x)} ${n(a.y)} L ${n(b.x)} ${n(b.y)}`, 1, c.lite, alpha);
  const handsOf = (p: Pose): Vec => {
    const j = forwardKinematics(p);
    return { x: p.x + j.rHand.x, y: p.y + j.rHand.y };
  };
  const runPole: Phase = {
    ...run,
    at: (u) => {
      const f = run.at(u);
      const h = handsOf(f.pose);
      return { ...f, props: { ink: [poleInk(h, { x: h.x + c.toward * poleLen() * 0.8, y: h.y + 24 })] } };
    },
  };
  const tip = (): Vec => ({ x: plantX(), y: c.g });
  // Vault: the body swings around the planted tip, from leaning back to beyond vertical.
  const vault: Phase = {
    dur: 0.75,
    flexible: false,
    at: (u) => {
      const L = poleLen() - 30;
      const phi = -0.9 * c.toward + 1.15 * c.toward * ease.inOut(u);
      const top = { x: tip().x + L * Math.sin(phi), y: tip().y - L * Math.cos(phi) };
      const p = pose({ x: top.x, y: top.y + 30, facing: c.toward, rot: c.toward * 0.9 * Math.sin(Math.PI * u), torso: 0.2, head: -0.3, lHip: 1.2, lKnee: 0.6, rHip: 1.0, rKnee: 0.5 });
      reach(p, 'l', top, 1);
      reach(p, 'r', { x: top.x, y: top.y + 6 }, 1);
      return { pose: p, expr: { eyes: 'wide', mouth: 'grin' }, props: { ink: [poleInk(tip(), top)] } };
    },
  };
  // Long enough for the hands to travel up to the pole top without whipping.
  const toVault = holdPhase(Math.max(0.35, blendTravel(run.at(1).pose, vault.at(0).pose) / 380), (u) => ({ pose: lerpPose(run.at(1).pose, vault.at(0).pose, ease.inOut(u)), props: { ink: [poleInk(tip(), handsOf(vault.at(0).pose))] } }));
  const fly: Phase = {
    dur: 0.45,
    flexible: false,
    at: (u) => {
      const a = vault.at(1).pose;
      const b = seated(c);
      const p = lerpPose(unwrapToward(a, b), b, ease.inOut(u));
      p.y -= 26 * 4 * u * (1 - u);
      return { pose: p, expr: u > 0.7 ? { eyes: 'happy', mouth: 'smile' } : { mouth: 'o' }, props: { ink: [poleInk(tip(), { x: tip().x + c.out * 30 * u, y: tip().y - poleLen() * (1 - 0.4 * u) }, 1 - u)] } };
    },
  };
  void fly;
  const standingPole = (): InkProp[] => [poleInk(tip(), { x: tip().x, y: tip().y - poleLen() }, 0.7)];
  return build('route-vault', c, [runPole, toVault, vault, ...topWalk(c, endOf(vault), standingPole)]);
}

function stairsRoute(c: Ctx): Clip {
  const height = (): number => c.g - c.panel().top;
  // At most 9 steps: a tall panel gets taller steps rather than a long staircase.
  const count = stairSteps(c.g - c.panel().top);
  const width = STEP_WIDTH;
  // Step k (0 = lowest) sits outside the panel, the last one right at the edge.
  const stepTop = (k: number): Vec => ({ x: edgeX(c) + c.out * (width * (count - k) - width / 2), y: c.g - (height() * (k + 1)) / (count + 1) });
  const startX = (): number => stepTop(0).x + c.out * 30;
  const walk = walkPhase(c, c.from.x, startX());
  const stairsInk = (drawnUpTo: number): InkProp[] => {
    const out: InkProp[] = [];
    for (let k = 0; k < count; k++) {
      const draw = Math.max(0, Math.min(1, drawnUpTo - k));
      if (draw <= 0) {
        break;
      }
      const s = stepTop(k);
      out.push(ink(`M ${n(s.x - width / 2)} ${n(c.g)} L ${n(s.x - width / 2)} ${n(s.y)} L ${n(s.x + width / 2)} ${n(s.y)} L ${n(s.x + width / 2)} ${n(c.g)}`, draw, c.lite));
    }
    return out;
  };
  const standOn = (x: number, surface: number): Pose => ({ ...standingAt(c, x, c.toward), y: surface - (BONES.thigh + BONES.shin) + 2 });
  const hops: Phase[] = [];
  for (let k = 0; k <= count; k++) {
    const kk = k;
    hops.push({
      dur: Math.min(0.26, 2 / (count + 1)),
      flexible: false,
      at: (u) => {
        const from = kk === 0 ? { x: startX(), y: c.g } : stepTop(kk - 1);
        const to = kk === count ? { x: edgeX(c) - c.out * 14, y: c.panel().top } : stepTop(kk);
        const a = standOn(from.x, from.y);
        const b = standOn(to.x, to.y);
        const p = lerpPose(a, b, u);
        p.y -= 12 * 4 * u * (1 - u);
        if (u > 0.2 && u < 0.8) {
          p.lKnee += 0.5;
          p.rKnee += 0.5;
        }
        return { pose: p, expr: { mouth: 'smile', brows: 0.2 }, props: { ink: stairsInk(kk + 0.4 + u) } };
      },
    });
  }
  const last = hops[hops.length - 1];
  const walkInk: Phase = { ...walk, at: (u) => ({ ...walk.at(u), props: { ink: stairsInk(u * 0.4) } }) };
  return build('route-stairs', c, [walkInk, ...hops, ...topWalk(c, endOf(last), () => stairsInk(count))]);
}

/** Builds the chosen route from the current pose. */
export function createRouteClip(route: RouteAction, cc: ClipContext, lite: boolean): Clip {
  const stage = cc.stage;
  const panel = cc.panelNow ?? ((): PanelRect => panelOf(stage));
  const seat = cc.seatNow ?? ((): Vec => stage.seat ?? { x: (panel().left + panel().right) / 2, y: panel().top });
  const side = approachSide(cc.from.x, panel(), stage.width);
  const out: 1 | -1 = side === 'left' ? -1 : 1;
  const c: Ctx = { stage, from: cc.from, g: stage.ground, seat, panel, side, out, toward: (-out as 1 | -1), lite };
  switch (route) {
    case 'route-hop':
      return hopRoute(c);
    case 'route-climb':
      return climbRoute(c);
    case 'route-ladder':
      return ladderRoute(c);
    case 'route-rope':
      return ropeRoute(c);
    case 'route-trampoline':
      return trampolineRoute(c);
    case 'route-balloon':
      return balloonRoute(c);
    case 'route-vault':
      return vaultRoute(c);
    case 'route-stairs':
      return stairsRoute(c);
  }
}

import { Clip, ClipEvent, ClipFrame, EntranceAction, ExitAction, InkProp, Stage, WALK, breathing, ease, gaitPose, plant, reach, segment, standAt, standHip } from './animations';
import { FigureMask } from './mask';
import { Phase, buildPhases, holdPhase, ink, n } from './phases';
import { Pose, Vec, handPos, headPos, shoulderPos } from './skeleton';

/**
 * In-scene exits and entrances: instead of walking past the viewport edge he leaves through something drawn in ink
 * right where he is — a door, a manhole, a portal, an elevator, a trapdoor, or he simply erases himself. The figure is
 * clipped (mask.ts) so he visibly disappears INTO the doorway or the hole. Some exits mirror into entrances.
 * Every clip starts from the displayed pose (phase runner), props are drawn on and erased/faded at the end.
 */

export type SceneEntrance = Extract<EntranceAction, 'enter-door' | 'enter-hatch' | 'enter-portal'>;
export type SceneAction = ExitAction | SceneEntrance;

export interface SceneContext {
  stage: Stage;
  from: Pose;
  lite: boolean;
  rng: () => number;
  /** Entrances: where he ends up standing. */
  targetX: number;
  /** Entrances: the edge he comes from (he faces away from it). */
  side: 'left' | 'right';
}

export interface ExitInfo {
  weight: number;
  /** Allowed in lite mode (plain door and hatch, no draw-on). */
  lite: boolean;
}

export const EXIT_INFO: Readonly<Record<ExitAction, ExitInfo>> = {
  'exit-door': { weight: 3, lite: true },
  'exit-hatch': { weight: 2.5, lite: true },
  'exit-portal': { weight: 1.5, lite: false },
  'exit-elevator': { weight: 1.5, lite: false },
  'exit-trapdoor': { weight: 1, lite: false },
  'exit-erase': { weight: 1, lite: false },
};

export const SCENE_ENTRANCES: readonly SceneEntrance[] = ['enter-door', 'enter-hatch', 'enter-portal'];
export const SCENE_ACTIONS: readonly SceneAction[] = [...(Object.keys(EXIT_INFO) as ExitAction[]), ...SCENE_ENTRANCES];

export function isSceneAction(action: string): action is SceneAction {
  return action in EXIT_INFO || (SCENE_ENTRANCES as readonly string[]).includes(action);
}

/* ───────────── shared ───────────── */

interface Mark {
  i: number;
  off: number;
  ev: Omit<ClipEvent, 't'>;
}

/** Build state of one clip. */
interface S {
  s: Stage;
  g: number;
  f: 1 | -1;
  lite: boolean;
  rng: () => number;
  phases: Phase[];
  marks: Mark[];
}

const TAU = Math.PI * 2;
const DOOR_HALF = 22;
const DOOR_H = 118;
const DOOR_OPEN = 1.35;
const LIFT_HALF = 25;
const LIFT_H = 120;
const LIFT_GAP = 23;
const HOLE_RX = 26;
const HOLE_RY = 7;
/** How deep he climbs/falls below the floor: the head ends below the rim. */
const DEEP = 140;
const PORTAL_R = 40;
const NO_INK: InkProp[] = [];

const clampX = (s: Stage, x: number, m: number): number => Math.min(s.width - m, Math.max(m, x));
const circle = (x: number, y: number, r: number): string => `M ${n(x - r)} ${n(y)} a ${n(r)} ${n(r)} 0 1 0 ${n(2 * r)} 0 a ${n(r)} ${n(r)} 0 1 0 ${n(-2 * r)} 0`;
const ellipse = (x: number, y: number, rx: number, ry: number): string => `M ${n(x - rx)} ${n(y)} A ${n(rx)} ${n(ry)} 0 1 0 ${n(x + rx)} ${n(y)} A ${n(rx)} ${n(ry)} 0 1 0 ${n(x - rx)} ${n(y)} Z`;
const mix = (a: number, b: number, t: number): number => a + (b - a) * t;

function push(c: S, p: Phase): void {
  c.phases.push(p);
}

/** An event `off` seconds into the phase pushed next. */
function mark(c: S, off: number, ev: Omit<ClipEvent, 't'>): void {
  c.marks.push({ i: c.phases.length, off, ev });
}

/** Walks between two floor points (skipped when already there); props stay as given. */
function walk(c: S, xa: number, xb: number, props: InkProp[], expr: ClipFrame['expr'] = { mouth: 'smile' }): void {
  const dist = Math.abs(xb - xa);
  if (dist < 2) {
    return;
  }
  const f: 1 | -1 = xb > xa ? 1 : -1;
  const frameProps = { ink: props };
  push(
    c,
    holdPhase(Math.max(0.3, dist / 100), (u) => ({ pose: gaitPose(c.s, mix(xa, xb, u), dist * u, f, WALK), expr, props: frameProps })),
  );
}

/** Draw-on of the props while he watches (lite: a quick fade-in instead). */
function drawOn(c: S, x: number, f: 1 | -1, look: Vec, props: (draw: number, alpha: number, t: number) => InkProp[]): void {
  const dur = drawTime(c);
  push(
    c,
    holdPhase(dur, (u) => {
      const p = breathing(c.s, x, f, u);
      const hx = headPos(p);
      return {
        pose: p,
        expr: { mouth: 'smile', brows: 0.3 },
        eyeDir: { x: Math.sign(look.x - hx.x) * 0.8, y: look.y > hx.y ? 0.6 : -0.4 },
        props: { ink: props(ease.out(u), c.lite ? u : 1, u * dur) },
      };
    }),
  );
}

/** The props go away at the end: erased stroke by stroke (lite: faded), then nothing is left. */
const drawTime = (c: S): number => (c.lite ? 0.3 : 0.9);

function eraseProps(c: S, pose: Pose, mask: FigureMask | null, props: (draw: number, alpha: number) => InkProp[], dur = 0.5): void {
  push(
    c,
    holdPhase(dur, (u) => ({
      pose,
      mask: mask ?? undefined,
      props: { ink: u >= 1 ? NO_INK : props(c.lite ? 1 : 1 - ease.inOut(u), c.lite ? 1 - u : 1 - u * u) },
    })),
  );
}

function crouchAt(c: S, x: number, f: 1 | -1, depth: number, torso: number): Pose {
  const p = standAt(c.s, x, f, { y: standHip(c.s) + depth, torso, head: -torso * 0.5 });
  plant(p, 'l', { x: x - f * 3, y: c.g });
  plant(p, 'r', { x: x + f * 5, y: c.g });
  return p;
}

function waveArm(p: Pose, t: number, k: number): void {
  p.rShoulder = mix(p.rShoulder, 2.7, k);
  p.rElbow = mix(p.rElbow, 0.5 + 0.45 * Math.sin(t * 13), k);
}

/** Holding a ladder inside a hole, `depth` px below standing; `cyc` drives the alternating limbs. */
function climbPose(c: S, x: number, f: 1 | -1, depth: number, cyc: number): Pose {
  const p = standAt(c.s, x, f, { y: standHip(c.s) + depth, torso: 0.08, head: -0.15 });
  const sw = Math.sin(cyc);
  p.lHip = 0.55 + 0.4 * sw;
  p.lKnee = 0.9 + 0.45 * sw;
  p.rHip = 0.55 - 0.4 * sw;
  p.rKnee = 0.9 - 0.45 * sw;
  const sh = shoulderPos(p);
  reach(p, 'l', { x: x + f * 7, y: sh.y + 16 + 5 * sw }, 1);
  reach(p, 'r', { x: x + f * 9, y: sh.y + 16 - 5 * sw }, 1);
  return p;
}

const holeMask = (cx: number, cy: number, rx = HOLE_RX, ry = HOLE_RY): FigureMask => ({ kind: 'hole', cx, cy, rx, ry });

/* ───────────── door ───────────── */

interface Door {
  dx: number;
  g: number;
  /** Hinge side (+1: right post). The open panel sticks out on that side. */
  h: 1 | -1;
  frame: string;
  closed: string;
}

function makeDoor(dx: number, g: number, h: 1 | -1): Door {
  const frame = `M ${n(dx - DOOR_HALF)} ${n(g)} L ${n(dx - DOOR_HALF)} ${n(g - DOOR_H)} L ${n(dx + DOOR_HALF)} ${n(g - DOOR_H)} L ${n(dx + DOOR_HALF)} ${n(g)} M ${n(dx - DOOR_HALF - 9)} ${n(g)} L ${n(dx + DOOR_HALF + 9)} ${n(g)}`;
  const door: Door = { dx, g, h, frame, closed: '' };
  door.closed = doorPanel(door, 0);
  return door;
}

/** x of the panel's free edge: on the far post when closed, swinging toward the hinge as it opens. */
function doorFree(d: Door, th: number): number {
  return d.dx + d.h * DOOR_HALF - d.h * 2 * DOOR_HALF * Math.cos(th);
}

/** The panel in perspective (2 px inside the frame): the free edge comes toward the viewer (taller) as it swings open. */
function doorPanel(d: Door, th: number): string {
  const half = DOOR_HALF - 2.5;
  const xh = d.dx + d.h * half;
  const xf = xh - d.h * 2 * half * Math.cos(th);
  const k = Math.sin(th);
  const top = d.g - DOOR_H + 3;
  const kx = xh + (xf - xh) * 0.85;
  return `M ${n(xh)} ${n(top)} L ${n(xf)} ${n(top - 7 * k)} L ${n(xf)} ${n(d.g + 3 * k)} L ${n(xh)} ${n(d.g)} Z ${circle(kx, d.g - 58, 1.8)}`;
}

/** The open part of the doorway (the figure stays visible only there once he is inside). */
function doorWindow(d: Door, th: number): FigureMask {
  const xf = doorFree(d, th);
  const near = d.dx - d.h * DOOR_HALF;
  return { kind: 'rect', x0: Math.min(xf, near), y0: d.g - DOOR_H, x1: Math.max(xf, near), y1: d.g + 8 };
}

function doorInk(d: Door, th: number, draw = 1, alpha = 1, lite = false): InkProp[] {
  if (th < 0.002) {
    return [ink(d.frame, draw, lite, alpha), ink(d.closed, draw, lite, alpha)];
  }
  const near = d.dx - d.h * DOOR_HALF;
  const xf = doorFree(d, th);
  let dark = '';
  for (let i = 1; i <= 3; i++) {
    const x = near + ((xf - near) * i) / 4;
    dark += `M ${n(x)} ${n(d.g - DOOR_H + 10)} L ${n(x - d.h * 5)} ${n(d.g - 5)} `;
  }
  return [ink(d.frame, draw, lite, alpha), ink(doorPanel(d, th), draw, lite, alpha), ink(dark, 1, lite, 0.3 * Math.sin(th) * alpha)];
}

function exitDoor(c: S, x0: number): void {
  const { g, f } = c;
  const dx = clampX(c.s, x0 + f * 62, 40);
  const h: 1 | -1 = dx >= x0 ? 1 : -1;
  const d = makeDoor(dx, g, h);
  // Inside, he stands in the middle of the open part of the doorway.
  const spot = dx - h * 4;
  const knock = c.rng() < 0.35;
  const peek = c.rng() < 0.4;
  const closedInk = doorInk(d, 0, 1, 1, c.lite);
  const openInk = doorInk(d, DOOR_OPEN, 1, 1, c.lite);
  const inside = standAt(c.s, spot, h, { y: standHip(c.s) - 3 });

  drawOn(c, x0, f, { x: dx, y: g - 60 }, (draw, alpha) => doorInk(d, 0, draw, alpha, c.lite));
  walk(c, x0, spot, closedInk);
  if (knock) {
    const knockAt = { x: spot + h * 15, y: g - 68 };
    mark(c, 0.3, { type: 'ring', point: knockAt });
    mark(c, 0.55, { type: 'ring', point: knockAt });
    push(
      c,
      holdPhase(1, (u) => {
        const p = breathing(c.s, spot, h, u);
        const up = ease.out(segment(u, 0, 0.25)) * (1 - ease.inOut(segment(u, 0.75, 1)));
        const tap = Math.max(0, Math.sin(u * 4 * TAU)) * segment(u, 0.2, 0.65) * (1 - segment(u, 0.6, 0.65));
        const q = { ...p };
        reach(q, 'r', { x: knockAt.x - h * 5 * tap, y: knockAt.y }, 1);
        p.rShoulder = mix(p.rShoulder, q.rShoulder, up);
        p.rElbow = mix(p.rElbow, q.rElbow, up);
        return { pose: p, expr: { mouth: 'flat', brows: 0.4 }, props: { ink: closedInk } };
      }),
    );
  }
  push(
    c,
    holdPhase(0.7, (u) => ({
      pose: breathing(c.s, spot, h, u),
      expr: { mouth: 'smile', brows: 0.3 },
      props: { ink: doorInk(d, DOOR_OPEN * ease.out(u), 1, 1, c.lite) },
    })),
  );
  // A step into the doorway (a little up = deeper into the scene); from here the doorway clips him.
  const win = doorWindow(d, DOOR_OPEN);
  push(
    c,
    holdPhase(0.55, (u) => {
      const p = standAt(c.s, spot, h, { y: standHip(c.s) - 3 * ease.inOut(u) });
      const lift = Math.sin(u * Math.PI);
      p.lHip += 0.5 * lift;
      p.lKnee += 0.8 * lift;
      return { pose: p, expr: { mouth: 'smile' }, mask: win, props: { ink: openInk } };
    }),
  );
  let last = inside;
  if (peek) {
    // Peeks back out of the doorway and waves before closing it.
    const back = standAt(c.s, spot, -h as 1 | -1, { y: standHip(c.s) - 3 });
    const waving = (t: number): Pose => {
      const p = { ...back };
      waveArm(p, t, ease.out(segment(t, 0, 0.25)));
      return p;
    };
    push(c, holdPhase(1.1, (u) => ({ pose: waving(u * 1.1), expr: { eyes: 'happy', mouth: 'grin' }, mask: win, props: { ink: openInk } })));
    last = waving(1.1);
  }
  const final = last;
  mark(c, 0.55, { type: 'dust', point: { x: dx, y: g }, strength: 0.2 });
  push(
    c,
    holdPhase(0.55, (u) => {
      const th = DOOR_OPEN * (1 - ease.in(u));
      return { pose: final, expr: { eyes: 'happy', mouth: 'grin' }, mask: doorWindow(d, th), props: { ink: doorInk(d, th, 1, 1, c.lite) } };
    }),
  );
  eraseProps(c, final, doorWindow(d, 0), (draw, alpha) => doorInk(d, 0, draw, alpha, c.lite));
}

function enterDoor(c: S, tx: number): void {
  const { g, f } = c;
  const dx = clampX(c.s, tx - f * 48, 40);
  // Hinge on the side he does not walk to: the open panel is out of his way.
  const h = -f as 1 | -1;
  const d = makeDoor(dx, g, h);
  const spot = dx + f * 4;
  const inside = standAt(c.s, spot, f, { y: standHip(c.s) - 3 });
  const closedMask = doorWindow(d, 0);
  const openInk = doorInk(d, DOOR_OPEN, 1, 1, c.lite);
  const end = standAt(c.s, tx, f);

  push(c, holdPhase(drawTime(c), (u) => ({ pose: inside, mask: closedMask, props: { ink: doorInk(d, 0, ease.out(u), c.lite ? u : 1, c.lite) } })));
  if (c.rng() < 0.3) {
    // Knock-knock from the other side first.
    const knockAt = { x: dx, y: g - 66 };
    mark(c, 0.15, { type: 'ring', point: knockAt });
    mark(c, 0.4, { type: 'ring', point: knockAt });
    push(c, holdPhase(0.7, () => ({ pose: inside, mask: closedMask, props: { ink: doorInk(d, 0, 1, 1, c.lite) } })));
  }
  push(
    c,
    holdPhase(0.7, (u) => {
      const th = DOOR_OPEN * ease.out(u);
      return { pose: inside, expr: { eyes: 'happy', mouth: 'grin' }, mask: doorWindow(d, th), props: { ink: doorInk(d, th, 1, 1, c.lite) } };
    }),
  );
  // Steps out toward the viewer: no longer behind the door frame.
  push(c, holdPhase(0.4, (u) => ({ pose: standAt(c.s, spot, f, { y: standHip(c.s) - 3 * (1 - ease.inOut(u)) }), expr: { mouth: 'smile' }, props: { ink: openInk } })));
  walk(c, spot, tx, openInk);
  push(
    c,
    holdPhase(0.5, (u) => {
      const th = DOOR_OPEN * (1 - ease.in(u));
      return { pose: end, expr: { mouth: 'smile' }, eyeDir: { x: -f, y: 0 }, props: { ink: doorInk(d, th, 1, 1, c.lite) } };
    }),
  );
  eraseProps(c, end, null, (draw, alpha) => doorInk(d, 0, draw, alpha, c.lite));
}

/* ───────────── manhole hatch ───────────── */

interface Hatch {
  hx: number;
  cy: number;
  rim: string;
  inside: string;
}

function makeHatch(hx: number, g: number): Hatch {
  const cy = g - 8;
  const rim = ellipse(hx, cy, HOLE_RX, HOLE_RY);
  const ladder = `M ${n(hx - 8)} ${n(cy - 15)} L ${n(hx - 8)} ${n(cy + 6)} M ${n(hx + 8)} ${n(cy - 15)} L ${n(hx + 8)} ${n(cy + 6)} M ${n(hx - 8)} ${n(cy - 9)} L ${n(hx + 8)} ${n(cy - 9)} M ${n(hx - 8)} ${n(cy - 2)} L ${n(hx + 8)} ${n(cy - 2)}`;
  const dark = `M ${n(hx - 19)} ${n(cy + 2)} Q ${n(hx)} ${n(cy + 7)} ${n(hx + 19)} ${n(cy + 2)} M ${n(hx - 12)} ${n(cy - 2)} Q ${n(hx)} ${n(cy + 2)} ${n(hx + 12)} ${n(cy - 2)}`;
  return { hx, cy, rim, inside: `${ladder} ${dark}` };
}

function lid(x: number, y: number, ry: number): string {
  return `${ellipse(x, y, HOLE_RX - 1, ry)} M ${n(x - 15)} ${n(y)} L ${n(x + 15)} ${n(y)}`;
}

function hatchInk(hh: Hatch, lidD: string, open: number, draw = 1, alpha = 1, lite = false): InkProp[] {
  const out = [ink(hh.rim, draw, lite, alpha), ink(lidD, draw, lite, alpha)];
  if (open > 0.01) {
    out.push(ink(hh.inside, 1, lite, open * alpha));
  }
  return out;
}

function exitHatch(c: S, x0: number): void {
  const { g, f } = c;
  const hh = makeHatch(clampX(c.s, x0 + f * 34, 40), g);
  const { hx, cy } = hh;
  const lx = clampX(c.s, x0 - f * 46, 30);
  const closedLid = lid(hx, cy, HOLE_RY);
  const asideLid = lid(lx, cy, HOLE_RY);
  const openInk = hatchInk(hh, asideLid, 1, 1, 1, c.lite);

  drawOn(c, x0, f, { x: hx, y: cy }, (draw, alpha) => hatchInk(hh, closedLid, 0, draw, alpha, c.lite));
  // Crouches and lifts the lid by its near edge.
  const lidUp = (u: number): { y: number; ry: number } => ({ y: cy - 22 * ease.inOut(segment(u, 0.35, 1)), ry: mix(HOLE_RY, 18, ease.inOut(segment(u, 0.35, 1))) });
  const lifting = (u: number): Pose => {
    const k = ease.inOut(segment(u, 0, 0.4));
    const p = crouchAt(c, x0, f, 20 * k, 0.7 * k);
    const l = lidUp(u);
    const grip = { x: hx - f * 21, y: l.y + 3 };
    const q = { ...p };
    reach(q, 'r', grip, 1);
    reach(q, 'l', { x: grip.x - f * 2, y: grip.y + 2 }, 1);
    p.rShoulder = mix(p.rShoulder, q.rShoulder, k);
    p.rElbow = mix(p.rElbow, q.rElbow, k);
    p.lShoulder = mix(p.lShoulder, q.lShoulder, k);
    p.lElbow = mix(p.lElbow, q.lElbow, k);
    return p;
  };
  push(
    c,
    holdPhase(1, (u) => {
      const l = lidUp(u);
      return { pose: lifting(u), expr: { mouth: 'flat', brows: 0.5 }, eyeDir: { x: f * 0.5, y: 0.8 }, props: { ink: hatchInk(hh, lid(hx, l.y, l.ry), segment(u, 0.4, 1), 1, 1, c.lite) } };
    }),
  );
  // Tosses it over his shoulder; it lands flat behind him.
  const liftEnd = lifting(1);
  const standing = standAt(c.s, x0, f);
  mark(c, 0.55, { type: 'dust', point: { x: lx, y: g }, strength: 0.3 });
  push(
    c,
    holdPhase(0.55, (u) => {
      const p = { ...standing };
      const k = ease.inOut(u);
      const arms = Math.sin(u * Math.PI);
      for (const key of ['y', 'torso', 'head', 'lHip', 'lKnee', 'rHip', 'rKnee', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow'] as const) {
        p[key] = mix(liftEnd[key], standing[key], k);
      }
      p.lShoulder += 2.2 * arms;
      p.rShoulder += 2.4 * arms;
      const ly = mix(cy - 22, cy, u) - 110 * u * (1 - u);
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: hatchInk(hh, lid(mix(hx, lx, u), ly, mix(18, HOLE_RY, u)), 1, 1, 1, c.lite) } };
    }),
  );
  walk(c, x0, hx, openInk);
  // Down the ladder; the rim hides everything below it.
  const mask = holeMask(hx, cy);
  const climb = (u: number): Pose => climbPose(c, hx, f, DEEP * u, u * 6 * Math.PI);
  push(c, holdPhase(2.2, (u) => ({ pose: climb(u), expr: { eyes: 'happy', mouth: 'grin' }, mask, props: { ink: openInk } })));
  const gone = climb(1);
  mark(c, 0.7, { type: 'dust', point: { x: hx, y: g }, strength: 0.2 });
  push(
    c,
    holdPhase(0.7, (u) => {
      const k = ease.inOut(u);
      return { pose: gone, mask, props: { ink: hatchInk(hh, lid(mix(lx, hx, k), cy, HOLE_RY), 1 - k, 1, 1, c.lite) } };
    }),
  );
  eraseProps(c, gone, mask, (draw, alpha) => hatchInk(hh, closedLid, 0, draw, alpha, c.lite));
}

function enterHatch(c: S, tx: number): void {
  const { g, f } = c;
  const hh = makeHatch(clampX(c.s, tx - f * 36, 40), g);
  const { hx, cy } = hh;
  const lx = clampX(c.s, hx - f * 52, 30);
  const closedLid = lid(hx, cy, HOLE_RY);
  const openInk = hatchInk(hh, lid(lx, cy, HOLE_RY), 1, 1, 1, c.lite);
  const mask = holeMask(hx, cy);
  const climb = (u: number): Pose => climbPose(c, hx, f, DEEP * (1 - u), u * 6 * Math.PI);
  const deep = climb(0);
  const end = standAt(c.s, tx, f);

  push(c, holdPhase(drawTime(c), (u) => ({ pose: deep, mask, props: { ink: hatchInk(hh, closedLid, 0, ease.out(u), c.lite ? u : 1, c.lite) } })));
  // The lid is pushed up from below and slides aside.
  mark(c, 0.7, { type: 'dust', point: { x: lx, y: g }, strength: 0.25 });
  push(
    c,
    holdPhase(0.7, (u) => {
      const k = ease.inOut(u);
      return { pose: deep, mask, props: { ink: hatchInk(hh, lid(mix(hx, lx, k), cy - 10 * Math.sin(u * Math.PI), HOLE_RY), k, 1, 1, c.lite) } };
    }),
  );
  push(c, holdPhase(2, (u) => ({ pose: climb(u), expr: { eyes: 'happy', mouth: 'grin' }, mask, props: { ink: openInk } })));
  walk(c, hx, tx, openInk);
  mark(c, 0.6, { type: 'dust', point: { x: hx, y: g }, strength: 0.2 });
  push(
    c,
    holdPhase(0.6, (u) => {
      const k = ease.inOut(u);
      return { pose: end, eyeDir: { x: -f, y: 0.5 }, props: { ink: hatchInk(hh, lid(mix(lx, hx, k), cy, HOLE_RY), 1 - k, 1, 1, c.lite) } };
    }),
  );
  eraseProps(c, end, null, (draw, alpha) => hatchInk(hh, closedLid, 0, draw, alpha, c.lite));
}

/* ───────────── portal ───────────── */

/** Swirl paths cached by quantized radius and angle (it spins every frame). */
const SPIRAL_CACHE = new Map<string, string>();

function spiral(cx: number, cy: number, r: number, a: number): string {
  const qa = Math.round(((a % TAU) + TAU) % TAU / (TAU / 48));
  const key = `${n(cx)}|${n(cy)}|${Math.round(r)}|${qa}`;
  let d = SPIRAL_CACHE.get(key);
  if (d === undefined) {
    const ang0 = (qa * TAU) / 48;
    const rr = Math.round(r);
    d = '';
    for (let k = 0; k <= 44; k++) {
      const t = k / 44;
      const ang = ang0 + t * 2.6 * TAU;
      d += `${k === 0 ? 'M' : 'L'} ${n(cx + Math.cos(ang) * rr * t)} ${n(cy + Math.sin(ang) * rr * t)} `;
    }
    if (SPIRAL_CACHE.size > 400) {
      SPIRAL_CACHE.clear();
    }
    SPIRAL_CACHE.set(key, d);
  }
  return d;
}

function portalInk(cx: number, cy: number, r: number, a: number, draw: number, alpha: number, lite: boolean): InkProp[] {
  if (r < 0.5) {
    return NO_INK;
  }
  return [ink(spiral(cx, cy, r, a), draw, lite, alpha), ink(circle(cx, cy, r), draw, lite, alpha * 0.5)];
}

/** Tucked ball, spinning, with the head moved onto `head`. */
function tucked(c: S, f: 1 | -1, rot: number, head: Vec): Pose {
  const p = standAt(c.s, 0, f, { y: 0, rot, torso: 0.6, head: -0.3, lHip: 1.6, lKnee: 2.2, rHip: 1.5, rKnee: 2.1, lShoulder: 1.2, lElbow: 1.4, rShoulder: 1.3, rElbow: 1.4 });
  const h = headPos(p);
  p.x = head.x - h.x;
  p.y = head.y - h.y;
  return p;
}

function exitPortal(c: S, x0: number): void {
  const { g, f } = c;
  const cx = clampX(c.s, x0 + f * 58, 50);
  const cy = g - 70;
  const spin = (t: number): number => t * 2.4;
  drawOn(c, x0, f, { x: cx, y: cy }, (draw, alpha, t) => portalInk(cx, cy, PORTAL_R, spin(t), draw, alpha, c.lite));
  const start = cx - f * 30;
  // The swirl keeps turning: every phase draws it at the time since the draw-on started.
  const dist = Math.abs(start - x0);
  let t0 = drawTime(c);
  if (dist >= 2) {
    const wf: 1 | -1 = start > x0 ? 1 : -1;
    const dur = Math.max(0.3, dist / 100);
    const tw = t0;
    push(c, holdPhase(dur, (u) => ({ pose: gaitPose(c.s, mix(x0, start, u), dist * u, wf, WALK), expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: portalInk(cx, cy, PORTAL_R, spin(tw + u * dur), 1, 1, c.lite) } })));
    t0 += dur;
  }
  const tc = t0;
  const crouch = crouchAt(c, start, f, 12, 0.4);
  push(c, holdPhase(0.35, (u) => ({ pose: crouch, expr: { eyes: 'happy', mouth: 'grin', brows: -0.3 }, props: { ink: portalInk(cx, cy, PORTAL_R, spin(tc + u * 0.35), 1, 1, c.lite) } })));
  // Dives in: spins, shrinks into the centre, clipped by the swirl's disc.
  const td = tc + 0.35;
  const crouchHead = headPos(crouch);
  // The disc closes in on him as he dives (nothing is cut off at the rim while he is still outside).
  const diveMask = (u: number): FigureMask => {
    const r = PORTAL_R + 90 * (1 - segment(u, 0, 0.6)) ** 2;
    return { kind: 'ellipse', cx, cy, rx: r, ry: r };
  };
  const dive = (u: number): Pose => {
    const k = ease.in(u);
    const p = tucked(c, f, f * 3 * Math.PI * k, { x: mix(crouchHead.x, cx, k), y: mix(crouchHead.y, cy, k) - 16 * Math.sin(u * Math.PI) });
    // The first moment blends from the crouch into the tuck.
    if (u < 0.25) {
      const b = ease.inOut(u / 0.25);
      for (const key of ['torso', 'head', 'lHip', 'lKnee', 'rHip', 'rKnee', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow'] as const) {
        p[key] = mix(crouch[key], p[key], b);
      }
      const h = headPos(p);
      const want = { x: mix(crouchHead.x, cx, k), y: mix(crouchHead.y, cy, k) - 16 * Math.sin(u * Math.PI) };
      p.x += want.x - h.x;
      p.y += want.y - h.y;
    }
    return p;
  };
  push(
    c,
    holdPhase(0.8, (u) => ({
      pose: dive(u),
      expr: { eyes: 'happy', mouth: 'grin' },
      scale: 1 - ease.in(u),
      mask: diveMask(u),
      props: { ink: portalInk(cx, cy, PORTAL_R, spin(td + u * 0.8), 1, 1, c.lite) },
    })),
  );
  const gone = dive(1);
  const tz = td + 0.8;
  mark(c, 0.6, { type: 'sparkle', point: { x: cx, y: cy + 10 } });
  push(
    c,
    holdPhase(0.6, (u) => {
      const r = PORTAL_R * (1 - ease.in(u));
      return { pose: gone, scale: 0, mask: { kind: 'ellipse', cx, cy, rx: r, ry: r }, props: { ink: u >= 1 ? NO_INK : portalInk(cx, cy, r, spin(tz + u * 0.6) * (1 + u * 2), 1, 1 - u * 0.5, c.lite) } };
    }),
  );
}

function enterPortal(c: S, tx: number): void {
  const { g, f } = c;
  const cx = clampX(c.s, tx - f * 40, 50);
  const cy = g - 70;
  const spin = (t: number): number => -t * 2.4;
  const end = standAt(c.s, tx, f);
  const land = crouchAt(c, tx, f, 12, 0.35);
  const landHead = headPos(land);
  const hidden = tucked(c, f, -f * 2 * Math.PI, { x: cx, y: cy });
  const t1 = drawTime(c);
  push(
    c,
    holdPhase(t1, (u) => {
      const r = PORTAL_R * ease.out(u);
      return { pose: hidden, scale: 0, mask: { kind: 'ellipse', cx, cy, rx: r, ry: r }, props: { ink: portalInk(cx, cy, r, spin(u * t1), c.lite ? 1 : ease.out(u), c.lite ? u : 1, c.lite) } };
    }),
  );
  // Pops out spinning and growing, lands beside the swirl.
  const pop = (u: number): Pose => {
    const k = ease.out(u);
    const head = { x: mix(cx, landHead.x, k), y: mix(cy, landHead.y, k) - 34 * Math.sin(u * Math.PI) };
    const p = tucked(c, f, -f * 2 * Math.PI * (1 - k), head);
    const b = ease.inOut(segment(u, 0.6, 1));
    for (const key of ['torso', 'head', 'lHip', 'lKnee', 'rHip', 'rKnee', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow'] as const) {
      p[key] = mix(p[key], land[key], b);
    }
    const h = headPos(p);
    p.x += head.x - h.x;
    p.y += head.y - h.y;
    return p;
  };
  mark(c, 0.75, { type: 'dust', at: 'feet', strength: 0.35 });
  push(
    c,
    holdPhase(0.75, (u) => {
      const r = PORTAL_R + 300 * u * u;
      return {
        pose: pop(u),
        expr: { eyes: 'happy', mouth: 'grin' },
        scale: ease.out(Math.min(1, u * 1.3)),
        mask: u < 1 ? { kind: 'ellipse', cx, cy, rx: r, ry: r } : undefined,
        props: { ink: portalInk(cx, cy, PORTAL_R, spin(t1 + u * 0.75), 1, 1, c.lite) },
      };
    }),
  );
  const tl = t1 + 0.75;
  push(c, holdPhase(0.4, (u) => ({ pose: lerpTo(land, end, ease.out(u)), expr: { mouth: 'smile' }, props: { ink: portalInk(cx, cy, PORTAL_R, spin(tl + u * 0.4), 1, 1, c.lite) } })));
  const tz = tl + 0.4;
  mark(c, 0.6, { type: 'sparkle', point: { x: cx, y: cy + 10 } });
  push(
    c,
    holdPhase(0.6, (u) => {
      const r = PORTAL_R * (1 - ease.in(u));
      return { pose: end, eyeDir: { x: -f, y: -0.3 }, props: { ink: u >= 1 ? NO_INK : portalInk(cx, cy, r, spin(tz + u * 0.6) * (1 + u * 2), 1, 1 - u * 0.5, c.lite) } };
    }),
  );
}

function lerpTo(a: Pose, b: Pose, k: number): Pose {
  const p = { ...b };
  for (const key of ['x', 'y', 'rot', 'torso', 'head', 'lHip', 'lKnee', 'rHip', 'rKnee', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow'] as const) {
    p[key] = mix(a[key], b[key], k);
  }
  return p;
}

/* ───────────── elevator ───────────── */

function exitElevator(c: S, x0: number): void {
  const { g, f } = c;
  const dx = clampX(c.s, x0 + f * 72, 40);
  const h: 1 | -1 = dx >= x0 ? 1 : -1;
  const bx = dx - h * (LIFT_HALF + 6);
  const by = g - 64;
  const frame =
    `M ${n(dx - LIFT_HALF)} ${n(g)} L ${n(dx - LIFT_HALF)} ${n(g - LIFT_H)} L ${n(dx + LIFT_HALF)} ${n(g - LIFT_H)} L ${n(dx + LIFT_HALF)} ${n(g)} ` +
    `M ${n(dx - 6)} ${n(g - LIFT_H - 10)} L ${n(dx + 6)} ${n(g - LIFT_H - 10)} L ${n(dx)} ${n(g - LIFT_H - 4)} Z ` +
    `M ${n(bx - 4)} ${n(by - 7)} L ${n(bx + 4)} ${n(by - 7)} L ${n(bx + 4)} ${n(by + 7)} L ${n(bx - 4)} ${n(by + 7)} Z ${circle(bx, by, 2.4)}`;
  const lit = circle(bx, by, 1);
  const panels = (gap: number): string => {
    const w = LIFT_HALF - 1;
    if (gap >= w - 0.3) {
      return '';
    }
    const top = g - LIFT_H + 1;
    return `M ${n(dx - w)} ${n(top)} L ${n(dx - gap)} ${n(top)} L ${n(dx - gap)} ${n(g)} L ${n(dx - w)} ${n(g)} Z M ${n(dx + gap)} ${n(top)} L ${n(dx + w)} ${n(top)} L ${n(dx + w)} ${n(g)} L ${n(dx + gap)} ${n(g)} Z`;
  };
  const closedPanels = panels(0);
  const liftInk = (gap: number, pressed: boolean, draw = 1, alpha = 1): InkProp[] => {
    const out = [ink(frame, draw, c.lite, alpha), ink(gap < 0.01 ? closedPanels : panels(gap), draw, c.lite, alpha)];
    if (pressed) {
      out.push(ink(lit, 1, c.lite, alpha));
    }
    if (gap > 2) {
      out.push(ink(`M ${n(dx - gap * 0.5)} ${n(g - LIFT_H + 12)} L ${n(dx - gap * 0.5 - 4)} ${n(g - 6)} M ${n(dx + gap * 0.4)} ${n(g - LIFT_H + 12)} L ${n(dx + gap * 0.4 - 4)} ${n(g - 6)}`, 1, c.lite, 0.3 * alpha));
    }
    return out;
  };
  const closedInk = liftInk(0, false);
  const pressedInk = liftInk(0, true);
  const openInk = liftInk(LIFT_GAP, true);
  const atButton = bx - h * 15;

  drawOn(c, x0, f, { x: dx, y: g - 80 }, (draw, alpha) => liftInk(0, false, draw, alpha));
  walk(c, x0, atButton, closedInk);
  // Presses the call button: ding.
  mark(c, 0.3, { type: 'ring', point: { x: bx, y: by } });
  push(
    c,
    holdPhase(0.65, (u) => {
      const p = breathing(c.s, atButton, h, u);
      const k = Math.sin(Math.min(1, u / 0.85) * Math.PI);
      const q = { ...p };
      reach(q, 'r', { x: bx - h * 2, y: by }, 1);
      p.rShoulder = mix(p.rShoulder, q.rShoulder, k);
      p.rElbow = mix(p.rElbow, q.rElbow, k);
      return { pose: p, expr: { mouth: 'flat', brows: 0.3 }, props: { ink: u > 0.45 ? pressedInk : closedInk } };
    }),
  );
  // Waits, looking up at the floor arrow; ding — the doors slide apart.
  mark(c, 0.6, { type: 'ring', point: { x: dx, y: g - LIFT_H - 8 } });
  push(c, holdPhase(0.6, (u) => ({ pose: breathing(c.s, atButton, h, 0.65 + u), expr: { mouth: 'flat' }, eyeDir: { x: h * 0.4, y: -0.9 }, props: { ink: pressedInk } })));
  push(c, holdPhase(0.6, (u) => ({ pose: breathing(c.s, atButton, h, 1.25 + u), expr: { mouth: 'smile' }, props: { ink: liftInk(LIFT_GAP * ease.inOut(u), true) } })));
  walk(c, atButton, dx, openInk);
  const win = (gap: number): FigureMask => ({ kind: 'rect', x0: dx - gap, y0: g - LIFT_H, x1: dx + gap, y1: g + 8 });
  const inside = standAt(c.s, dx, -h as 1 | -1);
  const waving = (t: number): Pose => {
    const p = { ...inside };
    waveArm(p, t, ease.out(segment(t, 0, 0.25)));
    return p;
  };
  push(c, holdPhase(1, (u) => ({ pose: waving(u), expr: { eyes: 'happy', mouth: 'grin' }, mask: win(LIFT_GAP), props: { ink: openInk } })));
  const last = waving(1);
  push(
    c,
    holdPhase(0.6, (u) => {
      const gap = LIFT_GAP * (1 - ease.inOut(u));
      return { pose: last, expr: { eyes: 'happy', mouth: 'grin' }, mask: win(gap), props: { ink: liftInk(gap, true) } };
    }),
  );
  eraseProps(c, last, win(0), (draw, alpha) => liftInk(0, false, draw, alpha));
}

/* ───────────── trapdoor gag ───────────── */

function exitTrapdoor(c: S, x: number): void {
  const { g, f } = c;
  const cy = g - 6;
  const rx = HOLE_RX;
  const ry = 6;
  const xh = x + f * rx;
  const outline = ellipse(x, cy, rx, ry);
  const dark = `M ${n(x - 19)} ${n(cy + 1)} Q ${n(x)} ${n(cy + 6)} ${n(x + 19)} ${n(cy + 1)}`;
  /** φ = 0 closed (lid lies in the floor), π/2 hanging straight down from the hinge. */
  const trapInk = (phi: number, alpha = 1, draw = 1): InkProp[] => {
    if (phi < 0.02) {
      return [ink(outline, draw, c.lite, alpha * 0.75), ink(`M ${n(x - 14)} ${n(cy)} L ${n(x + 14)} ${n(cy)}`, draw, c.lite, alpha * 0.75)];
    }
    const L = 2 * rx;
    const ex = xh - f * L * Math.cos(phi);
    const ey = cy + L * Math.sin(phi);
    return [ink(outline, 1, c.lite, alpha), ink(`M ${n(xh)} ${n(cy)} L ${n(ex)} ${n(ey)} M ${n(xh)} ${n(cy + 3)} L ${n(ex + f * 3)} ${n(ey + 2)}`, 1, c.lite, alpha), ink(dark, 1, c.lite, 0.4 * alpha)];
  };
  const closedInk = trapInk(0);
  push(
    c,
    holdPhase(c.lite ? 0.3 : 0.8, (u) => ({
      pose: breathing(c.s, x, f, u),
      expr: { mouth: 'o', brows: 0.2 },
      eyeDir: { x: f * 0.5, y: -0.6 },
      props: { ink: trapInk(0, c.lite ? u : 1, ease.out(u)) },
    })),
  );
  push(c, holdPhase(0.5, (u) => ({ pose: breathing(c.s, x, f, 0.8 + u), expr: { mouth: 'o', brows: 0.2 }, eyeDir: { x: -f * 0.5, y: -0.6 }, props: { ink: closedInk } })));
  const mask = holeMask(x, cy, rx, ry);
  const still = breathing(c.s, x, f, 1.3);
  mark(c, 0, { type: 'bang' });
  push(c, holdPhase(0.18, (u) => ({ pose: still, expr: { eyes: 'wide', brows: 1, mouth: 'o' }, mask, props: { ink: trapInk((Math.PI / 2) * ease.in(u)) } })));
  // The cartoon beat: hanging in the air, a look down, a look at the viewer.
  const openInk = trapInk(Math.PI / 2);
  const hang = (u: number): Pose => {
    const p = { ...still };
    const k = ease.out(u);
    p.lHip += 0.25 * k;
    p.lKnee += 0.35 * k;
    p.rHip += 0.15 * k;
    p.rKnee += 0.25 * k;
    return p;
  };
  push(c, holdPhase(0.4, (u) => ({ pose: hang(u), expr: { eyes: 'wide', brows: 1, mouth: 'wobbly' }, eyeDir: u < 0.5 ? { x: 0, y: 1 } : { x: 0, y: 0 }, mask, props: { ink: openInk } })));
  const top = hang(1);
  const fall = (u: number): Pose => {
    const p = { ...top };
    const k = ease.inOut(segment(u, 0, 0.3));
    p.y = top.y + (DEEP + 30) * u * u;
    p.lShoulder = mix(top.lShoulder, 2.9 + 0.15 * Math.sin(u * 40), k);
    p.rShoulder = mix(top.rShoulder, 3.1 - 0.15 * Math.sin(u * 40), k);
    p.lElbow = mix(top.lElbow, 0.2, k);
    p.rElbow = mix(top.rElbow, 0.2, k);
    return p;
  };
  push(c, holdPhase(0.5, (u) => ({ pose: fall(u), expr: { eyes: 'wide', brows: 1, mouth: 'o' }, mask, props: { ink: openInk } })));
  const gone = fall(1);
  mark(c, 0.2, { type: 'dust', point: { x, y: g }, strength: 0.3 });
  push(c, holdPhase(0.2, (u) => ({ pose: gone, mask, props: { ink: trapInk((Math.PI / 2) * (1 - ease.in(u))) } })));
  eraseProps(c, gone, mask, (draw, alpha) => trapInk(0, alpha, draw), 0.45);
}

/* ───────────── pencil-eraser gag ───────────── */

function exitErase(c: S, x: number): void {
  const { g, f } = c;
  /** Eraser with its rubbing (bottom) edge on `line`. */
  const eraser = (ex: number, line: number, draw = 1, alpha = 1): InkProp[] => [
    ink(`M ${n(ex - 14)} ${n(line - 14)} L ${n(ex + 14)} ${n(line - 14)} L ${n(ex + 14)} ${n(line)} L ${n(ex - 14)} ${n(line)} Z M ${n(ex - f * 5)} ${n(line - 14)} L ${n(ex - f * 5)} ${n(line)}`, draw, c.lite, alpha),
  ];
  const vis = (line: number): FigureMask => ({ kind: 'rect', x0: -100000, y0: -100000, x1: 100000, y1: line });
  // Pulls it out: in the raised hand.
  const holding = (t: number): Pose => {
    const p = breathing(c.s, x, f, t);
    p.rShoulder = 1.2;
    p.rElbow = 0.7;
    return p;
  };
  const inHand = (p: Pose): Vec => {
    const hnd = handPos(p, 'r');
    return { x: hnd.x + f * 4, y: hnd.y - 2 };
  };
  push(
    c,
    holdPhase(0.8, (u) => {
      const p = holding(u);
      const e = inHand(p);
      return { pose: p, expr: { eyes: 'happy', mouth: 'grin', brows: -0.4 }, eyeDir: { x: f * 0.7, y: -0.2 }, props: { ink: eraser(e.x, e.y, ease.out(u)) } };
    }),
  );
  const ex0 = x + f * 14;
  const rubX = (t: number): number => ex0 + 6 * Math.sin(t * 18);
  const squatting = (k: number, line: number, ex: number): Pose => {
    const p = crouchAt(c, x, f, 22 * k, 0.95 * k);
    reach(p, 'r', { x: ex, y: line + 2 }, 1);
    return p;
  };
  // Squats down to his feet.
  const startHand = inHand(holding(0.8));
  push(
    c,
    holdPhase(0.5, (u) => {
      const k = ease.inOut(u);
      const line = mix(startHand.y, g + 3, k);
      const ex = mix(startHand.x, ex0, k);
      return { pose: squatting(k, line, ex), expr: { eyes: 'happy', mouth: 'grin' }, eyeDir: { x: f * 0.4, y: 0.9 }, props: { ink: eraser(ex, line) } };
    }),
  );
  // Rubs from the feet up: whatever is below the line is gone.
  mark(c, 0.3, { type: 'dust', at: 'rHand', point: { x: ex0, y: g - 6 }, strength: 0.25 });
  mark(c, 0.7, { type: 'dust', at: 'rHand', point: { x: ex0, y: g - 16 }, strength: 0.25 });
  push(
    c,
    holdPhase(0.9, (u) => {
      const line = mix(g + 3, g - 22, u);
      const ex = rubX(u * 0.9);
      return { pose: squatting(1, line, ex), expr: { mouth: 'flat', brows: 0.3 }, eyeDir: { x: f * 0.4, y: 0.9 }, mask: vis(line), props: { ink: eraser(ex, line) } };
    }),
  );
  mark(c, 0.5, { type: 'dust', at: 'rHand', point: { x: ex0, y: g - 40 }, strength: 0.25 });
  push(
    c,
    holdPhase(0.9, (u) => {
      const line = mix(g - 22, g - 60, u);
      const ex = rubX(0.9 + u * 0.9);
      return { pose: squatting(1 - ease.inOut(u), line, ex), expr: { mouth: 'flat', brows: 0.6 }, eyeDir: { x: f * 0.3, y: 0.7 }, mask: vis(line), props: { ink: eraser(ex, line) } };
    }),
  );
  // Too late to stop: the eraser goes over his face too.
  mark(c, 0.4, { type: 'dust', at: 'rHand', point: { x: ex0, y: g - 80 }, strength: 0.25 });
  push(
    c,
    holdPhase(0.9, (u) => {
      const line = mix(g - 60, g - 106, ease.inOut(u));
      const ex = rubX(1.8 + u * 0.9);
      const p = squatting(0, line, ex);
      return { pose: p, expr: u > 0.35 ? { eyes: 'wide', brows: 1, mouth: 'o' } : { mouth: 'flat', brows: 0.8 }, eyeDir: { x: 0, y: 0 }, mask: vis(line), props: { ink: eraser(ex, line) } };
    }),
  );
  const gone = squatting(0, g - 106, rubX(2.7));
  const exEnd = rubX(2.7);
  // Left alone, the eraser drops and fades.
  mark(c, 0.55, { type: 'dust', point: { x: exEnd, y: g }, strength: 0.3 });
  push(
    c,
    holdPhase(0.9, (u) => {
      const k = ease.in(segment(u, 0, 0.6));
      const line = mix(g - 106, g, k);
      return { pose: gone, mask: vis(g - 106), props: { ink: u >= 1 ? NO_INK : eraser(exEnd, line, 1, 1 - segment(u, 0.6, 1)) } };
    }),
  );
}

/* ───────────── entry point ───────────── */

/** Where an entrance ends (standing); exits end hidden, so they have no end pose to match. */
export function sceneEnd(action: SceneEntrance, c: SceneContext): Pose {
  const f: 1 | -1 = c.side === 'left' ? 1 : -1;
  return standAt(c.stage, clampX(c.stage, c.targetX, 60), f);
}

export function createSceneClip(action: SceneAction, ctx: SceneContext): Clip {
  const entrance = (SCENE_ENTRANCES as readonly string[]).includes(action);
  const f: 1 | -1 = entrance ? (ctx.side === 'left' ? 1 : -1) : ctx.from.facing;
  const c: S = { s: ctx.stage, g: ctx.stage.ground, f, lite: ctx.lite, rng: ctx.rng, phases: [], marks: [] };
  // Exits happen where he is, but at least a little inside the viewport (he walks in first if needed).
  const x = clampX(ctx.stage, ctx.from.x, 60);
  if (!entrance) {
    walk(c, ctx.from.x, x, NO_INK);
  }
  const tx = clampX(ctx.stage, ctx.targetX, 60);
  switch (action) {
    case 'exit-door':
      exitDoor(c, x);
      break;
    case 'exit-hatch':
      exitHatch(c, x);
      break;
    case 'exit-portal':
      exitPortal(c, x);
      break;
    case 'exit-elevator':
      exitElevator(c, x);
      break;
    case 'exit-trapdoor':
      exitTrapdoor(c, x);
      break;
    case 'exit-erase':
      exitErase(c, x);
      break;
    case 'enter-door':
      enterDoor(c, tx);
      break;
    case 'enter-hatch':
      enterHatch(c, tx);
      break;
    case 'enter-portal':
      enterPortal(c, tx);
      break;
  }
  const marks = c.marks;
  return buildPhases(action, ctx.from, c.phases, {
    cut: entrance,
    events: (starts) => marks.map((m) => ({ ...m.ev, t: starts[m.i] + m.off }) as ClipEvent),
  });
}

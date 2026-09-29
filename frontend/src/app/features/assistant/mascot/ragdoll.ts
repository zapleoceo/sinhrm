import { angleFromDown, normalizeAngle } from './ik';
import { BONES, Joints, Pose, STAND, Vec } from './skeleton';

/**
 * Verlet ragdoll of «Стік»: 12 points joined by distance constraints, gravity, air drag, ground and wall collisions
 * with friction/restitution. Used after a throw, a slip, a trip or a faint — he really falls, tumbles and settles.
 * State lives in preallocated typed arrays (no allocations per step). Deterministic for the same inputs.
 */

/** Point indices. */
export const P = {
  Head: 0,
  Neck: 1,
  Shoulder: 2,
  Pelvis: 3,
  LElbow: 4,
  LHand: 5,
  RElbow: 6,
  RHand: 7,
  LKnee: 8,
  LFoot: 9,
  RKnee: 10,
  RFoot: 11,
} as const;

export const POINT_COUNT = 12;

/** Collision radius per point (the head is a circle, limbs are thin ink). */
const RADIUS: readonly number[] = [BONES.headR, 2, 2, 3, 2, 2, 2, 2, 2, 2.5, 2, 2.5];

/** [a, b, min, max, stiffness] — equal min/max is a rigid bone, a range is a soft joint limit. */
type Link = readonly [number, number, number, number, number];

const HEAD_OFF = BONES.neck + BONES.headR;
const ARM = BONES.upperArm + BONES.foreArm;
const LEG = BONES.thigh + BONES.shin;

export const LINKS: readonly Link[] = [
  // Spine and head (rigid).
  [P.Pelvis, P.Shoulder, BONES.shoulderAt, BONES.shoulderAt, 1],
  [P.Shoulder, P.Neck, BONES.spine - BONES.shoulderAt, BONES.spine - BONES.shoulderAt, 1],
  [P.Pelvis, P.Neck, BONES.spine, BONES.spine, 1],
  [P.Neck, P.Head, HEAD_OFF, HEAD_OFF, 1],
  // Keeps the head roughly in line with the spine (a stiff neck, some nodding allowed).
  [P.Pelvis, P.Head, BONES.spine + HEAD_OFF - 5, BONES.spine + HEAD_OFF, 0.7],
  // Arms.
  [P.Shoulder, P.LElbow, BONES.upperArm, BONES.upperArm, 1],
  [P.LElbow, P.LHand, BONES.foreArm, BONES.foreArm, 1],
  [P.Shoulder, P.RElbow, BONES.upperArm, BONES.upperArm, 1],
  [P.RElbow, P.RHand, BONES.foreArm, BONES.foreArm, 1],
  [P.Shoulder, P.LHand, ARM * 0.3, ARM, 0.6],
  [P.Shoulder, P.RHand, ARM * 0.3, ARM, 0.6],
  // Legs.
  [P.Pelvis, P.LKnee, BONES.thigh, BONES.thigh, 1],
  [P.LKnee, P.LFoot, BONES.shin, BONES.shin, 1],
  [P.Pelvis, P.RKnee, BONES.thigh, BONES.thigh, 1],
  [P.RKnee, P.RFoot, BONES.shin, BONES.shin, 1],
  [P.Pelvis, P.LFoot, LEG * 0.45, LEG, 0.7],
  [P.Pelvis, P.RFoot, LEG * 0.45, LEG, 0.7],
  // Knees do not pass through each other completely; limbs do not fold into the chest.
  [P.LKnee, P.RKnee, 3, LEG, 0.3],
  [P.Neck, P.LKnee, 12, 200, 0.5],
  [P.Neck, P.RKnee, 12, 200, 0.5],
];

export interface RagdollWorld {
  /** y of the floor surface (viewport bottom). */
  ground: number;
  width: number;
  gravity: number;
  /** Fraction of velocity lost per second in the air. */
  drag: number;
  restitution: number;
  /** Tangential velocity kept per contact step (0..1). */
  friction: number;
  iterations: number;
}

export interface Ragdoll {
  pos: Float64Array;
  prev: Float64Array;
  /** Steps in a row with (almost) no motion. */
  stillSteps: number;
  age: number;
  rested: boolean;
  /** Fastest ground impact so far (px/s). */
  maxImpact: number;
}

export interface RagdollStep {
  /** Fastest downward speed of a point that hit the floor in this step (px/s), 0 — none. */
  impact: number;
  wall: boolean;
  /** Just came to rest in this step. */
  rested: boolean;
}

export type Orientation = 'back' | 'face' | 'side' | 'sitting';

/**
 * Where the user holds him: a spot on bone a→b (t = 0 at a, 1 at b; a === b for a single point) pulled toward
 * the target (x, y). The rest of the body follows only through the constraints.
 */
export interface Grab {
  a: number;
  b: number;
  t: number;
  x: number;
  y: number;
}

/** Share of the remaining gap the held spot closes per constraint iteration (stiff, but not a teleport). */
export const GRAB_STIFFNESS = 0.6;
/** Constraint iterations while held (keeps him stiff and stable at fast pointer speeds). */
export const HOLD_ITERATIONS = 18;
/** Largest distance the held spot is pulled in one step (px, = 2700 px/s). */
const MAX_GRAB_STEP = 45;
/** Last iterations without the hold: bones keep their length, the held spot may lag a bit at extreme speed. */
const HOLD_SETTLE = 8;
/** Extra air damping while held (a dangling body swings, then calms down). */
export const HOLD_DRAG = 0.9;

/** Bones a click can grab (rigid links), plus the head as a disc. */
const GRAB_BONES: readonly (readonly [number, number])[] = [
  [P.Neck, P.Head],
  [P.Pelvis, P.Neck],
  [P.Shoulder, P.LElbow],
  [P.LElbow, P.LHand],
  [P.Shoulder, P.RElbow],
  [P.RElbow, P.RHand],
  [P.Pelvis, P.LKnee],
  [P.LKnee, P.LFoot],
  [P.Pelvis, P.RKnee],
  [P.RKnee, P.RFoot],
];

export const RAGDOLL_DT = 1 / 60;
/** Per-step displacement cap (px) — no explosions, no tunnelling. */
const MAX_STEP = 70;
const STILL_SPEED = 0.5;
const STILL_STEPS = 20;
/** Longest tumble before he counts as lying still (a slow slide or a wobble would otherwise delay the get-up). */
const MAX_AGE = 4.5;

export function ragdollWorld(width: number, ground: number): RagdollWorld {
  return { ground, width, gravity: 2600, drag: 0.4, restitution: 0.28, friction: 0.55, iterations: 8 };
}

/** Joint order of the Joints structure → ragdoll points. */
const FROM_JOINTS: readonly (keyof Joints)[] = ['head', 'neck', 'shoulder', 'hip', 'lElbow', 'lHand', 'rElbow', 'rHand', 'lKnee', 'lFoot', 'rKnee', 'rFoot'];

/**
 * Ragdoll from the current drawing: joints relative to the root at (x, y), linear velocity (px/s) and spin
 * (rad/s, clockwise) about the pelvis — the throw's angular momentum.
 */
export function createRagdoll(root: Vec, joints: Joints, vx: number, vy: number, spin: number, dt = RAGDOLL_DT): Ragdoll {
  const pos = new Float64Array(POINT_COUNT * 2);
  const prev = new Float64Array(POINT_COUNT * 2);
  const pelvis = joints.hip;
  for (let i = 0; i < POINT_COUNT; i++) {
    const j = joints[FROM_JOINTS[i]] as Vec;
    const x = root.x + j.x;
    const y = root.y + j.y;
    const rx = j.x - pelvis.x;
    const ry = j.y - pelvis.y;
    const pvx = vx - spin * ry;
    const pvy = vy + spin * rx;
    pos[i * 2] = x;
    pos[i * 2 + 1] = y;
    prev[i * 2] = x - pvx * dt;
    prev[i * 2 + 1] = y - pvy * dt;
  }
  return { pos, prev, stillSteps: 0, age: 0, rested: false, maxImpact: 0 };
}

export function point(r: Ragdoll, i: number): Vec {
  return { x: r.pos[i * 2], y: r.pos[i * 2 + 1] };
}

/** Velocity of a point (px/s). */
export function velocity(r: Ragdoll, i: number, dt = RAGDOLL_DT): Vec {
  return { x: (r.pos[i * 2] - r.prev[i * 2]) / dt, y: (r.pos[i * 2 + 1] - r.prev[i * 2 + 1]) / dt };
}

/** Kinetic energy proxy: sum of squared point speeds (px²/s²). */
export function kineticEnergy(r: Ragdoll, dt = RAGDOLL_DT): number {
  let e = 0;
  for (let i = 0; i < POINT_COUNT; i++) {
    const dx = (r.pos[i * 2] - r.prev[i * 2]) / dt;
    const dy = (r.pos[i * 2 + 1] - r.prev[i * 2 + 1]) / dt;
    e += dx * dx + dy * dy;
  }
  return e;
}

/** The spot on the body nearest to a click (bone segments and the head disc). */
export function grabAt(r: Ragdoll, x: number, y: number): Grab {
  const { pos } = r;
  let best: Grab = { a: P.Pelvis, b: P.Pelvis, t: 0, x, y };
  let bestD = Infinity;
  const hx = pos[P.Head * 2];
  const hy = pos[P.Head * 2 + 1];
  const headD = Math.max(0, Math.hypot(x - hx, y - hy) - BONES.headR);
  if (headD < bestD) {
    bestD = headD;
    best = { a: P.Head, b: P.Head, t: 0, x, y };
  }
  for (const [a, b] of GRAB_BONES) {
    const ax = pos[a * 2];
    const ay = pos[a * 2 + 1];
    const dx = pos[b * 2] - ax;
    const dy = pos[b * 2 + 1] - ay;
    const len2 = dx * dx + dy * dy || 1e-6;
    const t = Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / len2));
    // Limbs get a small handicap so a click on the body picks the torso/head when it is a near tie.
    const limb = a !== P.Pelvis || b !== P.Neck ? (b === P.Head ? 0 : 2.5) : 0;
    const d = Math.hypot(x - (ax + dx * t), y - (ay + dy * t)) + limb;
    if (d < bestD - 0.5) {
      bestD = d;
      best = { a, b, t, x, y };
    }
  }
  // Holding a limb near its end means holding the end point itself (hand, foot, head).
  if (best.a !== best.b && best.t > 0.8) {
    best = { ...best, a: best.b, t: 0 };
  }
  return best;
}

/** Current position of the held spot. */
export function anchorOf(r: Ragdoll, g: Grab): Vec {
  const { pos } = r;
  return {
    x: pos[g.a * 2] + (pos[g.b * 2] - pos[g.a * 2]) * g.t,
    y: pos[g.a * 2 + 1] + (pos[g.b * 2 + 1] - pos[g.a * 2 + 1]) * g.t,
  };
}

/** Centre of mass (points weighted: head and pelvis heavier). */
export function centerOfMass(r: Ragdoll): Vec {
  let x = 0;
  let y = 0;
  let m = 0;
  for (let i = 0; i < POINT_COUNT; i++) {
    const w = i === P.Head || i === P.Pelvis ? 2 : 1;
    x += r.pos[i * 2] * w;
    y += r.pos[i * 2 + 1] * w;
    m += w;
  }
  return { x: x / m, y: y / m };
}

/** Pulls the held spot toward its target, splitting the correction between the bone's two points. */
function pullGrab(pos: Float64Array, g: Grab, tx: number, ty: number): void {
  const u = g.a === g.b ? 0 : g.t;
  const ax = g.a * 2;
  const bx = g.b * 2;
  const cx = pos[ax] + (pos[bx] - pos[ax]) * u;
  const cy = pos[ax + 1] + (pos[bx + 1] - pos[ax + 1]) * u;
  const dx = (tx - cx) * GRAB_STIFFNESS;
  const dy = (ty - cy) * GRAB_STIFFNESS;
  if (g.a === g.b) {
    pos[ax] += dx;
    pos[ax + 1] += dy;
    return;
  }
  const k = (1 - u) * (1 - u) + u * u;
  pos[ax] += (dx * (1 - u)) / k;
  pos[ax + 1] += (dy * (1 - u)) / k;
  pos[bx] += (dx * u) / k;
  pos[bx + 1] += (dy * u) / k;
}

/**
 * A weak "muscle" wiggle of the free hands and feet while held: a small sideways push of each limb end
 * across its bone (a torque about the elbow/knee), not a pose override. Mutates `r`.
 */
export function applyStruggle(r: Ragdoll, time: number, strength: number, grab: Grab | null): void {
  if (strength <= 0) {
    return;
  }
  const limbs: readonly (readonly [number, number, number])[] = [
    [P.LHand, P.LElbow, 0],
    [P.RHand, P.RElbow, 1.7],
    [P.LFoot, P.LKnee, 3.1],
    [P.RFoot, P.RKnee, 4.4],
  ];
  const { pos } = r;
  for (const [end, joint, phase] of limbs) {
    if (grab && (grab.a === end || grab.b === end)) {
      continue;
    }
    const dx = pos[end * 2] - pos[joint * 2];
    const dy = pos[end * 2 + 1] - pos[joint * 2 + 1];
    const len = Math.hypot(dx, dy) || 1;
    const push = strength * Math.sin(time * (9 + phase) + phase);
    pos[end * 2] += (-dy / len) * push;
    pos[end * 2 + 1] += (dx / len) * push;
  }
}

/** One fixed step: verlet integration → constraints (→ the hold) → collisions. Mutates `r`, allocates nothing. */
export function stepRagdoll(r: Ragdoll, w: RagdollWorld, dt = RAGDOLL_DT, grab: Grab | null = null): RagdollStep {
  const out: RagdollStep = { impact: 0, wall: false, rested: false };
  if (r.rested) {
    return out;
  }
  if (!grab) {
    r.age += dt;
  }
  const { pos, prev } = r;
  const keep = Math.max(0, 1 - (grab ? HOLD_DRAG : w.drag) * dt);
  const g = w.gravity * dt * dt;
  let maxMove = 0;
  for (let i = 0; i < POINT_COUNT; i++) {
    const ix = i * 2;
    const iy = ix + 1;
    let vx = (pos[ix] - prev[ix]) * keep;
    let vy = (pos[iy] - prev[iy]) * keep + g;
    const sp = Math.hypot(vx, vy);
    if (sp > MAX_STEP) {
      vx *= MAX_STEP / sp;
      vy *= MAX_STEP / sp;
    }
    prev[ix] = pos[ix];
    prev[iy] = pos[iy];
    pos[ix] += vx;
    pos[iy] += vy;
  }
  const iterations = grab ? HOLD_ITERATIONS : w.iterations;
  // At extreme pointer speed the held spot follows at most MAX_GRAB_STEP per step (a slight lag, bones intact).
  let tx = 0;
  let ty = 0;
  if (grab) {
    const a0 = anchorOf(r, grab);
    const gx = grab.x - a0.x;
    const gy = grab.y - a0.y;
    const gd = Math.hypot(gx, gy);
    const k = gd > MAX_GRAB_STEP ? MAX_GRAB_STEP / gd : 1;
    tx = a0.x + gx * k;
    ty = a0.y + gy * k;
  }
  for (let k = 0; k < iterations; k++) {
    for (const [a, b, min, max, stiff] of LINKS) {
      const ax = a * 2;
      const bx = b * 2;
      const dx = pos[bx] - pos[ax];
      const dy = pos[bx + 1] - pos[ax + 1];
      const d = Math.hypot(dx, dy) || 1e-6;
      const target = d < min ? min : d > max ? max : d;
      if (target === d) {
        continue;
      }
      const f = ((d - target) / d) * 0.5 * stiff;
      pos[ax] += dx * f;
      pos[ax + 1] += dy * f;
      pos[bx] -= dx * f;
      pos[bx + 1] -= dy * f;
    }
    rigidShoulder(pos);
    if (grab && k < iterations - HOLD_SETTLE) {
      pullGrab(pos, grab, tx, ty);
    }
    collide(r, w, k === 0 ? out : null, dt);
  }
  for (let i = 0; i < POINT_COUNT; i++) {
    maxMove = Math.max(maxMove, Math.hypot(pos[i * 2] - prev[i * 2], pos[i * 2 + 1] - prev[i * 2 + 1]));
  }
  r.maxImpact = Math.max(r.maxImpact, out.impact);
  if (grab) {
    // Held: never "comes to rest".
    r.stillSteps = 0;
    return out;
  }
  r.stillSteps = maxMove < STILL_SPEED && touchingGround(r, w) ? r.stillSteps + 1 : 0;
  if (r.stillSteps >= STILL_STEPS || r.age >= MAX_AGE) {
    r.rested = true;
    out.rested = true;
    // Freeze: no residual velocity.
    prev.set(pos);
  }
  return out;
}

/**
 * The shoulder sits on the spine (pelvis → neck) at a fixed ratio — a rigid bar, not a hinge: whatever pulled the
 * shoulder off the line moves the spine instead (split by the lever), then the shoulder is put back on it.
 */
const SHOULDER_AT = BONES.shoulderAt / BONES.spine;

function rigidShoulder(pos: Float64Array): void {
  const px = P.Pelvis * 2;
  const nx = P.Neck * 2;
  const sx = P.Shoulder * 2;
  const idealX = pos[px] + (pos[nx] - pos[px]) * SHOULDER_AT;
  const idealY = pos[px + 1] + (pos[nx + 1] - pos[px + 1]) * SHOULDER_AT;
  const cx = pos[sx] - idealX;
  const cy = pos[sx + 1] - idealY;
  pos[px] += cx * (1 - SHOULDER_AT);
  pos[px + 1] += cy * (1 - SHOULDER_AT);
  pos[nx] += cx * SHOULDER_AT;
  pos[nx + 1] += cy * SHOULDER_AT;
  pos[sx] = pos[px] + (pos[nx] - pos[px]) * SHOULDER_AT;
  pos[sx + 1] = pos[px + 1] + (pos[nx + 1] - pos[px + 1]) * SHOULDER_AT;
}

function collide(r: Ragdoll, w: RagdollWorld, report: RagdollStep | null, dt: number): void {
  const { pos, prev } = r;
  for (let i = 0; i < POINT_COUNT; i++) {
    const ix = i * 2;
    const iy = ix + 1;
    const rad = RADIUS[i];
    const floor = w.ground - rad;
    if (pos[iy] > floor) {
      const vy = pos[iy] - prev[iy];
      const vx = pos[ix] - prev[ix];
      pos[iy] = floor;
      if (report && vy > 0) {
        report.impact = Math.max(report.impact, vy / dt);
      }
      // Bounce with restitution; friction slows the slide along the floor.
      prev[iy] = floor + (vy > 0 ? vy * w.restitution : 0);
      prev[ix] = pos[ix] - vx * w.friction;
    }
    const left = rad;
    const right = w.width - rad;
    if (pos[ix] < left || pos[ix] > right) {
      const vx = pos[ix] - prev[ix];
      pos[ix] = pos[ix] < left ? left : right;
      prev[ix] = pos[ix] + vx * 0.4;
      if (report) {
        report.wall = true;
      }
    }
  }
}

function touchingGround(r: Ragdoll, w: RagdollWorld): boolean {
  for (let i = 0; i < POINT_COUNT; i++) {
    if (r.pos[i * 2 + 1] >= w.ground - RADIUS[i] - 1.5) {
      return true;
    }
  }
  return false;
}

/**
 * The viewport changed (resize): keeps the body inside the new walls and on/above the new floor by moving it rigidly
 * (positions and previous positions together — no velocity kick), then lets physics settle it again.
 * A lower floor → he falls onto it; a higher one → lifted with it.
 */
export function reseat(r: Ragdoll, w: RagdollWorld): void {
  let lift = 0;
  let left = 0;
  let right = 0;
  for (let i = 0; i < POINT_COUNT; i++) {
    const rad = RADIUS[i];
    lift = Math.max(lift, r.pos[i * 2 + 1] - (w.ground - rad));
    left = Math.max(left, rad - r.pos[i * 2]);
    right = Math.max(right, r.pos[i * 2] - (w.width - rad));
  }
  const dx = left > 0 ? left : right > 0 ? -Math.min(right, Math.max(0, w.width - 60)) : 0;
  const dy = lift > 0 ? -lift : 0;
  if (dx !== 0 || dy !== 0) {
    for (let i = 0; i < POINT_COUNT; i++) {
      r.pos[i * 2] += dx;
      r.prev[i * 2] += dx;
      r.pos[i * 2 + 1] += dy;
      r.prev[i * 2 + 1] += dy;
    }
  }
  // Wake up and settle on the new floor.
  r.rested = false;
  r.stillSteps = 0;
}

/** Joints (relative to the pelvis) straight from the ragdoll points — what is drawn is exactly the physics. */
export function ragdollJoints(r: Ragdoll, facing: 1 | -1): Joints {
  const px = r.pos[P.Pelvis * 2];
  const py = r.pos[P.Pelvis * 2 + 1];
  const rel = (i: number): Vec => ({ x: r.pos[i * 2] - px, y: r.pos[i * 2 + 1] - py });
  const head = rel(P.Head);
  const neck = rel(P.Neck);
  return {
    hip: { x: 0, y: 0 },
    neck,
    shoulder: rel(P.Shoulder),
    head,
    lElbow: rel(P.LElbow),
    lHand: rel(P.LHand),
    rElbow: rel(P.RElbow),
    rHand: rel(P.RHand),
    lKnee: rel(P.LKnee),
    lFoot: rel(P.LFoot),
    rKnee: rel(P.RKnee),
    rFoot: rel(P.RFoot),
    headAngle: Math.atan2(head.x - neck.x, -(head.y - neck.y)),
    facing,
  };
}

function fitFacing(r: Ragdoll, facing: 1 | -1): Pose {
  const pt = (i: number): Vec => ({ x: r.pos[i * 2], y: r.pos[i * 2 + 1] });
  const pelvis = pt(P.Pelvis);
  const neck = pt(P.Neck);
  const shoulder = pt(P.Shoulder);
  const rot = Math.atan2(neck.x - pelvis.x, -(neck.y - pelvis.y));
  const c = Math.cos(-rot);
  const s = Math.sin(-rot);
  // World delta → body frame (x forward).
  const body = (a: Vec, b: Vec): Vec => {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    return { x: (dx * c - dy * s) * facing, y: dx * s + dy * c };
  };
  const down = (a: Vec, b: Vec): number => {
    const d = body(a, b);
    return angleFromDown(d.x, d.y);
  };
  const h = body(neck, pt(P.Head));
  const lUpper = down(shoulder, pt(P.LElbow));
  const rUpper = down(shoulder, pt(P.RElbow));
  const lHip = down(pelvis, pt(P.LKnee));
  const rHip = down(pelvis, pt(P.RKnee));
  return {
    ...STAND,
    x: pelvis.x,
    y: pelvis.y,
    rot,
    facing,
    torso: 0,
    head: Math.atan2(h.x, -h.y),
    lShoulder: lUpper,
    lElbow: normalizeAngle(down(pt(P.LElbow), pt(P.LHand)) - lUpper),
    rShoulder: rUpper,
    rElbow: normalizeAngle(down(pt(P.RElbow), pt(P.RHand)) - rUpper),
    lHip,
    lKnee: normalizeAngle(lHip - down(pt(P.LKnee), pt(P.LFoot))),
    rHip,
    rKnee: normalizeAngle(rHip - down(pt(P.RKnee), pt(P.RFoot))),
    squash: 1,
  };
}

/**
 * Pose (angles) reproducing the ragdoll, so animations can blend out of it without a jump.
 * Facing is chosen so the knees bend naturally (the knees reveal where his front is), with hysteresis.
 */
export function fitPose(r: Ragdoll, current: 1 | -1): Pose {
  const a = fitFacing(r, current);
  const b = fitFacing(r, current === 1 ? -1 : 1);
  const bend = (p: Pose): number => Math.sin(p.lKnee) + Math.sin(p.rKnee);
  return bend(b) > bend(a) + 0.3 ? b : a;
}

/** How he lies: on his back, face down, curled on a side, or sitting up. */
export function orientationOf(p: Pose): Orientation {
  const upY = -Math.cos(p.rot);
  if (upY < -0.64) {
    return 'sitting';
  }
  const forwardY = p.facing * Math.sin(p.rot);
  const curled = p.lKnee > 1.6 && p.rKnee > 1.6;
  if (upY > 0.64 || curled) {
    return 'side';
  }
  if (forwardY < -0.35) {
    return 'back';
  }
  return forwardY > 0.35 ? 'face' : 'side';
}

import { chainEnd, solveTwoBone } from './ik';

/** Point in screen pixels (y down). */
export interface Vec {
  x: number;
  y: number;
}

/** Bone lengths of «Стік» (px). About 110 px from feet to the top of the head. */
export const BONES = {
  spine: 30,
  shoulderAt: 26,
  neck: 4,
  headR: 11,
  upperArm: 18,
  foreArm: 18,
  thigh: 23,
  shin: 23,
} as const;

/** Hip height above the ground when standing (legs slightly bent). */
export const STAND_HIP = 44;

/**
 * A pose: root (hip) position in the viewport + joint angles.
 * Limb angles are measured from "hanging straight down" in the body frame, positive = forward (toward `facing`).
 * Elbow: positive folds the forearm forward/up. Knee: positive folds the shin backward (natural knee).
 */
export interface Pose {
  x: number;
  y: number;
  /** Whole-body rotation around the hip, clockwise (rad). */
  rot: number;
  facing: 1 | -1;
  /** Spine lean from vertical, forward positive. */
  torso: number;
  /** Head tilt relative to the spine. */
  head: number;
  lShoulder: number;
  lElbow: number;
  rShoulder: number;
  rElbow: number;
  lHip: number;
  lKnee: number;
  rHip: number;
  rKnee: number;
  /** 1 neutral, < 1 squashed, > 1 stretched (volume-preserving, anchored at the feet). */
  squash: number;
  /** While turning around: horizontal scale going from the old facing to the new one through 0 (continuous). */
  turn?: number;
}

export type AngleKey = 'torso' | 'head' | 'lShoulder' | 'lElbow' | 'rShoulder' | 'rElbow' | 'lHip' | 'lKnee' | 'rHip' | 'rKnee';

export const ANGLE_KEYS: readonly AngleKey[] = ['torso', 'head', 'lShoulder', 'lElbow', 'rShoulder', 'rElbow', 'lHip', 'lKnee', 'rHip', 'rKnee'];

/** Joint positions relative to the hip (world axes, after facing, rotation and squash). */
export interface Joints {
  hip: Vec;
  neck: Vec;
  shoulder: Vec;
  head: Vec;
  lElbow: Vec;
  lHand: Vec;
  rElbow: Vec;
  rHand: Vec;
  lKnee: Vec;
  lFoot: Vec;
  rKnee: Vec;
  rFoot: Vec;
  /** Absolute rotation of the head (rad, clockwise) for drawing the face. */
  headAngle: number;
  facing: 1 | -1;
}

/** Leg angles that put the foot at (dx forward, dy down) from the hip. */
export function legTo(dx: number, dy: number): { hip: number; knee: number } {
  const s = solveTwoBone(dx, dy, BONES.thigh, BONES.shin, 1);
  return { hip: s.upper, knee: -s.lower };
}

/** Arm angles (relative to the torso) that put the hand at (dx forward, dy down) from the shoulder. */
export function armTo(dx: number, dy: number, torso: number, bend: 1 | -1 = -1): { shoulder: number; elbow: number } {
  // Arms hang relative to the spine: solve in absolute angles, then subtract the lean.
  const sol = solveTwoBone(dx, dy, BONES.upperArm, BONES.foreArm, bend);
  return { shoulder: sol.upper - torso, elbow: sol.lower };
}

const standLeftLeg = legTo(-3, STAND_HIP);
const standRightLeg = legTo(4, STAND_HIP);

/** Relaxed standing pose at the origin. */
export const STAND: Readonly<Pose> = {
  x: 0,
  y: 0,
  rot: 0,
  facing: 1,
  torso: 0.04,
  head: 0,
  lShoulder: -0.2,
  lElbow: 0.3,
  rShoulder: 0.2,
  rElbow: 0.38,
  lHip: standLeftLeg.hip,
  lKnee: standLeftLeg.knee,
  rHip: standRightLeg.hip,
  rKnee: standRightLeg.knee,
  squash: 1,
};

/** Allocation guard for tests: how many full forward-kinematics solves ran (a proxy for per-frame garbage). */
export const KIN_STATS = { fk: 0 };

/**
 * Forward kinematics: joint positions relative to the hip. Pure. Computed straight into the 12 result points
 * (no intermediate vectors), so a solve allocates only what it returns.
 */
export function forwardKinematics(p: Pose): Joints {
  KIN_STATS.fk++;
  // Horizontal direction; during a turn it passes smoothly through 0 instead of flipping.
  const f = p.turn ?? p.facing;
  const ux = f * Math.sin(p.torso);
  const uy = -Math.cos(p.torso);
  const headTilt = p.torso + p.head;
  const nx = ux * BONES.spine;
  const ny = uy * BONES.spine;
  const sx0 = ux * BONES.shoulderAt;
  const sy0 = uy * BONES.shoulderAt;
  const hl = BONES.neck + BONES.headR;
  const hx = nx + f * Math.sin(headTilt) * hl;
  const hy = ny - Math.cos(headTilt) * hl;
  const lU = p.torso + p.lShoulder;
  const rU = p.torso + p.rShoulder;
  const leX = sx0 + f * Math.sin(lU) * BONES.upperArm;
  const leY = sy0 + Math.cos(lU) * BONES.upperArm;
  const lhX = leX + f * Math.sin(lU + p.lElbow) * BONES.foreArm;
  const lhY = leY + Math.cos(lU + p.lElbow) * BONES.foreArm;
  const reX = sx0 + f * Math.sin(rU) * BONES.upperArm;
  const reY = sy0 + Math.cos(rU) * BONES.upperArm;
  const rhX = reX + f * Math.sin(rU + p.rElbow) * BONES.foreArm;
  const rhY = reY + Math.cos(rU + p.rElbow) * BONES.foreArm;
  const lkX = f * Math.sin(p.lHip) * BONES.thigh;
  const lkY = Math.cos(p.lHip) * BONES.thigh;
  const lfX = lkX + f * Math.sin(p.lHip - p.lKnee) * BONES.shin;
  const lfY = lkY + Math.cos(p.lHip - p.lKnee) * BONES.shin;
  const rkX = f * Math.sin(p.rHip) * BONES.thigh;
  const rkY = Math.cos(p.rHip) * BONES.thigh;
  const rfX = rkX + f * Math.sin(p.rHip - p.rKnee) * BONES.shin;
  const rfY = rkY + Math.cos(p.rHip - p.rKnee) * BONES.shin;

  const anchorY = Math.max(lfY, rfY);
  const sy = p.squash;
  const sx = 1 / Math.sqrt(Math.max(sy, 0.05));
  const cos = Math.cos(p.rot);
  const sin = Math.sin(p.rot);
  const T = (qx: number, qy: number): Vec => {
    const x = qx * sx;
    const y = anchorY + (qy - anchorY) * sy;
    return { x: x * cos - y * sin, y: x * sin + y * cos };
  };
  return {
    hip: T(0, 0),
    neck: T(nx, ny),
    shoulder: T(sx0, sy0),
    head: T(hx, hy),
    lElbow: T(leX, leY),
    lHand: T(lhX, lhY),
    rElbow: T(reX, reY),
    rHand: T(rhX, rhY),
    lKnee: T(lkX, lkY),
    lFoot: T(lfX, lfY),
    rKnee: T(rkX, rkY),
    rFoot: T(rfX, rfY),
    headAngle: p.rot + p.facing * headTilt,
    facing: p.facing,
  };
}

/** World position of the shoulder (squash ignored) — without a full FK solve. */
export function shoulderPos(p: Pose): Vec {
  const f = p.turn ?? p.facing;
  const x = f * Math.sin(p.torso) * BONES.shoulderAt;
  const y = -Math.cos(p.torso) * BONES.shoulderAt;
  const c = Math.cos(p.rot);
  const s = Math.sin(p.rot);
  return { x: p.x + x * c - y * s, y: p.y + x * s + y * c };
}

/** World position of the head centre (squash ignored). */
export function headPos(p: Pose): Vec {
  const f = p.turn ?? p.facing;
  const t = p.torso + p.head;
  const hl = BONES.neck + BONES.headR;
  const x = f * Math.sin(p.torso) * BONES.spine + f * Math.sin(t) * hl;
  const y = -Math.cos(p.torso) * BONES.spine - Math.cos(t) * hl;
  const c = Math.cos(p.rot);
  const s = Math.sin(p.rot);
  return { x: p.x + x * c - y * s, y: p.y + x * s + y * c };
}

/** World position of a hand (squash ignored). */
export function handPos(p: Pose, side: 'l' | 'r'): Vec {
  const f = p.turn ?? p.facing;
  const up = p.torso + (side === 'l' ? p.lShoulder : p.rShoulder);
  const el = side === 'l' ? p.lElbow : p.rElbow;
  const x = f * (Math.sin(p.torso) * BONES.shoulderAt + Math.sin(up) * BONES.upperArm + Math.sin(up + el) * BONES.foreArm);
  const y = -Math.cos(p.torso) * BONES.shoulderAt + Math.cos(up) * BONES.upperArm + Math.cos(up + el) * BONES.foreArm;
  const c = Math.cos(p.rot);
  const s = Math.sin(p.rot);
  return { x: p.x + x * c - y * s, y: p.y + x * s + y * c };
}

/** World position of a foot (squash ignored). */
export function footPos(p: Pose, side: 'l' | 'r'): Vec {
  const f = p.turn ?? p.facing;
  const hip = side === 'l' ? p.lHip : p.rHip;
  const knee = side === 'l' ? p.lKnee : p.rKnee;
  const x = f * (Math.sin(hip) * BONES.thigh + Math.sin(hip - knee) * BONES.shin);
  const y = Math.cos(hip) * BONES.thigh + Math.cos(hip - knee) * BONES.shin;
  const c = Math.cos(p.rot);
  const s = Math.sin(p.rot);
  return { x: p.x + x * c - y * s, y: p.y + x * s + y * c };
}

/** Linear blend of two poses; a change of facing becomes a continuous turn (x-scale through 0). */
export function lerpPose(a: Pose, b: Pose, t: number): Pose {
  const m = (x: number, y: number): number => x + (y - x) * t;
  const ta = a.turn ?? a.facing;
  const tb = b.turn ?? b.facing;
  const turn = ta === tb || t >= 1 ? {} : { turn: m(ta, tb) };
  return {
    ...turn,
    x: m(a.x, b.x),
    y: m(a.y, b.y),
    rot: m(a.rot, b.rot),
    facing: t < 0.5 ? a.facing : b.facing,
    torso: m(a.torso, b.torso),
    head: m(a.head, b.head),
    lShoulder: m(a.lShoulder, b.lShoulder),
    lElbow: m(a.lElbow, b.lElbow),
    rShoulder: m(a.rShoulder, b.rShoulder),
    rElbow: m(a.rElbow, b.rElbow),
    lHip: m(a.lHip, b.lHip),
    lKnee: m(a.lKnee, b.lKnee),
    rHip: m(a.rHip, b.rHip),
    rKnee: m(a.rKnee, b.rKnee),
    squash: m(a.squash, b.squash),
  };
}

/** Lowest point of the figure relative to the hip (for ground contact). */
export function footReach(p: Pose): number {
  const j = forwardKinematics(p);
  return Math.max(j.lFoot.y, j.rFoot.y);
}

/** Chain end of a leg for tests/animation checks. */
export function footOffset(hipAngle: number, knee: number): Vec {
  return chainEnd(hipAngle, -knee, BONES.thigh, BONES.shin);
}

const TRAVEL_KEYS = ['head', 'lHand', 'rHand', 'lFoot', 'rFoot', 'lKnee', 'rKnee', 'lElbow', 'rElbow'] as const;

/** Largest distance any drawn joint moves between two poses (px, world). */
export function maxJointTravel(a: Pose, b: Pose): number {
  const ja = forwardKinematics(a);
  const jb = forwardKinematics(b);
  let d = 0;
  for (const k of TRAVEL_KEYS) {
    d = Math.max(d, Math.hypot(b.x + jb[k].x - a.x - ja[k].x, b.y + jb[k].y - a.y - ja[k].y));
  }
  return d;
}

/** Same angle as `target`, unwrapped by whole turns to the one nearest to `ref` (no spinning the long way round). */
export function nearestAngle(target: number, ref: number): number {
  return target + 2 * Math.PI * Math.round((ref - target) / (2 * Math.PI));
}

/** `from` with every angle moved by whole turns to the equivalent nearest to `to` (same drawing, shortest blend). */
export function unwrapToward(from: Pose, to: Pose): Pose {
  const out: Pose = { ...from };
  for (const k of ANGLE_KEYS) {
    out[k] = nearestAngle(from[k], to[k]);
  }
  out.rot = nearestAngle(from.rot, to.rot);
  return out;
}

/** Longest joint path when blending a → b (sampled, so arcs count, not just the end points). */
export function blendTravel(a: Pose, b: Pose): number {
  let total = 0;
  let prev = a;
  for (let i = 1; i <= 6; i++) {
    const cur = lerpPose(a, b, i / 6);
    total += maxJointTravel(prev, cur);
    prev = cur;
  }
  return total;
}

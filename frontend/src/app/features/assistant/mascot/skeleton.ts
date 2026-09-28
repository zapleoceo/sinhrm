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

/** Direction of an angle measured from straight down (forward = facing). */
function down(a: number, facing: 1 | -1): Vec {
  return { x: facing * Math.sin(a), y: Math.cos(a) };
}

function add(p: Vec, d: Vec, len: number): Vec {
  return { x: p.x + d.x * len, y: p.y + d.y * len };
}

/** Forward kinematics: joint positions relative to the hip. Pure. */
export function forwardKinematics(p: Pose): Joints {
  const f = p.facing;
  const hip: Vec = { x: 0, y: 0 };
  const up = { x: f * Math.sin(p.torso), y: -Math.cos(p.torso) };
  const neck = add(hip, up, BONES.spine);
  const shoulder = add(hip, up, BONES.shoulderAt);
  const headTilt = p.torso + p.head;
  const head = add(neck, { x: f * Math.sin(headTilt), y: -Math.cos(headTilt) }, BONES.neck + BONES.headR);
  const lUpper = p.torso + p.lShoulder;
  const rUpper = p.torso + p.rShoulder;
  const lElbow = add(shoulder, down(lUpper, f), BONES.upperArm);
  const lHand = add(lElbow, down(lUpper + p.lElbow, f), BONES.foreArm);
  const rElbow = add(shoulder, down(rUpper, f), BONES.upperArm);
  const rHand = add(rElbow, down(rUpper + p.rElbow, f), BONES.foreArm);
  const lKnee = add(hip, down(p.lHip, f), BONES.thigh);
  const lFoot = add(lKnee, down(p.lHip - p.lKnee, f), BONES.shin);
  const rKnee = add(hip, down(p.rHip, f), BONES.thigh);
  const rFoot = add(rKnee, down(p.rHip - p.rKnee, f), BONES.shin);

  const points = { hip, neck, shoulder, head, lElbow, lHand, rElbow, rHand, lKnee, lFoot, rKnee, rFoot };
  const anchorY = Math.max(lFoot.y, rFoot.y);
  const sy = p.squash;
  const sx = 1 / Math.sqrt(Math.max(sy, 0.05));
  const cos = Math.cos(p.rot);
  const sin = Math.sin(p.rot);
  const out = {} as Record<keyof typeof points, Vec>;
  for (const key of Object.keys(points) as (keyof typeof points)[]) {
    const q = points[key];
    const x = q.x * sx;
    const y = anchorY + (q.y - anchorY) * sy;
    out[key] = { x: x * cos - y * sin, y: x * sin + y * cos };
  }
  return { ...out, headAngle: p.rot + f * headTilt, facing: f };
}

/** Linear blend of two poses (facing switches at the midpoint). */
export function lerpPose(a: Pose, b: Pose, t: number): Pose {
  const m = (x: number, y: number): number => x + (y - x) * t;
  return {
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

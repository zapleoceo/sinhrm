/**
 * Analytic two-bone IK in the mascot's limb convention: angles are measured from "hanging straight down",
 * positive = rotating forward (+x). The target is relative to the root joint (shoulder or hip), y down.
 */
export interface TwoBoneSolution {
  /** Absolute angle of the first bone (from straight down, forward positive). */
  upper: number;
  /** Angle of the second bone relative to the first (forward positive). */
  lower: number;
  /** false when the target was out of reach (the limb is stretched toward it). */
  reached: boolean;
}

const EPS = 1e-4;

/** Angle from straight down of a vector (x forward, y down). */
export function angleFromDown(x: number, y: number): number {
  return Math.atan2(x, y);
}

/**
 * Solves shoulder→elbow→hand (or hip→knee→foot) for a target (tx, ty).
 * bend = +1 puts the middle joint forward of the root→target line (a knee), -1 behind it (an elbow reaching up).
 */
export function solveTwoBone(tx: number, ty: number, l1: number, l2: number, bend: 1 | -1): TwoBoneSolution {
  const dist = Math.hypot(tx, ty);
  const min = Math.abs(l1 - l2) + EPS;
  const max = l1 + l2 - EPS;
  const d = Math.min(Math.max(dist, min), max);
  const base = dist < EPS ? 0 : angleFromDown(tx, ty);
  const cosA = (l1 * l1 + d * d - l2 * l2) / (2 * l1 * d);
  const a = Math.acos(Math.min(1, Math.max(-1, cosA)));
  const upper = base + bend * a;
  const kx = l1 * Math.sin(upper);
  const ky = l1 * Math.cos(upper);
  // Direction of the second bone toward the (clamped) target point.
  const px = Math.sin(base) * d;
  const py = Math.cos(base) * d;
  const lowerAbs = angleFromDown(px - kx, py - ky);
  return { upper, lower: normalizeAngle(lowerAbs - upper), reached: dist >= min && dist <= max };
}

/** Wraps an angle to (-π, π]. */
export function normalizeAngle(a: number): number {
  let r = a % (2 * Math.PI);
  if (r <= -Math.PI) {
    r += 2 * Math.PI;
  } else if (r > Math.PI) {
    r -= 2 * Math.PI;
  }
  return r;
}

/** End point of a two-bone chain (forward kinematics in the same convention). */
export function chainEnd(upper: number, lower: number, l1: number, l2: number): { x: number; y: number } {
  return {
    x: l1 * Math.sin(upper) + l2 * Math.sin(upper + lower),
    y: l1 * Math.cos(upper) + l2 * Math.cos(upper + lower),
  };
}

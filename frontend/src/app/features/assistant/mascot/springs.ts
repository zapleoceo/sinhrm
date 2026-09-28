import { ANGLE_KEYS, AngleKey, Pose } from './skeleton';

/** 1-D damped spring (unit mass). */
export interface SpringState {
  x: number;
  v: number;
}

/** k — stiffness (1/s²), zeta — damping ratio (1 = critical, < 1 overshoots). */
export interface SpringParams {
  k: number;
  zeta: number;
}

/** Semi-implicit Euler step toward `target`. Pure; stable for dt·√k < 1 (60 Hz fixed step). */
export function springStep(s: SpringState, target: number, dt: number, p: SpringParams): SpringState {
  const c = 2 * p.zeta * Math.sqrt(p.k);
  const a = p.k * (target - s.x) - c * s.v;
  const v = s.v + a * dt;
  return { x: s.x + v * dt, v };
}

/** Frame-rate independent exponential approach (critically damped smoothing without velocity). */
export function approach(current: number, target: number, dt: number, halfLife: number): number {
  if (halfLife <= 0) {
    return target;
  }
  return target + (current - target) * Math.pow(2, -dt / halfLife);
}

/** Joints with secondary motion: loose arms and head that overshoot. Legs follow the clip exactly (planted feet never slide). */
export type LooseKey = 'torso' | 'head' | 'lShoulder' | 'rShoulder' | 'lElbow' | 'rElbow';

export const JOINT_SPRINGS: Readonly<Record<LooseKey, SpringParams>> = {
  torso: { k: 260, zeta: 0.7 },
  head: { k: 170, zeta: 0.42 },
  lShoulder: { k: 150, zeta: 0.4 },
  rShoulder: { k: 150, zeta: 0.4 },
  lElbow: { k: 120, zeta: 0.36 },
  rElbow: { k: 120, zeta: 0.36 },
};

function isLoose(key: AngleKey): key is LooseKey {
  return key in JOINT_SPRINGS;
}

/** Squash & stretch wobble after an impact. */
export const SQUASH_SPRING: SpringParams = { k: 320, zeta: 0.32 };

export type PoseSprings = Record<AngleKey | 'squash', SpringState>;

export function initPoseSprings(p: Pose): PoseSprings {
  const out = { squash: { x: p.squash, v: 0 } } as PoseSprings;
  for (const key of ANGLE_KEYS) {
    out[key] = { x: p[key], v: 0 };
  }
  return out;
}

/**
 * Advances every joint spring toward the target pose. Root acceleration (ax, ay, px/s², body frame: x forward, clamped)
 * swings the arms and bobs the head — inertia makes them lag and overshoot after a stop.
 */
export function stepPoseSprings(s: PoseSprings, target: Pose, dt: number, rawAx: number, rawAy: number): PoseSprings {
  const out = {} as PoseSprings;
  const ax = Math.max(-8000, Math.min(8000, rawAx));
  const ay = Math.max(-8000, Math.min(8000, rawAy));
  const kick = (key: AngleKey): number => {
    switch (key) {
      case 'lShoulder':
      case 'rShoulder':
        return -ax * 0.008;
      case 'lElbow':
      case 'rElbow':
        return -ax * 0.005 + ay * 0.004;
      case 'head':
        return -ax * 0.005 - ay * 0.003;
      case 'torso':
        return -ax * 0.002;
      default:
        return 0;
    }
  };
  for (const key of ANGLE_KEYS) {
    const cur = s[key];
    out[key] = isLoose(key) ? springStep({ x: cur.x, v: cur.v + kick(key) * dt }, target[key], dt, JOINT_SPRINGS[key]) : { x: target[key], v: 0 };
  }
  const sq = springStep(s.squash, target.squash, dt, SQUASH_SPRING);
  out.squash = { x: Math.min(1.35, Math.max(0.55, sq.x)), v: sq.v };
  return out;
}

/** Target pose with the spring-filtered angles applied. */
export function applySprings(target: Pose, s: PoseSprings): Pose {
  const out: Pose = { ...target, squash: s.squash.x };
  for (const key of ANGLE_KEYS) {
    out[key] = s[key].x;
  }
  return out;
}

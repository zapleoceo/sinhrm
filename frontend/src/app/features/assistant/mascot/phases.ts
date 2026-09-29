import { ActionName, Clip, ClipEvent, ClipFrame, InkProp, ease } from './animations';
import { Pose, blendTravel, lerpPose, unwrapToward } from './skeleton';

/**
 * Phase runner shared by seat routes, locomotion moves and idle activities: a clip made of phases, each a pure
 * function of its local progress u ∈ [0, 1]. It starts with a blend from the exact current pose and fades every seam
 * out as an offset, so a clip never jumps (the continuity tests hold it to < 40 px per frame).
 */

export interface Phase {
  dur: number;
  /** Stretched/squeezed to fit the clip into its min..max duration. */
  flexible: boolean;
  /** Never squeezed below this (speed cap). */
  minDur?: number;
  at(u: number): ClipFrame;
}

export interface BuildOptions {
  events?: (starts: number[]) => ClipEvent[];
  minTotal?: number;
  maxTotal?: number;
  /** Start exactly at the first phase (no blend from the current pose) — for entrances from off screen. */
  cut?: boolean;
}

const BLEND_IN = 0.25;
const CROSS = 0.14;

export const n = (v: number): string => (Math.round(v * 10) / 10).toString();

export function ink(d: string, draw: number, lite: boolean, alpha = 1): InkProp {
  return { d, draw: lite ? 1 : Math.max(0, Math.min(1, draw)), alpha };
}

/** A phase of fixed length (a gesture, a trick). */
export function holdPhase(dur: number, at: (u: number) => ClipFrame): Phase {
  return { dur, flexible: false, at };
}

/** End pose of a phase (for the phase that follows it). */
export const endOf = (p: Phase) => (): Pose => p.at(1).pose;

/** `pose` shifted by `k` × (from − to) in every channel (angles unwrapped): a fading seam offset. */
export function offsetPose(pose: Pose, from: Pose, to: Pose, k: number): Pose {
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

/** Runs phases in order, starting with a blend from the exact current pose (`from`). */
export function buildPhases(action: ActionName, from: Pose, phases: Phase[], o: BuildOptions = {}): Clip {
  const first = phases[0];
  const firstPose = first.at(0).pose;
  // Longer blend-in when he starts far from the first pose (e.g. sitting → a trick).
  const blendIn = o.cut ? 0 : Math.min(0.8, Math.max(BLEND_IN, blendTravel(unwrapToward(from, firstPose), firstPose) / 420));
  const fixed = phases.filter((p) => !p.flexible).reduce((a, p) => a + p.dur, 0) + blendIn;
  const flex = phases.filter((p) => p.flexible).reduce((a, p) => a + p.dur, 0);
  let k = 1;
  if (flex > 0) {
    if (o.maxTotal !== undefined && fixed + flex > o.maxTotal) {
      k = Math.max(0.3, (o.maxTotal - fixed) / flex);
    } else if (o.minTotal !== undefined && fixed + flex < o.minTotal) {
      k = (o.minTotal - fixed) / flex;
    }
  }
  const durs = phases.map((p) => (p.flexible ? Math.max(p.minDur ?? 0, p.dur * k) : p.dur));
  const starts: number[] = [];
  let t0 = blendIn;
  for (const d of durs) {
    starts.push(t0);
    t0 += d;
  }
  const duration = t0;
  const crossDur = phases.map(() => -1);
  const seams: ({ end: Pose; start: Pose } | undefined)[] = phases.map(() => undefined);
  return {
    action,
    duration,
    blend: 0,
    physics: false,
    stiff: true,
    events: o.events ? o.events(starts) : [],
    sample(t: number): ClipFrame {
      if (t < blendIn) {
        const target = first.at(0);
        const u = ease.inOut(t / blendIn);
        return { ...target, pose: lerpPose(unwrapToward(from, target.pose), target.pose, u) };
      }
      for (let i = phases.length - 1; i >= 0; i--) {
        if (t >= starts[i] || i === 0) {
          const u = durs[i] <= 0 ? 1 : Math.min(1, (t - starts[i]) / durs[i]);
          const frame = phases[i].at(u);
          const since = t - starts[i];
          if (i > 0) {
            let cross = crossDur[i];
            if (cross < 0 || since < cross) {
              // The seam poses are read once per phase (while inside the fade window), not every frame.
              const seam = (seams[i] ??= { end: phases[i - 1].at(1).pose, start: phases[i].at(0).pose });
              if (cross < 0) {
                const gap = blendTravel(seam.end, seam.start);
                // Already continuous (a bounce flowing into the next): no fade.
                cross = gap < 2 ? 0 : Math.min(durs[i] * 0.8, Math.max(CROSS, gap / 420));
                crossDur[i] = cross;
              }
              if (since < cross) {
                // The seam gap fades out as an offset on top of the new phase: its own motion is kept.
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

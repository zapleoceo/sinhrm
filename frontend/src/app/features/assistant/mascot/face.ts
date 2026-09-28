import { Vec } from './skeleton';

/** Eye drawings: dots, ^ ^ when happy, lines when sleepy, X X after a bonk, spirals when dizzy. */
export type EyeShape = 'dot' | 'wide' | 'happy' | 'sleepy' | 'closed' | 'x' | 'spiral';
export type MouthShape = 'smile' | 'grin' | 'o' | 'flat' | 'wobbly' | 'frown' | 'open';

export interface Expression {
  eyes: EyeShape;
  /** -1 frowning … 0 neutral … 1 raised/worried. */
  brows: number;
  mouth: MouthShape;
  /** 0..1 — how open the mouth is while talking. */
  talk: number;
}

export const NEUTRAL: Readonly<Expression> = { eyes: 'dot', brows: 0, mouth: 'smile', talk: 0 };

/**
 * Pupil offset inside an eye white for a cursor position (all in the same coordinates).
 * Far cursor → almost the full `max`; near cursor → a smaller offset (eased by distance), so both eyes converge
 * when the cursor is close. The result never leaves the circle of radius `max`.
 */
export function pupilOffset(eye: Vec, cursor: Vec, max: number, falloff = 90): Vec {
  const dx = cursor.x - eye.x;
  const dy = cursor.y - eye.y;
  const len = Math.hypot(dx, dy);
  if (len < 1e-6 || max <= 0) {
    return { x: 0, y: 0 };
  }
  const mag = max * (1 - Math.exp(-len / falloff));
  return { x: (dx / len) * mag, y: (dy / len) * mag };
}

/** Lid closure (0 open … 1 shut) when the cursor is very close to the eyes: he squints at it. */
export function squintFor(distance: number, near = 70): number {
  return distance >= near ? 0 : 0.45 * (1 - distance / near);
}

/** Blink curve: 0 → 1 → 0 over `duration` seconds starting at `start`. */
export function blinkAmount(t: number, start: number, duration = 0.14): number {
  const u = (t - start) / duration;
  return u <= 0 || u >= 1 ? 0 : Math.sin(u * Math.PI);
}

/** Rigid-body root of the mascot while airborne (thrown, jumping, falling). Pure fixed-step integration. */
export interface Body {
  x: number;
  y: number;
  vx: number;
  vy: number;
  /** Body rotation (rad, clockwise) and its angular velocity — the ragdoll-ish tumble. */
  rot: number;
  spin: number;
}

export interface World {
  width: number;
  /** Hip y at which the feet touch the ground (floor or the chat panel edge). */
  groundHip: number;
  gravity: number;
  /** Air drag (1/s). */
  drag: number;
  /** Vertical velocity kept after a bounce. */
  restitution: number;
  /** Horizontal velocity kept after touching the ground. */
  friction: number;
  /** Bounce off the side edges (false for exits that leave the screen). */
  walls: boolean;
  /** Land on the ground (false for exits through the bottom edge). */
  floor: boolean;
  wallMargin: number;
  /** Lowest hip y allowed at the top edge (null — no ceiling). */
  ceiling: number | null;
}

export type PhysicsEvent =
  | { type: 'impact'; speed: number }
  | { type: 'wall'; speed: number }
  | { type: 'settled' }
  | { type: 'offscreen' };

export interface StepResult {
  body: Body;
  events: PhysicsEvent[];
  /** Resting on the ground: the airborne phase is over. */
  settled: boolean;
}

export const PHYSICS_DT = 1 / 60;
export const GRAVITY = 2600;
/** Below this vertical speed after a bounce he stays on the ground. */
const SETTLE_SPEED = 150;
const OFFSCREEN = 260;

export function defaultWorld(width: number, groundHip: number): World {
  return {
    width,
    groundHip,
    gravity: GRAVITY,
    drag: 0.35,
    restitution: 0.36,
    friction: 0.6,
    walls: true,
    floor: true,
    wallMargin: 30,
    ceiling: 50,
  };
}

/** Vertical launch speed that reaches `height` px above the start (negative = up). */
export function launchSpeed(height: number, gravity = GRAVITY): number {
  return -Math.sqrt(2 * gravity * Math.max(0, height));
}

/** Time to reach the apex for a launch speed. */
export function apexTime(vy: number, gravity = GRAVITY): number {
  return Math.max(0, -vy / gravity);
}

/** One fixed step: gravity, drag, spin, walls, ceiling and ground bounce with restitution. */
export function physicsStep(b: Body, dt: number, w: World): StepResult {
  const events: PhysicsEvent[] = [];
  const damp = Math.exp(-w.drag * dt);
  let vx = b.vx * damp;
  let vy = (b.vy + w.gravity * dt) * damp;
  let spin = b.spin * Math.exp(-0.3 * dt);
  let x = b.x + vx * dt;
  let y = b.y + vy * dt;
  const rot = b.rot + spin * dt;
  let settled = false;

  if (w.walls) {
    const min = w.wallMargin;
    const max = w.width - w.wallMargin;
    if ((x < min && vx < 0) || (x > max && vx > 0)) {
      events.push({ type: 'wall', speed: Math.abs(vx) });
      x = x < min ? min : max;
      vx = -vx * 0.5;
      spin = -spin * 0.6;
    }
  }
  if (w.ceiling !== null && y < w.ceiling && vy < 0) {
    y = w.ceiling;
    vy = -vy * 0.3;
  }
  if (w.floor && y >= w.groundHip && vy > 0) {
    events.push({ type: 'impact', speed: vy });
    y = w.groundHip;
    vy = -vy * w.restitution;
    vx *= w.friction;
    spin *= 0.35;
    if (Math.abs(vy) < SETTLE_SPEED) {
      vy = 0;
      settled = true;
      events.push({ type: 'settled' });
    }
  }
  const off = (!w.floor && y > w.groundHip + OFFSCREEN) || (!w.walls && (x < -OFFSCREEN || x > w.width + OFFSCREEN));
  if (off) {
    events.push({ type: 'offscreen' });
  }
  return { body: { x, y, vx, vy, rot, spin }, events, settled };
}

/** Fixed-timestep accumulator: how many steps of `h` fit into the elapsed frame time (capped to avoid a spiral). */
export function accumulate(acc: number, frameDt: number, h = PHYSICS_DT, maxFrame = 0.1): { steps: number; acc: number } {
  let total = acc + Math.min(Math.max(frameDt, 0), maxFrame);
  let steps = 0;
  while (total >= h) {
    total -= h;
    steps++;
  }
  return { steps, acc: total };
}

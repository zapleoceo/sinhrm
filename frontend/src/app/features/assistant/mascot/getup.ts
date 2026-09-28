import { Clip, ClipFrame, GetUpAction, Key, Stage, breathing, ease, keyed, plant, pose, reach, segment, simple, standAt } from './animations';
import { BONES, Pose, blendTravel, forwardKinematics, unwrapToward } from './skeleton';
import { Orientation } from './ragdoll';

/**
 * Getting up after a real fall. Every sequence STARTS at the exact current pose (fitted from the resting ragdoll),
 * blends to the first key in ~0.22 s and then follows keyframes: roll → push up → knee → squat → stand.
 * The root is continuous and the body never sinks into the floor (the whole pose is lifted if a limb would).
 */

const PI = Math.PI;

export type GetUpVariant = 'full' | 'sit';

export interface GetUpContext {
  stage: Stage;
  from: Pose;
  orientation: Orientation;
  variant: GetUpVariant;
}

/** Lifts the pose so no joint goes below the floor. */
export function keepAboveGround(p: Pose, ground: number): Pose {
  const j = forwardKinematics(p);
  const lowest = Math.max(
    j.lFoot.y,
    j.rFoot.y,
    j.lKnee.y + 1,
    j.rKnee.y + 1,
    j.lHand.y + 1,
    j.rHand.y + 1,
    3,
    j.head.y + BONES.headR,
  );
  const excess = p.y + lowest - ground;
  return excess > 0 ? { ...p, y: p.y - excess } : p;
}

/* ───────────── canonical poses on the floor ───────────── */

function lyingBack(x: number, g: number, f: 1 | -1): Pose {
  const p = pose({ x, y: g - 3, rot: -f * (PI / 2), facing: f, torso: 0, head: -0.1, lShoulder: 0.2, lElbow: 0.2, rShoulder: 0.35, rElbow: 0.3 });
  // Knees up, feet flat on the floor.
  plant(p, 'l', { x: x + f * 34, y: g - 2.5 });
  plant(p, 'r', { x: x + f * 38, y: g - 2.5 });
  return p;
}

function sittingFloor(x: number, g: number, f: 1 | -1): Pose {
  const p = pose({ x, y: g - 3, rot: 0, facing: f, torso: 0.28, head: 0.05 });
  plant(p, 'l', { x: x + f * 40, y: g - 2.5 });
  plant(p, 'r', { x: x + f * 42, y: g - 2.5 });
  reach(p, 'l', { x: x - f * 14, y: g - 1 }, 1);
  reach(p, 'r', { x: x - f * 9, y: g - 1 }, 1);
  return p;
}

function tucked(x: number, g: number, f: 1 | -1): Pose {
  const p = pose({ x, y: g - 3, rot: 0, facing: f, torso: 0.62, head: -0.1 });
  plant(p, 'l', { x: x + f * 17, y: g - 2.5 });
  plant(p, 'r', { x: x + f * 21, y: g - 2.5 });
  reach(p, 'l', { x: x - f * 6, y: g - 1 }, 1);
  reach(p, 'r', { x: x + f * 2, y: g - 1 }, 1);
  return p;
}

function squat(x: number, g: number, f: 1 | -1): Pose {
  const p = pose({ x: x + f * 9, y: g - 27, rot: 0, facing: f, torso: 0.55, head: -0.25, lShoulder: 0.9, lElbow: 0.7, rShoulder: 1.0, rElbow: 0.6 });
  plant(p, 'l', { x: x + f * 15, y: g - 2.5 });
  plant(p, 'r', { x: x + f * 20, y: g - 2.5 });
  return p;
}

function lyingFace(x: number, g: number, f: 1 | -1): Pose {
  return pose({ x, y: g - 3, rot: f * (PI / 2), facing: f, torso: 0, head: -0.3, lShoulder: PI + 0.2, lElbow: 0.1, rShoulder: PI + 0.35, rElbow: 0.2, lHip: -0.05, lKnee: 0.1, rHip: 0.05, rKnee: 0.2 });
}

function plank(x: number, g: number, f: 1 | -1, alpha: number): Pose {
  const len = BONES.thigh + BONES.shin;
  const footX = x - f * len;
  const p = pose({ x: footX + f * len * Math.cos(alpha), y: g - len * Math.sin(alpha) - 2.5, rot: f * (PI / 2 - alpha), facing: f, torso: 0, head: -0.35, lHip: 0, lKnee: 0.05, rHip: 0.05, rKnee: 0.05 });
  const j = forwardKinematics(p);
  const sx = p.x + j.shoulder.x;
  reach(p, 'l', { x: sx - f, y: g - 1 }, -1);
  reach(p, 'r', { x: sx + f * 3, y: g - 1 }, -1);
  return p;
}

function kneeling(x: number, g: number, f: 1 | -1): Pose {
  return pose({ x, y: g - 2.5 - BONES.thigh, rot: 0, facing: f, torso: 0.3, head: -0.2, lHip: 0.05, lKnee: PI / 2, rHip: 0.5, rKnee: 1.9, lShoulder: 0.4, lElbow: 0.5, rShoulder: 0.55, rElbow: 0.4 });
}

/* ───────────── sequences ───────────── */

interface Step {
  dt: number;
  p: Pose;
  e?: (u: number) => number;
}

/** Joints never travel faster than this on average within a key segment (px/s; the eased peak is ~3×). */
const MAX_JOINT_SPEED = 600;

function keysFrom(from: Pose, steps: Step[]): Key[] {
  const keys: Key[] = [{ t: 0, p: from }];
  let t = 0;
  let prev = from;
  for (const s of steps) {
    // Every angle takes the short way from the previous key (the ragdoll can leave any multiple of 2π).
    const p = unwrapToward(s.p, prev);
    // Longer segments when limbs have far to go along the blended path (no whip-fast frames).
    t += Math.max(s.dt, blendTravel(prev, p) / MAX_JOINT_SPEED);
    keys.push({ t, p, e: s.e });
    prev = p;
  }
  return keys;
}

function steps(c: GetUpContext): Step[] {
  const g = c.stage.ground;
  const x = c.from.x;
  const f = c.from.facing;
  const toSit: Step[] = [];
  switch (c.orientation) {
    case 'back':
      toSit.push({ dt: 0.22, p: lyingBack(x, g, f) }, { dt: 0.55, p: sittingFloor(x, g, f), e: ease.out });
      break;
    case 'side':
      // Roll onto the back first, then sit up.
      toSit.push({ dt: 0.22, p: { ...lyingBack(x, g, f), lHip: 1.4, lKnee: 2.1, rHip: 1.2, rKnee: 2 } }, { dt: 0.3, p: lyingBack(x, g, f) }, { dt: 0.55, p: sittingFloor(x, g, f), e: ease.out });
      break;
    case 'face':
      toSit.push(
        { dt: 0.22, p: lyingFace(x, g, f) },
        { dt: 0.45, p: plank(x, g, f, 0.5) },
        { dt: 0.35, p: kneeling(x - f * 12, g, f) },
      );
      break;
    case 'sitting':
      toSit.push({ dt: 0.22, p: sittingFloor(x, g, f) });
      break;
  }
  if (c.variant === 'sit') {
    const last = toSit[toSit.length - 1].p;
    if (c.orientation === 'face') {
      toSit.push({ dt: 0.45, p: sittingFloor(last.x, g, f) });
    }
    return toSit;
  }
  // Stand up on screen even if he fell against a wall.
  const base = Math.min(c.stage.width - 45, Math.max(45, toSit[toSit.length - 1].p.x));
  return [...toSit, ...standUpSteps(base, g, f, c.orientation === 'face')];
}

function standUpSteps(x: number, g: number, f: 1 | -1, fromKnee: boolean): Step[] {
  const rise: Step[] = fromKnee ? [] : [{ dt: 0.38, p: tucked(x, g, f) }];
  return [...rise, { dt: 0.38, p: squat(x, g, f) }, { dt: 0.45, p: standAt({ width: 0, height: 0, ground: g, seat: null, corner: { x: 0, y: 0 } }, x + f * 14, f), e: ease.back }];
}

function sequence(action: GetUpAction, from: Pose, stage: Stage, keys: Key[], expr: (t: number) => ClipFrame['expr']): Clip {
  const track = keyed(keys);
  const duration = keys[keys.length - 1].t;
  return {
    action,
    duration,
    blend: 0,
    physics: false,
    stiff: true,
    events: [{ t: duration - 0.2, type: 'dust', at: 'feet', strength: 0.25 }],
    sample: (t: number): ClipFrame => ({ pose: keepAboveGround(track(t), stage.ground), expr: expr(t) }),
  };
}

/** Get up (or only sit up, variant 'sit') from wherever the ragdoll came to rest. */
export function createGetUp(c: GetUpContext): Clip {
  const keys = keysFrom(c.from, steps(c));
  return sequence('getup', c.from, c.stage, keys, (t) => (t < 0.5 ? { eyes: 'closed', brows: 0.4, mouth: 'wobbly' } : { brows: -0.3, mouth: 'flat' }));
}

/** From sitting on the floor to standing. */
export function createStandUp(stage: Stage, from: Pose): Clip {
  const g = stage.ground;
  const f = from.facing;
  const x = Math.min(stage.width - 45, Math.max(45, from.x));
  const keys = keysFrom(from, [{ dt: 0.22, p: sittingFloor(from.x, g, f) }, ...standUpSteps(x, g, f, false)]);
  return sequence('stand-up', from, stage, keys, () => ({ brows: -0.6, mouth: 'flat' }));
}

/** Sulking on the floor with crossed arms (thrown too often). */
export function createSulk(stage: Stage, from: Pose): Clip {
  return { ...sulk(stage, from), stiff: true };
}

function sulk(stage: Stage, from: Pose): Clip {
  const g = stage.ground;
  const f = from.facing;
  const sit = sittingFloor(from.x, g, f);
  const crossed: Pose = { ...sit, torso: 0.12, lShoulder: 0.95, lElbow: 2.35, rShoulder: 1.05, rElbow: 2.25, head: -0.12 };
  return simple(
    'sulk',
    4.2,
    (t) => {
      const w = ease.inOut(segment(t, 0, 0.4));
      const p = keepAboveGround({ ...crossed, head: crossed.head + 0.05 * Math.sin(t * 0.9) }, g);
      const peek = t > 2.2 && t < 3.1;
      return {
        pose: t < 0.4 ? blendPose(from, p, w) : p,
        expr: { eyes: 'dot', brows: -0.9, mouth: 'frown' },
        // Looks away, then a sideways glance at the cursor.
        eyeDir: peek ? undefined : { x: -f * 0.9, y: -0.1 },
      };
    },
    [],
    0,
  );
}

/** Rubs the sore head, shakes it off and gives the cursor a grumpy look. */
export function createRubHead(stage: Stage, from: Pose): Clip {
  return { ...rubHead(stage, from), stiff: true };
}

function rubHead(stage: Stage, from: Pose): Clip {
  const x = from.x;
  const f = from.facing;
  return simple(
    'rub-head',
    2.6,
    (t) => {
      const p = breathing(stage, x, f, t);
      const w = ease.inOut(segment(t, 0, 0.3)) * (1 - ease.inOut(segment(t, 1.4, 1.7)));
      const j = forwardKinematics(p);
      const top = { x: p.x + j.head.x - f * 2 + 2.5 * Math.cos(t * 14), y: p.y + j.head.y - BONES.headR + 1 + 1.5 * Math.sin(t * 14) };
      const rubbed = { ...p };
      reach(rubbed, 'r', top, -1);
      const out = blendPose(p, rubbed, w);
      const shake = t > 1.5 && t < 2.0 ? 0.35 * Math.sin((t - 1.5) * 38) * (1 - (t - 1.5) / 0.5) : 0;
      out.head += shake;
      const grumpy = t > 2.0;
      return {
        pose: t < 0.3 ? blendPose(from, out, ease.inOut(segment(t, 0, 0.3))) : out,
        expr: grumpy ? { eyes: 'dot', brows: -0.8, mouth: 'frown' } : { eyes: 'closed', brows: 0.6, mouth: 'wobbly' },
      };
    },
    [],
    0,
  );
}

function blendPose(from: Pose, b: Pose, t: number): Pose {
  const a = unwrapToward(from, b);
  const out = { ...b };
  const record = out as unknown as Record<string, number>;
  const ra = a as unknown as Record<string, number>;
  const rb = b as unknown as Record<string, number>;
  for (const k of Object.keys(b)) {
    if (k !== 'facing') {
      record[k] = ra[k] + (rb[k] - ra[k]) * t;
    }
  }
  return out;
}

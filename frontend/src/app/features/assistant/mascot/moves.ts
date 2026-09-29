import { Clip, ClipEvent, ClipFrame, Gait, InkProp, MoveAction, RUN, SNEAK, Stage, ease, gaitPose, plant, pose, reach, standAt, standHip } from './animations';
import { Phase, buildPhases, holdPhase, ink, n } from './phases';
import { BONES, Pose, legTo, lerpPose, shoulderPos } from './skeleton';

/**
 * Ways of getting around the bottom edge (and entering/leaving the screen): pure functions of time, feet and hands
 * placed by IK, vehicles/props drawn in ink. Every move goes from x0 to x1 and ends standing at x1 (standAt).
 */

export interface MoveContext {
  stage: Stage;
  from: Pose;
  x0: number;
  x1: number;
  lite: boolean;
  rng: () => number;
  /** Entrance from off screen: start right at the first pose. */
  cut: boolean;
}

interface M {
  s: Stage;
  g: number;
  x0: number;
  x1: number;
  d: 1 | -1;
  dist: number;
  lite: boolean;
  rng: () => number;
}

const SKIP: Gait = { stride: 72, stance: 0.42, lift: 16, bob: 7, armSwing: 1.0, elbow: 0.8, lean: 0.05, hipDrop: 0 };
const MOON: Gait = { stride: 46, stance: 0.62, lift: 3, bob: 1.2, armSwing: 0.25, elbow: 1.1, lean: -0.08, hipDrop: 2 };
const SPRINT: Gait = { stride: 118, stance: 0.32, lift: 20, bob: 4.5, armSwing: 1.3, elbow: 1.7, lean: 0.42, hipDrop: 5 };

/** Standing at x facing f — the pose every move ends in. */
function stand(m: M, x: number, f: 1 | -1 = m.d): Pose {
  return standAt(m.s, x, f);
}

function settle(m: M, dur = 0.3): Phase {
  return holdPhase(dur, () => ({ pose: stand(m, m.x1) }));
}

/** Travel with a gait from xa to xb at `speed` (px/s); `pause` gives cartoon stop-and-go. */
function gaitPhase(m: M, xa: number, xb: number, gait: Gait, speed: number, o: { facing?: 1 | -1; backwards?: boolean; pause?: number; expr?: ClipFrame['expr']; props?: (x: number, s: number) => InkProp[] } = {}): Phase {
  const dist = Math.abs(xb - xa);
  const dur = Math.max(0.2, dist / speed);
  const omega = (2 * Math.PI) / 1.1;
  const pause = o.pause ?? 0;
  const facing = o.facing ?? (xb >= xa ? 1 : -1);
  return {
    dur,
    flexible: false,
    at: (u) => {
      const t = u * dur;
      const raw = pause > 0 ? speed * (t - (pause * Math.sin(omega * t)) / omega) : speed * t;
      const s = Math.min(dist, Math.max(0, raw * (dist > 0 ? 1 : 0)));
      const k = dist > 0 ? s / dist : 1;
      const x = xa + (xb - xa) * k;
      const p = gaitPose(m.s, x, o.backwards ? -s : s, facing, gait);
      return { pose: p, expr: o.expr, props: o.props ? { ink: o.props(x, s) } : undefined };
    },
  };
}

/* ───────────── simple gaits ───────────── */

function run(m: M): Phase[] {
  return [gaitPhase(m, m.x0, m.x1, RUN, 300, { expr: { mouth: 'grin', brows: -0.2 } }), settle(m)];
}

function sprint(m: M): Phase[] {
  return [gaitPhase(m, m.x0, m.x1, SPRINT, 470, { expr: { mouth: 'grin', brows: -0.5 } }), settle(m, 0.35)];
}

function skip(m: M): Phase[] {
  return [gaitPhase(m, m.x0, m.x1, SKIP, 170, { expr: { eyes: 'happy', mouth: 'grin' } }), settle(m)];
}

function sneak(m: M): Phase[] {
  return [gaitPhase(m, m.x0, m.x1, SNEAK, 60, { pause: 0.85, expr: { mouth: 'flat', brows: 0.6 } }), settle(m)];
}

function moonwalk(m: M): Phase[] {
  // Faces backwards and glides the other way.
  return [
    gaitPhase(m, m.x0, m.x1, MOON, 95, { facing: (-m.d as 1 | -1), backwards: true, expr: { mouth: 'smile', eyes: 'happy' } }),
    holdPhase(0.35, () => ({ pose: stand(m, m.x1, -m.d as 1 | -1), expr: { eyes: 'happy', mouth: 'grin' } })),
    settle(m, 0.35),
  ];
}

/* ───────────── acrobatics ───────────── */

function crawl(m: M): Phase[] {
  const dur = Math.max(0.5, m.dist / 70);
  const cycle = 44;
  const floorPose = (x: number, s: number): Pose => {
    const p = pose({ x, y: m.g - 24, rot: m.d * (Math.PI / 2 - 0.2), facing: m.d, torso: 0, head: -0.9 });
    const k = (s / cycle) % 1;
    const step = (ph: number): number => {
      const q = (k + ph) % 1;
      return q < 0.6 ? 10 - (q / 0.6) * 20 : -10 + ((q - 0.6) / 0.4) * 20;
    };
    const lift = (ph: number): number => {
      const q = (k + ph) % 1;
      return q < 0.6 ? 0 : 6 * Math.sin(((q - 0.6) / 0.4) * Math.PI);
    };
    const sx = shoulderPos(p).x;
    reach(p, 'l', { x: sx + m.d * (8 + step(0)), y: m.g - 1 - lift(0) }, 1);
    reach(p, 'r', { x: sx + m.d * (8 + step(0.5)), y: m.g - 1 - lift(0.5) }, 1);
    plant(p, 'l', { x: x - m.d * (26 - step(0.5)), y: m.g - 1 - lift(0.5) });
    plant(p, 'r', { x: x - m.d * (26 - step(0)), y: m.g - 1 - lift(0) });
    return p;
  };
  return [
    holdPhase(0.4, () => ({ pose: floorPose(m.x0, 0), expr: { mouth: 'flat' } })),
    { dur, flexible: false, at: (u) => ({ pose: floorPose(m.x0 + (m.x1 - m.x0) * u, m.dist * u), expr: { mouth: 'flat', brows: 0.3 } }) },
    holdPhase(0.35, () => ({ pose: { ...stand(m, m.x1), y: standHip(m.s) + 12, torso: 0.6, lHip: 1.1, lKnee: 1.8, rHip: 1.0, rKnee: 1.7 } })),
    settle(m, 0.35),
  ];
}

/** A tucked or spread body turning around its centre: hands/feet touching the floor on the way. */
function wheelPose(m: M, x: number, turn: number, spread: boolean): Pose {
  const inv = (1 - Math.cos(turn)) / 2;
  const p = pose({
    x,
    y: m.g - (spread ? 44 + 26 * inv : 20),
    rot: m.d * turn,
    facing: m.d,
    torso: 0,
    head: spread ? 0 : 0.5,
    lShoulder: spread ? Math.PI - 0.35 : 0.9,
    rShoulder: spread ? Math.PI + 0.35 : 1.0,
    lElbow: spread ? 0.05 : 1.6,
    rElbow: spread ? 0.05 : 1.6,
    lHip: spread ? -0.55 : 1.9,
    lKnee: spread ? 0.05 : 2.4,
    rHip: spread ? 0.55 : 1.8,
    rKnee: spread ? 0.05 : 2.3,
  });
  return p;
}

function cartwheel(m: M): Phase[] {
  const wheels = Math.max(1, Math.min(4, Math.round(m.dist / 130)));
  const mid = m.x1 - m.d * 0;
  const start = m.x0 + m.d * 10;
  return [
    holdPhase(0.3, () => ({ pose: { ...stand(m, start), lShoulder: Math.PI - 0.35, rShoulder: Math.PI + 0.35, lElbow: 0.05, rElbow: 0.05, lHip: -0.3, rHip: 0.3 }, expr: { mouth: 'grin' } })),
    {
      dur: 0.75 * wheels,
      flexible: false,
      at: (u) => ({ pose: wheelPose(m, start + (mid - start) * u, u * wheels * 2 * Math.PI, true), expr: { eyes: 'happy', mouth: 'grin' } }),
    },
    settle(m, 0.4),
  ];
}

function roll(m: M): Phase[] {
  const rolls = Math.max(1, Math.min(3, Math.round(m.dist / 110)));
  const crouchX = m.x0;
  return [
    holdPhase(0.35, () => ({ pose: { ...stand(m, crouchX), y: standHip(m.s) + 14, torso: 0.8, head: 0.4, lHip: 1.3, lKnee: 2.1, rHip: 1.2, rKnee: 2.0, lShoulder: 1.0, rShoulder: 1.1, lElbow: 0.6, rElbow: 0.6 } })),
    { dur: 0.6 * rolls, flexible: false, at: (u) => ({ pose: wheelPose(m, crouchX + (m.x1 - crouchX) * u, u * rolls * 2 * Math.PI, false), expr: { eyes: 'closed', mouth: 'o' } }) },
    holdPhase(0.3, () => ({ pose: { ...stand(m, m.x1), y: standHip(m.s) + 12, torso: 0.6, lHip: 1.1, lKnee: 1.8, rHip: 1.0, rKnee: 1.7, lShoulder: 1.2, rShoulder: 1.3 } })),
    settle(m, 0.35),
  ];
}

function backflip(m: M): Phase[] {
  const flipX = m.x1;
  const runTo = m.x1 - m.d * 40;
  const up = 110;
  const T = 0.7;
  return [
    gaitPhase(m, m.x0, runTo, RUN, 280),
    holdPhase(0.3, () => ({ pose: { ...stand(m, runTo), y: standHip(m.s) + 12, torso: 0.4, lHip: 1.0, lKnee: 1.7, rHip: 0.9, rKnee: 1.6, lShoulder: -0.9, rShoulder: -0.8, lElbow: 0.3, rElbow: 0.3 } })),
    {
      dur: T,
      flexible: false,
      at: (u) => {
        const x = runTo + (flipX - runTo) * u;
        const y = standHip(m.s) - up * 4 * u * (1 - u);
        const turn = -m.d * 2 * Math.PI * ease.inOut(u);
        const tuck = Math.sin(Math.PI * u);
        const p = pose({ x, y, rot: turn, facing: m.d, torso: 0.5 * tuck, head: 0.3 * tuck, lShoulder: 2.4 - tuck, rShoulder: 2.5 - tuck, lElbow: 0.4 + tuck, rElbow: 0.4 + tuck, lHip: 1.6 * tuck, lKnee: 2.2 * tuck, rHip: 1.5 * tuck, rKnee: 2.1 * tuck });
        return { pose: p, expr: { eyes: u > 0.2 && u < 0.8 ? 'closed' : 'wide', mouth: 'grin' } };
      },
    },
    holdPhase(0.3, () => ({ pose: { ...stand(m, flipX), y: standHip(m.s) + 10, torso: 0.3, lHip: 0.8, lKnee: 1.4, rHip: 0.7, rKnee: 1.3, lShoulder: 1.5, rShoulder: 1.6, lElbow: 0.1, rElbow: 0.1 }, expr: { eyes: 'happy', mouth: 'grin' } })),
    settle(m, 0.3),
  ];
}

function kneeslide(m: M): Phase[] {
  const slideFrom = m.x0 + (m.x1 - m.x0) * 0.45;
  const knees = (x: number): Pose => {
    const p = pose({ x, y: m.g - 2 - BONES.thigh + 2, facing: m.d, torso: -0.35, head: -0.4, lShoulder: 1.9, rShoulder: 2.1, lElbow: 0.2, rElbow: 0.2 });
    const l = legTo(-2, BONES.thigh);
    p.lHip = l.hip;
    p.lKnee = Math.PI / 2 + 0.1;
    p.rHip = l.hip + 0.1;
    p.rKnee = Math.PI / 2;
    return p;
  };
  const slideDist = Math.abs(m.x1 - slideFrom);
  return [
    gaitPhase(m, m.x0, slideFrom, RUN, 320, { expr: { mouth: 'grin' } }),
    {
      dur: Math.max(0.5, slideDist / 220),
      flexible: false,
      at: (u) => ({ pose: knees(slideFrom + (m.x1 - slideFrom) * ease.out(u)), expr: { eyes: 'happy', mouth: 'open', talk: 0.8 } }),
    },
    holdPhase(0.35, () => ({ pose: { ...stand(m, m.x1), y: standHip(m.s) + 12, torso: 0.5, lHip: 1.1, lKnee: 1.8, rHip: 0.9, rKnee: 1.6 } })),
    settle(m, 0.3),
  ];
}

function handwalk(m: M): Phase[] {
  const dur = Math.max(0.6, m.dist / 75);
  const inverted = (x: number, s: number): Pose => {
    const k = (s / 36) % 1;
    const p = pose({ x, y: m.g - 72 - 2 * Math.sin(k * 4 * Math.PI), rot: m.d * Math.PI, facing: m.d, torso: 0.1, head: 0.2, lHip: 0.25 + 0.2 * Math.sin(k * 2 * Math.PI), lKnee: 0.4, rHip: -0.1 - 0.2 * Math.sin(k * 2 * Math.PI), rKnee: 0.6 });
    const sx = shoulderPos(p).x;
    const step = (ph: number): number => {
      const q = (k + ph) % 1;
      return q < 0.6 ? 8 - (q / 0.6) * 16 : -8 + ((q - 0.6) / 0.4) * 16;
    };
    const lift = (ph: number): number => {
      const q = (k + ph) % 1;
      return q < 0.6 ? 0 : 5 * Math.sin(((q - 0.6) / 0.4) * Math.PI);
    };
    reach(p, 'l', { x: sx + m.d * step(0), y: m.g - 1 - lift(0) }, 1);
    reach(p, 'r', { x: sx + m.d * step(0.5), y: m.g - 1 - lift(0.5) }, 1);
    return p;
  };
  return [
    holdPhase(0.35, () => ({ pose: { ...stand(m, m.x0), y: standHip(m.s) + 12, torso: 1.1, lShoulder: 1.4, rShoulder: 1.5, lHip: 1.2, lKnee: 1.6, rHip: 1.0, rKnee: 1.4 } })),
    holdPhase(0.5, (u) => {
      const a = { ...stand(m, m.x0), y: standHip(m.s) + 12, torso: 1.1, lShoulder: 1.4, rShoulder: 1.5, lHip: 1.2, lKnee: 1.6, rHip: 1.0, rKnee: 1.4 };
      return { pose: lerpPose(a, inverted(m.x0, 0), ease.inOut(u)), expr: { mouth: 'o' } };
    }),
    { dur, flexible: false, at: (u) => ({ pose: inverted(m.x0 + (m.x1 - m.x0) * u, m.dist * u), expr: { mouth: 'wobbly', brows: 0.7 } }) },
    holdPhase(0.55, (u) => {
      const b = { ...stand(m, m.x1), y: standHip(m.s) + 10, torso: 0.9, lShoulder: 1.2, rShoulder: 1.3, lHip: 1.1, lKnee: 1.5, rHip: 0.9, rKnee: 1.3, rot: m.d * 2 * Math.PI };
      return { pose: lerpPose(inverted(m.x1, m.dist), b, ease.inOut(u)) };
    }),
    settle(m, 0.35),
  ];
}

/* ───────────── vehicles ───────────── */

function board(m: M, x: number, lift: number, draw: number, alpha = 1): InkProp {
  const y = m.g - 5 - lift;
  return ink(`M ${n(x - 18)} ${n(y - 1)} Q ${n(x)} ${n(y + 2)} ${n(x + 18)} ${n(y - 1)} M ${n(x - 11)} ${n(y + 3)} a 2.5 2.5 0 1 0 0.1 0 M ${n(x + 11)} ${n(y + 3)} a 2.5 2.5 0 1 0 0.1 0`, draw, m.lite, alpha);
}

function skateboard(m: M): Phase[] {
  const rideFrom = m.x0 + m.d * 20;
  const ollieAt = 0.75;
  const onBoard = (x: number, t: number, lift: number): Pose => {
    const p = pose({ x, y: m.g - 7 - (BONES.thigh + BONES.shin) + 6 - lift, facing: m.d, torso: 0.25, head: -0.2, lShoulder: -0.9, rShoulder: 1.0, lElbow: 0.3, rElbow: 0.3 });
    plant(p, 'l', { x: x - m.d * 9, y: m.g - 8 - lift });
    plant(p, 'r', { x: x + m.d * 9, y: m.g - 8 - lift });
    p.torso += 0.03 * Math.sin(t * 5);
    return p;
  };
  const dist = Math.abs(m.x1 - rideFrom);
  const dur = Math.max(1.0, dist / 240);
  return [
    holdPhase(0.4, (u) => ({ pose: stand(m, m.x0), expr: { mouth: 'grin' }, props: { ink: [board(m, rideFrom, 0, u)] } })),
    holdPhase(0.5, (u) => {
      // Push off: one foot on the board, the other kicks.
      const p = onBoard(rideFrom + m.d * 10 * u, 0, 0);
      plant(p, 'l', { x: rideFrom - m.d * (18 * Math.cos(u * Math.PI * 2)), y: m.g - 1 - 4 * Math.max(0, Math.sin(u * Math.PI * 2)) });
      return { pose: p, props: { ink: [board(m, rideFrom + m.d * 10 * u, 0, 1)] } };
    }),
    {
      dur,
      flexible: false,
      at: (u) => {
        const x = rideFrom + m.d * 10 + (m.x1 - rideFrom - m.d * 10) * u;
        const o = u > ollieAt ? Math.sin(((u - ollieAt) / (1 - ollieAt)) * Math.PI) : 0;
        const lift = 28 * o;
        return { pose: onBoard(x, u * dur, lift), expr: o > 0 ? { eyes: 'happy', mouth: 'grin' } : { mouth: 'smile' }, props: { ink: [board(m, x, lift, 1)] } };
      },
    },
    holdPhase(0.4, () => ({ pose: stand(m, m.x1), props: { ink: [board(m, m.x1, 0, 1)] } })),
  ];
}

function unicycle(m: M): Phase[] {
  const R = 13;
  const hubY = m.g - R;
  const seatY = hubY - 30;
  const dur = Math.max(1.2, m.dist / 110);
  const wheel = (x: number, ang: number, draw: number): InkProp => {
    const sp = (a: number): string => `M ${n(x)} ${n(hubY)} L ${n(x + Math.cos(a) * R)} ${n(hubY + Math.sin(a) * R)}`;
    return ink(`M ${n(x - R)} ${n(hubY)} a ${R} ${R} 0 1 0 ${2 * R} 0 a ${R} ${R} 0 1 0 ${-2 * R} 0 ${sp(ang)} ${sp(ang + 2.1)} ${sp(ang + 4.2)} M ${n(x)} ${n(hubY)} L ${n(x)} ${n(seatY)} M ${n(x - 6)} ${n(seatY)} L ${n(x + 6)} ${n(seatY)}`, draw, m.lite);
  };
  const riding = (x: number, t: number, ang: number): Pose => {
    const wob = 0.12 * Math.sin(t * 3.1) + 0.06 * Math.sin(t * 7.3);
    const p = pose({ x, y: seatY - 2, facing: m.d, torso: wob, head: -wob, lShoulder: -1.5 + wob, rShoulder: 1.6 + wob, lElbow: 0.2, rElbow: 0.2 });
    const pr = 7;
    plant(p, 'l', { x: x + Math.cos(ang) * pr, y: hubY + Math.sin(ang) * pr });
    plant(p, 'r', { x: x + Math.cos(ang + Math.PI) * pr, y: hubY + Math.sin(ang + Math.PI) * pr });
    return p;
  };
  return [
    holdPhase(0.45, (u) => ({ pose: stand(m, m.x0 - m.d * 16), props: { ink: [wheel(m.x0, 0, u)] } })),
    holdPhase(0.4, (u) => ({ pose: lerpPose(stand(m, m.x0 - m.d * 16), riding(m.x0, 0, 0), ease.inOut(u)), props: { ink: [wheel(m.x0, 0, 1)] } })),
    {
      dur,
      flexible: false,
      at: (u) => {
        const x = m.x0 + (m.x1 - m.x0) * u;
        const ang = (m.d * (x - m.x0)) / R;
        return { pose: riding(x, u * dur, ang), expr: { mouth: 'wobbly', brows: 0.6 }, props: { ink: [wheel(x, ang, 1)] } };
      },
    },
    holdPhase(0.45, (u) => ({ pose: lerpPose(riding(m.x1, dur, (m.d * (m.x1 - m.x0)) / R), stand(m, m.x1 + m.d * 16), ease.inOut(u)), props: { ink: [wheel(m.x1, (m.d * (m.x1 - m.x0)) / R, 1)] } })),
    holdPhase(0.3, () => ({ pose: stand(m, m.x1 + m.d * 16), props: { ink: [wheel(m.x1, 0, 1)] } })),
    settle(m, 0.4),
  ];
}

function scooter(m: M): Phase[] {
  const deckY = m.g - 6;
  const scMirror = (x: number, draw: number): InkProp => {
    const d = m.d;
    const X = (dx: number): string => n(x + d * dx);
    return ink(`M ${X(-16)} ${n(deckY)} L ${X(14)} ${n(deckY)} L ${X(18)} ${n(deckY - 44)} M ${X(12)} ${n(deckY - 44)} L ${X(24)} ${n(deckY - 44)} M ${X(-14)} ${n(deckY + 3)} a 3 3 0 1 0 0.1 0 M ${X(16)} ${n(deckY + 3)} a 3 3 0 1 0 0.1 0`, draw, m.lite);
  };
  const rider = (x: number, t: number): Pose => {
    const p = pose({ x: x - m.d * 2, y: deckY - (BONES.thigh + BONES.shin) + 4, facing: m.d, torso: 0.18, head: -0.15 });
    const hb = { x: x + m.d * 18, y: deckY - 44 };
    reach(p, 'l', hb, -1);
    reach(p, 'r', { x: hb.x - m.d * 3, y: hb.y + 1 }, -1);
    plant(p, 'r', { x: x + m.d * 2, y: deckY - 2 });
    const kick = Math.sin(t * 6);
    plant(p, 'l', { x: x - m.d * (12 + 14 * kick), y: m.g - 1 - 5 * Math.max(0, kick) });
    return p;
  };
  const dur = Math.max(1.0, m.dist / 200);
  return [
    holdPhase(0.4, (u) => ({ pose: stand(m, m.x0 - m.d * 10), props: { ink: [scMirror(m.x0, u)] } })),
    { dur, flexible: false, at: (u) => { const x = m.x0 + (m.x1 - m.x0) * u; return { pose: rider(x, u * dur), expr: { mouth: 'grin' }, props: { ink: [scMirror(x, 1)] } }; } },
    holdPhase(0.35, () => ({ pose: stand(m, m.x1), props: { ink: [scMirror(m.x1 + m.d * 10, 1)] } })),
  ];
}

function pogo(m: M): Phase[] {
  const hops = Math.max(2, Math.min(8, Math.round(m.dist / 45)));
  const hopT = 0.36;
  const stickLen = 46;
  const pogoInk = (x: number, footY: number, draw: number): InkProp =>
    ink(`M ${n(x)} ${n(footY + 12)} L ${n(x)} ${n(footY - stickLen + 12)} M ${n(x - 7)} ${n(footY)} L ${n(x + 7)} ${n(footY)} M ${n(x - 6)} ${n(footY - stickLen + 12)} L ${n(x + 6)} ${n(footY - stickLen + 12)}`, draw, m.lite);
  const onPogo = (x: number, lift: number, squash: number): Pose => {
    const footY = m.g - 12 - lift;
    const p = pose({ x, y: footY - (BONES.thigh + BONES.shin) + 6, facing: m.d, torso: 0.1, head: -0.2, squash });
    plant(p, 'l', { x: x - 3, y: footY });
    plant(p, 'r', { x: x + 3, y: footY });
    reach(p, 'l', { x: x - 5, y: footY - stickLen + 12 }, -1);
    reach(p, 'r', { x: x + 5, y: footY - stickLen + 12 }, -1);
    return p;
  };
  return [
    holdPhase(0.4, (u) => ({ pose: stand(m, m.x0 - m.d * 12), props: { ink: [pogoInk(m.x0, m.g - 12, u)] } })),
    holdPhase(0.3, (u) => ({ pose: lerpPose(stand(m, m.x0 - m.d * 12), onPogo(m.x0, 0, 1), ease.inOut(u)), props: { ink: [pogoInk(m.x0, m.g - 12, 1)] } })),
    {
      dur: hops * hopT,
      flexible: false,
      at: (u) => {
        const k = u * hops;
        const i = Math.min(hops - 1, Math.floor(k));
        const f = k - i;
        const lift = 36 * 4 * f * (1 - f);
        const x = m.x0 + (m.x1 - m.x0) * ((i + f) / hops);
        const squash = f < 0.1 || f > 0.9 ? 0.88 : 1.04;
        return { pose: onPogo(x, lift, squash), expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: [pogoInk(x, m.g - 12 - lift, 1)] } };
      },
    },
    holdPhase(0.35, (u) => ({ pose: lerpPose(onPogo(m.x1, 0, 1), stand(m, m.x1 + m.d * 12), ease.inOut(u)), props: { ink: [pogoInk(m.x1, m.g - 12, 1)] } })),
    holdPhase(0.2, () => ({ pose: stand(m, m.x1 + m.d * 12), props: { ink: [pogoInk(m.x1, m.g - 12, 1)] } })),
    settle(m, 0.35),
  ];
}

/* ───────────── edge moves ───────────── */

/** Hanging from the top edge of the viewport, hand over hand along it, then a drop to the floor. */
function monkeybars(m: M, cut: boolean): Phase[] {
  const handY = 3;
  const G = 34;
  /** Hand-over-hand: each hand stays on its grip, then swings to the next one (quantized, eased). */
  const grip = (sv: number, phase: number): number => {
    const k = (sv + phase) / G;
    const base = Math.floor(k);
    return (base + ease.inOut(Math.max(0, (k - base - 0.55) / 0.45))) * G - phase;
  };
  const hang = (x: number, sv: number): Pose => {
    const k = sv / G;
    const swing = 0.16 * Math.sin(k * Math.PI);
    const p = pose({ x, y: handY + BONES.upperArm + BONES.foreArm + BONES.shoulderAt - 4, facing: m.d, rot: swing * m.d, torso: 0, head: -0.3, lHip: 0.3 + 0.3 * Math.sin(k * Math.PI), lKnee: 0.7, rHip: 0.1 - 0.3 * Math.sin(k * Math.PI), rKnee: 0.9 });
    reach(p, 'l', { x: m.x0 + m.d * (grip(sv, 0) + 10), y: handY }, -1);
    reach(p, 'r', { x: m.x0 + m.d * (grip(sv, G / 2) + 10), y: handY }, -1);
    return p;
  };
  const dur = Math.max(1.0, m.dist / 120);
  const hipHang = handY + BONES.upperArm + BONES.foreArm + BONES.shoulderAt - 4;
  const fallH = standHip(m.s) - hipHang;
  const fallT = Math.sqrt((2 * fallH) / 2200);
  // Not entering from off screen: a cartoon super-jump up to the top edge first.
  const leapH = standHip(m.s) - hipHang;
  const leapT = Math.max(0.6, (leapH * 2) / (28 * 60));
  const crouch = { ...stand(m, m.x0), y: standHip(m.s) + 12, torso: 0.4, lHip: 1.0, lKnee: 1.7, rHip: 0.9, rKnee: 1.6, lShoulder: -0.8, rShoulder: -0.7 };
  const leap: Phase[] = cut
    ? []
    : [
        holdPhase(0.45, (u) => ({ pose: { ...crouch, lShoulder: -0.8 + 3.6 * ease.inOut(u), rShoulder: -0.7 + 3.6 * ease.inOut(u) }, expr: { brows: -0.4, mouth: 'flat' } })),
        {
          dur: leapT,
          flexible: false,
          at: (u) => {
            const k = 1 - (1 - u) * (1 - u);
            const target = hang(m.x0, 0);
            const p = lerpPose({ ...crouch, lShoulder: 2.8, rShoulder: 2.9 }, target, ease.inOut(u));
            p.y = crouch.y + (target.y - crouch.y) * k;
            return { pose: p, expr: { eyes: 'wide', mouth: 'grin' } };
          },
        },
      ];
  return [
    ...leap,
    { dur, flexible: false, at: (u) => ({ pose: hang(m.x0 + (m.x1 - m.x0) * u, m.dist * u), expr: { mouth: 'grin', brows: -0.2 } }) },
    holdPhase(0.3, () => ({ pose: hang(m.x1, m.dist), expr: { eyes: 'wide', mouth: 'o' } })),
    {
      dur: fallT,
      flexible: false,
      at: (u) => {
        const t = u * fallT;
        const p = pose({ x: m.x1, y: hipHang + 0.5 * 2200 * t * t, facing: m.d, lShoulder: 2.6, rShoulder: 2.8, lElbow: 0.3, rElbow: 0.3, lHip: 0.3, lKnee: 0.5, rHip: 0.1, rKnee: 0.6 });
        return { pose: p, expr: { eyes: 'wide', mouth: 'o', brows: 1 } };
      },
    },
    holdPhase(0.3, () => ({ pose: { ...stand(m, m.x1), y: standHip(m.s) + 12, torso: 0.4, lHip: 1.0, lKnee: 1.7, rHip: 0.9, rKnee: 1.6, lShoulder: -0.7, rShoulder: 0.8 } })),
    settle(m, 0.35),
  ];
}

/** Runs to a side edge, walks up it a little, backflips off and lands facing back into the screen. */
function wallflip(m: M): Phase[] {
  const wallX = m.d > 0 ? m.s.width : 0;
  const nearX = wallX - m.d * 50;
  const land = nearX - m.d * 90;
  const onWall = (h: number, t: number): Pose => {
    const p = pose({ x: wallX - m.d * 44, y: m.g - 20 - h, rot: -m.d * (Math.PI / 2 - 0.25), facing: m.d, torso: 0, head: 0.5, lShoulder: -0.8 + 0.4 * Math.sin(t * 9), rShoulder: 0.8 - 0.4 * Math.sin(t * 9) });
    plant(p, 'l', { x: wallX - m.d * 1, y: m.g - 12 - h - 10 * Math.max(0, Math.sin(t * 9)) });
    plant(p, 'r', { x: wallX - m.d * 1, y: m.g - 12 - h - 10 * Math.max(0, -Math.sin(t * 9)) });
    return p;
  };
  const back: 1 | -1 = -m.d as 1 | -1;
  return [
    gaitPhase(m, m.x0, nearX, RUN, 300),
    holdPhase(0.9, (u) => ({ pose: onWall(70 * ease.inOut(u), u * 0.9), expr: { mouth: 'grin', brows: -0.3 } })),
    {
      dur: 0.75,
      flexible: false,
      at: (u) => {
        const a = { x: wallX - m.d * 44, y: m.g - 90 };
        const x = a.x + (land - a.x) * u;
        const y = a.y + (standHip(m.s) - a.y) * u - 70 * 4 * u * (1 - u);
        // From lying against the wall (feet on it) a backward turn to upright: −(π/2 − 0.25) → −2π.
        const turn = -m.d * (Math.PI / 2 - 0.25) - m.d * (Math.PI * 1.5 + 0.25) * ease.inOut(u);
        const tuck = Math.sin(Math.PI * u);
        const p = pose({ x, y, rot: turn, facing: m.d, torso: 0.4 * tuck, head: 0.3, lShoulder: 2.2, rShoulder: 2.3, lElbow: 0.4, rElbow: 0.4, lHip: 1.4 * tuck, lKnee: 2 * tuck, rHip: 1.3 * tuck, rKnee: 1.9 * tuck });
        return { pose: p, expr: { eyes: 'happy', mouth: 'grin' } };
      },
    },
    holdPhase(0.35, () => ({ pose: { ...standAt(m.s, land, back), y: standHip(m.s) + 10, torso: 0.3, lHip: 0.8, lKnee: 1.4, rHip: 0.7, rKnee: 1.3, lShoulder: 1.6, rShoulder: 1.7 } })),
    holdPhase(0.3, () => ({ pose: standAt(m.s, land, back) })),
  ];
}

/* ───────────── registry ───────────── */

export interface MoveInfo {
  weight: number;
  /** Kept in lite mode. */
  lite: boolean;
  entrance: boolean;
  exit: boolean;
  wander: boolean;
}

export const MOVES: Readonly<Record<MoveAction, MoveInfo>> = {
  'move-run': { weight: 1.2, lite: true, entrance: true, exit: true, wander: true },
  'move-sprint': { weight: 0.8, lite: true, entrance: true, exit: true, wander: true },
  'move-skip': { weight: 1, lite: true, entrance: true, exit: true, wander: true },
  'move-sneak': { weight: 0.8, lite: true, entrance: true, exit: false, wander: true },
  'move-moonwalk': { weight: 0.8, lite: true, entrance: true, exit: true, wander: true },
  'move-crawl': { weight: 0.6, lite: true, entrance: true, exit: false, wander: true },
  'move-cartwheel': { weight: 0.8, lite: true, entrance: false, exit: false, wander: true },
  'move-roll': { weight: 0.7, lite: true, entrance: false, exit: false, wander: true },
  'move-backflip': { weight: 0.6, lite: true, entrance: false, exit: false, wander: true },
  'move-kneeslide': { weight: 0.6, lite: true, entrance: false, exit: false, wander: true },
  'move-handwalk': { weight: 0.5, lite: true, entrance: false, exit: false, wander: true },
  'move-skateboard': { weight: 0.8, lite: false, entrance: true, exit: true, wander: true },
  'move-unicycle': { weight: 0.6, lite: false, entrance: true, exit: true, wander: true },
  'move-scooter': { weight: 0.7, lite: false, entrance: true, exit: true, wander: true },
  'move-pogo': { weight: 0.6, lite: false, entrance: true, exit: true, wander: true },
  'move-monkeybars': { weight: 0.7, lite: true, entrance: true, exit: false, wander: false },
  'move-wallflip': { weight: 0.5, lite: true, entrance: false, exit: false, wander: true },
};

export const MOVE_ACTIONS = Object.keys(MOVES) as MoveAction[];

function phasesFor(action: MoveAction, m: M, cut: boolean): Phase[] {
  switch (action) {
    case 'move-run':
      return run(m);
    case 'move-sprint':
      return sprint(m);
    case 'move-skip':
      return skip(m);
    case 'move-sneak':
      return sneak(m);
    case 'move-moonwalk':
      return moonwalk(m);
    case 'move-crawl':
      return crawl(m);
    case 'move-cartwheel':
      return cartwheel(m);
    case 'move-roll':
      return roll(m);
    case 'move-backflip':
      return backflip(m);
    case 'move-kneeslide':
      return kneeslide(m);
    case 'move-handwalk':
      return handwalk(m);
    case 'move-skateboard':
      return skateboard(m);
    case 'move-unicycle':
      return unicycle(m);
    case 'move-scooter':
      return scooter(m);
    case 'move-pogo':
      return pogo(m);
    case 'move-monkeybars':
      return monkeybars(m, cut);
    case 'move-wallflip':
      return wallflip(m);
  }
}

/** Where a move ends (standing): x1, except the wall flip which lands back inside, facing the other way. */
export function moveEnd(action: MoveAction, c: MoveContext): Pose {
  const d: 1 | -1 = c.x1 >= c.x0 ? 1 : -1;
  if (action === 'move-wallflip') {
    const wallX = d > 0 ? c.stage.width : 0;
    return standAt(c.stage, wallX - d * 140, -d as 1 | -1);
  }
  return standAt(c.stage, c.x1, d);
}

export function createMoveClip(action: MoveAction, c: MoveContext): Clip {
  const d: 1 | -1 = c.x1 >= c.x0 ? 1 : -1;
  const m: M = { s: c.stage, g: c.stage.ground, x0: c.x0, x1: c.x1, d, dist: Math.abs(c.x1 - c.x0), lite: c.lite, rng: c.rng };
  const phases = phasesFor(action, m, c.cut);
  const events: (starts: number[]) => ClipEvent[] =
    action === 'move-sprint' ? (s) => Array.from({ length: Math.max(1, Math.floor((m.dist / 470) / 0.15)) }, (_, i) => ({ t: s[0] + i * 0.15, type: 'dust' as const, at: 'feet' as const, strength: 0.25 })) : () => [];
  return buildPhases(action, c.from, phases, { events, cut: c.cut });
}

export function isMove(action: string): action is MoveAction {
  return action in MOVES;
}

/** A floor x at least 50 px inside the viewport. */
export function fitX(stage: Stage, x: number): number {
  return Math.min(stage.width - 50, Math.max(50, x));
}

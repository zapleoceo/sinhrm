import { ActivityAction, Clip, ClipEvent, ClipFrame, InkProp, Stage, breathing, ease, pose, reach, segment, sitting, standAt, standHip } from './animations';
import { Phase, buildPhases, holdPhase, ink, n } from './phases';
import { BONES, Pose, Vec, footPos, handPos, headPos, lerpPose, shoulderPos } from './skeleton';

/**
 * Idle activities with drawn props (juggling's siblings): pure functions of time, 3–8 s each, with small comic
 * failures. Every activity starts from the displayed pose and ends standing where it began (standAt) — except
 * fishing, which happens seated on the chat panel and ends in the seated pose.
 */

export interface ActivityContext {
  stage: Stage;
  from: Pose;
  lite: boolean;
  rng: () => number;
  /** Where he sits when docked on the chat panel (fishing). */
  seat: Vec | null;
  /** Pointer position (waving at the cursor), null when unknown. */
  pointer: Vec | null;
}

interface A {
  s: Stage;
  g: number;
  x: number;
  f: 1 | -1;
  lite: boolean;
  rng: () => number;
  seat: Vec | null;
  pointer: Vec | null;
}

const TAU = Math.PI * 2;

function base(a: A, t: number): Pose {
  return breathing(a.s, a.x, a.f, t);
}

const hand = handPos;
const shoulder = shoulderPos;
const headOf = headPos;

const circle = (x: number, y: number, r: number): string => `M ${n(x - r)} ${n(y)} a ${n(r)} ${n(r)} 0 1 0 ${n(2 * r)} 0 a ${n(r)} ${n(r)} 0 1 0 ${n(-2 * r)} 0`;

/** The last phase: standing exactly where he started. */
function finish(a: A, dur = 0.35): Phase {
  return holdPhase(dur, () => ({ pose: standAt(a.s, a.x, a.f) }));
}

/** A looping body phase: `at(t)` gets seconds since the phase start. */
function loop(dur: number, at: (t: number, u: number) => ClipFrame): Phase {
  return { dur, flexible: false, at: (u) => at(u * dur, u) };
}

/* ───────────── activities ───────────── */

function yoyo(a: A): Phase[] {
  const holdHand = (p: Pose): Pose => {
    const q = { ...p };
    const sh = shoulder(q);
    reach(q, 'r', { x: sh.x + a.f * 14, y: sh.y + 16 }, 1);
    return q;
  };
  const yoyoInk = (h: Vec, y: Vec): InkProp[] => [ink(`M ${n(h.x)} ${n(h.y)} L ${n(y.x)} ${n(y.y)}`, 1, a.lite, 0.8), ink(circle(y.x, y.y, 3.5), 1, a.lite)];
  const upDown = loop(1.8, (t) => {
    const p = holdHand(base(a, t));
    const h = hand(p, 'r');
    p.rElbow += 0.25 * Math.max(0, Math.sin(t * 3.5 * Math.PI) * -1);
    const drop = 36 * Math.abs(Math.sin((t / 0.9) * Math.PI));
    return { pose: p, expr: { mouth: 'smile' }, eyeDir: { x: a.f * 0.3, y: 0.8 }, props: { ink: yoyoInk(h, { x: h.x, y: h.y + 4 + drop }) } };
  });
  const aroundWorld = loop(1.4, (t, u) => {
    const p = holdHand(base(a, t + 1.8));
    const h = hand(p, 'r');
    const ang = Math.PI / 2 + u * TAU * 1.5;
    const r = 36 * Math.min(1, u * 4, (1 - u) * 4);
    return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: yoyoInk(h, { x: h.x + a.f * Math.cos(ang) * r, y: h.y + Math.sin(ang) * r + 4 }) } };
  });
  // Walk the dog: the yo-yo rolls along the floor, then snaps back up (and bonks his hand).
  const dog = loop(1.6, (t, u) => {
    const p = holdHand(base(a, t + 3.2));
    const h = hand(p, 'r');
    const out = Math.sin(Math.min(1, u * 1.2) * Math.PI);
    const y = { x: h.x + a.f * 42 * out, y: a.g - 4 - (u > 0.8 ? (u - 0.8) * 5 * (a.g - 4 - h.y) : 0) };
    return { pose: p, expr: u > 0.9 ? { eyes: 'x', mouth: 'o' } : { mouth: 'grin' }, eyeDir: { x: a.f * 0.6, y: 0.9 }, props: { ink: yoyoInk(h, y) } };
  });
  return [upDown, aroundWorld, dog, finish(a)];
}

function keepyuppy(a: A): Phase[] {
  const kicks = 4;
  const kickT = 0.5;
  const footAt = (p: Pose): Vec => footPos(p, 'r');
  const kickPose = (t: number, k: number): Pose => {
    const p = base(a, t);
    const lift = Math.max(0, Math.sin(k * Math.PI));
    p.rHip = 0.1 + 1.0 * lift;
    p.rKnee = 0.3 + 0.6 * lift;
    p.lShoulder = -0.6;
    p.rShoulder = 0.7;
    return p;
  };
  const ball = (x: number, y: number): InkProp[] => [ink(`${circle(x, y, 5)} M ${n(x - 5)} ${n(y)} L ${n(x + 5)} ${n(y)}`, 1, a.lite)];
  const juggle = loop(kicks * kickT, (t) => {
    const k = (t % kickT) / kickT;
    const p = kickPose(t, k < 0.2 ? k / 0.2 : 0);
    const f = footAt(kickPose(t, 1));
    const by = f.y - 8 - 46 * 4 * k * (1 - k);
    return { pose: p, expr: { mouth: 'flat', brows: 0.4 }, eyeDir: { x: a.f * 0.2, y: 0.4 }, props: { ink: ball(f.x, by) } };
  });
  // The last kick goes wild: straight up, lands on his head, bounces off and rolls away.
  const bonk = loop(1.6, (t, u) => {
    const p = kickPose(kicks * kickT + t, u < 0.1 ? u / 0.1 : 0);
    const h = headOf(base(a, 0));
    const f = footAt(kickPose(0, 1));
    let bx: number;
    let by: number;
    if (u < 0.5) {
      const q = u / 0.5;
      bx = f.x + (h.x - f.x) * q;
      by = f.y - 8 + (h.y - BONES.headR - 5 - (f.y - 8)) * q - 110 * 4 * q * (1 - q);
    } else {
      const q = (u - 0.5) / 0.5;
      bx = h.x + a.f * 90 * q;
      by = h.y - BONES.headR - 5 - 30 * 4 * Math.min(q * 2, 1) * (1 - Math.min(q * 2, 1)) + (a.g - 5 - (h.y - BONES.headR - 5)) * Math.max(0, q * 2 - 1);
    }
    return { pose: p, expr: u > 0.48 && u < 0.8 ? { eyes: 'x', mouth: 'o' } : { eyes: 'wide', brows: 0.8, mouth: 'o' }, eyeDir: { x: 0, y: -1 }, props: { ink: ball(bx, Math.min(a.g - 5, by)) } };
  });
  const rub = loop(1.0, (t) => {
    const p = base(a, t);
    const h = headOf(p);
    reach(p, 'r', { x: h.x + 2 * Math.cos(t * 14), y: h.y - BONES.headR + 1 }, -1);
    return { pose: p, expr: { eyes: 'closed', mouth: 'wobbly' } };
  });
  return [juggle, bonk, rub, finish(a)];
}

function skippingRope(a: A): Phase[] {
  const jumps = 6;
  const jt = 0.46;
  const hold = (p: Pose): Pose => {
    const q = { ...p };
    const sh = shoulder(q);
    reach(q, 'l', { x: sh.x - a.f * 18, y: sh.y + 26 }, 1);
    reach(q, 'r', { x: sh.x + a.f * 18, y: sh.y + 26 }, 1);
    return q;
  };
  const rope = (p: Pose, ang: number): InkProp[] => {
    const l = hand(p, 'l');
    const r = hand(p, 'r');
    const midY = p.y + 20 + Math.cos(ang) * 60;
    return [ink(`M ${n(l.x)} ${n(l.y)} Q ${n((l.x + r.x) / 2)} ${n(midY)} ${n(r.x)} ${n(r.y)}`, 1, a.lite)];
  };
  const jump = loop(jumps * jt, (t) => {
    const k = (t % jt) / jt;
    const lift = 14 * Math.max(0, Math.sin(k * Math.PI));
    const p = hold(base(a, t));
    p.y -= lift;
    p.lKnee += 0.4 * (1 - Math.sin(k * Math.PI));
    p.rKnee += 0.4 * (1 - Math.sin(k * Math.PI));
    // The rope passes under his feet at the top of each jump.
    return { pose: p, expr: { mouth: 'grin', eyes: 'happy' }, props: { ink: rope(p, k * TAU + Math.PI) } };
  });
  const tangle = loop(0.9, (t, u) => {
    const p = hold(base(a, t));
    p.torso += 0.15 * Math.sin(u * TAU * 2);
    return { pose: p, expr: { mouth: 'wobbly', brows: 0.8 }, props: { ink: rope(p, Math.PI) } };
  });
  return [jump, tangle, finish(a)];
}

function paperPlane(a: A): Phase[] {
  const fold = loop(1.1, (t) => {
    const p = base(a, t);
    const sh = shoulder(p);
    const c = { x: sh.x + a.f * 14, y: sh.y + 18 };
    reach(p, 'l', { x: c.x - 2 + Math.sin(t * 12) * 2, y: c.y }, 1);
    reach(p, 'r', { x: c.x + 2, y: c.y + Math.cos(t * 12) * 2 }, 1);
    return { pose: p, expr: { mouth: 'flat', brows: 0.3 }, eyeDir: { x: a.f * 0.5, y: 0.6 }, props: { ink: [ink(`M ${n(c.x - 6)} ${n(c.y)} L ${n(c.x + 6)} ${n(c.y - 2)} L ${n(c.x + 4)} ${n(c.y + 3)} Z`, 1, a.lite)] } };
  });
  const throwP = loop(0.4, (t, u) => {
    const p = base(a, 1.1 + t);
    p.rShoulder = 2.4 - 1.4 * ease.out(u);
    p.rElbow = 0.6 - 0.5 * u;
    return { pose: p, expr: { mouth: 'grin' } };
  });
  // The plane glides across the screen and comes back to crash near his feet.
  const glideDur = 3.2;
  const planeAt = (u: number): { x: number; y: number; ang: number } => {
    const start = { x: a.x + a.f * 30, y: standHip(a.s) - 50 };
    const farX = Math.min(a.s.width - 30, Math.max(30, a.x + a.f * Math.min(420, a.s.width * 0.45)));
    const x = u < 0.6 ? start.x + (farX - start.x) * ease.out(u / 0.6) : farX + (a.x + a.f * 20 - farX) * ease.in((u - 0.6) / 0.4);
    const y = u < 0.6 ? start.y - 120 * Math.sin((u / 0.6) * Math.PI * 0.8) : start.y - 120 * Math.sin(Math.PI * 0.8) * (1 - ease.in((u - 0.6) / 0.4)) + (a.g - 4 - start.y) * ease.in((u - 0.6) / 0.4);
    const ang = u < 0.6 ? 0 : Math.PI;
    return { x, y, ang };
  };
  const plane = (q: { x: number; y: number; ang: number }): InkProp[] => {
    const dir = (q.ang === 0 ? a.f : -a.f) as number;
    return [ink(`M ${n(q.x + dir * 8)} ${n(q.y)} L ${n(q.x - dir * 7)} ${n(q.y - 4)} L ${n(q.x - dir * 4)} ${n(q.y)} L ${n(q.x - dir * 7)} ${n(q.y + 3)} Z`, 1, a.lite)];
  };
  const glide = loop(glideDur, (t, u) => {
    const p = base(a, 1.5 + t);
    const q = planeAt(u);
    const h = headOf(p);
    const len = Math.hypot(q.x - h.x, q.y - h.y) || 1;
    const crash = u > 0.93;
    // Arms up in surprise as it crashes back (eased, no snap).
    const up = ease.inOut(segment(u, 0.86, 0.96));
    p.lShoulder += (1.2 - p.lShoulder) * up;
    p.rShoulder += (1.3 - p.rShoulder) * up;
    return { pose: p, expr: crash ? { eyes: 'wide', mouth: 'o', brows: 1 } : { mouth: 'o', brows: 0.4 }, eyeDir: { x: (q.x - h.x) / len, y: (q.y - h.y) / len }, props: { ink: plane(q) } };
  });
  return [fold, throwP, glide, finish(a)];
}

function bubbles(a: A): Phase[] {
  const count = 6;
  const wand = (p: Pose): Vec => {
    const h = headOf(p);
    return { x: h.x + a.f * 16, y: h.y + 4 };
  };
  const hold = (p: Pose): Pose => {
    const q = { ...p };
    const w = wand(q);
    reach(q, 'r', { x: w.x - a.f * 4, y: w.y + 8 }, -1);
    return q;
  };
  const dur = 5.4;
  const bubble = (i: number, t: number, w: Vec): { x: number; y: number; r: number; alive: boolean } => {
    const born = 0.6 + i * 0.55;
    const life = 2.6;
    const age = t - born;
    const last = i === count - 1;
    if (age < 0 || age > life) {
      return { x: 0, y: 0, r: 0, alive: false };
    }
    const k = age / life;
    // The last bubble drifts back and pops in his face.
    const x = last ? w.x + a.f * (20 * Math.sin(k * Math.PI) - 18 * k) : w.x + a.f * (10 + 110 * k) + 8 * Math.sin(age * 3 + i);
    const y = last ? w.y - 6 * Math.sin(k * Math.PI) : w.y - 90 * k - 6 * i;
    return { x, y, r: 4 + 3 * Math.min(1, age * 3), alive: true };
  };
  const blow = loop(dur, (t) => {
    const p = hold(base(a, t));
    const w = wand(p);
    const items: InkProp[] = [ink(circle(w.x, w.y, 4), 1, a.lite)];
    for (let i = 0; i < count; i++) {
      const b = bubble(i, t, w);
      if (b.alive) {
        items.push(ink(circle(b.x, b.y, b.r), 1, a.lite, 0.8));
      }
    }
    const popInFace = t > 0.6 + (count - 1) * 0.55 + 2.4;
    return { pose: p, expr: popInFace ? { eyes: 'closed', mouth: 'wobbly' } : { mouth: 'o', brows: 0.2 }, props: { ink: items } };
  });
  return [blow, finish(a)];
}

function coffee(a: A): Phase[] {
  const cup = (p: Pose, lift: number): { pose: Pose; c: Vec } => {
    const q = { ...p };
    const h = headOf(q);
    const c = { x: h.x + a.f * (14 - 8 * lift), y: h.y + 18 - 16 * lift };
    reach(q, 'r', { x: c.x - a.f * 3, y: c.y + 2 }, -1);
    q.head += -0.25 * lift;
    return { pose: q, c };
  };
  const cupInk = (c: Vec, t: number, steam: boolean): InkProp[] => {
    const items = [ink(`M ${n(c.x - 4)} ${n(c.y - 5)} L ${n(c.x - 3)} ${n(c.y + 3)} L ${n(c.x + 3)} ${n(c.y + 3)} L ${n(c.x + 4)} ${n(c.y - 5)} M ${n(c.x + 4)} ${n(c.y - 3)} q 3 1 0 4`, 1, a.lite)];
    if (steam) {
      for (let i = 0; i < 2; i++) {
        const sx = c.x - 2 + i * 4;
        const w = Math.sin(t * 4 + i);
        items.push(ink(`M ${n(sx)} ${n(c.y - 7)} q ${n(3 * w)} -5 0 -10 q ${n(-3 * w)} -5 0 -10`, 1, a.lite, 0.5));
      }
    }
    return items;
  };
  const sips = loop(4.5, (t) => {
    const k = (t % 1.5) / 1.5;
    const lift = k > 0.3 && k < 0.8 ? Math.sin(((k - 0.3) / 0.5) * Math.PI) : 0;
    const { pose: p, c } = cup(base(a, t), lift);
    return { pose: p, expr: lift > 0.5 ? { eyes: 'closed', mouth: 'o' } : { eyes: 'happy', mouth: 'smile' }, props: { ink: cupInk(c, t, lift < 0.3) } };
  });
  return [sips, finish(a)];
}

function newspaper(a: A): Phase[] {
  const paper = (p: Pose, page: number): { pose: Pose; ink: InkProp[] } => {
    const q = { ...p, head: 0.15 };
    const sh = shoulder(q);
    const cx = sh.x + a.f * 20;
    const cy = sh.y + 6;
    reach(q, 'l', { x: cx - 16, y: cy - 6 }, 1);
    reach(q, 'r', { x: cx + 16, y: cy - 6 }, 1);
    let d = `M ${n(cx - 17)} ${n(cy - 16)} L ${n(cx + 17)} ${n(cy - 16)} L ${n(cx + 17)} ${n(cy + 14)} L ${n(cx - 17)} ${n(cy + 14)} Z M ${n(cx)} ${n(cy - 16)} L ${n(cx)} ${n(cy + 14)}`;
    for (let i = 0; i < 4; i++) {
      d += ` M ${n(cx - 14)} ${n(cy - 10 + i * 6)} L ${n(cx - 3)} ${n(cy - 10 + i * 6)} M ${n(cx + 3)} ${n(cy - 10 + i * 6)} L ${n(cx + 14)} ${n(cy - 10 + i * 6)}`;
    }
    if (page > 0 && page < 1) {
      const px = cx + 17 - page * 34;
      d += ` M ${n(cx)} ${n(cy - 16)} Q ${n((cx + px) / 2)} ${n(cy - 22)} ${n(px)} ${n(cy - 16)}`;
    }
    return { pose: q, ink: [ink(d, 1, a.lite)] };
  };
  const read = loop(5.2, (t) => {
    const { pose: p, ink: items } = paper(base(a, t), segment(t, 2.2, 2.8));
    const peek = t > 3.6 && t < 4.6;
    if (peek) {
      p.y -= 3;
      p.head = -0.25;
    }
    return { pose: p, expr: peek ? { brows: -0.4, mouth: 'flat' } : { mouth: 'smile', brows: 0.2 }, eyeDir: peek ? undefined : { x: a.f * 0.7, y: 0.4 }, props: { ink: items } };
  });
  return [read, finish(a)];
}

function floorSit(a: A, t: number, lift = 0): Pose {
  const p = pose({ x: a.x, y: a.g - 16 - lift, facing: a.f, torso: 0.05, head: 0.1 + 0.03 * Math.sin(t), lHip: 1.5, lKnee: 2.6, rHip: 1.4, rKnee: 2.5 });
  return p;
}

function laptop(a: A): Phase[] {
  const typing = loop(5, (t) => {
    const p = floorSit(a, t);
    const lap = { x: a.x + a.f * 16, y: a.g - 18 };
    reach(p, 'l', { x: lap.x - a.f * 3 + Math.sin(t * 23) * 1.5, y: lap.y - 2 - Math.max(0, Math.sin(t * 19)) * 2 }, 1);
    reach(p, 'r', { x: lap.x + a.f * 4 + Math.cos(t * 21) * 1.5, y: lap.y - 2 - Math.max(0, Math.cos(t * 17)) * 2 }, 1);
    p.head = 0.35;
    const lx = lap.x;
    const d = `M ${n(lx - 10)} ${n(lap.y)} L ${n(lx + 10)} ${n(lap.y)} M ${n(lx + a.f * 10)} ${n(lap.y)} L ${n(lx + a.f * 14)} ${n(lap.y - 14)} L ${n(lx - a.f * 4)} ${n(lap.y - 14)} L ${n(lx - a.f * 6)} ${n(lap.y)}`;
    const oops = t > 3.6 && t < 4.2;
    return { pose: p, expr: oops ? { eyes: 'wide', mouth: 'o', brows: 1 } : { mouth: 'flat', brows: 0.3 }, eyeDir: { x: a.f * 0.5, y: 0.5 }, props: { ink: [ink(d, 1, a.lite)] } };
  });
  const standUp = loop(0.5, (_t, u) => ({ pose: lerpPose(floorSit(a, 5), standAt(a.s, a.x, a.f), ease.inOut(u)) }));
  return [typing, standUp, finish(a, 0.25)];
}

function dance(a: A): Phase[] {
  const PART = 1.1;
  /** The arms/head of dance move `part` (0..3) on a beat. */
  const partPose = (p: Pose, part: number, beat: number): Pose => {
    const q = { ...p };
    if (part === 0) {
      q.lShoulder = 2.6 + 0.4 * beat;
      q.rShoulder = 2.6 - 0.4 * beat;
    } else if (part === 1) {
      q.rShoulder = 2.8;
      q.rElbow = 0.1;
      q.lShoulder = -0.6;
      q.lElbow = 1.4;
    } else if (part === 2) {
      q.lShoulder = 1.5 + 0.8 * beat;
      q.rShoulder = 1.5 - 0.8 * beat;
      q.lElbow = 1.6;
      q.rElbow = 1.6;
    } else {
      q.lShoulder = 0.6;
      q.rShoulder = 0.6;
      q.lElbow = 2.2 + 0.3 * beat;
      q.rElbow = 2.2 - 0.3 * beat;
      q.head += 0.25 * beat;
    }
    return q;
  };
  const moves = loop(4.4, (t) => {
    const p = base(a, t);
    const beat = Math.sin(t * Math.PI * 2.2);
    const part = Math.min(3, Math.floor(t / PART));
    p.y += 3 * Math.abs(beat);
    p.lKnee += 0.3 * Math.abs(beat);
    p.rKnee += 0.3 * Math.abs(beat);
    p.torso += 0.12 * beat;
    const cur = partPose(p, part, beat);
    // Each new move blends in over a quarter second.
    const w = part === 0 ? 1 : ease.inOut(Math.min(1, (t - part * PART) / 0.3));
    return { pose: w < 1 ? lerpPose(partPose(p, part - 1, beat), cur, w) : cur, expr: { eyes: 'happy', mouth: 'grin' } };
  });
  return [moves, finish(a, 0.4)];
}

function meditate(a: A): Phase[] {
  const sitDown = loop(0.6, (_t, u) => ({ pose: lerpPose(standAt(a.s, a.x, a.f), floorSit(a, 0), ease.inOut(u)) }));
  const float = loop(4.2, (t, u) => {
    const lift = 22 * Math.sin(Math.min(1, u * 3, (1 - u) * 3) * (Math.PI / 2)) + 2 * Math.sin(t * 2);
    const p = floorSit(a, t, lift);
    reach(p, 'l', { x: a.x - a.f * 10, y: p.y + 12 }, 1);
    reach(p, 'r', { x: a.x + a.f * 12, y: p.y + 12 }, 1);
    p.head = 0;
    return { pose: p, expr: { eyes: 'closed', mouth: 'smile', brows: -0.1 } };
  });
  const up = loop(0.55, (_t, u) => ({ pose: lerpPose(floorSit(a, 4.2), standAt(a.s, a.x, a.f), ease.inOut(u)) }));
  return [sitDown, float, up, finish(a, 0.25)];
}

function headstand(a: A): Phase[] {
  const crouch = { ...standAt(a.s, a.x, a.f), y: standHip(a.s) + 14, torso: 1.2, head: 0.5, lShoulder: 1.2, rShoulder: 1.3, lHip: 1.4, lKnee: 1.9, rHip: 1.3, rKnee: 1.8 };
  const upside = (t: number): Pose => {
    const wob = 0.05 * Math.sin(t * 5) + 0.03 * Math.sin(t * 11);
    const p = pose({ x: a.x + wob * 30, y: a.g - (BONES.spine + BONES.neck + 2 * BONES.headR) + 2, rot: a.f * Math.PI + wob, facing: a.f, torso: 0, head: 0, lHip: 0.1, lKnee: 0.15, rHip: -0.1, rKnee: 0.1 });
    reach(p, 'l', { x: a.x - 14, y: a.g - 1 }, 1);
    reach(p, 'r', { x: a.x + 14, y: a.g - 1 }, 1);
    return p;
  };
  return [
    loop(0.4, (_t, u) => ({ pose: lerpPose(standAt(a.s, a.x, a.f), crouch, ease.inOut(u)) })),
    loop(0.6, (_t, u) => ({ pose: lerpPose(crouch, upside(0), ease.inOut(u)), expr: { mouth: 'o' } })),
    loop(3.0, (t) => ({ pose: upside(t), expr: { mouth: 'wobbly', brows: 0.6 } })),
    loop(0.6, (_t, u) => ({ pose: lerpPose(upside(3), { ...crouch, rot: a.f * 2 * Math.PI }, ease.inOut(u)) })),
    finish(a, 0.45),
  ];
}

function magic(a: A): Phase[] {
  const flower = a.rng() < 0.5;
  const hatAt = (p: Pose): Vec => {
    const sh = shoulder(p);
    return { x: sh.x + a.f * 18, y: sh.y + 26 };
  };
  const hatInk = (h: Vec): InkProp => ink(`M ${n(h.x - 12)} ${n(h.y)} L ${n(h.x + 12)} ${n(h.y)} M ${n(h.x - 8)} ${n(h.y)} L ${n(h.x - 7)} ${n(h.y + 14)} L ${n(h.x + 7)} ${n(h.y + 14)} L ${n(h.x + 8)} ${n(h.y)}`, 1, a.lite);
  const hold = (p: Pose): Pose => {
    const q = { ...p };
    const h = hatAt(q);
    reach(q, 'l', { x: h.x - a.f * 6, y: h.y + 12 }, 1);
    return q;
  };
  const taps = loop(1.6, (t, u) => {
    const p = hold(base(a, t));
    const h = hatAt(p);
    const tap = Math.max(0, Math.sin(t * 9));
    reach(p, 'r', { x: h.x + a.f * 6, y: h.y - 12 + 8 * tap }, -1);
    return { pose: p, expr: { brows: 0.6, mouth: 'flat' }, props: { ink: [ink(hatInk(h).d, u * 3, a.lite)] } };
  });
  const reveal = loop(2.2, (t, u) => {
    const p = hold(base(a, 1.6 + t));
    const h = hatAt(p);
    const rise = 28 * ease.out(Math.min(1, u * 1.6));
    reach(p, 'r', { x: h.x, y: h.y - 4 - rise }, -1);
    const top = { x: h.x, y: h.y - rise };
    const thing = flower
      ? `M ${n(top.x)} ${n(h.y)} L ${n(top.x)} ${n(top.y)} ${circle(top.x, top.y - 4, 4)} ${circle(top.x - 5, top.y - 4, 2.5)} ${circle(top.x + 5, top.y - 4, 2.5)}`
      : `${circle(top.x, top.y, 5)} M ${n(top.x - 3)} ${n(top.y - 4)} L ${n(top.x - 4)} ${n(top.y - 14)} M ${n(top.x + 3)} ${n(top.y - 4)} L ${n(top.x + 4)} ${n(top.y - 14)}`;
    return { pose: p, expr: u > 0.4 ? { eyes: 'happy', mouth: 'grin' } : { mouth: 'o', brows: 0.8 }, props: { ink: [hatInk(h), ink(thing, u * 2.5, a.lite)] } };
  });
  const bow = loop(0.9, (t, u) => {
    const p = base(a, t);
    p.torso = 0.04 + 0.6 * Math.sin(u * Math.PI);
    p.head = 0.3 * Math.sin(u * Math.PI);
    p.rShoulder = 0.9;
    p.rElbow = 1.8;
    return { pose: p, expr: { eyes: 'closed', mouth: 'smile' } };
  });
  return [taps, reveal, bow, finish(a)];
}

function fishing(a: A): Phase[] {
  const seat = a.seat ?? { x: a.x, y: a.g };
  const f: 1 | -1 = -1;
  const sit = (t: number): Pose => sitting(seat.x, seat.y, f, t);
  const rod = (p: Pose, bob: number, fish: number): InkProp[] => {
    const hL = hand(p, 'l');
    const tip = { x: hL.x + f * 36, y: hL.y - 26 };
    const bobY = seat.y + 60 + bob;
    const items = [ink(`M ${n(hL.x)} ${n(hL.y)} L ${n(tip.x)} ${n(tip.y)} M ${n(tip.x)} ${n(tip.y)} L ${n(tip.x)} ${n(bobY - fish * 70)}`, 1, a.lite), ink(circle(tip.x, bobY - fish * 70, 2.5), 1, a.lite)];
    if (fish > 0) {
      const fy = bobY - fish * 70 + 8;
      items.push(ink(`M ${n(tip.x - 8)} ${n(fy)} Q ${n(tip.x)} ${n(fy - 6)} ${n(tip.x + 8)} ${n(fy)} Q ${n(tip.x)} ${n(fy + 6)} ${n(tip.x - 8)} ${n(fy)} M ${n(tip.x + 8)} ${n(fy)} L ${n(tip.x + 13)} ${n(fy - 4)} L ${n(tip.x + 13)} ${n(fy + 4)} Z`, 1, a.lite));
    }
    return items;
  };
  const hold = (p: Pose): Pose => {
    const q = { ...p };
    const sh = shoulder(q);
    reach(q, 'l', { x: sh.x + f * 16, y: sh.y + 14 }, 1);
    reach(q, 'r', { x: sh.x + f * 10, y: sh.y + 18 }, 1);
    return q;
  };
  const wait = loop(3.0, (t) => {
    const p = hold(sit(t));
    const bite = t > 2.3;
    return { pose: p, expr: bite ? { eyes: 'wide', mouth: 'o', brows: 0.9 } : { eyes: 'sleepy', mouth: 'flat' }, props: { ink: rod(p, bite ? 5 * Math.sin(t * 30) : 2 * Math.sin(t * 2), 0) } };
  });
  const reel = loop(1.4, (t, u) => {
    const p = hold(sit(3 + t));
    p.lShoulder += 0.4 * ease.out(u);
    return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: rod(p, 0, ease.out(u)) } };
  });
  const release = loop(1.0, (t, u) => {
    const p = hold(sit(4.4 + t));
    return { pose: p, expr: { mouth: 'smile' }, props: { ink: rod(p, 0, 1 - ease.in(u)) } };
  });
  return [wait, reel, release, holdPhase(0.35, () => ({ pose: sit(0) }))];
}

function kite(a: A): Phase[] {
  const fly = loop(6, (t, u) => {
    const p = base(a, t);
    const sh = shoulder(p);
    reach(p, 'r', { x: sh.x + a.f * 16, y: sh.y - 10 }, -1);
    p.head = -0.4;
    const h = hand(p, 'r');
    const rise = ease.out(Math.min(1, u * 2));
    const kx = Math.min(a.s.width - 20, Math.max(20, h.x + a.f * (60 + 80 * rise) + 18 * Math.sin(t * 1.3)));
    const ky = Math.max(24, h.y - (40 + 150 * rise) + 10 * Math.sin(t * 2.1));
    const kiteD = `M ${n(kx)} ${n(ky - 12)} L ${n(kx + 9)} ${n(ky)} L ${n(kx)} ${n(ky + 14)} L ${n(kx - 9)} ${n(ky)} Z M ${n(kx)} ${n(ky - 12)} L ${n(kx)} ${n(ky + 14)} M ${n(kx - 9)} ${n(ky)} L ${n(kx + 9)} ${n(ky)}`;
    let tail = `M ${n(kx)} ${n(ky + 14)}`;
    for (let i = 1; i <= 4; i++) {
      tail += ` L ${n(kx + (i % 2 ? 5 : -5) * Math.sin(t * 5 + i))} ${n(ky + 14 + i * 7)}`;
    }
    return { pose: p, expr: { eyes: 'happy', mouth: 'grin' }, eyeDir: { x: a.f * 0.4, y: -0.9 }, props: { ink: [ink(`M ${n(h.x)} ${n(h.y)} Q ${n((h.x + kx) / 2)} ${n((h.y + ky) / 2 + 30)} ${n(kx)} ${n(ky + 14)}`, 1, a.lite, 0.7), ink(kiteD, 1, a.lite), ink(tail, 1, a.lite, 0.8)] } };
  });
  return [fly, finish(a)];
}

function selfie(a: A): Phase[] {
  const phoneAt = (p: Pose): Vec => {
    const sh = shoulder(p);
    return { x: sh.x + a.f * 30, y: sh.y - 20 };
  };
  const phoneInk = (c: Vec): InkProp => ink(`M ${n(c.x - 4)} ${n(c.y - 7)} L ${n(c.x + 4)} ${n(c.y - 7)} L ${n(c.x + 4)} ${n(c.y + 7)} L ${n(c.x - 4)} ${n(c.y + 7)} Z`, 1, a.lite);
  const pose1 = loop(2.6, (t) => {
    const p = base(a, t);
    const c = phoneAt(p);
    reach(p, 'r', c, -1);
    // Peace sign with the other hand near his head.
    const hd = headOf(p);
    reach(p, 'l', { x: hd.x - a.f * 14, y: hd.y - 8 }, -1);
    p.head = -0.15;
    p.torso -= 0.05;
    return { pose: p, expr: t > 1.6 ? { eyes: 'closed', mouth: 'grin' } : { eyes: 'happy', mouth: 'grin' }, eyeDir: { x: a.f * 0.8, y: -0.4 }, props: { ink: [phoneInk(c)] } };
  });
  const check = loop(1.6, (t) => {
    const p = base(a, 2.6 + t);
    const sh = shoulder(p);
    const c = { x: sh.x + a.f * 16, y: sh.y + 10 };
    reach(p, 'r', c, 1);
    p.head = 0.4;
    return { pose: p, expr: { mouth: t > 0.8 ? 'grin' : 'flat', brows: 0.4 }, eyeDir: { x: a.f * 0.5, y: 0.7 }, props: { ink: [phoneInk(c)] } };
  });
  return [pose1, check, finish(a)];
}

function wave(a: A): Phase[] {
  const toward: 1 | -1 = a.pointer ? (a.pointer.x >= a.x ? 1 : -1) : a.f;
  const b = { ...a, f: toward };
  const waving = loop(2.6, (t) => {
    const p = base(b, t);
    p.rShoulder = 2.8;
    p.rElbow = 0.4 + 0.5 * Math.sin(t * 12);
    p.head = -0.1;
    return { pose: p, expr: { eyes: 'happy', mouth: 'grin', brows: 0.4 } };
  });
  return [waving, holdPhase(0.35, () => ({ pose: standAt(a.s, a.x, toward) })), finish(a, 0.35)];
}

function glassDoodle(a: A): Phase[] {
  const origin = { x: a.x + a.f * 36, y: standHip(a.s) - 42 };
  const shape = (u: number): Vec => {
    const ang = u * TAU;
    // A little sun: circle then two rays.
    if (u < 0.75) {
      const q = u / 0.75;
      return { x: origin.x + Math.cos(q * TAU) * 9, y: origin.y + Math.sin(q * TAU) * 9 };
    }
    const q = (u - 0.75) / 0.25;
    return { x: origin.x + 12 + 8 * q, y: origin.y - 12 - 8 * q + 0 * ang };
  };
  const pathUpTo = (u: number): string => {
    let d = '';
    for (let i = 0; i <= 30; i++) {
      const q = (i / 30) * u;
      const pt = shape(q);
      d += `${i === 0 ? 'M' : 'L'} ${n(pt.x)} ${n(pt.y)} `;
    }
    return d;
  };
  const draw = loop(2.4, (t, u) => {
    const p = base(a, t);
    p.torso = 0.12;
    reach(p, 'r', shape(u), -1);
    return { pose: p, expr: { mouth: 'flat', brows: 0.3 }, eyeDir: { x: a.f * 0.9, y: -0.1 }, props: { ink: [ink(pathUpTo(u), 1, a.lite)] } };
  });
  const admire = loop(0.8, (t) => ({ pose: base(a, 2.4 + t), expr: { eyes: 'happy', mouth: 'grin' }, props: { ink: [ink(pathUpTo(1), 1, a.lite)] } }));
  const erase = loop(1.4, (t, u) => {
    const p = base(a, 3.2 + t);
    p.torso = 0.12;
    reach(p, 'r', { x: origin.x + 14 * Math.sin(t * 14), y: origin.y - 6 + 12 * u }, -1);
    return { pose: p, expr: { mouth: 'flat' }, props: { ink: [ink(pathUpTo(1), 1, a.lite, 1 - u)] } };
  });
  return [draw, admire, erase, finish(a)];
}

/* ───────────── registry ───────────── */

export interface ActivityInfo {
  weight: number;
  /** Kept in lite mode (no particle-heavy props). */
  lite: boolean;
  /** Only when docked on the chat panel. */
  docked?: boolean;
  /** Weight multiplier 6:00–11:00 local time. */
  morning?: number;
  /** Only on a "windy" day. */
  windy?: boolean;
}

export const ACTIVITIES: Readonly<Record<ActivityAction, ActivityInfo>> = {
  'act-yoyo': { weight: 1.2, lite: true },
  'act-keepyuppy': { weight: 1.2, lite: true },
  'act-rope': { weight: 1, lite: true },
  'act-plane': { weight: 1, lite: true },
  'act-bubbles': { weight: 1, lite: false },
  'act-coffee': { weight: 0.8, lite: true, morning: 4 },
  'act-newspaper': { weight: 0.9, lite: true },
  'act-laptop': { weight: 0.9, lite: true },
  'act-dance': { weight: 1, lite: true },
  'act-meditate': { weight: 0.7, lite: true },
  'act-headstand': { weight: 0.7, lite: true },
  'act-magic': { weight: 0.9, lite: true },
  'act-fishing': { weight: 1, lite: true, docked: true },
  'act-kite': { weight: 1.2, lite: false, windy: true },
  'act-selfie': { weight: 0.8, lite: true },
  'act-wave': { weight: 0.9, lite: true },
  'act-glassdoodle': { weight: 0.8, lite: true },
};

export const ACTIVITY_ACTIONS = Object.keys(ACTIVITIES) as ActivityAction[];

export function isActivity(action: string): action is ActivityAction {
  return action in ACTIVITIES;
}

function phasesFor(action: ActivityAction, a: A): Phase[] {
  switch (action) {
    case 'act-yoyo':
      return yoyo(a);
    case 'act-keepyuppy':
      return keepyuppy(a);
    case 'act-rope':
      return skippingRope(a);
    case 'act-plane':
      return paperPlane(a);
    case 'act-bubbles':
      return bubbles(a);
    case 'act-coffee':
      return coffee(a);
    case 'act-newspaper':
      return newspaper(a);
    case 'act-laptop':
      return laptop(a);
    case 'act-dance':
      return dance(a);
    case 'act-meditate':
      return meditate(a);
    case 'act-headstand':
      return headstand(a);
    case 'act-magic':
      return magic(a);
    case 'act-fishing':
      return fishing(a);
    case 'act-kite':
      return kite(a);
    case 'act-selfie':
      return selfie(a);
    case 'act-wave':
      return wave(a);
    case 'act-glassdoodle':
      return glassDoodle(a);
  }
}

function eventsFor(action: ActivityAction, a: A): ((starts: number[]) => ClipEvent[]) | undefined {
  if (action === 'act-selfie') {
    return (s) => [{ t: s[0] + 1.6, type: 'sparkle', point: { x: a.x + a.f * 34, y: standHip(a.s) - 46 } }];
  }
  if (action === 'act-bubbles') {
    return (s) => [{ t: s[0] + 0.6 + 5 * 0.55 + 2.55, type: 'ring', at: 'head' }];
  }
  if (action === 'act-magic') {
    return (s) => [{ t: s[1] + 0.8, type: 'sparkle', at: 'rHand' }];
  }
  return undefined;
}

/** Where an activity ends. */
export function activityEnd(action: ActivityAction, c: ActivityContext): Pose {
  if (action === 'act-fishing' && c.seat) {
    return sitting(c.seat.x, c.seat.y, -1, 0);
  }
  return standAt(c.stage, c.from.x, c.from.facing);
}

export function createActivityClip(action: ActivityAction, c: ActivityContext): Clip {
  const a: A = { s: c.stage, g: c.stage.ground, x: c.from.x, f: c.from.facing, lite: c.lite, rng: c.rng, seat: c.seat, pointer: c.pointer };
  return buildPhases(action, c.from, phasesFor(action, a), { events: eventsFor(action, a) });
}

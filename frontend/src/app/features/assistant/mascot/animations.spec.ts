import { ANGLE_KEYS, STAND, forwardKinematics } from './skeleton';
import { ActionName, ActivityAction, ENTRANCES, GetUpAction, MoveAction, RouteAction, EXITS, IDLE_WEIGHTS, Stage, WALK, ballistic, createClip, createGesture, gaitPose } from './animations';
import { MascotEngine } from './mascot-engine';
import { GRAVITY, PHYSICS_DT, defaultWorld, physicsStep } from './physics';

const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 900, y: 400 }, corner: { x: 1166, y: 766 } };
type ClipAction = Exclude<ActionName, GetUpAction | RouteAction | MoveAction | ActivityAction>;
const OTHERS: ClipAction[] = ['peek-out', 'sleep', 'wake', 'dragged', 'airborne', 'land', 'dizzy', 'dust', 'docked', 'static', 'hold-on', 'curl', 'orb', 'orb-pop', 'unfold', 'idle-breathe'];
const ALL: ClipAction[] = [...ENTRANCES, ...EXITS, 'exit-peek', ...(Object.keys(IDLE_WEIGHTS) as ClipAction[]), ...OTHERS];

function seeded(seed = 1): () => number {
  let s = seed;
  return () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

describe('animation clips', () => {
  it.each(ALL)('%s produces finite poses over its whole duration', (action) => {
    const clip = createClip(action, { stage: STAGE, from: { ...STAND, x: 500, y: 754 }, side: 'left', targetX: 600, rng: seeded(3) });
    const end = Number.isFinite(clip.duration) ? clip.duration : 3;
    for (let t = 0; t <= end; t += end / 12) {
      const f = clip.sample(t, { vx: 100, vy: -200, spin: 1, pointer: { x: 520, y: 700 } });
      for (const key of [...ANGLE_KEYS, 'x', 'y', 'rot', 'squash'] as const) {
        expect(Number.isFinite(f.pose[key])).toBe(true);
      }
      const j = forwardKinematics(f.pose);
      expect(Number.isFinite(j.head.x) && Number.isFinite(j.lFoot.y)).toBe(true);
    }
  });

  it('walk cycle keeps the stance foot planted (IK, no sliding) on the ground', () => {
    const x0 = 300;
    const stanceEnd = WALK.stride * WALK.stance;
    const xs: number[] = [];
    for (let s = 1; s < stanceEnd - 1; s += 2) {
      const p = gaitPose(STAGE, x0 + s, s, 1, WALK);
      const j = forwardKinematics(p);
      xs.push(p.x + j.lFoot.x);
      expect(Math.abs(p.y + j.lFoot.y - STAGE.ground)).toBeLessThan(0.6);
    }
    expect(Math.max(...xs) - Math.min(...xs)).toBeLessThan(0.6);
  });

  it('ballistic() lands the jump on the target x', () => {
    const shot = ballistic(100, 900, 600, 754, 200);
    const world = { ...defaultWorld(1200, 754), drag: 0, walls: false, ceiling: null };
    let b = { x: 100, y: 900, vx: shot.vx, vy: shot.vy, rot: 0, spin: 0 };
    for (let i = 0; i < 1000; i++) {
      const r = physicsStep(b, PHYSICS_DT, world);
      b = r.body;
      if (r.events.some((e) => e.type === 'impact')) {
        break;
      }
    }
    expect(Math.abs(b.x - 600)).toBeLessThan(8);
    expect(shot.time).toBeGreaterThan(Math.sqrt((2 * 200) / GRAVITY));
  });

  it('the point gesture aims the arm at the target', () => {
    const base = { ...STAND, x: 500, y: 700 };
    const right = createGesture('point', { x: 900, y: 610 }).sample(0, base).pose.rShoulder ?? 0;
    const down = createGesture('point', { x: 500, y: 1000 }).sample(0, base).pose.rShoulder ?? 0;
    expect(right).toBeGreaterThan(1.2);
    expect(Math.abs(down)).toBeLessThan(0.3);
  });
});

describe('mascot engine', () => {
  const tickFor = (e: MascotEngine, seconds: number): string[] => {
    const events: string[] = [];
    for (let t = 0; t < seconds; t += 1 / 60) {
      events.push(...e.tick(1 / 60).events.map((ev) => (ev.type === 'clipDone' ? `done:${ev.action}` : ev.type)));
    }
    return events;
  };

  it('a jump entrance launches, flies, lands (dust + squash) and plays the landing', () => {
    const e = new MascotEngine(STAGE, seeded(5));
    e.play('enter-jump', { targetX: 600 });
    const events = tickFor(e, 0.1);
    expect(e.airborne).toBe(true);
    events.push(...tickFor(e, 3));
    expect(events).toContain('landed');
    expect(events).toContain('done:land');
    expect(Math.abs(e.root.x - 600)).toBeLessThan(80);
    expect(e.root.y).toBeCloseTo(STAGE.ground - 44, 0);
  });

  it('spawns dust when he hits the ground', () => {
    const e = new MascotEngine(STAGE, seeded(5));
    e.play('enter-drop', { targetX: 400 });
    let sawDust = false;
    for (let t = 0; t < 4 && !sawDust; t += 1 / 60) {
      sawDust = e.tick(1 / 60).frame.effects.some((fx) => fx.kind === 'puff');
    }
    expect(sawDust).toBe(true);
  });

  it('is thrown with the pointer velocity after a drag and lands', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    e.play('static', { targetX: 600, blend: 0 });
    tickFor(e, 0.2);
    const r = e.root;
    e.dragStart(r.x, r.y - 20);
    tickFor(e, 0.1);
    for (let i = 1; i <= 5; i++) {
      e.dragMove(r.x + i * 20, r.y - 20 - i * 30);
    }
    tickFor(e, 0.05);
    e.dragEnd();
    expect(e.airborne).toBe(true);
    const events = tickFor(e, 5);
    expect(events).toContain('landed');
    expect(e.root.x).toBeGreaterThan(r.x);
  });

  it('is deterministic for the same random source', () => {
    const run = (): number[] => {
      const e = new MascotEngine(STAGE, seeded(9));
      e.play('enter-rope', { side: 'left', targetX: 500 });
      const xs: number[] = [];
      for (let i = 0; i < 180; i++) {
        xs.push(e.tick(1 / 60).frame.pose.x);
      }
      return xs;
    };
    expect(run()).toEqual(run());
  });

  it('reduced motion: no effects and no spring lag', () => {
    const e = new MascotEngine(STAGE, seeded(5), true);
    e.play('enter-drop', { targetX: 400 });
    let effects = 0;
    for (let t = 0; t < 4; t += 1 / 60) {
      effects += e.tick(1 / 60).frame.effects.length;
    }
    expect(effects).toBe(0);
  });

  it('curls into the corner circle and unfolds back', () => {
    const e = new MascotEngine({ ...STAGE, seat: null }, seeded(4));
    e.play('static', { targetX: 600, blend: 0 });
    tickFor(e, 0.1);
    e.play('curl');
    const events = tickFor(e, 2);
    expect(events).toContain('done:curl');
    e.play('orb');
    e.tick(0.6);
    const frame = e.tick(0.1).frame;
    expect(frame.morph).toBeCloseTo(1, 1);
    expect(Math.hypot(e.head.x - STAGE.corner.x, e.head.y - STAGE.corner.y)).toBeLessThan(40);
    e.play('unfold');
    tickFor(e, 2.2);
    expect(e.tick(1 / 60).frame.morph).toBeLessThan(0.05);
  });
});

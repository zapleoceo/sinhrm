import { accumulate, apexTime, defaultWorld, launchSpeed, physicsStep, Body, PHYSICS_DT } from './physics';
import { STAND } from './skeleton';
import { applySprings, approach, initPoseSprings, springStep, stepPoseSprings } from './springs';

function run(body: Body, seconds: number, world = defaultWorld(1000, 700)): { body: Body; events: string[]; settledAt: number | null } {
  let b = body;
  const events: string[] = [];
  let settledAt: number | null = null;
  const steps = Math.round(seconds / PHYSICS_DT);
  for (let i = 0; i < steps; i++) {
    const r = physicsStep(b, PHYSICS_DT, world);
    b = r.body;
    events.push(...r.events.map((e) => e.type));
    if (r.settled && settledAt === null) {
      settledAt = i * PHYSICS_DT;
      break;
    }
  }
  return { body: b, events, settledAt };
}

describe('springs', () => {
  it('a critically damped spring converges without overshoot', () => {
    let s = { x: 0, v: 0 };
    let max = 0;
    for (let i = 0; i < 600; i++) {
      s = springStep(s, 1, PHYSICS_DT, { k: 200, zeta: 1 });
      max = Math.max(max, s.x);
    }
    expect(s.x).toBeCloseTo(1, 3);
    expect(max).toBeLessThanOrEqual(1.0001);
  });

  it('an underdamped spring overshoots and still settles', () => {
    let s = { x: 0, v: 0 };
    let max = 0;
    for (let i = 0; i < 1200; i++) {
      s = springStep(s, 1, PHYSICS_DT, { k: 150, zeta: 0.35 });
      max = Math.max(max, s.x);
    }
    expect(max).toBeGreaterThan(1.2);
    expect(s.x).toBeCloseTo(1, 2);
  });

  it('approach() is frame-rate independent', () => {
    let a = 0;
    for (let i = 0; i < 10; i++) {
      a = approach(a, 1, 0.01, 0.05);
    }
    expect(approach(0, 1, 0.1, 0.05)).toBeCloseTo(a, 6);
    expect(approach(0, 1, 0.05, 0.05)).toBeCloseTo(0.5, 6);
  });

  it('root deceleration swings the loose arms forward, legs stay planted', () => {
    const target = { ...STAND };
    let s = initPoseSprings(target);
    // A hard stop while running forward: negative acceleration.
    for (let i = 0; i < 12; i++) {
      s = stepPoseSprings(s, target, PHYSICS_DT, -3000, 0);
    }
    const shown = applySprings(target, s);
    expect(shown.rShoulder).toBeGreaterThan(target.rShoulder + 0.05);
    expect(Math.abs(shown.lKnee - target.lKnee)).toBeLessThan(0.01);
    for (let i = 0; i < 600; i++) {
      s = stepPoseSprings(s, target, PHYSICS_DT, 0, 0);
    }
    expect(applySprings(target, s).rShoulder).toBeCloseTo(target.rShoulder, 2);
  });
});

describe('physics', () => {
  it('follows a parabola under gravity (reaches the expected apex)', () => {
    const vy = launchSpeed(200);
    const world = { ...defaultWorld(1000, 700), drag: 0, ceiling: null };
    let b: Body = { x: 100, y: 700, vx: 0, vy, rot: 0, spin: 0 };
    let minY = b.y;
    for (let i = 0; i < Math.round(apexTime(vy) / PHYSICS_DT) + 5; i++) {
      b = physicsStep(b, PHYSICS_DT, world).body;
      minY = Math.min(minY, b.y);
    }
    expect(700 - minY).toBeGreaterThan(190);
    expect(700 - minY).toBeLessThan(210);
  });

  it('bounces with restitution and settles on the ground', () => {
    const r = run({ x: 200, y: 100, vx: 0, vy: 0, rot: 0, spin: 0 }, 5);
    expect(r.events.filter((e) => e === 'impact').length).toBeGreaterThanOrEqual(2);
    expect(r.settledAt).not.toBeNull();
    expect(r.body.y).toBe(700);
    expect(r.body.vy).toBe(0);
  });

  it('each bounce is lower than the previous one', () => {
    const world = defaultWorld(1000, 700);
    let b: Body = { x: 200, y: 100, vx: 0, vy: 0, rot: 0, spin: 0 };
    const speeds: number[] = [];
    for (let i = 0; i < 1000 && speeds.length < 3; i++) {
      const r = physicsStep(b, PHYSICS_DT, world);
      b = r.body;
      for (const e of r.events) {
        if (e.type === 'impact') {
          speeds.push(e.speed);
        }
      }
    }
    expect(speeds[1]).toBeLessThan(speeds[0] * 0.5);
  });

  it('bounces off the side walls and reverses the spin', () => {
    const world = defaultWorld(400, 700);
    let b: Body = { x: 350, y: 300, vx: 2000, vy: 0, rot: 0, spin: 5 };
    let wall = false;
    for (let i = 0; i < 30 && !wall; i++) {
      const r = physicsStep(b, PHYSICS_DT, world);
      b = r.body;
      wall = r.events.some((e) => e.type === 'wall');
    }
    expect(wall).toBe(true);
    expect(b.vx).toBeLessThan(0);
    expect(b.spin).toBeLessThan(0);
    expect(b.x).toBeLessThanOrEqual(400 - world.wallMargin);
  });

  it('reports offscreen when the floor is disabled (exit through the bottom)', () => {
    const r = run({ x: 200, y: 600, vx: 0, vy: -300, rot: 0, spin: 0 }, 3, { ...defaultWorld(1000, 700), floor: false });
    expect(r.events).toContain('offscreen');
  });

  it('spins the body while airborne', () => {
    const world = defaultWorld(1000, 700);
    const b = physicsStep({ x: 200, y: 300, vx: 0, vy: 0, rot: 0, spin: 6 }, 0.1, world).body;
    expect(b.rot).toBeGreaterThan(0.5);
  });

  it('fixed-timestep accumulator carries the remainder and caps long frames', () => {
    const a = accumulate(0, 0.02, 0.01);
    expect(a.steps).toBe(2);
    expect(a.acc).toBeCloseTo(0, 9);
    const b = accumulate(0.004, 0.009, 0.01);
    expect(b.steps).toBe(1);
    expect(b.acc).toBeCloseTo(0.003, 9);
    expect(accumulate(0, 5, 0.01).steps).toBe(10);
  });
});

import { createGetUp, keepAboveGround, nearestAngle } from './getup';
import { Stage } from './animations';
import { Grab, LINKS, P, POINT_COUNT, Ragdoll, RAGDOLL_DT, anchorOf, applyStruggle, centerOfMass, createRagdoll, fitPose, grabAt, kineticEnergy, orientationOf, point, ragdollWorld, stepRagdoll } from './ragdoll';
import { BONES, STAND, forwardKinematics } from './skeleton';

const W = ragdollWorld(1200, 800);
const STAGE: Stage = { width: 1200, height: 800, ground: 800, seat: null, corner: { x: 1166, y: 766 } };

function standingRagdoll(vx = 0, vy = 0, spin = 0, y = 756): Ragdoll {
  const pose = { ...STAND, x: 600, y };
  return createRagdoll({ x: pose.x, y: pose.y }, forwardKinematics(pose), vx, vy, spin);
}

function run(r: Ragdoll, seconds: number, each?: (r: Ragdoll) => void): number {
  let maxImpact = 0;
  for (let i = 0; i < Math.round(seconds / RAGDOLL_DT) && !r.rested; i++) {
    maxImpact = Math.max(maxImpact, stepRagdoll(r, W).impact);
    each?.(r);
  }
  return maxImpact;
}

function allFinite(r: Ragdoll): boolean {
  return Array.from(r.pos).every(Number.isFinite) && Array.from(r.prev).every(Number.isFinite);
}

describe('ragdoll', () => {
  it('is created at the exact drawn joints (continuous hand-over)', () => {
    const pose = { ...STAND, x: 300, y: 500 };
    const j = forwardKinematics(pose);
    const r = createRagdoll({ x: 300, y: 500 }, j, 0, 0, 0);
    expect(point(r, P.Head)).toEqual({ x: 300 + j.head.x, y: 500 + j.head.y });
    expect(point(r, P.Pelvis)).toEqual({ x: 300, y: 500 });
  });

  it('falls, bounces, loses energy and comes to rest lying on the floor', () => {
    const r = standingRagdoll(250, -700, -8, 400);
    const energies: number[] = [];
    run(r, 8, (rr) => energies.push(kineticEnergy(rr)));
    expect(r.rested).toBe(true);
    expect(allFinite(r)).toBe(true);
    expect(kineticEnergy(r)).toBe(0);
    // Energy in the last second is far below the peak.
    const peak = Math.max(...energies);
    const tail = energies.slice(-30);
    expect(Math.max(...tail)).toBeLessThan(peak * 0.02);
    // Down on the floor (lying or slumped), not standing.
    expect(point(r, P.Pelvis).y).toBeGreaterThan(W.ground - 32);
  });

  it('keeps bones at their lengths (no explosion) after a violent throw', () => {
    const r = standingRagdoll(2600, -2600, 16, 300);
    run(r, 8);
    expect(allFinite(r)).toBe(true);
    for (const [a, b, min, max, stiff] of LINKS) {
      if (stiff === 1 && min === max) {
        const d = Math.hypot(r.pos[a * 2] - r.pos[b * 2], r.pos[a * 2 + 1] - r.pos[b * 2 + 1]);
        expect(Math.abs(d - min)).toBeLessThan(2);
      }
    }
  });

  it('never tunnels through the floor or walls, even very fast', () => {
    const r = standingRagdoll(-5000, 9000, 20, 200);
    run(r, 3, (rr) => {
      for (let i = 0; i < POINT_COUNT; i++) {
        expect(rr.pos[i * 2 + 1]).toBeLessThanOrEqual(W.ground + 1e-9);
        expect(rr.pos[i * 2]).toBeGreaterThanOrEqual(0);
        expect(rr.pos[i * 2]).toBeLessThanOrEqual(W.width);
      }
    });
    expect(point(r, P.Head).y).toBeLessThanOrEqual(W.ground - BONES.headR + 1e-9);
  });

  it('reports impacts proportional to the fall', () => {
    const low = run(standingRagdoll(0, 0, 0, 700), 0.6);
    const high = run(standingRagdoll(0, 1500, 0, 200), 0.6);
    expect(high).toBeGreaterThan(low);
    expect(high).toBeGreaterThan(1000);
  });

  it('preserves the throw spin: he tumbles', () => {
    const r = standingRagdoll(0, -600, 9, 300);
    const start = fitPose(r, 1).rot;
    for (let i = 0; i < 15; i++) {
      stepRagdoll(r, W);
    }
    expect(fitPose(r, 1).rot - start).toBeGreaterThan(1);
  });

  it('fitPose reproduces the ragdoll points (FK of the fitted pose ≈ points)', () => {
    const r = standingRagdoll(300, -500, 5, 350);
    run(r, 0.4);
    const p = fitPose(r, 1);
    const j = forwardKinematics(p);
    const keys = [
      ['head', P.Head],
      ['lHand', P.LHand],
      ['rFoot', P.RFoot],
      ['lKnee', P.LKnee],
    ] as const;
    for (const [k, i] of keys) {
      expect(Math.hypot(p.x + j[k].x - r.pos[i * 2], p.y + j[k].y - r.pos[i * 2 + 1])).toBeLessThan(4);
    }
  });

  it('detects orientation: back, face down, sitting, side', () => {
    expect(orientationOf({ ...STAND, rot: -Math.PI / 2, facing: 1, lKnee: 0.3, rKnee: 0.3 })).toBe('back');
    expect(orientationOf({ ...STAND, rot: Math.PI / 2, facing: -1, lKnee: 0.3, rKnee: 0.3 })).toBe('back');
    expect(orientationOf({ ...STAND, rot: Math.PI / 2, facing: 1, lKnee: 0.3, rKnee: 0.3 })).toBe('face');
    expect(orientationOf({ ...STAND, rot: 0.2 })).toBe('sitting');
    expect(orientationOf({ ...STAND, rot: Math.PI / 2, lKnee: 2, rKnee: 2 })).toBe('side');
    expect(orientationOf({ ...STAND, rot: Math.PI })).toBe('side');
  });

  it('one ragdoll step costs well under 0.2 ms', () => {
    const r = standingRagdoll(800, -900, 6, 300);
    const n = 2000;
    const t0 = performance.now();
    for (let i = 0; i < n; i++) {
      if (r.rested) {
        r.rested = false;
        r.stillSteps = 0;
        r.prev[P.Pelvis * 2 + 1] += 5;
      }
      stepRagdoll(r, W);
    }
    expect((performance.now() - t0) / n).toBeLessThan(0.2);
  });
});

describe('get-up from the resting ragdoll', () => {
  const settle = (vx: number, vy: number, spin: number): Ragdoll => {
    const r = standingRagdoll(vx, vy, spin, 400);
    run(r, 8);
    return r;
  };

  it.each([
    ['backward fall', -150, -500, -9],
    ['forward trip', 330, -160, 6.5],
    ['side faint', 40, 0, 2.6],
    ['wild throw', 1800, -1400, 10],
  ])('%s: starts exactly at the rest pose, stays above the floor, ends standing', (_name, vx, vy, spin) => {
    const r = settle(vx, vy, spin);
    const from = fitPose(r, 1);
    const clip = createGetUp({ stage: STAGE, from, orientation: orientationOf(from), variant: 'full' });
    const first = clip.sample(0, { vx: 0, vy: 0, spin: 0, pointer: null }).pose;
    expect(Math.hypot(first.x - from.x, first.y - from.y)).toBeLessThan(3);
    let prev = forwardKinematics(first);
    let prevRoot = first;
    for (let t = 1 / 60; t <= clip.duration; t += 1 / 60) {
      const p = clip.sample(t, { vx: 0, vy: 0, spin: 0, pointer: null }).pose;
      const j = forwardKinematics(p);
      for (const k of ['head', 'lHand', 'rHand', 'lFoot', 'rFoot'] as const) {
        const d = Math.hypot(p.x + j[k].x - (prevRoot.x + prev[k].x), p.y + j[k].y - (prevRoot.y + prev[k].y));
        expect(d).toBeLessThan(40);
        expect(p.y + j[k].y).toBeLessThanOrEqual(STAGE.ground + 0.5);
      }
      prev = j;
      prevRoot = p;
    }
    const end = clip.sample(clip.duration, { vx: 0, vy: 0, spin: 0, pointer: null }).pose;
    expect(Math.abs(end.rot)).toBeLessThan(0.05);
    expect(end.y).toBeCloseTo(STAGE.ground - 44, 0);
  });

  it('unwraps angles to the nearest turn and lifts poses out of the floor', () => {
    expect(nearestAngle(-Math.PI / 2, 5.5)).toBeCloseTo(3 * Math.PI / 2, 6);
    expect(nearestAngle(0.1, -0.1)).toBeCloseTo(0.1, 6);
    const sunk = keepAboveGround({ ...STAND, x: 0, y: 790 }, 800);
    const j = forwardKinematics(sunk);
    expect(sunk.y + Math.max(j.lFoot.y, j.rFoot.y)).toBeLessThanOrEqual(800 + 1e-9);
  });
});

describe('holding him (physical drag)', () => {
  const HOLD_W = ragdollWorld(3000, 5000);
  const standing = (): Ragdoll => {
    const pose = { ...STAND, x: 1500, y: 700 };
    return createRagdoll({ x: pose.x, y: pose.y }, forwardKinematics(pose), 0, 0, 0);
  };

  it('grabs the spot nearest to the click: hand, head, foot or torso', () => {
    const r = standing();
    const at = (i: number): Grab => grabAt(r, r.pos[i * 2] + 1, r.pos[i * 2 + 1] + 1);
    expect(at(P.RHand)).toMatchObject({ a: P.RHand, b: P.RHand });
    expect(at(P.LFoot)).toMatchObject({ a: P.LFoot, b: P.LFoot });
    expect(grabAt(r, r.pos[P.Head * 2], r.pos[P.Head * 2 + 1] - 9)).toMatchObject({ a: P.Head });
    const mid = grabAt(r, (r.pos[P.Pelvis * 2] + r.pos[P.Neck * 2]) / 2 + 1, (r.pos[P.Pelvis * 2 + 1] + r.pos[P.Neck * 2 + 1]) / 2);
    expect([mid.a, mid.b]).toEqual([P.Pelvis, P.Neck]);
  });

  function hold(r: Ragdoll, g: Grab, steps: number, move?: (i: number) => { x: number; y: number }, each?: (i: number) => void): void {
    for (let i = 0; i < steps; i++) {
      if (move) {
        const p = move(i);
        g.x = p.x;
        g.y = p.y;
      }
      stepRagdoll(r, HOLD_W, RAGDOLL_DT, g);
      each?.(i);
    }
  }

  it('held by the hand, he hangs straight down under it once the pointer is still', () => {
    const r = standing();
    const g = grabAt(r, r.pos[P.RHand * 2], r.pos[P.RHand * 2 + 1]);
    g.x = 1500;
    g.y = 300;
    hold(r, g, 600);
    const anchor = anchorOf(r, g);
    const com = centerOfMass(r);
    expect(Math.hypot(anchor.x - 1500, anchor.y - 300)).toBeLessThan(3);
    expect(Math.abs(com.x - anchor.x)).toBeLessThan(6);
    expect(com.y).toBeGreaterThan(anchor.y + 30);
    // Held by the foot → upside down: the head is the lowest point.
    const r2 = standing();
    const g2 = grabAt(r2, r2.pos[P.LFoot * 2], r2.pos[P.LFoot * 2 + 1]);
    g2.x = 1500;
    g2.y = 300;
    hold(r2, g2, 600);
    expect(point(r2, P.Head).y).toBeGreaterThan(point(r2, P.Pelvis).y);
  });

  it('a sideways swing then a stop gives a damped pendulum (overshoot, then decay)', () => {
    const r = standing();
    const g = grabAt(r, r.pos[P.Head * 2], r.pos[P.Head * 2 + 1]);
    g.x = 1500;
    g.y = 300;
    hold(r, g, 400);
    const angles: number[] = [];
    // Yank 200 px to the right in 0.2 s, then hold still.
    hold(
      r,
      g,
      420,
      (i) => ({ x: 1500 + 200 * Math.min(1, i / 12), y: 300 }),
      () => {
        const a = anchorOf(r, g);
        const c = centerOfMass(r);
        angles.push(Math.atan2(c.x - a.x, c.y - a.y));
      },
    );
    // The body lags behind (negative angle), swings past vertical (positive), and settles.
    const minA = Math.min(...angles.slice(0, 40));
    const maxA = Math.max(...angles.slice(10, 120));
    expect(minA).toBeLessThan(-0.15);
    expect(maxA).toBeGreaterThan(0.05);
    const late = angles.slice(-60).map(Math.abs);
    expect(Math.max(...late)).toBeLessThan(Math.max(Math.abs(minA), maxA) * 0.5);
  });

  it('stays finite and bone lengths hold at pointer speeds up to 8000 px/s', () => {
    const r = standing();
    const g = grabAt(r, r.pos[P.LHand * 2], r.pos[P.LHand * 2 + 1]);
    const speed = 8000 / 60;
    hold(r, g, 600, (i) => ({ x: 1500 + Math.sin(i * 0.35) * speed * 3, y: 800 + Math.cos(i * 0.27) * speed * 2 }));
    expect(allFinite(r)).toBe(true);
    for (const [a, b, min, max, stiff] of LINKS) {
      if (stiff === 1 && min === max) {
        const d = Math.hypot(r.pos[a * 2] - r.pos[b * 2], r.pos[a * 2 + 1] - r.pos[b * 2 + 1]);
        expect(Math.abs(d - min)).toBeLessThan(6);
      }
    }
  });

  it('the struggle wiggles only the free limbs', () => {
    const r = standing();
    const g = grabAt(r, r.pos[P.RHand * 2], r.pos[P.RHand * 2 + 1]);
    const hand = point(r, P.RHand);
    const foot = point(r, P.LFoot);
    applyStruggle(r, 0.3, 0.5, g);
    expect(point(r, P.RHand)).toEqual(hand);
    expect(point(r, P.LFoot)).not.toEqual(foot);
  });

  it('a held step with 18 iterations costs well under 0.3 ms', () => {
    const r = standing();
    const g = grabAt(r, r.pos[P.RHand * 2], r.pos[P.RHand * 2 + 1]);
    const n = 2000;
    const t0 = performance.now();
    hold(r, g, n, (i) => ({ x: 1500 + Math.sin(i * 0.2) * 300, y: 500 }));
    expect((performance.now() - t0) / n).toBeLessThan(0.3);
  });
});

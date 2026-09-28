import { chainEnd, normalizeAngle, solveTwoBone } from './ik';
import { BONES, STAND, STAND_HIP, armTo, footOffset, forwardKinematics, legTo, lerpPose } from './skeleton';

const close = (a: number, b: number, eps = 0.05): void => expect(Math.abs(a - b)).toBeLessThan(eps);

describe('two-bone IK', () => {
  it('reaches every target inside the ring (FK of the solution lands on the target)', () => {
    for (const [x, y] of [
      [0, 40],
      [12, 30],
      [-15, 25],
      [30, -10],
      [5, 44],
      [-20, -20],
    ]) {
      for (const bend of [1, -1] as const) {
        const s = solveTwoBone(x, y, 23, 23, bend);
        const end = chainEnd(s.upper, s.lower, 23, 23);
        expect(s.reached).toBe(true);
        close(end.x, x);
        close(end.y, y);
      }
    }
  });

  it('bends the middle joint to the requested side', () => {
    const knee = solveTwoBone(0, 40, 23, 23, 1);
    const elbow = solveTwoBone(0, 40, 23, 23, -1);
    expect(Math.sin(knee.upper)).toBeGreaterThan(0);
    expect(Math.sin(elbow.upper)).toBeLessThan(0);
  });

  it('stretches toward an unreachable target', () => {
    const s = solveTwoBone(100, 0, 23, 23, 1);
    const end = chainEnd(s.upper, s.lower, 23, 23);
    expect(s.reached).toBe(false);
    close(Math.hypot(end.x, end.y), 46, 0.1);
    close(Math.atan2(end.y, end.x), 0, 0.05);
  });

  it('normalizes angles into (-π, π]', () => {
    close(normalizeAngle(3 * Math.PI), Math.PI);
    close(normalizeAngle(-3 * Math.PI / 2), Math.PI / 2);
    close(normalizeAngle(0.5), 0.5);
  });
});

describe('skeleton forward kinematics', () => {
  it('stands with the feet on the ground below the hip and the head on top', () => {
    const j = forwardKinematics(STAND);
    close(Math.max(j.lFoot.y, j.rFoot.y), STAND_HIP, 0.5);
    expect(j.head.y).toBeCloseTo(-(BONES.spine + BONES.neck + BONES.headR), 0);
    expect(j.neck.y).toBeLessThan(j.shoulder.y + 1);
  });

  it('legTo / armTo put the end effector where asked', () => {
    const leg = legTo(8, 40);
    const foot = footOffset(leg.hip, leg.knee);
    close(foot.x, 8);
    close(foot.y, 40);
    const arm = armTo(10, 20, 0.3);
    const j = forwardKinematics({ ...STAND, torso: 0.3, rShoulder: arm.shoulder, rElbow: arm.elbow });
    close(j.rHand.x - j.shoulder.x, 10, 0.1);
    close(j.rHand.y - j.shoulder.y, 20, 0.1);
  });

  it('mirrors x when facing left', () => {
    const r = forwardKinematics({ ...STAND, rShoulder: 1.2 });
    const l = forwardKinematics({ ...STAND, rShoulder: 1.2, facing: -1 });
    close(l.rHand.x, -r.rHand.x);
    close(l.rHand.y, r.rHand.y);
  });

  it('rotates the whole body around the hip', () => {
    const j = forwardKinematics({ ...STAND, torso: 0, rot: Math.PI / 2 });
    close(j.head.x, BONES.spine + BONES.neck + BONES.headR, 0.5);
    close(j.head.y, 0, 0.5);
  });

  it('squashes around the feet (feet stay, head drops, body widens)', () => {
    const base = forwardKinematics(STAND);
    const squashed = forwardKinematics({ ...STAND, squash: 0.8 });
    close(Math.max(squashed.lFoot.y, squashed.rFoot.y), Math.max(base.lFoot.y, base.rFoot.y), 0.01);
    expect(squashed.head.y).toBeGreaterThan(base.head.y);
    expect(Math.abs(squashed.rFoot.x)).toBeGreaterThanOrEqual(Math.abs(base.rFoot.x));
  });

  it('lerps poses linearly and switches facing at the midpoint', () => {
    const a = { ...STAND, x: 0, torso: 0 };
    const b = { ...STAND, x: 100, torso: 1, facing: -1 as const };
    const m = lerpPose(a, b, 0.25);
    close(m.x, 25);
    close(m.torso, 0.25);
    expect(m.facing).toBe(1);
    expect(lerpPose(a, b, 0.75).facing).toBe(-1);
  });
});

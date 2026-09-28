import { DOODLE_SHAPES, doodlePoint, spawnDust, spawnSparkles, stepEffects } from './effects';
import { blinkAmount, pupilOffset, squintFor } from './face';
import { bubblePath, noise, sketchCircle, taperedPath } from './ink';

describe('pupil tracking', () => {
  it('points the pupil toward the cursor', () => {
    const right = pupilOffset({ x: 0, y: 0 }, { x: 500, y: 0 }, 2);
    expect(right.x).toBeGreaterThan(1.9);
    expect(Math.abs(right.y)).toBeLessThan(1e-9);
    const above = pupilOffset({ x: 0, y: 0 }, { x: 0, y: -400 }, 2);
    expect(above.y).toBeLessThan(-1.9);
  });

  it('never leaves the eye white', () => {
    for (const c of [
      { x: 1e6, y: -1e6 },
      { x: -3, y: 4 },
      { x: 0.5, y: 0.5 },
    ]) {
      const p = pupilOffset({ x: 0, y: 0 }, c, 1.8);
      expect(Math.hypot(p.x, p.y)).toBeLessThanOrEqual(1.8);
    }
  });

  it('a close cursor gives a smaller offset, so the two eyes converge', () => {
    const cursor = { x: 0, y: 20 };
    const left = pupilOffset({ x: -5, y: 0 }, cursor, 2);
    const right = pupilOffset({ x: 5, y: 0 }, cursor, 2);
    expect(left.x).toBeGreaterThan(0);
    expect(right.x).toBeLessThan(0);
    const far = pupilOffset({ x: -5, y: 0 }, { x: 0, y: 600 }, 2);
    expect(Math.hypot(left.x, left.y)).toBeLessThan(Math.hypot(far.x, far.y));
  });

  it('is centred when the cursor is exactly on the eye', () => {
    expect(pupilOffset({ x: 3, y: 3 }, { x: 3, y: 3 }, 2)).toEqual({ x: 0, y: 0 });
  });

  it('squints only when the cursor is very close; blinks are short bumps', () => {
    expect(squintFor(200)).toBe(0);
    expect(squintFor(10)).toBeGreaterThan(0.3);
    expect(blinkAmount(1.07, 1)).toBeCloseTo(1, 1);
    expect(blinkAmount(1.2, 1)).toBe(0);
    expect(blinkAmount(0.9, 1)).toBe(0);
  });
});

describe('effects', () => {
  const rng = (): number => 0.5;

  it('landing dust grows with impact and fades out', () => {
    expect(spawnDust(0, 0, 1, rng).length).toBeGreaterThan(spawnDust(0, 0, 0.1, rng).length);
    let list = spawnDust(0, 0, 1, rng);
    for (let i = 0; i < 200; i++) {
      list = stepEffects(list, 1 / 60);
    }
    expect(list).toEqual([]);
  });

  it('sparkles fly outward', () => {
    const [s] = stepEffects(spawnSparkles(0, 0, rng), 0.1);
    expect(Math.hypot(s.x, s.y)).toBeGreaterThan(3);
  });

  it('doodle curves are closed-ish and bounded', () => {
    for (const shape of DOODLE_SHAPES) {
      for (let u = 0; u <= 1; u += 0.05) {
        const p = doodlePoint(shape, u);
        expect(Math.abs(p.x)).toBeLessThan(2.5);
        expect(Math.abs(p.y)).toBeLessThan(2.5);
      }
    }
  });
});

describe('ink geometry', () => {
  it('noise is deterministic and within [-1, 1]', () => {
    expect(noise(3, 7)).toBe(noise(3, 7));
    for (let i = 0; i < 50; i++) {
      expect(Math.abs(noise(i, i * 3))).toBeLessThanOrEqual(1);
    }
  });

  it('builds closed tapered strokes and hand-drawn loops', () => {
    const d = taperedPath(
      [
        { x: 0, y: 0 },
        { x: 10, y: 10 },
        { x: 20, y: 5 },
      ],
      [4, 3, 1.5],
    );
    expect(d.startsWith('M ')).toBe(true);
    expect(d.endsWith('Z')).toBe(true);
    expect(d).not.toContain('NaN');
    expect(sketchCircle(0, 0, 10, 1)).not.toContain('NaN');
    expect(bubblePath(120, 40, 'left', 3)).toMatch(/Z$/);
  });
});

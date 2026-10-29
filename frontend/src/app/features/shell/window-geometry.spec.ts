import { clampWindowGeometry, defaultWindowGeometry, moveWindowGeometry, resizeWindowGeometry, WindowBounds, WindowGeometry } from './window-geometry';
import { WINDOW_SLOTS } from '../../core/windows/window-context';

const bounds: WindowBounds = { width: 900, height: 640 };
const initial: WindowGeometry = { x: 40, y: 30, width: 520, height: 380 };

describe('window geometry', () => {
  it('creates distinct defaults for every registered slot fully inside the workspace', () => {
    const defaults = WINDOW_SLOTS.map((slot) => defaultWindowGeometry(slot, bounds));
    const tasks = defaults[0];
    const people = defaults[1];
    expect(tasks).toEqual({ x: 24, y: 24, width: 760, height: 560 });
    expect(people).toEqual({ x: 72, y: 40, width: 688, height: 520 });
    expect(new Set(defaults.map(({ x, y }) => `${x}:${y}`)).size).toBe(WINDOW_SLOTS.length);
    for (const geometry of defaults) {
      expect(geometry.x + geometry.width).toBeLessThanOrEqual(bounds.width);
      expect(geometry.y + geometry.height).toBeLessThanOrEqual(bounds.height);
    }
  });

  it('moves only within the visible workspace bounds', () => {
    expect(moveWindowGeometry(initial, bounds, 900, 700)).toEqual({ x: 380, y: 260, width: 520, height: 380 });
    expect(moveWindowGeometry(initial, bounds, -100, -100)).toEqual({ x: 0, y: 0, width: 520, height: 380 });
  });

  it('resizes to the minimum and workspace edges without allowing negative size', () => {
    expect(resizeWindowGeometry(initial, bounds, -900, -900)).toEqual({ x: 40, y: 30, width: 320, height: 220 });
    expect(resizeWindowGeometry(initial, bounds, 900, 700)).toEqual({ x: 40, y: 30, width: 860, height: 610 });
  });

  it('clamps and shrinks a stored session geometry after the workspace becomes smaller', () => {
    expect(clampWindowGeometry({ x: 600, y: 500, width: 700, height: 500 }, { width: 440, height: 300 }))
      .toEqual({ x: 0, y: 0, width: 440, height: 300 });
  });

  it('handles very small or invalid bounds safely', () => {
    expect(clampWindowGeometry(initial, { width: 180, height: 90 })).toEqual({ x: 0, y: 0, width: 180, height: 90 });
    expect(defaultWindowGeometry('os1', { width: Number.NaN, height: -5 })).toEqual({ x: 0, y: 0, width: 0, height: 0 });
  });
});

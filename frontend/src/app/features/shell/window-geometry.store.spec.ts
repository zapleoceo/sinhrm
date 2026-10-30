import { WindowGeometryStore } from './window-geometry.store';

describe('WindowGeometryStore', () => {
  let store: WindowGeometryStore;

  beforeEach(() => {
    localStorage.clear();
    store = new WindowGeometryStore();
  });

  it('persists bounded geometry per authenticated user and slot', () => {
    store.save(1, 'os2', { x: 20, y: 30, width: 640, height: 480 });
    expect(store.read(1).os2).toEqual({ x: 20, y: 30, width: 640, height: 480 });
    expect(store.read(2)).toEqual({});
  });

  it('ignores malformed, oversized, non-finite, and out-of-range stored values', () => {
    localStorage.setItem('sinhrm.window-geometry.v1.1', '{broken');
    expect(store.read(1)).toEqual({});
    localStorage.setItem('sinhrm.window-geometry.v1.1', JSON.stringify({ os1: { x: Infinity, y: 0, width: 500, height: 400 }, os2: { x: 9000, y: 0, width: 500, height: 400 } }));
    expect(store.read(1)).toEqual({});
    localStorage.setItem('sinhrm.window-geometry.v1.1', 'x'.repeat(2049));
    expect(store.read(1)).toEqual({});
  });
});

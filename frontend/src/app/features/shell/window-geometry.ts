import { WindowSlot, WINDOW_SLOTS } from '../../core/windows/window-context';

export interface WindowBounds {
  readonly width: number;
  readonly height: number;
}

export interface WindowGeometry {
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
}

export const WINDOW_MIN_WIDTH = 320;
export const WINDOW_MIN_HEIGHT = 220;

const clamp = (value: number, min: number, max: number): number => Math.min(Math.max(value, min), max);
const finiteNonNegative = (value: number): number => Number.isFinite(value) ? Math.max(0, Math.round(value)) : 0;

/** Creates the two initial, slightly offset cards in local workspace coordinates. */
export function defaultWindowGeometry(slot: WindowSlot, bounds: WindowBounds): WindowGeometry {
  const index = Math.max(0, WINDOW_SLOTS.indexOf(slot));
  const columns = Math.ceil(Math.sqrt(WINDOW_SLOTS.length));
  const column = index % columns;
  const row = Math.floor(index / columns);
  const width = Math.min(Math.max(420, 760 - index * 72), finiteNonNegative(bounds.width));
  const height = Math.min(Math.max(360, 560 - index * 40), finiteNonNegative(bounds.height));
  const x = 24 + column * 48;
  const y = 24 + row * 56 + column * 16;
  return clampWindowGeometry({ x, y, width, height }, bounds);
}

/** Keeps a window fully inside the visible workspace, shrinking it if the viewport no longer fits it. */
export function clampWindowGeometry(geometry: WindowGeometry, bounds: WindowBounds): WindowGeometry {
  const maxWidth = finiteNonNegative(bounds.width);
  const maxHeight = finiteNonNegative(bounds.height);
  const width = clamp(finiteNonNegative(geometry.width), Math.min(WINDOW_MIN_WIDTH, maxWidth), maxWidth);
  const height = clamp(finiteNonNegative(geometry.height), Math.min(WINDOW_MIN_HEIGHT, maxHeight), maxHeight);
  return {
    x: clamp(finiteNonNegative(geometry.x), 0, maxWidth - width),
    y: clamp(finiteNonNegative(geometry.y), 0, maxHeight - height),
    width,
    height,
  };
}

export function moveWindowGeometry(
  start: WindowGeometry,
  bounds: WindowBounds,
  deltaX: number,
  deltaY: number,
): WindowGeometry {
  return clampWindowGeometry({ ...start, x: start.x + deltaX, y: start.y + deltaY }, bounds);
}

export function resizeWindowGeometry(
  start: WindowGeometry,
  bounds: WindowBounds,
  deltaX: number,
  deltaY: number,
): WindowGeometry {
  const maxWidth = finiteNonNegative(bounds.width);
  const maxHeight = finiteNonNegative(bounds.height);
  const x = clamp(finiteNonNegative(start.x), 0, maxWidth);
  const y = clamp(finiteNonNegative(start.y), 0, maxHeight);
  const availableWidth = maxWidth - x;
  const availableHeight = maxHeight - y;
  return {
    x,
    y,
    width: clamp(finiteNonNegative(start.width + deltaX), Math.min(WINDOW_MIN_WIDTH, availableWidth), availableWidth),
    height: clamp(finiteNonNegative(start.height + deltaY), Math.min(WINDOW_MIN_HEIGHT, availableHeight), availableHeight),
  };
}

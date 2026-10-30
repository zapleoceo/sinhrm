import { Injectable } from '@angular/core';
import { WindowGeometry } from './window-geometry';
import { WindowSlot, WINDOW_SLOTS } from '../../core/windows/window-context';
import { safeStorage } from '../../core/storage/safe-storage';

const STORAGE_PREFIX = 'sinhrm.window-geometry.v1.';
const MAX_STORAGE_LENGTH = 2048;

@Injectable({ providedIn: 'root' })
export class WindowGeometryStore {
  read(userId: number): Partial<Record<WindowSlot, WindowGeometry>> {
    const raw = safeStorage.get(`${STORAGE_PREFIX}${userId}`);
    if (!raw || raw.length > MAX_STORAGE_LENGTH) return {};
    try {
      const parsed: unknown = JSON.parse(raw);
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
      const result: Partial<Record<WindowSlot, WindowGeometry>> = {};
      for (const slot of WINDOW_SLOTS) {
        const value = (parsed as Record<string, unknown>)[slot];
        if (!isGeometry(value)) continue;
        result[slot] = value;
      }
      return result;
    } catch {
      return {};
    }
  }

  save(userId: number, slot: WindowSlot, geometry: WindowGeometry): void {
    if (!isGeometry(geometry)) return;
    const next = this.read(userId);
    next[slot] = geometry;
    safeStorage.set(`${STORAGE_PREFIX}${userId}`, JSON.stringify(next));
  }
}

function isGeometry(value: unknown): value is WindowGeometry {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const geometry = value as Record<string, unknown>;
  return ['x', 'y', 'width', 'height'].every((key) => typeof geometry[key] === 'number' && Number.isFinite(geometry[key]))
    && (geometry['x'] as number) >= 0 && (geometry['x'] as number) <= 8192
    && (geometry['y'] as number) >= 0 && (geometry['y'] as number) <= 8192
    && (geometry['width'] as number) >= 200 && (geometry['width'] as number) <= 8192
    && (geometry['height'] as number) >= 180 && (geometry['height'] as number) <= 8192;
}

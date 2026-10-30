import { Injectable } from '@angular/core';

export interface WorkspaceSnapshot {
  readonly version: 1;
  readonly userId: number;
  readonly internalUrl: string;
  readonly visibleUrl: string;
}

const STORAGE_PREFIX = 'sinhrm.workspace.v1.';
const MAX_SNAPSHOT_LENGTH = 42_000;
const HISTORY_STATE_KEY = 'sinhrmWorkspace';

@Injectable({ providedIn: 'root' })
export class WorkspaceSnapshotStore {
  readSession(userId: number): WorkspaceSnapshot | null {
    try {
      return this.parse(sessionStorage.getItem(`${STORAGE_PREFIX}${userId}`), userId);
    } catch {
      return null;
    }
  }

  writeSession(snapshot: WorkspaceSnapshot): void {
    const serialized = JSON.stringify(snapshot);
    if (serialized.length > MAX_SNAPSHOT_LENGTH) return;
    try {
      sessionStorage.setItem(`${STORAGE_PREFIX}${snapshot.userId}`, serialized);
    } catch {
      // A blocked or full session store only disables workspace recovery.
    }
  }

  readHistory(state: unknown, userId: number): WorkspaceSnapshot | null {
    if (state === null || typeof state !== 'object') return null;
    return this.parse((state as Record<string, unknown>)[HISTORY_STATE_KEY], userId);
  }

  historyState(snapshot: WorkspaceSnapshot): Record<string, WorkspaceSnapshot> {
    return { [HISTORY_STATE_KEY]: snapshot };
  }

  withoutHistoryState(state: Record<string, unknown> | undefined): Record<string, unknown> | undefined {
    if (!state || !(HISTORY_STATE_KEY in state)) return state;
    const cleanState = { ...state };
    delete cleanState[HISTORY_STATE_KEY];
    return Object.keys(cleanState).length ? cleanState : undefined;
  }

  clearSession(userId: number): void {
    try {
      sessionStorage.removeItem(`${STORAGE_PREFIX}${userId}`);
    } catch {
      // Storage may be unavailable.
    }
  }

  private parse(raw: unknown, userId: number): WorkspaceSnapshot | null {
    let value: unknown = raw;
    if (typeof raw === 'string') {
      if (raw.length > MAX_SNAPSHOT_LENGTH) return null;
      try {
        value = JSON.parse(raw);
      } catch {
        return null;
      }
    }
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return null;
    const record = value as Record<string, unknown>;
    if (record['version'] !== 1 || record['userId'] !== userId
      || typeof record['internalUrl'] !== 'string' || record['internalUrl'].length > 40_960
      || typeof record['visibleUrl'] !== 'string' || record['visibleUrl'].length > 4096
      || !record['visibleUrl'].startsWith('/') || record['visibleUrl'].startsWith('//')) return null;
    return {
      version: 1,
      userId,
      internalUrl: record['internalUrl'],
      visibleUrl: record['visibleUrl'],
    };
  }
}

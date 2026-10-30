import { WorkspaceSnapshot, WorkspaceSnapshotStore } from './workspace-snapshot.store';

describe('WorkspaceSnapshotStore', () => {
  let store: WorkspaceSnapshotStore;
  const snapshot: WorkspaceSnapshot = {
    version: 1,
    userId: 8,
    internalUrl: '/(os1:window/people)?osActive=os1',
    visibleUrl: '/people',
  };

  beforeEach(() => {
    sessionStorage.clear();
    store = new WorkspaceSnapshotStore();
  });

  it('stores a bounded per-user snapshot in this tab and attaches it to Router history state', () => {
    store.writeSession(snapshot);
    expect(store.readSession(8)).toEqual(snapshot);
    expect(store.readSession(9)).toBeNull();
    expect(store.readHistory({ ...store.historyState(snapshot), navigationId: 1 }, 8)).toEqual(snapshot);
  });

  it('rejects invalid users, unsafe visible URLs, malformed JSON, and oversized snapshots', () => {
    expect(store.readHistory(store.historyState(snapshot), 9)).toBeNull();
    expect(store.readHistory({ sinhrmWorkspace: { ...snapshot, visibleUrl: '//outside.example' } }, 8)).toBeNull();
    expect(store.readHistory({ sinhrmWorkspace: '{invalid' }, 8)).toBeNull();
    expect(store.readHistory({ sinhrmWorkspace: { ...snapshot, internalUrl: 'x'.repeat(40_961) } }, 8)).toBeNull();
  });

  it('clears only the current user tab snapshot', () => {
    store.writeSession(snapshot);
    store.writeSession({ ...snapshot, userId: 9 });
    store.clearSession(8);
    expect(store.readSession(8)).toBeNull();
    expect(store.readSession(9)).not.toBeNull();
  });
});

import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { CurrentUser } from '../../core/auth/auth.model';
import { desktopLayoutKey, DesktopLayoutStore } from './desktop-layout.store';

function makeUser(id: number): CurrentUser {
  return { id, name: `User ${id}`, email: '', avatar_url: null, locale: 'uk', roles: ['employee'], modules: ['people'] as string[], status: 'active' };
}

describe('DesktopLayoutStore', () => {
  let user: ReturnType<typeof signal<CurrentUser | null>>;

  beforeEach(() => {
    localStorage.clear();
    user = signal<CurrentUser | null>(makeUser(1));
  });

  afterEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
    TestBed.resetTestingModule();
  });

  function create(): DesktopLayoutStore {
    TestBed.configureTestingModule({ providers: [DesktopLayoutStore, { provide: AuthService, useValue: { user } }] });
    const store = TestBed.inject(DesktopLayoutStore);
    TestBed.tick();
    return store;
  }

  it('restores layout by account, saves an empty layout, and clears it on logout', () => {
    const store = create();
    expect(store.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
    store.remove('today');
    expect(JSON.parse(localStorage.getItem(desktopLayoutKey(1)) ?? 'null')).toEqual({ version: 1, widgets: [{ id: 'summary', size: 'm' }, { id: 'tasks', size: 's' }] });
    store.remove('tasks');
    store.remove('summary');
    expect(store.layout().widgets).toEqual([]);
    expect(JSON.parse(localStorage.getItem(desktopLayoutKey(1)) ?? 'null')).toEqual({ version: 1, widgets: [] });

    user.set(makeUser(2));
    TestBed.tick();
    expect(store.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
    user.set(null);
    TestBed.tick();
    expect(store.layout().widgets).toEqual([]);
  });

  it('rejects corrupt stored data and stays usable when browser storage throws', () => {
    localStorage.setItem(desktopLayoutKey(1), '{broken');
    const store = create();
    expect(store.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('storage disabled'); });
    expect(store.add('approvals')).toBe(true);
    expect(store.layout().widgets.at(-1)?.id).toBe('approvals');
  });

  it('falls back to defaults when storage cannot be read', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('storage disabled'); });
    const store = create();
    expect(store.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
  });

  it('reorders the visible set without shifting hidden widgets out of place', () => {
    localStorage.setItem(desktopLayoutKey(1), JSON.stringify({ version: 1, widgets: [
      { id: 'summary', size: 'm' }, { id: 'tasks', size: 's' }, { id: 'today', size: 'l' },
    ] }));
    const store = create();
    store.moveVisible(['tasks', 'today'], 0, 1);
    expect(store.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'today', 'tasks']);
    store.resize('tasks', 'l');
    expect(store.layout().widgets.at(-1)?.size).toBe('l');
  });
});

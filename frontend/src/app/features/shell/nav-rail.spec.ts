import { BreakpointObserver } from '@angular/cdk/layout';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AUTO_HIDE_DELAY_MS, HOVER_QUERY, NARROW_QUERY, NavRail, loadAutoHide, loadCollapsed, namedBy, saveAutoHide, saveCollapsed } from './nav-rail';

describe('nav rail state', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => vi.restoreAllMocks());

  it('is expanded by default and remembers the choice', () => {
    expect(loadCollapsed()).toBe(false);
    saveCollapsed(true);
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBe('1');
    expect(loadCollapsed()).toBe(true);
    saveCollapsed(false);
    expect(loadCollapsed()).toBe(false);
  });

  it('auto-hide is off (pinned) by default and kept under its own key', () => {
    expect(loadAutoHide()).toBe(false);
    saveAutoHide(true);
    expect(localStorage.getItem('sinhrm.nav.autohide')).toBe('1');
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBeNull();
    expect(loadAutoHide()).toBe(true);
  });

  it('works without storage: reads as expanded and pinned, saving does not throw', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied');
    });
    expect(loadCollapsed()).toBe(false);
    expect(loadAutoHide()).toBe(false);
    expect(() => saveCollapsed(true)).not.toThrow();
    expect(() => saveAutoHide(true)).not.toThrow();
  });

  it('a tooltip repeats the accessible name when it equals the aria-label or (no aria-label) is in the text', () => {
    const link = document.createElement('a');
    link.innerHTML = '<span class="label">Мої задачі</span><span>3</span>';
    expect(namedBy(link, ' Мої задачі ')).toBe(true);
    const user = document.createElement('button');
    user.setAttribute('aria-label', 'Меню користувача');
    user.textContent = 'Olena K';
    expect(namedBy(user, 'Olena K')).toBe(false);
    expect(namedBy(user, 'Меню користувача')).toBe(true);
  });
});

describe('nav rail auto-hide', () => {
  function rail(opts: { narrow?: boolean; hover?: boolean } = {}): NavRail {
    const { narrow = false, hover = true } = opts;
    TestBed.configureTestingModule({
      providers: [
        NavRail,
        { provide: BreakpointObserver, useValue: { observe: (q: string) => of({ matches: q === NARROW_QUERY ? narrow : q === HOVER_QUERY ? hover : false, breakpoints: {} }) } },
      ],
    });
    return TestBed.inject(NavRail);
  }

  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
  });
  afterEach(() => vi.useRealTimers());

  it('switching on keeps a rail; the pointer opens it over the content, leaving folds it after the delay', () => {
    const r = rail();
    expect([r.autoHideAvailable(), r.autoHide(), r.collapsed()]).toEqual([true, false, false]);
    r.toggleAutoHide();
    expect(localStorage.getItem('sinhrm.nav.autohide')).toBe('1');
    expect([r.autoHide(), r.peek(), r.collapsed()]).toEqual([true, false, true]);

    r.pointerEnter();
    expect([r.peek(), r.collapsed()]).toEqual([true, false]);
    r.pointerLeave();
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS - 50);
    expect(r.peek()).toBe(true);
    r.pointerEnter(); // back in time: the fold is cancelled
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS * 2);
    expect(r.peek()).toBe(true);
    r.pointerLeave();
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS);
    expect([r.peek(), r.collapsed()]).toEqual([false, true]);
  });

  it('switched on under the pointer it stays open until the pointer leaves; off → back to the pinned sidebar', () => {
    const r = rail();
    r.pointerEnter();
    r.toggleAutoHide();
    expect(r.peek()).toBe(true);
    r.toggleAutoHide();
    expect([r.autoHide(), r.peek(), r.collapsed()]).toEqual([false, false, false]);
  });

  it('keyboard focus holds it open, focus leaving folds it at once, a mouse click does not hold it; Esc folds', () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const r = rail();
    r.focusIn(false);
    expect(r.peek()).toBe(false);
    r.focusIn(true);
    expect(r.peek()).toBe(true);
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS * 2);
    expect(r.peek()).toBe(true);
    r.focusOut();
    expect(r.peek()).toBe(false);

    r.focusIn(true);
    r.escape();
    expect(r.peek()).toBe(false);
  });

  it('an open user menu keeps it open wherever the pointer goes; closing the menu folds it after the delay', () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const r = rail();
    r.pointerEnter();
    r.hold(true);
    r.pointerLeave();
    r.focusOut(); // focus moved into the menu overlay
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS * 2);
    expect(r.peek()).toBe(true);
    r.hold(false);
    vi.advanceTimersByTime(AUTO_HIDE_DELAY_MS);
    expect(r.peek()).toBe(false);
  });

  it('expand() on the auto-hide rail opens it over the content instead of unpinning', () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    const r = rail();
    r.expand();
    expect([r.autoHide(), r.peek()]).toEqual([true, true]);
    expect(localStorage.getItem('sinhrm.nav.collapsed')).toBeNull();
  });

  it('never applies on the mobile drawer or a touch screen', () => {
    localStorage.setItem('sinhrm.nav.autohide', '1');
    let r = rail({ narrow: true });
    expect([r.autoHideAvailable(), r.autoHide(), r.collapsed()]).toEqual([false, false, false]);
    r.pointerEnter();
    expect(r.peek()).toBe(false);

    TestBed.resetTestingModule();
    r = rail({ hover: false });
    expect([r.autoHideAvailable(), r.autoHide(), r.collapsed()]).toEqual([false, false, false]);
  });
});

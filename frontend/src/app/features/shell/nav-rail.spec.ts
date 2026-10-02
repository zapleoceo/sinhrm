import { loadCollapsed, saveCollapsed } from './nav-rail';

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

  it('works without storage: reads as expanded, saving does not throw', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied');
    });
    expect(loadCollapsed()).toBe(false);
    expect(() => saveCollapsed(true)).not.toThrow();
  });
});

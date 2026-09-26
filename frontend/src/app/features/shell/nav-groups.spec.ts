import { groupForUrl, loadExpanded, saveExpanded } from './nav-groups';

describe('nav groups', () => {
  it('maps URLs to their group, longest prefix wins', () => {
    expect(groupForUrl('/candidates/5')).toBe('recruiting');
    expect(groupForUrl('/reports')).toBe('recruiting');
    expect(groupForUrl('/reports/catalog/x')).toBe('services');
    expect(groupForUrl('/desk')).toBe('services');
    expect(groupForUrl('/desk/queue')).toBe('admin');
    expect(groupForUrl('/time/team?week=1')).toBe('people');
    expect(groupForUrl('/timeoff')).toBe('people');
    expect(groupForUrl('/pulse/mood')).toBe('perform');
    expect(groupForUrl('/admin/users')).toBe('admin');
    expect(groupForUrl('/admin/audit')).toBe('admin');
  });

  it('keeps top-level pages outside groups', () => {
    expect(groupForUrl('/')).toBeNull();
    expect(groupForUrl('/tasks')).toBeNull();
    expect(groupForUrl('/me')).toBeNull();
    expect(groupForUrl('/timesheet')).toBeNull();
  });

  it('persists expanded groups per user', () => {
    localStorage.clear();
    saveExpanded(1, new Set(['people', 'admin']));
    expect([...loadExpanded(1)].sort()).toEqual(['admin', 'people']);
    expect(loadExpanded(2).size).toBe(0);
  });

  it('ignores garbage and missing storage', () => {
    localStorage.setItem('sinhrm.nav.expanded.3', '{bad');
    expect(loadExpanded(3).size).toBe(0);
    localStorage.setItem('sinhrm.nav.expanded.4', '["people","hack"]');
    expect([...loadExpanded(4)]).toEqual(['people']);
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied'); });
    const spySet = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied'); });
    expect(loadExpanded(1).size).toBe(0);
    expect(() => saveExpanded(1, new Set(['people']))).not.toThrow();
    spy.mockRestore();
    spySet.mockRestore();
  });
});

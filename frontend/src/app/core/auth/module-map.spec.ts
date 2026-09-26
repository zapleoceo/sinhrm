import { moduleForUrl } from './module-map';

describe('moduleForUrl', () => {
  it('maps pages to their module, longest prefix first', () => {
    expect(moduleForUrl('/candidates/12?tab=cv')).toBe('recruiting');
    expect(moduleForUrl('/reports')).toBe('recruiting');
    expect(moduleForUrl('/reports/catalog/x')).toBe('reports');
    expect(moduleForUrl('/me')).toBe('people');
    expect(moduleForUrl('/me/documents')).toBe('documents');
    expect(moduleForUrl('/timeoff/calendar')).toBe('time-off');
    expect(moduleForUrl('/time/team')).toBe('time');
  });

  it('returns null for core pages', () => {
    expect(moduleForUrl('/')).toBeNull();
    expect(moduleForUrl('/tasks')).toBeNull();
    expect(moduleForUrl('/admin/modules')).toBeNull();
    expect(moduleForUrl('/timesheet')).toBeNull();
  });
});

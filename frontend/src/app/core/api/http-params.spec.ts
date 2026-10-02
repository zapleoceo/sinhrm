import { toParams } from './http-params';

describe('toParams', () => {
  it('drops empty values and stringifies the rest (the API reads "20" as 20)', () => {
    const p = toParams({ q: 'Ann', page: 2, perPage: 20, active: true, status: undefined, branch: null, empty: '', zero: 0, off: false });
    expect(p.keys()).toEqual(['q', 'page', 'perPage', 'active', 'zero', 'off']);
    expect(p.get('page')).toBe('2');
    expect(p.get('active')).toBe('true');
    expect(p.get('zero')).toBe('0');
    expect(p.get('off')).toBe('false');
  });

  it('accepts a typed query interface without spreading it', () => {
    interface Query {
      q?: string;
      page?: number;
    }
    const query: Query = { page: 3 };
    expect(toParams(query).toString()).toBe('page=3');
    expect(toParams({}).toString()).toBe('');
  });
});

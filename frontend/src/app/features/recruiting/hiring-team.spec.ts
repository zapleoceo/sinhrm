import { withCurrent } from './hiring-team';

describe('withCurrent', () => {
  it('appends assigned people missing from the picker list, once', () => {
    const list = [{ id: 1, name: 'Ann' }];
    expect(withCurrent(list, { id: 2, name: 'Bob' }, { id: 1, name: 'Ann' }, null, undefined)).toEqual([
      { id: 1, name: 'Ann' },
      { id: 2, name: 'Bob' },
    ]);
    expect(list).toHaveLength(1);
  });
});

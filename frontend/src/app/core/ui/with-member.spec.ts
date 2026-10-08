import { withMember } from './with-member';

describe('withMember', () => {
  it('returns a new set with the value added or removed; the original is untouched', () => {
    const original: ReadonlySet<number> = new Set([1, 2]);
    const added = withMember(original, 3, true);
    expect([...added]).toEqual([1, 2, 3]);
    expect(added).not.toBe(original);
    expect([...withMember(original, 1, false)]).toEqual([2]);
    expect([...original]).toEqual([1, 2]);
  });

  it('adding a present value or removing a missing one still gives a new equal set', () => {
    const original: ReadonlySet<string> = new Set(['a']);
    const same = withMember(original, 'a', true);
    expect(same).not.toBe(original);
    expect([...same]).toEqual(['a']);
    expect([...withMember(original, 'b', false)]).toEqual(['a']);
  });
});

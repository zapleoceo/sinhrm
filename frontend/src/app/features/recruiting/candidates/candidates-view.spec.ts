import { CANDIDATES_VIEW_KEY, readViewPref, saveViewPref } from './candidates-view';

describe('candidates view toggle (Список | Дошка)', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    localStorage.clear();
  });

  it('defaults to the list and remembers the board with its vacancy', () => {
    expect(readViewPref()).toEqual({ view: 'list', vacancyId: null });
    saveViewPref({ view: 'board', vacancyId: 5 });
    expect(JSON.parse(localStorage.getItem(CANDIDATES_VIEW_KEY) ?? '{}')).toEqual({ view: 'board', vacancyId: 5 });
    expect(readViewPref()).toEqual({ view: 'board', vacancyId: 5 });
  });

  it('survives broken or blocked storage', () => {
    localStorage.setItem(CANDIDATES_VIEW_KEY, '{oops');
    expect(readViewPref().view).toBe('list');
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => saveViewPref({ view: 'board', vacancyId: 1 })).not.toThrow();
  });
});

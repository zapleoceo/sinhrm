import { rankByScreening } from './screening-ranking';

interface Card {
  id: number;
  screening_score?: number | null;
}

const ids = (cards: readonly Card[]): number[] => cards.map((card) => card.id);

describe('rankByScreening', () => {
  it('puts higher scores first and zero before null or missing scores', () => {
    const cards: Card[] = [
      { id: 1, screening_score: null },
      { id: 2, screening_score: 0 },
      { id: 3, screening_score: 82 },
      { id: 4 },
      { id: 5, screening_score: 100 },
    ];
    expect(ids(rankByScreening(cards))).toEqual([5, 3, 2, 1, 4]);
  });

  it('preserves the default order for equal scores and unscored cards', () => {
    const cards: Card[] = [
      { id: 1, screening_score: 82 },
      { id: 2, screening_score: null },
      { id: 3, screening_score: 82 },
      { id: 4 },
      { id: 5, screening_score: 0 },
      { id: 6, screening_score: 0 },
    ];
    expect(ids(rankByScreening(cards))).toEqual([1, 3, 5, 6, 2, 4]);
  });

  it('does not mutate source order or clone cards, so switching off restores the original order', () => {
    const first = Object.freeze({ id: 1, screening_score: 0 });
    const second = Object.freeze({ id: 2, screening_score: 82 });
    const cards = Object.freeze([first, second]);
    const ranked = rankByScreening(cards);
    expect(ids(cards)).toEqual([1, 2]);
    expect(ranked).toEqual([second, first]);
    expect(ranked).not.toBe(cards);
    expect(ranked[0]).toBe(second);
  });

  it('returns an independent empty list', () => {
    const cards: readonly Card[] = Object.freeze([]);
    expect(rankByScreening(cards)).toEqual([]);
    expect(rankByScreening(cards)).not.toBe(cards);
  });
});

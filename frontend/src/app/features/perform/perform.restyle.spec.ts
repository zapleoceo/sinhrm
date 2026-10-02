import { FEEDBACK_TONE, FEEDBACK_TYPES } from './perform.model';
import { ObjectivesPage } from './objectives/objectives.page';
import { ReviewResults } from './reviews/review-results';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Perform restyle', () => {
  it('gives every feedback type a status-pill tone (colour + marker shape)', () => {
    for (const s of FEEDBACK_TYPES) {
      expect(PILL_TONES).toContain(FEEDBACK_TONE[s]);
    }
  });

  it('review results mark reviewer types by shape as well as colour', () => {
    const style = css(ReviewResults);
    for (const type of ['manager', 'peer', 'upward']) {
      expect(style).toMatch(new RegExp(`data-type=.?${type}.?\\][^{]*::before`));
    }
  });

  it('objective progress is a route line drawn once and static with reduced motion', () => {
    const style = css(ObjectivesPage);
    expect(style).toMatch(/@keyframes\s+\S*trace/);
    expect(style).toMatch(/prefers-reduced-motion:\s*reduce[^@]*animation:\s*none/);
    expect(css(ObjectivesPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

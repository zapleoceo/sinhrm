import { HiringDetailPage } from './hiring-detail.page';
import { HIRING_STATUSES, PILL_TONE, statusTone } from './hiring-requests.model';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Hiring requests restyle', () => {
  it('maps every request status to a status-pill tone', () => {
    for (const s of HIRING_STATUSES) {
      expect(PILL_TONES).toContain(PILL_TONE[statusTone(s)]);
    }
    expect(PILL_TONE[statusTone('closed')]).toBe('good');
    expect(PILL_TONE[statusTone('rejected')]).toBe('bad');
  });

  it('approval route is drawn as stations on a line, no hex colours', () => {
    const style = css(HiringDetailPage);
    expect(style).toContain('--station');
    expect(style).toMatch(/prefers-reduced-motion/);
    expect(css(HiringDetailPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

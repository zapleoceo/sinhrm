import { RunCard } from './runs/run-card';
import { RUN_STATUSES, RUN_STATUS_TONE } from './workflows.model';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Workflows restyle', () => {
  it('gives every run status a status-pill tone (colour + marker shape)', () => {
    for (const s of RUN_STATUSES) {
      expect(PILL_TONES).toContain(RUN_STATUS_TONE[s]);
    }
  });

  it('run steps are stations on one line (ring per step, success line behind done steps)', () => {
    const style = css(RunCard);
    expect(style).toContain('--station');
    expect(style).toContain('var(--app-success)');
    expect(css(RunCard)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

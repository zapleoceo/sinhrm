import { LEAVE_REQUEST_STATUSES, LEAVE_STATUS_TONE } from './timeoff.model';
import { BalancesPanel } from './widgets/balances-panel';
import { RequestsList } from './widgets/requests-list';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Time off restyle', () => {
  it('gives every leave request status a status-pill tone (colour + marker shape)', () => {
    for (const s of LEAVE_REQUEST_STATUSES) {
      expect(PILL_TONES).toContain(LEAVE_STATUS_TONE[s]);
    }
  });

  it('balances are KPI tiles with a top sleeper; rejected requests are muted, not faded', () => {
    expect(css(BalancesPanel)).toMatch(/border-top-width:\s*4px/);
    expect(css(RequestsList)).not.toMatch(/opacity/);
  });
});

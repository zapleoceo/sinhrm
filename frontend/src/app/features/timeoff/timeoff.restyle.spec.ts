import { Type } from '@angular/core';
import { LEAVE_REQUEST_STATUSES, LEAVE_STATUS_TONE } from './timeoff.model';
import { BalancesPanel } from './widgets/balances-panel';
import { RequestsList } from './widgets/requests-list';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

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

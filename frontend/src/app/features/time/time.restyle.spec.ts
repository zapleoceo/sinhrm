import { MyWeekPage } from './my-week.page';
import { TIMESHEET_STATUS_TONE, TimesheetStatus } from './time.model';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Time restyle', () => {
  it('gives every timesheet status a status-pill tone (colour + marker shape)', () => {
    for (const s of (['draft', 'submitted', 'approved', 'rejected'] as const satisfies readonly TimesheetStatus[])) {
      expect(PILL_TONES).toContain(TIMESHEET_STATUS_TONE[s]);
    }
  });

  it('week grid: hour cells reach 44px on phones, totals in mono, no hex', () => {
    const style = css(MyWeekPage);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*min-height:\s*2\.75rem/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(MyWeekPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

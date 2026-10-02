import { AuditHistory } from './audit-history';
import { css } from '../../../testing/css';

describe('Audit restyle', () => {
  it('history reads as a feed on one route line (track + station rings)', () => {
    const style = css(AuditHistory);
    expect(style).toContain('var(--app-track)');
    expect(style).toMatch(/border-radius:\s*50%/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(AuditHistory)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

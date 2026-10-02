import { ErrorsPage } from './errors.page';
import { css } from '../../../testing/css';

describe('Errors page restyle', () => {
  it('resolved groups: dashed line + neutral rail instead of a fade; count is a mono badge', () => {
    const style = css(ErrorsPage);
    expect(style).not.toMatch(/opacity/);
    expect(style).toMatch(/border-style:\s*dashed/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(ErrorsPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

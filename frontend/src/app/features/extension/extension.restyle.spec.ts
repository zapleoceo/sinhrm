import { ExtensionPage } from './extension.page';
import { css } from '../../../testing/css';

describe('Extension restyle', () => {
  it('panels and the one-time token box use the 1.5px line, the token is mono', () => {
    const style = css(ExtensionPage);
    expect(style).not.toMatch(/1px (solid|dashed)/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(ExtensionPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('secondary text uses the global .small utility (styles.scss), not a local copy', () => {
    expect(css(ExtensionPage)).not.toMatch(/\.small[^{]*\{/);
  });
});

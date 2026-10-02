import { DirectoryPage } from './directory.page';
import { css } from '../../../testing/css';

describe('Directory restyle', () => {
  it('panel frame comes from the global .panel; notices use 1.5px lines; status is a pill', () => {
    const style = css(DirectoryPage);
    expect(style).toContain('var(--app-border-w)');
    expect(style).not.toMatch(/1px solid/);
    expect(style).not.toContain('--mat-chip-label-text-color');
    expect(css(DirectoryPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

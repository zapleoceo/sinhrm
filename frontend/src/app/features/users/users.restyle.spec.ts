import { UsersPage } from './users.page';
import { css } from '../../../testing/css';

describe('Users restyle', () => {
  it('frame comes from the global .panel; status is a pill, not a chip colour override', () => {
    const style = css(UsersPage);
    expect(style).not.toMatch(/1px solid/);
    expect(style).not.toContain('--mat-chip-label-text-color');
    expect(css(UsersPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

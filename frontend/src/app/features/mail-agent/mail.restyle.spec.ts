import { MailPage } from './mail.page';
import { css } from '../../../testing/css';

describe('Mail agent restyle', () => {
  it('status tiles carry a top «sleeper» line; warnings use the AA warn text token', () => {
    const style = css(MailPage);
    expect(style).toMatch(/border-top:\s*4px solid/);
    expect(style).toContain('var(--app-warn-text)');
    expect(css(MailPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});

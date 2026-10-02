import { ChannelPanel } from './channel-panel';
import { css } from '../../../testing/css';

describe('Channels restyle', () => {
  it('channel panel separator is the 1.5px track line', () => {
    const style = css(ChannelPanel);
    expect(style).toContain('var(--app-track)');
    expect(style).not.toMatch(/1px solid/);
  });
});
